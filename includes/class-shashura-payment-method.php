<?php
/**
 * CampTix payment method: Shashura mobile money (MTN / Airtel Uganda).
 *
 * Buyer flow
 *  1. CampTix checkout calls payment_checkout(); we send the buyer to our phone page.
 *  2. Buyer enters a number; it is format-checked, rate-limited and verified with KYC.
 *  3. Buyer confirms the account name / network / amount; we push a PIN prompt (requestToPay).
 *  4. The waiting page polls; when Shashura reports TS with the exact order amount the
 *     order is completed and the buyer lands on CampTix's ticket page.
 *
 * Safety rules
 *  - Only Shashura's /enquiry decides that an order is paid. Browser requests and webhook
 *    bodies are only hints that tell us *when* to ask.
 *  - Paid means: state TS, external_ref equal to our reference, amount equal to the order total.
 *  - Every attempt of an order is reconciled, so a buyer who approves an older prompt is
 *    still recognised (and a second paid attempt is flagged for refund).
 *  - Orders are never set to "pending" (CampTix would hold the seats forever). They stay
 *    drafts until paid, cancelled, failed or timed out by CampTix's sweep, which asks
 *    Shashura first.
 *  - Settlement runs under a per-order database lock so the webhook and the browser cannot
 *    complete the same order twice.
 *
 * @package CampTix_Shashura
 */

defined( 'ABSPATH' ) || exit;

class CampTix_Payment_Method_Shashura extends CampTix_Payment_Method {

	public $id                   = 'shashura';
	public $name                 = 'Mobile Money (MTN / Airtel)';
	public $description          = 'Pay with MTN or Airtel Mobile Money via Shashura.';
	public $supported_currencies = array( 'UGX' );
	public $supported_features   = array(
		'refund-single'  => false,
		'refund-all'     => false,
		'refund-partial' => false,
	);

	const MAX_ATTEMPTS       = 5;           // PIN prompts per order.
	const MAX_LOOKUPS        = 8;           // KYC lookups per order.
	const LOOKUPS_PER_IP     = 20;          // KYC lookups per IP per hour.
	const LOOKUPS_ALERT      = 300;         // KYC lookups per site per hour that trigger an admin alert (never blocks).
	const RETRY_AFTER        = 150;         // Seconds before a new prompt may replace an unanswered one.
	const CONFIRM_TTL        = 900;         // Seconds a verified number stays valid for confirmation.
	const CHECKOUT_HOLD      = 900;         // Seconds CampTix holds the seats once checkout reaches us.
	const SESSION_HOLD       = 1800;        // Seconds CampTix holds the seats after a prompt is sent.
	const POLL_MIN_INTERVAL  = 3;           // Seconds between enquiries for the same attempt.
	const UNKNOWN_FAIL_AFTER = 600;         // An attempt Shashura never heard of counts as lost after this.
	const LOCK_STALE_AFTER   = 300;         // Seconds after which an abandoned lock is cleared.
	const WEBHOOK_MAX_BYTES  = 65536;
	const FINAL_STATES       = array( 'paid', 'failed', 'mismatch', 'rejected' );
	const STATUSES           = array( 'draft', 'pending', 'publish', 'cancel', 'refund', 'failed', 'timeout' );

	protected $options = array();

	/** Set by render_wait() just before render_page(); localized onto checkout.js's poller. */
	private $wait_script_data = array();

	/** Declared here too: CampTix 1.x's base class doesn't, which is deprecated on PHP 8.2+. */
	public $camptix_options;

	/** @var array Orders already looked at during the current timeout sweep. */
	private $swept = array();

	/* ==================================================================
	 * Setup
	 * ================================================================ */

	public function camptix_init() {
		$this->options = array_merge( array(
			'client_id'      => '',
			'client_secret'  => '',
			'environment'    => 'sandbox',
			'webhook_secret' => '',
			'base_url'       => '',
			'name_display'   => 'full',
			'ip_source'      => 'remote_addr',
			'proxy_hops'     => 1,
			// Off by default: a "Powered by Shashura" credit link must never
			// appear on a buyer-facing page without the site administrator
			// explicitly opting in (WordPress.org Plugin Directory guidelines).
			'show_branding'  => false,
			'last_check'     => '',
		), $this->get_payment_options() );

		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'camptix_timeout_sweep_start', array( $this, 'reset_sweep' ) );
		add_action( 'camptix_pre_attendee_timeout', array( $this, 'pre_attendee_timeout' ) );
		add_filter( 'camptix_currencies', array( $this, 'ensure_ugx' ) ); // CampTix 1.x has no UGX.
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	public function ensure_ugx( $currencies ) {
		if ( ! isset( $currencies['UGX'] ) ) {
			$currencies['UGX'] = array( 'label' => 'Uganda Shilling', 'format' => 'UGX %s', 'decimal_point' => 0 );
		}
		return $currencies;
	}

	private function api() {
		return new Shasmomo_API(
			$this->options['client_id'],
			$this->options['client_secret'],
			$this->options['environment'],
			$this->options['webhook_secret'],
			$this->options['base_url']
		);
	}

	/** The webhook secret is required: without it a payment made after the buyer leaves can be lost. */
	private function is_configured() {
		return '' !== $this->options['client_id'] && '' !== $this->options['client_secret'] && '' !== $this->options['webhook_secret'];
	}

	/* ==================================================================
	 * Settings
	 * ================================================================ */

	public function payment_settings_fields() {
		$this->add_settings_field_helper( 'client_id', __( 'Client ID', 'shashura-mobile-money-for-camptix' ), array( $this, 'field_plain' ),
			__( 'Starts with PID_. Found on your app in the Shashura dashboard.', 'shashura-mobile-money-for-camptix' ) );

		$this->add_settings_field_helper( 'client_secret', __( 'Client Secret', 'shashura-mobile-money-for-camptix' ), array( $this, 'field_secret' ),
			__( 'Starts with PSK_. Each environment has its own secret.', 'shashura-mobile-money-for-camptix' ) );

		$this->add_settings_field_helper( 'base_url', __( 'API Base URL', 'shashura-mobile-money-for-camptix' ), array( $this, 'field_plain' ),
			sprintf(
				/* translators: %s: default API base URL */
				__( 'Leave blank to use the default, %s. Only change this to point the plugin at a different gateway instance (e.g. a sandbox or local dev deployment).', 'shashura-mobile-money-for-camptix' ),
				'<code>' . esc_html( Shasmomo_API::BASE_URL ) . '</code>'
			) );

		$this->add_settings_field_helper( 'environment', __( 'Environment', 'shashura-mobile-money-for-camptix' ), array( $this, 'field_environment' ),
			__( 'Use Sandbox to test. Switch to Production to take real payments.', 'shashura-mobile-money-for-camptix' ) );

		$this->add_settings_field_helper( 'webhook_secret', __( 'Webhook Secret', 'shashura-mobile-money-for-camptix' ), array( $this, 'field_secret' ),
			sprintf(
				/* translators: %s: callback URL */
				__( 'The app\'s webhook signing secret. On the Shashura Apps page set the Callback URL to %s', 'shashura-mobile-money-for-camptix' ),
				'<code>' . esc_html( $this->webhook_url() ) . '</code>'
			) );

		$this->add_settings_field_helper( 'name_display', __( 'Account name shown to buyer', 'shashura-mobile-money-for-camptix' ), array( $this, 'field_name_display' ),
			__( 'How the registered name from the number check is shown before the buyer confirms.', 'shashura-mobile-money-for-camptix' ) );

		$this->add_settings_field_helper( 'ip_source', __( 'Visitor IP from', 'shashura-mobile-money-for-camptix' ), array( $this, 'field_ip_source' ),
			__( 'Used to rate-limit number checks. Choose a proxy header only if your site really sits behind that proxy: visitors can fake these headers otherwise.', 'shashura-mobile-money-for-camptix' ) );

		$this->add_settings_field_helper( 'proxy_hops', __( 'Trusted proxies', 'shashura-mobile-money-for-camptix' ), array( $this, 'field_proxy_hops' ),
			__( 'Only used with X-Forwarded-For: the number of proxies you control in front of the site (usually 1).', 'shashura-mobile-money-for-camptix' ) );

		$this->add_settings_field_helper( 'show_branding', __( 'Show "Powered by Shashura"', 'shashura-mobile-money-for-camptix' ), array( $this, 'field_show_branding' ),
			__( 'Off by default. Turning this on adds a small "Powered by Shashura" credit link to the buyer-facing payment pages.', 'shashura-mobile-money-for-camptix' ) );

		$this->add_settings_field_helper( 'last_check', __( 'Connection', 'shashura-mobile-money-for-camptix' ), array( $this, 'field_status' ) );
	}

	public function field_plain( $args ) {
		printf( '<input type="text" class="regular-text" autocomplete="off" name="%s" value="%s" />', esc_attr( $args['name'] ), esc_attr( $args['value'] ) );
		$this->field_description( $args );
	}

	/** Secrets are never printed back into the page. Leaving the box empty keeps the saved value. */
	public function field_secret( $args ) {
		$has = '' !== (string) $args['value'];
		printf(
			'<input type="password" class="regular-text" autocomplete="new-password" name="%s" value="" placeholder="%s" />',
			esc_attr( $args['name'] ),
			esc_attr( $has ? __( 'Saved. Leave blank to keep it.', 'shashura-mobile-money-for-camptix' ) : '' )
		);
		$this->field_description( $args );
	}

	public function field_environment( $args ) {
		printf( '<select name="%s">', esc_attr( $args['name'] ) );
		foreach ( array( 'sandbox' => __( 'Sandbox (testing)', 'shashura-mobile-money-for-camptix' ), 'production' => __( 'Production (real payments)', 'shashura-mobile-money-for-camptix' ) ) as $k => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $args['value'], $k, false ), esc_html( $label ) );
		}
		echo '</select>';
		$this->field_description( $args );
	}

	public function field_name_display( $args ) {
		printf( '<select name="%s">', esc_attr( $args['name'] ) );
		foreach ( array( 'full' => __( 'Full name (e.g. Mugisha Samuel)', 'shashura-mobile-money-for-camptix' ), 'partial' => __( 'First name and initials (e.g. Mugisha S.)', 'shashura-mobile-money-for-camptix' ) ) as $k => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $args['value'], $k, false ), esc_html( $label ) );
		}
		echo '</select>';
		$this->field_description( $args );
	}

	public function field_ip_source( $args ) {
		$choices = array(
			'remote_addr'     => __( 'Direct connection (REMOTE_ADDR)', 'shashura-mobile-money-for-camptix' ),
			'cloudflare'      => __( 'Cloudflare (CF-Connecting-IP)', 'shashura-mobile-money-for-camptix' ),
			'x_real_ip'       => __( 'Reverse proxy (X-Real-IP)', 'shashura-mobile-money-for-camptix' ),
			'x_forwarded_for' => __( 'Reverse proxy (X-Forwarded-For)', 'shashura-mobile-money-for-camptix' ),
		);
		printf( '<select name="%s">', esc_attr( $args['name'] ) );
		foreach ( $choices as $k => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $args['value'], $k, false ), esc_html( $label ) );
		}
		echo '</select>';
		$this->field_description( $args );
	}

	public function field_proxy_hops( $args ) {
		printf(
			'<input type="number" class="small-text" min="1" max="5" step="1" name="%s" value="%s" />',
			esc_attr( $args['name'] ),
			esc_attr( (string) max( 1, (int) $args['value'] ) )
		);
		$this->field_description( $args );
	}

	public function field_show_branding( $args ) {
		printf(
			'<label><input type="checkbox" name="%s" value="1"%s /> %s</label>',
			esc_attr( $args['name'] ),
			checked( ! empty( $args['value'] ), true, false ),
			esc_html__( 'Show the credit link', 'shashura-mobile-money-for-camptix' )
		);
		$this->field_description( $args );
	}

	public function field_status( $args ) {
		if ( '' === $this->options['webhook_secret'] ) {
			echo '<p style="color:#d63638;margin:0 0 6px">&#9888; ' . esc_html__( 'Add the Webhook Secret. Mobile Money is not offered at checkout until it is set.', 'shashura-mobile-money-for-camptix' ) . '</p>';
		}
		$v = is_array( $args['value'] ) ? $args['value'] : array();
		if ( empty( $v['at'] ) ) {
			echo '<em>' . esc_html__( 'Not checked yet. Save your credentials to test them.', 'shashura-mobile-money-for-camptix' ) . '</em>';
			return;
		}
		$when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $v['at'] );
		if ( ! empty( $v['ok'] ) ) {
			echo '<span style="color:#008a20">&#10003; ' . esc_html__( 'Connected', 'shashura-mobile-money-for-camptix' ) . '</span> <span class="description">(' . esc_html( $when ) . ')</span>';
		} else {
			echo '<span style="color:#d63638">&#10007; ' . esc_html( $v['msg'] ) . '</span> <span class="description">(' . esc_html( $when ) . ')</span>';
		}
	}

	private function field_description( $args ) {
		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . wp_kses( $args['description'], array( 'code' => array() ) ) . '</p>';
		}
	}

	public function validate_options( $input ) {
		$input  = is_array( $input ) ? wp_unslash( $input ) : array();
		$output = $this->options;

		if ( isset( $input['client_id'] ) ) {
			$output['client_id'] = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $input['client_id'] );
		}
		foreach ( array( 'client_secret', 'webhook_secret' ) as $key ) {
			if ( isset( $input[ $key ] ) && '' !== trim( (string) $input[ $key ] ) ) {
				$output[ $key ] = trim( (string) $input[ $key ] );
			}
		}
		if ( isset( $input['environment'] ) ) {
			$output['environment'] = 'production' === $input['environment'] ? 'production' : 'sandbox';
		}
		if ( isset( $input['base_url'] ) ) {
			$base_url = untrailingslashit( trim( (string) $input['base_url'] ) );
			$output['base_url'] = '' !== $base_url ? esc_url_raw( $base_url ) : '';
		}
		if ( isset( $input['name_display'] ) ) {
			$output['name_display'] = 'partial' === $input['name_display'] ? 'partial' : 'full';
		}
		if ( isset( $input['ip_source'] ) ) {
			$output['ip_source'] = in_array( $input['ip_source'], array( 'remote_addr', 'cloudflare', 'x_real_ip', 'x_forwarded_for' ), true ) ? $input['ip_source'] : 'remote_addr';
		}
		if ( isset( $input['proxy_hops'] ) ) {
			$output['proxy_hops'] = min( 5, max( 1, absint( $input['proxy_hops'] ) ) );
		}
		// Checkbox: absent from $input entirely when unchecked, so this only
		// turns on if the admin explicitly ticked it on this save.
		$output['show_branding'] = ! empty( $input['show_branding'] );

		// Test the credentials on save so the admin sees a problem now, not at the first sale.
		if ( '' !== $output['client_id'] && '' !== $output['client_secret'] ) {
			$api    = new Shasmomo_API( $output['client_id'], $output['client_secret'], $output['environment'], '', $output['base_url'] );
			$result = $api->test_credentials();
			$output['last_check'] = array(
				'at'  => time(),
				'ok'  => ! is_wp_error( $result ),
				'msg' => is_wp_error( $result ) ? $this->admin_error_text( $result ) : '',
			);
		}
		return $output;
	}

	private function admin_error_text( WP_Error $e ) {
		$code = Shasmomo_API::error_code( $e );
		$map  = array(
			'INVALID_SECRET'     => __( 'Client Secret is wrong for this environment.', 'shashura-mobile-money-for-camptix' ),
			'INVALID_APP'        => __( 'No Shashura app matches this Client ID and environment.', 'shashura-mobile-money-for-camptix' ),
			'IP_NOT_WHITELISTED' => __( 'This server\'s IP is not on the app\'s allow-list in Shashura.', 'shashura-mobile-money-for-camptix' ),
		);
		if ( isset( $map[ $code ] ) ) {
			return $map[ $code ];
		}
		/* translators: %s: error message returned by the Shashura API */
		return sprintf( __( 'Could not connect: %s', 'shashura-mobile-money-for-camptix' ), sanitize_text_field( $e->get_error_message() ) );
	}

	/* ==================================================================
	 * URLs
	 * ================================================================ */

	private function url( $action, $token, $extra = array() ) {
		global $camptix;
		return add_query_arg( array_merge( array(
			'tix_action'         => $action,
			'tix_payment_method' => $this->id,
			'tix_payment_token'  => $token,
		), $extra ), $camptix->get_tickets_url() );
	}

	private function webhook_url() {
		return rest_url( 'camptix-shashura/v1/webhook' );
	}

	/* ==================================================================
	 * Order context (state lives on the CampTix attendee posts)
	 * ================================================================ */

	/**
	 * @return array|null { token, ids, first, status, total, attempts, pending, lookups }
	 */
	private function load( $token ) {
		if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return null;
		}
		$ids = get_posts( array(
			'post_type'        => 'tix_attendee',
			'post_status'      => self::STATUSES,
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'order'            => 'ASC',
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- a payment callback must resolve its own order regardless of any front-end query filter (e.g. language/exclude filters) another plugin adds.
			'suppress_filters' => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- CampTix stores the payment token only as post meta; there is no other lookup path.
			'meta_query'       => array( array( 'key' => 'tix_payment_token', 'value' => $token, 'compare' => '=', 'type' => 'CHAR' ) ),
		) );
		if ( ! $ids ) {
			return null;
		}
		$first = (int) $ids[0];

		// Only orders the buyer placed with this payment method.
		if ( $this->id !== get_post_meta( $first, 'tix_payment_method', true ) ) {
			return null;
		}

		$order = $this->get_order_by_attendee_id( $first );
		$total = isset( $order['total'] ) ? (int) round( (float) $order['total'] ) : 0;
		$att   = get_post_meta( $first, '_shashura_attempts', true );
		$pend  = get_post_meta( $first, '_shashura_pending', true );

		return array(
			'token'    => $token,
			'ids'      => array_map( 'intval', $ids ),
			'first'    => $first,
			'status'   => get_post_status( $first ),
			'total'    => $total,
			'attempts' => is_array( $att ) ? $att : array(),
			'pending'  => is_array( $pend ) ? $pend : array(),
			'lookups'  => (int) get_post_meta( $first, '_shashura_lookups', true ),
		);
	}

	private function save( $ctx, $key, $value ) {
		foreach ( $ctx['ids'] as $id ) {
			update_post_meta( $id, $key, wp_slash( $value ) );
		}
	}

	private function find_token_by_reference( $reference ) {
		if ( ! is_string( $reference ) || ! preg_match( '/^[A-Za-z0-9_\-]{1,64}$/', $reference ) ) {
			return '';
		}
		$ids = get_posts( array(
			'post_type'        => 'tix_attendee',
			'post_status'      => self::STATUSES,
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- a webhook must resolve the order its own prompt reference belongs to regardless of any front-end query filter.
			'suppress_filters' => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the prompt reference is only stored as post meta; there is no other lookup path.
			'meta_query'       => array( array( 'key' => '_shashura_ref', 'value' => $reference, 'compare' => '=', 'type' => 'CHAR' ) ),
		) );
		return $ids ? (string) get_post_meta( $ids[0], 'tix_payment_token', true ) : '';
	}

	/* ==================================================================
	 * Lock: one settlement per order at a time (webhook vs. browser vs. sweep)
	 * ================================================================ */

	/**
	 * Take the order's lock. The row value carries a random owner id, so a request can only
	 * ever release its own lock (never one a later request took after a stale cleanup).
	 *
	 * @return array|false { name, value }
	 */
	private function lock( $token ) {
		global $wpdb;
		$name = 'shasmomo_lock_' . md5( $token );

		// Direct queries are required for the whole function: the lock is a race-free
		// "insert if absent" primitive (INSERT IGNORE / affected-rows check), which
		// add_option()/update_option() cannot provide, and an object cache would make
		// the single source of truth (the row) stale across requests racing for the lock.
		for ( $try = 0; $try < 2; $try++ ) {
			$value = time() . '|' . wp_generate_password( 20, false, false );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = $wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$name,
				$value
			) );
			if ( 1 === (int) $ok ) {
				$lock = array( 'name' => $name, 'value' => $value );
				register_shutdown_function( array( $this, 'unlock' ), $lock ); // Released even if CampTix exits mid-way.
				return $lock;
			}
			// Clear a lock left by a crashed request. A full settlement (5 enquiries x timeout) fits well inside this.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			if ( '' !== $held && (int) $held < time() - self::LOCK_STALE_AFTER ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $held ) );
				continue;
			}
			return false;
		}
		return false;
	}

	public function unlock( $lock ) {
		global $wpdb;
		if ( is_array( $lock ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releases the same race-free lock row taken in lock() above.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $lock['name'], $lock['value'] ) );
		}
	}

	/* ==================================================================
	 * Reconciliation: the only place that decides an order is paid
	 * ================================================================ */

	/**
	 * Ask Shashura about every unsettled attempt and record the answers.
	 *
	 * @return array { paid: attempt[], latest: attempt|null, attempts }
	 */
	private function reconcile( $ctx, $force = false ) {
		global $camptix;
		$attempts = $ctx['attempts'];
		$changed  = false;
		$now      = time();
		$api      = $this->api();

		foreach ( $attempts as $i => $a ) {
			if ( in_array( $a['status'], self::FINAL_STATES, true ) ) {
				continue;
			}
			// Sent under the other environment's credentials, which this API can't query. Leave it
			// open (never "failed": the buyer may have paid) until the environment is switched back.
			if ( isset( $a['env'] ) && $a['env'] !== $this->options['environment'] ) {
				if ( empty( $a['env_warned'] ) ) {
					$a['env_warned'] = 1;
					$attempts[ $i ]  = $a;
					$changed         = true;
					$camptix->log( sprintf( 'Attempt %s was sent in %s but the environment is now %s; it cannot be checked until you switch back. Check it in the Shashura dashboard.', $a['ref'], $a['env'], $this->options['environment'] ), $ctx['first'], null, 'payment' );
				}
				continue;
			}
			$interval = 'lost' === $a['status'] ? 60 : self::POLL_MIN_INTERVAL;
			if ( ! $force && ! empty( $a['checked'] ) && $a['checked'] > $now - $interval ) {
				continue;
			}

			$r            = $api->enquiry( $a['ref'] );
			$a['checked'] = $now;

			if ( is_wp_error( $r ) ) {
				// Shashura doesn't know this reference (yet). Show it as failed so the buyer can
				// retry, but keep asking: an outage or delay must not lose a real payment.
				if ( 404 === Shasmomo_API::http_status( $r ) && $now - $a['created'] > self::UNKNOWN_FAIL_AFTER ) {
					$a['status'] = 'lost';
				}
				$camptix->log( 'Shashura enquiry error for ' . $a['ref'] . ': ' . $r->get_error_message(), $ctx['first'], null, 'payment' );
			} elseif ( ! empty( $a['tid'] ) && '' !== $r['tid'] && $r['tid'] !== $a['tid'] ) {
				// external_ref is overwritten with the telco's own confirmation id once a
				// payment settles, so it no longer reliably matches what was asked for — tid
				// is Shashura's own permanent identifier and is what's checked instead.
				$camptix->log( 'Shashura enquiry returned a different transaction; ignoring.', $ctx['first'], array( 'asked_tid' => $a['tid'], 'got_tid' => $r['tid'] ), 'payment' );
			} else {
				$a['raw_state']   = $r['raw_state'];
				$a['tid']         = $r['tid'] ? $r['tid'] : ( isset( $a['tid'] ) ? $a['tid'] : '' );
				$a['network_ref'] = $r['network_ref'];

				if ( 'paid' === $r['state'] ) {
					if ( null === $r['amount'] ) {
						// Never complete without seeing the amount.
						$camptix->log( 'Shashura reported TS without an amount; not completing.', $ctx['first'], $r, 'payment' );
					} elseif ( $r['amount'] !== (int) $a['amount'] || $r['amount'] !== $ctx['total'] ) {
						$a['status']      = 'mismatch';
						$a['paid_amount'] = $r['amount'];
						$camptix->log( sprintf( 'Shashura amount mismatch on %s: paid %d, order total %d. Not completing.', $a['ref'], $r['amount'], $ctx['total'] ), $ctx['first'], $r, 'payment' );
					} else {
						$a['status']      = 'paid';
						$a['paid_amount'] = $r['amount'];
					}
				} elseif ( 'failed' === $r['state'] ) {
					$a['status'] = 'failed';
				} else {
					$a['status'] = 'pending';
				}
			}

			$attempts[ $i ] = $a;
			$changed        = true;
		}

		if ( $changed ) {
			$this->save( $ctx, '_shashura_attempts', $attempts );
		}

		$paid = array_values( array_filter( $attempts, function ( $a ) {
			return 'paid' === $a['status'];
		} ) );

		return array(
			'paid'     => $paid,
			'latest'   => $attempts ? end( $attempts ) : null,
			'attempts' => $attempts,
		);
	}

	/**
	 * Reconcile under the order lock and, if paid, tell CampTix. Any other CampTix result
	 * (cancel, fail) is decided by $otherwise, which also runs while the lock is held, so it
	 * can never overwrite a payment another request is recording at the same moment.
	 *
	 * @param callable|null $otherwise fn( $ctx, $rec, $state ) called under the lock when not paid.
	 * @return string paid | pending | failed | none | busy
	 */
	private function settle( $ctx, $interactive, $force = false, $otherwise = null ) {
		$lock = $this->lock( $ctx['token'] );
		if ( ! $lock ) {
			return 'busy';
		}

		$ctx = $this->load( $ctx['token'] ); // Fresh state now that we hold the lock.
		if ( ! $ctx ) {
			$this->unlock( $lock );
			return 'none';
		}
		$rec = $this->reconcile( $ctx, $force );

		if ( $rec['paid'] ) {
			$this->report( $ctx['token'], CampTix_Plugin::PAYMENT_STATUS_COMPLETED, $this->payment_data( $ctx, $rec ), $interactive );
			$this->unlock( $lock );
			return 'paid';
		}

		if ( ! $rec['latest'] ) {
			$state = 'none';
		} else {
			$state = in_array( $rec['latest']['status'], array( 'failed', 'rejected', 'mismatch', 'lost' ), true ) ? 'failed' : 'pending';
		}
		if ( $otherwise ) {
			call_user_func( $otherwise, $ctx, $rec, $state ); // May redirect and exit; the shutdown hook unlocks.
		}
		$this->unlock( $lock );
		return $state;
	}

	/** True when every attempt has a final, unpaid answer from Shashura. */
	private function all_failed( $rec ) {
		foreach ( $rec['attempts'] as $a ) {
			if ( ! in_array( $a['status'], array( 'failed', 'rejected', 'mismatch' ), true ) ) {
				return false;
			}
		}
		return (bool) $rec['attempts'];
	}

	private function payment_data( $ctx, $rec ) {
		global $camptix;
		$p = $rec['paid'][0];

		if ( count( $rec['paid'] ) > 1 && ! get_post_meta( $ctx['first'], '_shashura_dup_alerted', true ) ) {
			$refs = wp_list_pluck( array_slice( $rec['paid'], 1 ), 'ref' );
			$camptix->log(
				'DUPLICATE mobile money payment: more than one prompt was approved for this order. Refund the extra payment(s) manually.',
				$ctx['first'],
				$refs,
				'payment'
			);
			update_post_meta( $ctx['first'], '_shashura_dup_alerted', time() );
			wp_mail(
				get_option( 'admin_email' ),
				sprintf( '[%s] Duplicate mobile money payment – refund needed', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				sprintf(
					"A buyer approved more than one payment prompt for the same order.\n\nOrder attendee: %s\nKept: %s\nRefund in the Shashura dashboard: %s\n",
					admin_url( 'post.php?post=' . $ctx['first'] . '&action=edit' ),
					$p['ref'],
					implode( ', ', $refs )
				)
			);
		}

		return array(
			'transaction_id'      => $p['tid'] ? $p['tid'] : $p['ref'],
			'transaction_details' => array(
				'raw' => array(
					'gateway'            => 'shashura',
					'environment'        => $this->options['environment'],
					'reference'          => $p['ref'],
					'tid'                => $p['tid'],
					'network_ref'        => isset( $p['network_ref'] ) ? $p['network_ref'] : '',
					'network'            => $p['network'],
					'payer_name'         => $p['name'],
					'payer_number'       => Shasmomo_Phone::mask( $p['msisdn'] ),
					'amount'             => $p['paid_amount'],
					'duplicate_payments' => count( $rec['paid'] ) > 1 ? wp_list_pluck( array_slice( $rec['paid'], 1 ), 'ref' ) : array(),
				),
			),
		);
	}

	/** payment_result(), non-interactive where CampTix supports it (2.x); CampTix 1.x always redirects. */
	private function report( $token, $result, $data, $interactive ) {
		global $camptix;
		if ( $interactive ) {
			return $camptix->payment_result( $token, $result, $data );
		}
		$m = new ReflectionMethod( $camptix, 'payment_result' );
		if ( $m->getNumberOfParameters() >= 4 ) {
			return $camptix->payment_result( $token, $result, $data, false );
		}
		add_filter( 'wp_redirect', '__return_false', 999 );
		return $camptix->payment_result( $token, $result, $data );
	}

	/* ==================================================================
	 * CampTix entry point
	 * ================================================================ */

	public function payment_checkout( $payment_token ) {
		global $camptix;

		if ( ! $this->is_configured() || ! in_array( $this->camptix_options['currency'], $this->supported_currencies, true ) ) {
			$camptix->log( 'Shashura is not configured, or the currency is not UGX.', 0, null, 'payment' );
			$camptix->error_flag( 'payment_failed' );
			return CampTix_Plugin::PAYMENT_STATUS_FAILED;
		}

		// Without this CampTix holds the seats for 24 hours, so scripted abandoned checkouts
		// could keep an event sold out. Sending a prompt extends the hold.
		if ( method_exists( $camptix, 'set_order_session_expiry' ) ) {
			$camptix->set_order_session_expiry( $payment_token, time() + self::CHECKOUT_HOLD );
		}

		wp_safe_redirect( $this->url( 'shashura_pay', $payment_token ) );
		die();
	}

	/* ==================================================================
	 * Routing
	 * ================================================================ */

	public function template_redirect() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing (which template to render), not a state change. Every handler below that actually changes state verifies its own nonce.
		if ( empty( $_GET['tix_payment_method'] ) || $this->id !== $_GET['tix_payment_method'] || empty( $_GET['tix_action'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		$action = sanitize_key( wp_unslash( $_GET['tix_action'] ) );
		if ( ! in_array( $action, array( 'shashura_pay', 'shashura_wait', 'shashura_status', 'payment_return', 'payment_cancel' ), true ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		$token = isset( $_GET['tix_payment_token'] ) ? sanitize_key( wp_unslash( $_GET['tix_payment_token'] ) ) : '';
		$ctx   = $this->load( $token );

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- DONOTCACHEPAGE is a fixed, unprefixed constant name that page-cache plugins (WP Super Cache, W3TC, etc.) look for literally; it cannot be renamed.
			define( 'DONOTCACHEPAGE', true ); // Page-cache plugins must never store these pages.
		}
		nocache_headers();
		header( 'X-Frame-Options: DENY' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );

		if ( 'shashura_status' === $action ) {
			$this->handle_status( $ctx );
		}
		if ( ! $ctx ) {
			$this->render_message( __( 'Order not found', 'shashura-mobile-money-for-camptix' ), __( 'We could not find this order. Please start your ticket purchase again.', 'shashura-mobile-money-for-camptix' ), 404 );
		}

		switch ( $action ) {
			case 'payment_return':
				$this->handle_return( $ctx );
				break;
			case 'payment_cancel':
				$this->handle_cancel( $ctx );
				break;
			case 'shashura_wait':
				$this->handle_wait( $ctx );
				break;
			default:
				$this->handle_pay( $ctx );
		}
	}

	/** Orders that are not drafts can't be paid again: send paid ones to their tickets. */
	private function guard_draft( $ctx ) {
		if ( 'draft' === $ctx['status'] ) {
			return;
		}
		if ( in_array( $ctx['status'], array( 'publish', 'pending' ), true ) ) {
			$this->redirect_to_tickets( $ctx );
		}
		// Cancelled, failed or timed out, but a prompt may have been approved since: ask first.
		if ( in_array( $ctx['status'], array( 'cancel', 'failed', 'timeout' ), true ) && $ctx['attempts'] && $this->can_recover( $ctx ) ) {
			$this->settle( $ctx, true, true ); // Redirects to the tickets when it recovers a payment.
		}
		$this->render_message(
			__( 'This order is closed', 'shashura-mobile-money-for-camptix' ),
			__( 'This order is no longer waiting for payment. If you were charged, contact the organiser with your phone number. Otherwise start a new ticket purchase.', 'shashura-mobile-money-for-camptix' ),
			410,
			true
		);
	}

	/** CampTix 1.x can't record a result for a timed-out order. */
	private function can_recover( $ctx ) {
		global $camptix;
		return 'timeout' !== $ctx['status'] || method_exists( $camptix, 'set_order_session_expiry' );
	}

	private function redirect_to_tickets( $ctx ) {
		global $camptix;
		$access = get_post_meta( $ctx['first'], 'tix_access_token', true );
		wp_safe_redirect( add_query_arg( array( 'tix_action' => 'access_tickets', 'tix_access_token' => $access ), $camptix->get_tickets_url() ) . '#tix' );
		die();
	}

	/* ==================================================================
	 * Step 1 + 2: number entry, KYC, confirm, push
	 * ================================================================ */

	private function handle_pay( $ctx ) {
		$this->guard_draft( $ctx );

		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
			$notice = '';
			if ( isset( $_GET['notice'] ) && 'declined' === $_GET['notice'] ) {
				$notice = __( 'The last payment request was declined or expired. You can try again.', 'shashura-mobile-money-for-camptix' );
			}
			$this->render_phone( $ctx, '', $notice );
		}

		if ( ! isset( $_POST['_shasmomo_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_shasmomo_nonce'] ) ), 'shasmomo_' . $ctx['token'] ) ) {
			$this->render_phone( $ctx, __( 'Your session expired. Please try again.', 'shashura-mobile-money-for-camptix' ) );
		}

		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
		if ( 'confirm' === $step ) {
			$this->step_confirm( $ctx );
		}
		$this->step_lookup( $ctx );
	}

	private function step_lookup( $ctx ) {
		global $camptix;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the caller, handle_pay(), before step_lookup() is reached.
		$msisdn = Shasmomo_Phone::normalize( isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '' );
		if ( '' === $msisdn ) {
			$this->render_phone( $ctx, __( 'Enter a valid MTN or Airtel Uganda number, e.g. 0772 123 456.', 'shashura-mobile-money-for-camptix' ) );
		}
		if ( count( $ctx['attempts'] ) >= self::MAX_ATTEMPTS ) {
			$this->render_message( __( 'Too many attempts', 'shashura-mobile-money-for-camptix' ), __( 'This order has reached the maximum number of payment attempts. Please start a new ticket purchase.', 'shashura-mobile-money-for-camptix' ), 429, true );
		}

		// KYC reveals a subscriber's registered name, so it must not become a lookup service.
		if ( $ctx['lookups'] >= self::MAX_LOOKUPS || ! $this->ip_allowance() ) {
			$this->render_phone( $ctx, __( 'Too many number checks. Please wait a while and try again.', 'shashura-mobile-money-for-camptix' ) );
		}
		$this->save( $ctx, '_shashura_lookups', $ctx['lookups'] + 1 );

		$kyc = $this->api()->kyc( $msisdn );
		if ( is_wp_error( $kyc ) ) {
			$http = Shasmomo_API::http_status( $kyc );
			$camptix->log( 'Shashura KYC failed: ' . $kyc->get_error_message(), $ctx['first'], array( 'code' => Shasmomo_API::error_code( $kyc ), 'http' => $http ), 'payment' );
			$msg = ( $http >= 400 && $http < 500 && 401 !== $http ) || 'shashura_kyc_empty' === $kyc->get_error_code()
				? __( 'This number is not a registered MTN or Airtel Mobile Money account. Check it and try again.', 'shashura-mobile-money-for-camptix' )
				: __( 'We could not reach the mobile money service. Please try again in a moment.', 'shashura-mobile-money-for-camptix' );
			$this->render_phone( $ctx, $msg );
		}
		if ( ! in_array( $kyc['network'], array( 'MTN', 'AIRTEL' ), true ) ) {
			$this->render_phone( $ctx, __( 'Only MTN and Airtel Mobile Money numbers are supported.', 'shashura-mobile-money-for-camptix' ) );
		}

		$pending = array(
			'msisdn'  => $msisdn,
			'name'    => $kyc['name'],
			'network' => $kyc['network'],
			'at'      => time(),
		);
		$this->save( $ctx, '_shashura_pending', $pending );
		$this->render_confirm( $ctx, $pending );
	}

	private function step_confirm( $ctx ) {
		global $camptix;
		$p = $ctx['pending'];

		if ( empty( $p['msisdn'] ) || $p['at'] < time() - self::CONFIRM_TTL ) {
			$this->render_phone( $ctx, __( 'Please enter your number again.', 'shashura-mobile-money-for-camptix' ) );
		}
		if ( $ctx['total'] <= 0 ) {
			$this->render_message( __( 'Nothing to pay', 'shashura-mobile-money-for-camptix' ), __( 'This order has no amount to pay.', 'shashura-mobile-money-for-camptix' ), 400, true );
		}

		// Anything already paid? Then complete instead of charging again.
		$state = $this->settle( $ctx, true, true );
		if ( 'busy' === $state ) {
			wp_safe_redirect( $this->url( 'shashura_wait', $ctx['token'] ) );
			die();
		}
		$lock = $this->lock( $ctx['token'] );
		if ( ! $lock ) {
			wp_safe_redirect( $this->url( 'shashura_wait', $ctx['token'] ) );
			die();
		}

		// Every limit is checked again here, under the lock, so parallel clicks can't slip past.
		$ctx    = $this->load( $ctx['token'] );
		$latest = $ctx['attempts'] ? end( $ctx['attempts'] ) : null;

		if ( 'draft' !== $ctx['status'] ) {
			$this->unlock( $lock );
			$this->guard_draft( $ctx );
		}
		if ( $latest && in_array( $latest['status'], array( 'sent', 'pending' ), true ) && $latest['created'] > time() - self::RETRY_AFTER ) {
			$this->unlock( $lock );
			$this->render_confirm( $ctx, $p, __( 'A payment request is already on your phone. Approve it, or wait two minutes before sending a new one.', 'shashura-mobile-money-for-camptix' ) );
		}
		if ( count( $ctx['attempts'] ) >= self::MAX_ATTEMPTS ) {
			$this->unlock( $lock );
			$this->render_message( __( 'Too many attempts', 'shashura-mobile-money-for-camptix' ), __( 'This order has reached the maximum number of payment attempts. Please start a new ticket purchase.', 'shashura-mobile-money-for-camptix' ), 429, true );
		}

		// Recorded before the push, so a fast webhook or a timed-out push can still be matched.
		$attempt = array(
			'ref'     => 'CTX' . strtoupper( wp_generate_password( 16, false, false ) ),
			'tid'     => '',
			'msisdn'  => $p['msisdn'],
			'name'    => $p['name'],
			'network' => $p['network'],
			'amount'  => $ctx['total'],
			'env'     => $this->options['environment'],
			'status'  => 'sent',
			'created' => time(),
			'checked' => 0,
		);
		$attempts   = $ctx['attempts'];
		$attempts[] = $attempt;
		$this->save( $ctx, '_shashura_attempts', $attempts );
		add_post_meta( $ctx['first'], '_shashura_ref', $attempt['ref'] );

		if ( method_exists( $camptix, 'set_order_session_expiry' ) ) {
			$camptix->set_order_session_expiry( $ctx['token'], time() + self::SESSION_HOLD );
		}

		$res = $this->api()->request_to_pay( $attempt['msisdn'], $attempt['amount'], $attempt['ref'] );

		$ctx      = $this->load( $ctx['token'] );
		$attempts = $ctx['attempts'];
		$i        = count( $attempts ) - 1;

		if ( is_wp_error( $res ) ) {
			$http = Shasmomo_API::http_status( $res );
			$code = Shasmomo_API::error_code( $res );
			$camptix->log( 'Shashura requestToPay failed: ' . $res->get_error_message(), $ctx['first'], array( 'ref' => $attempt['ref'], 'code' => $code, 'http' => $http ), 'payment' );

			if ( $http >= 400 && $http < 500 ) {
				// Definitely not pushed.
				$attempts[ $i ]['status'] = 'rejected';
				$this->save( $ctx, '_shashura_attempts', $attempts );
				$this->unlock( $lock );

				if ( 'GATEWAY_REJECTED' === $code ) {
					$msg = __( 'Your mobile network declined the request. Check your balance and try again.', 'shashura-mobile-money-for-camptix' );
				} elseif ( 'INVALID_INPUT' === $code ) {
					$msg = __( 'The number or amount was not accepted. Please check and try again.', 'shashura-mobile-money-for-camptix' );
				} else {
					$msg = __( 'The payment request could not be sent. Please try again.', 'shashura-mobile-money-for-camptix' );
				}
				$this->render_phone( $ctx, $msg );
			}
			// Timeout or 5xx: the prompt may still have gone out. The waiting page asks Shashura.
		} else {
			$attempts[ $i ]['tid'] = $res['tid'];
			$this->save( $ctx, '_shashura_attempts', $attempts );
		}

		$this->save( $ctx, '_shashura_pending', array() );
		$this->unlock( $lock );
		wp_safe_redirect( $this->url( 'shashura_wait', $ctx['token'] ) );
		die();
	}

	private function client_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip     = $remote;
		$source = $this->options['ip_source'];
		if ( 'cloudflare' === $source && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		} elseif ( 'x_real_ip' === $source && ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
		} elseif ( 'x_forwarded_for' === $source && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			// Each trusted proxy appends the address it received the request from, so the
			// visitor is N entries from the right. Entries further left come from the request
			// itself and can be set to anything, so they are never used.
			$list  = array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) ) ) );
			$index = count( $list ) - max( 1, (int) $this->options['proxy_hops'] );
			$ip    = $index >= 0 ? $list[ $index ] : '';
		}
		// filter_var() below is the real validation: anything that isn't a well-formed IP
		// (a spoofed or malformed header) falls back to REMOTE_ADDR.
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = $remote;
		}
		return (string) apply_filters( 'shasmomo_client_ip', $ip );
	}

	/**
	 * Counts a KYC lookup against the per-IP hourly budget. Site-wide volume only raises an
	 * admin alert: a hard site cap would let an attacker with a few IPs block every buyer.
	 */
	private function ip_allowance() {
		$hour = gmdate( 'YmdH' );
		$key  = 'shasmomo_kyc_' . md5( $this->client_ip() . '|' . $hour );
		$n    = (int) get_transient( $key );
		if ( $n >= (int) apply_filters( 'shasmomo_lookups_per_ip', self::LOOKUPS_PER_IP ) ) {
			return false;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );

		$site_key = 'shasmomo_kyc_site_' . $hour;
		$site     = (int) get_transient( $site_key ) + 1;
		set_transient( $site_key, $site, HOUR_IN_SECONDS );
		if ( $site === (int) apply_filters( 'shasmomo_lookups_alert', self::LOOKUPS_ALERT ) ) {
			wp_mail(
				get_option( 'admin_email' ),
				sprintf( '[%s] Unusual number of mobile money number checks', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				sprintf( "Mobile Money number checks reached %d in the last hour. This can be a busy sale, or someone using checkout to look up names behind phone numbers. Check Tickets → Attendees for many unfinished orders.\n", $site )
			);
		}
		return true;
	}

	/* ==================================================================
	 * Step 3: waiting, polling, return, cancel
	 * ================================================================ */

	private function handle_wait( $ctx ) {
		$this->guard_draft( $ctx );
		if ( ! $ctx['attempts'] ) {
			wp_safe_redirect( $this->url( 'shashura_pay', $ctx['token'] ) );
			die();
		}
		$this->render_wait( $ctx, end( $ctx['attempts'] ) );
	}

	private function noninteractive_supported() {
		$m = new ReflectionMethod( $GLOBALS['camptix'], 'payment_result' );
		return $m->getNumberOfParameters() >= 4;
	}

	private function handle_status( $ctx ) {
		$nonce = isset( $_GET['_shasmomo_nonce'] ) ? sanitize_key( wp_unslash( $_GET['_shasmomo_nonce'] ) ) : '';
		if ( ! $ctx || ! wp_verify_nonce( $nonce, 'shasmomo_' . $ctx['token'] ) ) {
			wp_send_json( array( 'state' => 'error' ), 403 );
		}

		if ( in_array( $ctx['status'], array( 'publish', 'pending' ), true ) ) {
			$state = 'paid';
		} else {
			$open = 'draft' === $ctx['status'];
			$closed_recoverable = in_array( $ctx['status'], array( 'cancel', 'failed', 'timeout' ), true ) && $ctx['attempts'] && $this->can_recover( $ctx );
			if ( $this->noninteractive_supported() && ( $open || $closed_recoverable ) ) {
				$state = $this->settle( $ctx, false );
			} elseif ( $open ) {
				// CampTix 1.x: report what Shashura says; the return page records it.
				$rec   = $this->reconcile( $ctx );
				$state = $rec['paid'] ? 'paid' : ( $rec['latest'] && in_array( $rec['latest']['status'], array( 'failed', 'rejected', 'mismatch', 'lost' ), true ) ? 'failed' : 'pending' );
			} else {
				$state = 'closed';
			}
			if ( ! $open && 'paid' !== $state ) {
				$state = 'closed';
			}
			if ( 'busy' === $state || 'none' === $state ) {
				$state = 'pending';
			}
		}

		$out = array( 'state' => $state );
		if ( 'paid' === $state ) {
			$ctx  = $this->load( $ctx['token'] );
			$paid = array_values( array_filter( $ctx['attempts'], function ( $a ) {
				return 'paid' === $a['status'];
			} ) );
			if ( $paid ) {
				$out['details'] = array(
					'name'      => $this->display_name( $paid[0]['name'] ),
					'phone'     => Shasmomo_Phone::mask( $paid[0]['msisdn'] ),
					'network'   => 'AIRTEL' === $paid[0]['network'] ? 'Airtel' : 'MTN',
					'amount'    => 'UGX ' . number_format_i18n( $paid[0]['paid_amount'] ),
					'reference' => $paid[0]['tid'] ? $paid[0]['tid'] : $paid[0]['ref'],
				);
			}
			$out['next'] = $this->url( 'payment_return', $ctx['token'] );
		} elseif ( 'failed' === $state ) {
			$out['next'] = count( $ctx['attempts'] ) < self::MAX_ATTEMPTS
				? $this->url( 'shashura_pay', $ctx['token'], array( 'notice' => 'declined' ) )
				: $this->url( 'payment_return', $ctx['token'] );
		} elseif ( 'closed' === $state ) {
			$out['next'] = $this->url( 'shashura_pay', $ctx['token'] );
		}
		wp_send_json( $out );
	}

	private function handle_return( $ctx ) {
		$this->guard_draft( $ctx ); // Paid orders go to their tickets; closed ones are re-checked first.

		// Paid: CampTix records it and redirects. Every prompt definitely failed and none left: fail the order.
		$state = $this->settle( $ctx, true, true, function ( $ctx, $rec, $state ) {
			if ( 'failed' === $state && count( $rec['attempts'] ) >= self::MAX_ATTEMPTS && $this->all_failed( $rec ) ) {
				$this->report( $ctx['token'], CampTix_Plugin::PAYMENT_STATUS_FAILED, array( 'error_code' => 'shashura_attempts_exhausted' ), true );
				die();
			}
		} );

		if ( 'failed' === $state && count( $ctx['attempts'] ) >= self::MAX_ATTEMPTS ) {
			$this->render_message(
				__( 'Payment not confirmed yet', 'shashura-mobile-money-for-camptix' ),
				__( 'We could not confirm your payment yet. If money left your account, your tickets will be emailed as soon as it is confirmed. Otherwise please start a new ticket purchase.', 'shashura-mobile-money-for-camptix' ),
				200,
				true
			);
		}
		if ( 'failed' === $state ) {
			wp_safe_redirect( $this->url( 'shashura_pay', $ctx['token'], array( 'notice' => 'declined' ) ) );
			die();
		}
		if ( 'none' === $state ) {
			wp_safe_redirect( $this->url( 'shashura_pay', $ctx['token'] ) );
			die();
		}
		wp_safe_redirect( $this->url( 'shashura_wait', $ctx['token'] ) );
		die();
	}

	private function handle_cancel( $ctx ) {
		$nonce = isset( $_GET['_shasmomo_nonce'] ) ? sanitize_key( wp_unslash( $_GET['_shasmomo_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'shasmomo_' . $ctx['token'] ) ) {
			$this->render_message( __( 'Link expired', 'shashura-mobile-money-for-camptix' ), __( 'This cancel link has expired. Go back and try again.', 'shashura-mobile-money-for-camptix' ), 403, true );
		}
		$this->guard_draft( $ctx );

		// Decided under the order lock: if any prompt was approved the order completes instead,
		// and a webhook recording a payment right now can't be overwritten by this cancel.
		$state = $this->settle( $ctx, true, true, function ( $ctx ) {
			if ( 'draft' === $ctx['status'] ) {
				$this->report( $ctx['token'], CampTix_Plugin::PAYMENT_STATUS_CANCELLED, array(), true );
				die();
			}
		} );

		if ( 'busy' === $state ) {
			// Something is recording this order right now. Let the waiting page show the outcome.
			wp_safe_redirect( $this->url( 'shashura_wait', $ctx['token'] ) );
			die();
		}
		$this->guard_draft( $this->load( $ctx['token'] ) );
		$this->redirect_to_tickets( $ctx );
	}

	/* ==================================================================
	 * Webhook (REST): POST /wp-json/camptix-shashura/v1/webhook
	 * ================================================================ */

	public function register_rest_routes() {
		register_rest_route( 'camptix-shashura/v1', '/webhook', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_webhook' ),
			'permission_callback' => '__return_true', // Authenticated by the HMAC signature below.
		) );
	}

	public function rest_webhook( WP_REST_Request $request ) {
		global $camptix;
		$raw = (string) $request->get_body();

		if ( strlen( $raw ) > self::WEBHOOK_MAX_BYTES ) {
			return new WP_REST_Response( array( 'ok' => false ), 413 );
		}
		if ( '' === $this->options['webhook_secret'] ) {
			$camptix->log( 'Shashura webhook rejected: no webhook secret configured.', 0, null, 'payment' );
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'not_configured' ), 401 );
		}
		if ( ! $this->api()->verify_signature( $raw, (string) $request->get_header( 'x_shashura_signature' ) ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'bad_signature' ), 401 );
		}

		$event = json_decode( $raw, true );
		if ( ! is_array( $event ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'bad_json' ), 400 );
		}

		// Signed but not for us: acknowledge so Shashura stops retrying, change nothing.
		if ( isset( $event['type'] ) && 'COLLECTION' !== $event['type'] ) {
			return new WP_REST_Response( array( 'ok' => true, 'ignored' => true ), 200 );
		}

		$ref   = isset( $event['external_ref'] ) && is_string( $event['external_ref'] ) ? $event['external_ref'] : '';
		$token = $this->find_token_by_reference( $ref );
		$ctx   = $token ? $this->load( $token ) : null;
		if ( ! $ctx ) {
			return new WP_REST_Response( array( 'ok' => true, 'ignored' => true ), 200 );
		}

		// The event must come from the environment the prompt was sent in.
		$sent_in = $this->options['environment'];
		foreach ( $ctx['attempts'] as $a ) {
			if ( $a['ref'] === $ref && ! empty( $a['env'] ) ) {
				$sent_in = $a['env'];
			}
		}
		if ( isset( $event['environment'] ) && $event['environment'] !== $sent_in ) {
			return new WP_REST_Response( array( 'ok' => true, 'ignored' => true ), 200 );
		}
		if ( $sent_in !== $this->options['environment'] ) {
			// Can't verify it with the current credentials. Ask Shashura to retry later rather than drop it.
			$camptix->log( 'Webhook for a prompt sent in ' . $sent_in . ' while the plugin is set to ' . $this->options['environment'] . '; asking Shashura to retry.', $ctx['first'], array( 'ref' => $ref ), 'payment' );
			return new WP_REST_Response( array( 'ok' => false, 'retry' => true ), 503 );
		}
		if ( 'refund' === $ctx['status'] ) {
			return new WP_REST_Response( array( 'ok' => true, 'ignored' => true ), 200 );
		}
		if ( ! $this->can_recover( $ctx ) ) {
			$camptix->log( 'Payment webhook for a timed-out order; CampTix 1.x cannot reopen it. Check the payment manually.', $ctx['first'], array( 'ref' => $ref ), 'payment' );
			return new WP_REST_Response( array( 'ok' => true, 'ignored' => true ), 200 );
		}

		// The event body is only a hint. settle() asks /enquiry before anything changes.
		$state = $this->settle( $ctx, false, true );
		if ( 'busy' === $state ) {
			return new WP_REST_Response( array( 'ok' => false, 'retry' => true ), 503 ); // Shashura retries later.
		}
		return new WP_REST_Response( array( 'ok' => true, 'state' => $state ), 200 );
	}

	/* ==================================================================
	 * CampTix timeout sweep: ask Shashura before an order is timed out
	 * ================================================================ */

	public function reset_sweep() {
		$this->swept = array();
	}

	public function pre_attendee_timeout( $attendee_id ) {
		global $camptix;
		if ( 'draft' !== get_post_status( $attendee_id ) || $this->id !== get_post_meta( $attendee_id, 'tix_payment_method', true ) ) {
			return;
		}
		$token = (string) get_post_meta( $attendee_id, 'tix_payment_token', true );
		if ( isset( $this->swept[ $token ] ) ) {
			return;
		}
		$this->swept[ $token ] = true;

		$ctx = $this->load( $token );
		if ( ! $ctx || ! $ctx['attempts'] ) {
			return;
		}

		$state = $this->settle( $ctx, false, true );
		if ( 'paid' === $state ) {
			return;
		}

		// A prompt still in flight (or Shashura unreachable): hold the seats a little longer, up to a day.
		$latest = end( $ctx['attempts'] );
		if ( in_array( $state, array( 'pending', 'busy' ), true ) && $latest['created'] > time() - DAY_IN_SECONDS && method_exists( $camptix, 'set_order_session_expiry' ) ) {
			$camptix->set_order_session_expiry( $token, time() + 10 * MINUTE_IN_SECONDS );
			$camptix->log( 'Mobile money payment still pending at timeout; holding the order.', $attendee_id, null, 'payment' );
		}
	}

	/* ==================================================================
	 * Privacy: WordPress personal data export / erase (Tools → Export/Erase Personal Data)
	 * ================================================================ */

	public function register_exporter( $exporters ) {
		$exporters['shashura-mobile-money-for-camptix'] = array( 'exporter_friendly_name' => 'CampTix Mobile Money', 'callback' => array( $this, 'privacy_export' ) );
		return $exporters;
	}

	public function register_eraser( $erasers ) {
		$erasers['shashura-mobile-money-for-camptix'] = array( 'eraser_friendly_name' => 'CampTix Mobile Money', 'callback' => array( $this, 'privacy_erase' ) );
		return $erasers;
	}

	private function attendees_by_email( $email, $page ) {
		return get_posts( array(
			'post_type'      => 'tix_attendee',
			'post_status'    => self::STATUSES,
			'posts_per_page' => 50,
			'paged'          => max( 1, (int) $page ),
			'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- WP core's Privacy Export/Erase tooling only identifies a person by email, and CampTix stores it as post meta; this runs only on an admin-triggered privacy request, never in the buyer-facing hot path.
			'meta_query'     => array(
				array( 'key' => 'tix_email', 'value' => $email ),
				array( 'key' => '_shashura_attempts', 'compare' => 'EXISTS' ),
			),
		) );
	}

	public function privacy_export( $email, $page = 1 ) {
		$items = array();
		$ids   = $this->attendees_by_email( $email, $page );
		foreach ( $ids as $id ) {
			$attempts = get_post_meta( $id, '_shashura_attempts', true );
			foreach ( is_array( $attempts ) ? $attempts : array() as $a ) {
				$items[] = array(
					'group_id'    => 'shashura-mobile-money-for-camptix',
					'group_label' => 'Mobile Money payments',
					'item_id'     => 'shashura-' . $id . '-' . $a['ref'],
					'data'        => array(
						array( 'name' => 'Reference', 'value' => $a['ref'] ),
						array( 'name' => 'Date', 'value' => gmdate( 'Y-m-d H:i', (int) $a['created'] ) . ' UTC' ),
						array( 'name' => 'Mobile number', 'value' => $a['msisdn'] ),
						array( 'name' => 'Registered name', 'value' => $a['name'] ),
						array( 'name' => 'Network', 'value' => $a['network'] ),
						array( 'name' => 'Amount (UGX)', 'value' => (string) $a['amount'] ),
						array( 'name' => 'Status', 'value' => $a['status'] ),
					),
				);
			}
		}
		return array( 'data' => $items, 'done' => count( $ids ) < 50 );
	}

	/** Removes payer names and numbers; keeps references, amounts and statuses for the accounts. */
	public function privacy_erase( $email, $page = 1 ) {
		$removed = false;
		$ids     = $this->attendees_by_email( $email, $page );
		foreach ( $ids as $id ) {
			$attempts = get_post_meta( $id, '_shashura_attempts', true );
			foreach ( is_array( $attempts ) ? $attempts : array() as $i => $a ) {
				$attempts[ $i ]['msisdn'] = '256000000000';
				$attempts[ $i ]['name']   = '';
			}
			update_post_meta( $id, '_shashura_attempts', wp_slash( $attempts ) );
			delete_post_meta( $id, '_shashura_pending' );

			$txn = get_post_meta( $id, 'tix_transaction_details', true );
			if ( is_array( $txn ) && isset( $txn['raw']['payer_name'] ) ) {
				$txn['raw']['payer_name']   = '';
				$txn['raw']['payer_number'] = '';
				update_post_meta( $id, 'tix_transaction_details', wp_slash( $txn ) );
			}
			$removed = true;
		}
		return array( 'items_removed' => $removed, 'items_retained' => false, 'messages' => array(), 'done' => count( $ids ) < 50 );
	}

	/* ==================================================================
	 * Views
	 * ================================================================ */

	private function display_name( $name ) {
		if ( 'partial' !== $this->options['name_display'] ) {
			return $name;
		}
		$parts = preg_split( '/\s+/', trim( $name ) );
		$first = array_shift( $parts );
		$inits = array_map( function ( $p ) {
			return mb_substr( $p, 0, 1 ) . '.';
		}, $parts );
		return trim( $first . ' ' . implode( ' ', $inits ) );
	}

	private function amount_text( $ctx ) {
		return 'UGX ' . number_format_i18n( $ctx['total'] );
	}

	private function nonce_field( $ctx ) {
		printf( '<input type="hidden" name="_shasmomo_nonce" value="%s">', esc_attr( wp_create_nonce( 'shasmomo_' . $ctx['token'] ) ) );
	}

	private function cancel_link( $ctx, $label = null ) {
		$url = $this->url( 'payment_cancel', $ctx['token'], array( '_shasmomo_nonce' => wp_create_nonce( 'shasmomo_' . $ctx['token'] ) ) );
		printf( '<a class="link" href="%s">%s</a>', esc_url( $url ), esc_html( $label ? $label : __( 'Cancel order', 'shashura-mobile-money-for-camptix' ) ) );
	}

	private function render_phone( $ctx, $error = '', $notice = '' ) {
		$prefill = isset( $ctx['pending']['msisdn'] ) ? '0' . substr( $ctx['pending']['msisdn'], 3 ) : '';
		ob_start();
		?>
		<h1><?php esc_html_e( 'Pay with Mobile Money', 'shashura-mobile-money-for-camptix' ); ?></h1>
		<p class="muted"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
		<p class="total"><?php echo esc_html( $this->amount_text( $ctx ) ); ?></p>
		<?php if ( $notice ) : ?><div class="note"><?php echo esc_html( $notice ); ?></div><?php endif; ?>
		<form method="post" action="<?php echo esc_url( $this->url( 'shashura_pay', $ctx['token'] ) ); ?>">
			<?php $this->nonce_field( $ctx ); ?>
			<input type="hidden" name="step" value="lookup">
			<label for="phone"><?php esc_html_e( 'MTN or Airtel number', 'shashura-mobile-money-for-camptix' ); ?></label>
			<input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" maxlength="20" placeholder="0772 123 456" value="<?php echo esc_attr( $prefill ); ?>" required>
			<?php if ( $error ) : ?><div class="err" role="alert"><?php echo esc_html( $error ); ?></div><?php endif; ?>
			<button type="submit"><?php esc_html_e( 'Verify number', 'shashura-mobile-money-for-camptix' ); ?></button>
		</form>
		<p class="fine"><?php esc_html_e( 'We check the number with your mobile network and show you the registered name before anything is charged.', 'shashura-mobile-money-for-camptix' ); ?></p>
		<?php $this->cancel_link( $ctx ); ?>
		<?php
		$this->render_page( __( 'Pay with Mobile Money', 'shashura-mobile-money-for-camptix' ), ob_get_clean(), $error ? 422 : 200 );
	}

	private function render_confirm( $ctx, $p, $error = '' ) {
		ob_start();
		?>
		<h1><?php esc_html_e( 'Confirm payment details', 'shashura-mobile-money-for-camptix' ); ?></h1>
		<p class="muted"><?php esc_html_e( 'Check that this is the account you want to pay from.', 'shashura-mobile-money-for-camptix' ); ?></p>
		<dl class="det">
			<?php if ( '' !== $p['name'] ) : ?>
				<dt><?php esc_html_e( 'Account name', 'shashura-mobile-money-for-camptix' ); ?></dt><dd><?php echo esc_html( $this->display_name( $p['name'] ) ); ?></dd>
			<?php endif; ?>
			<dt><?php esc_html_e( 'Mobile number', 'shashura-mobile-money-for-camptix' ); ?></dt><dd><?php echo esc_html( Shasmomo_Phone::pretty( $p['msisdn'] ) ); ?></dd>
			<dt><?php esc_html_e( 'Network', 'shashura-mobile-money-for-camptix' ); ?></dt><dd><?php echo esc_html( 'AIRTEL' === $p['network'] ? 'Airtel Money' : 'MTN Mobile Money' ); ?></dd>
			<dt><?php esc_html_e( 'Amount', 'shashura-mobile-money-for-camptix' ); ?></dt><dd class="amt"><?php echo esc_html( $this->amount_text( $ctx ) ); ?></dd>
		</dl>
		<?php if ( $error ) : ?><div class="err" role="alert"><?php echo esc_html( $error ); ?></div><?php endif; ?>
		<form method="post" action="<?php echo esc_url( $this->url( 'shashura_pay', $ctx['token'] ) ); ?>">
			<?php $this->nonce_field( $ctx ); ?>
			<input type="hidden" name="step" value="confirm">
			<button type="submit"><?php esc_html_e( 'Confirm and send payment request', 'shashura-mobile-money-for-camptix' ); ?></button>
		</form>
		<a class="link" href="<?php echo esc_url( $this->url( 'shashura_pay', $ctx['token'] ) ); ?>"><?php esc_html_e( 'Use a different number', 'shashura-mobile-money-for-camptix' ); ?></a>
		<?php
		$this->render_page( __( 'Confirm payment', 'shashura-mobile-money-for-camptix' ), ob_get_clean() );
	}

	private function render_wait( $ctx, $a ) {
		$status_url = $this->url( 'shashura_status', $ctx['token'], array( '_shasmomo_nonce' => wp_create_nonce( 'shasmomo_' . $ctx['token'] ) ) );
		$return_url = $this->url( 'payment_return', $ctx['token'] );
		$retry_url  = $this->url( 'shashura_pay', $ctx['token'] );
		ob_start();
		?>
		<div id="wait">
			<h1><?php esc_html_e( 'Approve on your phone', 'shashura-mobile-money-for-camptix' ); ?></h1>
			<p class="muted"><?php
				printf(
					/* translators: 1: network, 2: masked number, 3: amount */
					esc_html__( 'We sent a %1$s request to %2$s for %3$s. Enter your PIN on your phone to approve it.', 'shashura-mobile-money-for-camptix' ),
					esc_html( 'AIRTEL' === $a['network'] ? 'Airtel Money' : 'MTN Mobile Money' ),
					'<strong>' . esc_html( Shasmomo_Phone::mask( $a['msisdn'] ) ) . '</strong>',
					'<strong>' . esc_html( 'UGX ' . number_format_i18n( $a['amount'] ) ) . '</strong>'
				);
			?></p>
			<div class="spin" aria-hidden="true"></div>
			<p class="muted center" id="msg" aria-live="polite"><?php esc_html_e( 'Waiting for confirmation…', 'shashura-mobile-money-for-camptix' ); ?></p>
			<noscript><p class="center"><a class="btn" href="<?php echo esc_url( $return_url ); ?>"><?php esc_html_e( 'I have approved it', 'shashura-mobile-money-for-camptix' ); ?></a></p></noscript>
			<a class="link" id="retry" hidden href="<?php echo esc_url( $retry_url ); ?>"><?php esc_html_e( 'No prompt? Send a new request', 'shashura-mobile-money-for-camptix' ); ?></a>
			<?php $this->cancel_link( $ctx ); ?>
		</div>
		<div id="done" hidden>
			<div class="ok" aria-hidden="true">&#10003;</div>
			<h1 class="center"><?php esc_html_e( 'Payment received', 'shashura-mobile-money-for-camptix' ); ?></h1>
			<p class="muted center"><?php esc_html_e( 'Thank you. Taking you to your tickets…', 'shashura-mobile-money-for-camptix' ); ?></p>
			<dl class="det" id="det"></dl>
		</div>
		<?php
		// Data for assets/js/checkout.js's poller — read by render_page() via
		// $this->wait_script_data, since the script itself is enqueued there
		// (after this method's output buffer is already captured).
		$this->wait_script_data = array(
			'statusUrl' => $status_url,
			'labels'    => array(
				'name'      => __( 'Paid by', 'shashura-mobile-money-for-camptix' ),
				'phone'     => __( 'Mobile number', 'shashura-mobile-money-for-camptix' ),
				'network'   => __( 'Network', 'shashura-mobile-money-for-camptix' ),
				'amount'    => __( 'Amount', 'shashura-mobile-money-for-camptix' ),
				'reference' => __( 'Reference', 'shashura-mobile-money-for-camptix' ),
				'failed'    => __( 'The request was declined or expired.', 'shashura-mobile-money-for-camptix' ),
				'slow'      => __( 'Still waiting. If you already approved, keep this page open a little longer.', 'shashura-mobile-money-for-camptix' ),
			),
		);
		$this->render_page( __( 'Approve payment', 'shashura-mobile-money-for-camptix' ), ob_get_clean() );
	}

	private function render_message( $title, $text, $status = 200, $home = false ) {
		global $camptix;
		ob_start();
		?>
		<h1><?php echo esc_html( $title ); ?></h1>
		<p class="muted"><?php echo esc_html( $text ); ?></p>
		<?php if ( $home ) : ?><a class="btn" href="<?php echo esc_url( $camptix->get_tickets_url() ); ?>"><?php esc_html_e( 'Back to tickets', 'shashura-mobile-money-for-camptix' ); ?></a><?php endif; ?>
		<?php
		$this->render_page( $title, ob_get_clean(), $status );
	}

	private function render_page( $title, $body, $status = 200 ) {
		status_header( $status );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		$accent = sanitize_hex_color( (string) apply_filters( 'shasmomo_accent_color', '#1d4ed8' ) );
		$accent = $accent ? $accent : '#1d4ed8';

		// This page is a standalone HTML document (no get_header()/wp_head()), so
		// assets are enqueued normally — for proper URLs, versioning and dependency
		// handling — then printed directly by handle rather than via wp_head()/
		// wp_footer(), which would also pull in every other plugin's and the
		// theme's own head/footer output onto this minimal, noindex payment page.
		wp_enqueue_style( 'shasmomo-checkout', SHASMOMO_URL . 'assets/css/checkout.css', array(), SHASMOMO_VERSION );
		wp_add_inline_style( 'shasmomo-checkout', ':root{--a:' . esc_attr( $accent ) . '}' );

		wp_enqueue_script( 'shasmomo-checkout', SHASMOMO_URL . 'assets/js/checkout.js', array(), SHASMOMO_VERSION, true );
		if ( ! empty( $this->wait_script_data ) ) {
			wp_localize_script( 'shasmomo-checkout', 'shasmomoWait', $this->wait_script_data );
		}
		?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title . ' – ' . get_bloginfo( 'name' ) ); ?></title>
<?php wp_print_styles( array( 'shasmomo-checkout' ) ); ?>
</head>
<body><main class="card"><?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from escaped parts above. ?></main>
<?php if ( ! empty( $this->options['show_branding'] ) ) : ?>
<p class="poweredby"><?php esc_html_e( 'Powered by', 'shashura-mobile-money-for-camptix' ); ?> <a href="https://shashura.com" target="_blank" rel="noopener noreferrer">Shashura</a></p>
<?php endif; ?>
<?php wp_print_scripts( array( 'shasmomo-checkout' ) ); ?>
</body>
</html>
		<?php
		die();
	}
}
