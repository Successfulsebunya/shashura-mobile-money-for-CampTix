<?php
/**
 * Shashura MoMo API client.
 *
 * Base URL https://shashura.com/api/v1. The X-Target-Environment header selects sandbox
 * or production on the same base URL.
 *
 *  POST /auth            {client_id, client_secret}       -> data.access_token (1 hour)
 *  GET  /kyc/{msisdn}                                     -> data.name, data.network, data.msisdn
 *  POST /requestToPay    {msisdn, amount, reference}      -> 202, data.tid
 *  GET  /enquiry/{ref}                                    -> data.state PENDING | TS | TF, data.amount, data.external_ref
 *  Webhook  POST {event, environment, tid, external_ref, type, status, amount, ...}
 *           X-Shashura-Signature = hex HMAC-SHA256(raw body, app webhook secret)
 *
 * @package CampTix_Shashura
 */

defined( 'ABSPATH' ) || exit;

class Shasmomo_API {

	const BASE_URL = 'https://shashura.com/api/v1';

	/** Enquiry `state` -> normalized. Anything else is "pending", never "paid". */
	const STATES = array(
		'TS'      => 'paid',
		'TF'      => 'failed',
		'PENDING' => 'pending',
	);

	private $base_url;
	private $client_id;
	private $client_secret;
	private $environment;
	private $webhook_secret;

	public function __construct( $client_id, $client_secret, $environment, $webhook_secret = '', $base_url_override = '' ) {
		$base = '' !== trim( (string) $base_url_override ) ? trim( (string) $base_url_override ) : self::BASE_URL;
		$base = (string) apply_filters( 'shasmomo_base_url', $base );
		// Credentials and bearer tokens must never travel over plain HTTP, except to a local/loopback
		// host (used to point the plugin at a dev gateway instance running on the same machine).
		if ( 0 !== strpos( $base, 'https://' ) && ! self::is_local_http( $base ) && ! apply_filters( 'shasmomo_allow_insecure_base_url', false ) ) {
			$base = self::BASE_URL;
		}

		$this->base_url       = untrailingslashit( $base );
		$this->client_id      = (string) $client_id;
		$this->client_secret  = (string) $client_secret;
		$this->environment    = in_array( $environment, array( 'sandbox', 'production' ), true ) ? $environment : 'production';
		$this->webhook_secret = (string) $webhook_secret;
	}

	private static function is_local_http( $url ) {
		if ( 0 !== strpos( $url, 'http://' ) ) {
			return false;
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) || (bool) preg_match( '/\.local$/', $host );
	}

	/* ------------------------------------------------------------------
	 * Endpoints
	 * ---------------------------------------------------------------- */

	/** Check the credentials by fetching a fresh token. @return true|WP_Error */
	public function test_credentials() {
		$token = $this->token( true );
		return is_wp_error( $token ) ? $token : true;
	}

	/**
	 * @param string $msisdn Digits with country code, e.g. 256770000000.
	 * @return array|WP_Error { name, network, msisdn }
	 */
	public function kyc( $msisdn ) {
		$res = $this->authed( 'GET', '/kyc/' . rawurlencode( $msisdn ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data    = $this->data( $res );
		$name    = isset( $data['name'] ) && is_string( $data['name'] ) ? trim( $data['name'] ) : '';
		$network = isset( $data['network'] ) && is_string( $data['network'] ) ? strtoupper( trim( $data['network'] ) ) : '';

		if ( '' === $name && '' === $network ) {
			return new WP_Error( 'shashura_kyc_empty', 'KYC returned no subscriber.' );
		}
		return array(
			'name'    => sanitize_text_field( $name ),
			'network' => sanitize_text_field( $network ),
			'msisdn'  => isset( $data['msisdn'] ) ? preg_replace( '/\D+/', '', (string) $data['msisdn'] ) : $msisdn,
		);
	}

	/**
	 * Push a PIN prompt to the payer's phone.
	 *
	 * @return array|WP_Error { tid }
	 */
	public function request_to_pay( $msisdn, $amount, $reference ) {
		$res = $this->authed( 'POST', '/requestToPay', array(
			'msisdn'    => (string) $msisdn,
			'amount'    => (int) $amount,
			'reference' => (string) $reference,
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = $this->data( $res );
		return array( 'tid' => isset( $data['tid'] ) ? sanitize_text_field( (string) $data['tid'] ) : '' );
	}

	/**
	 * @return array|WP_Error { state: paid|failed|pending, raw_state, amount: int|null, external_ref, tid, network_ref }
	 */
	public function enquiry( $reference ) {
		$res = $this->authed( 'GET', '/enquiry/' . rawurlencode( $reference ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = $this->data( $res );
		$raw  = isset( $data['state'] ) && is_scalar( $data['state'] ) ? strtoupper( trim( (string) $data['state'] ) ) : '';

		return array(
			'state'        => isset( self::STATES[ $raw ] ) ? self::STATES[ $raw ] : 'pending',
			'raw_state'    => $raw,
			'amount'       => isset( $data['amount'] ) && is_numeric( $data['amount'] ) ? (int) round( (float) $data['amount'] ) : null,
			'external_ref' => isset( $data['external_ref'] ) ? (string) $data['external_ref'] : '',
			'tid'          => isset( $data['tid'] ) ? sanitize_text_field( (string) $data['tid'] ) : '',
			'network_ref'  => isset( $data['network_ref'] ) ? sanitize_text_field( (string) $data['network_ref'] ) : '',
		);
	}

	/** Constant-time check of X-Shashura-Signature against the raw request body. */
	public function verify_signature( $raw_body, $signature ) {
		if ( '' === $this->webhook_secret || ! is_string( $signature ) || '' === $signature ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', (string) $raw_body, $this->webhook_secret );
		return hash_equals( $expected, strtolower( trim( $signature ) ) );
	}

	/** Shashura error code (e.g. GATEWAY_REJECTED) carried on a WP_Error, or ''. */
	public static function error_code( $error ) {
		$d = is_wp_error( $error ) ? $error->get_error_data() : null;
		return is_array( $d ) && ! empty( $d['api_code'] ) ? (string) $d['api_code'] : '';
	}

	/** HTTP status carried on a WP_Error (0 for transport errors). */
	public static function http_status( $error ) {
		$d = is_wp_error( $error ) ? $error->get_error_data() : null;
		return is_array( $d ) && ! empty( $d['http'] ) ? (int) $d['http'] : 0;
	}

	/* ------------------------------------------------------------------
	 * Auth and transport
	 * ---------------------------------------------------------------- */

	private function token_key() {
		// Tied to the secret and environment, so changing either invalidates the cached token.
		return 'shasmomo_tok_' . md5( $this->client_id . '|' . $this->client_secret . '|' . $this->environment );
	}

	private function token( $force = false ) {
		if ( '' === $this->client_id || '' === $this->client_secret ) {
			return new WP_Error( 'shashura_not_configured', 'Shashura credentials are not set.' );
		}
		if ( ! $force ) {
			$cached = get_transient( $this->token_key() );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		$res = $this->send( 'POST', '/auth', array(
			'client_id'     => $this->client_id,
			'client_secret' => $this->client_secret,
		), array(
			'X-CLIENT-ID'          => $this->client_id,
			'X-Target-Environment' => $this->environment,
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$data  = $this->data( $res );
		$token = isset( $data['access_token'] ) && is_string( $data['access_token'] ) ? $data['access_token'] : '';
		if ( '' === $token ) {
			return new WP_Error( 'shashura_auth', 'Authentication returned no access token.' );
		}
		$ttl = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : HOUR_IN_SECONDS;
		set_transient( $this->token_key(), $token, max( 60, min( $ttl, HOUR_IN_SECONDS ) - 120 ) );
		return $token;
	}

	private function authed( $method, $path, $body = null ) {
		$token = $this->token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$res = $this->send( $method, $path, $body, $this->headers( $token ) );

		// Token expired or revoked early: refresh once and retry.
		if ( is_wp_error( $res ) && ( 401 === self::http_status( $res ) || in_array( self::error_code( $res ), array( 'EXPIRED_TOKEN', 'UNAUTHORIZED' ), true ) ) ) {
			delete_transient( $this->token_key() );
			$token = $this->token( true );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$res = $this->send( $method, $path, $body, $this->headers( $token ) );
		}
		return $res;
	}

	private function headers( $token ) {
		return array(
			'Authorization'        => 'Bearer ' . $token,
			'X-CLIENT-ID'          => $this->client_id,
			'X-Target-Environment' => $this->environment,
			'X-COUNTRY'            => 'UG',
		);
	}

	private function send( $method, $path, $body, $headers ) {
		$args = array(
			'method'      => $method,
			'timeout'     => (int) apply_filters( 'shasmomo_http_timeout', 25 ),
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array_merge( array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			), $headers ),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->base_url . $path, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'shashura_transport', $response->get_error_message(), array( 'http' => 0, 'api_code' => '' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$api_code = is_array( $data ) && isset( $data['code'] ) && is_string( $data['code'] ) ? $data['code'] : '';
			$message  = is_array( $data ) && isset( $data['message'] ) && is_string( $data['message'] ) ? $data['message'] : 'HTTP ' . $code;
			return new WP_Error( 'shashura_http', $message, array( 'http' => $code, 'api_code' => $api_code ) );
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'shashura_bad_json', 'Unreadable response from Shashura.', array( 'http' => $code, 'api_code' => '' ) );
		}
		return $data;
	}

	private function data( $res ) {
		return isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array();
	}
}
