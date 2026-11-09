<?php
/** Run with `wp eval-file .../tests/integration/endpoint.php` on a disposable multisite. */
if ( ! defined( 'ABSPATH' ) || ! is_multisite() ) {
	throw new RuntimeException( 'Use a disposable WordPress multisite installation.' );
}
require_once dirname( __DIR__, 2 ) . '/includes/class-shashura-api.php';
function shasmomo_integration_check( $ok, $message ) {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
	echo "PASS $message\n";
}

// WordPress URL validation and Requests parsing run normally. Only the final
// socket transport is replaced, after validation. No pre_http_request shortcut.
class Shasmomo_Test_Transport {
	public static $calls = array();
	public static $redirect = false;
	public function request( $url, $headers = array(), $data = array(), $options = array() ) {
		self::$calls[] = array( $url, $options );
		if ( self::$redirect ) {
			return "HTTP/1.1 302 Found\r\nLocation: https://127.0.0.1/auth\r\nContent-Length: 0\r\n\r\n";
		}
		$body = wp_json_encode( array( 'data' => array( 'access_token' => 'fixture-' . count( self::$calls ) ) ) );
		return "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen( $body ) . "\r\n\r\n" . $body;
	}
}
$transport = function ( &$url, &$headers, &$data, &$type, &$options ) {
	$options['transport'] = new Shasmomo_Test_Transport();
};
add_action( 'requests-requests.before_request', $transport, 10, 5 );
$endpoint = 'https://public.shasmomo.test/api';
$filter = function () use ( &$endpoint ) { return $endpoint; };
add_filter( 'shasmomo_base_url', $filter );

if ( getenv( 'SHASMOMO_DNS_PRIVATE' ) ) {
	$api = new Shasmomo_API( 'invented-client', 'invented-secret', 'sandbox' );
	shasmomo_integration_check( is_wp_error( $api->test_credentials() ) && ! Shasmomo_Test_Transport::$calls, 'a formerly public hostname now resolving private is rejected before dispatch' );
	exit;
}

try {
	foreach ( array( 'https://127.0.0.1', 'https://10.0.0.1', 'https://172.16.0.1', 'https://192.168.0.1', 'https://private.shasmomo.test', 'https://[::1]', 'https://user:pass@public.shasmomo.test', 'https://public.shasmomo.test:8080', 'http://public.shasmomo.test', 'https://public.shasmomo.test?leak=1', 'https://public.shasmomo.test#fragment' ) as $bad ) {
		$endpoint = $bad;
		$before = count( Shasmomo_Test_Transport::$calls );
		$api = new Shasmomo_API( 'invented-client', 'invented-secret', 'sandbox' );
		shasmomo_integration_check( is_wp_error( $api->test_credentials() ) && $before === count( Shasmomo_Test_Transport::$calls ), 'unsafe destination never reaches transport: ' . $bad );
	}
	$endpoint = 'https://public.shasmomo.test/api';
	$api = new Shasmomo_API( 'invented-client', 'invented-secret', 'sandbox', '', 'https://127.0.0.1' );
	shasmomo_integration_check( true === $api->test_credentials(), 'saved override ignored; allowed endpoint passes real WordPress validation' );
	$call = end( Shasmomo_Test_Transport::$calls );
	shasmomo_integration_check( $endpoint . '/auth' === $call[0] && ! $call[1]['follow_redirects'] && false !== $call[1]['verify'], 'Requests receives no-redirect and TLS-verification options' );
	Shasmomo_Test_Transport::$redirect = true;
	$before = count( Shasmomo_Test_Transport::$calls );
	shasmomo_integration_check( is_wp_error( $api->test_credentials() ) && count( Shasmomo_Test_Transport::$calls ) === $before + 1, '302 response is rejected without dispatching its private destination' );
	Shasmomo_Test_Transport::$redirect = false;
	$key = new ReflectionMethod( 'Shasmomo_API', 'token_key' );
	$token = new ReflectionMethod( 'Shasmomo_API', 'token' );
	if ( PHP_VERSION_ID < 80100 ) { $key->setAccessible( true ); $token->setAccessible( true ); }
	$base_key = $key->invoke( $api );
	foreach ( array( array( 'other-client', 'invented-secret', 'sandbox' ), array( 'invented-client', 'other-secret', 'sandbox' ), array( 'invented-client', 'invented-secret', 'production' ) ) as $credentials ) {
		$other = new Shasmomo_API( ...$credentials );
		shasmomo_integration_check( $base_key !== $key->invoke( $other ), 'token cache bound to client, secret and environment' );
	}
	$endpoint = 'https://public.shasmomo.test/other';
	$other = new Shasmomo_API( 'invented-client', 'invented-secret', 'sandbox' );
	shasmomo_integration_check( $base_key !== $key->invoke( $other ), 'token cache bound to effective endpoint' );
	$endpoint = 'https://public.shasmomo.test/api';
	$home_site_id = get_current_blog_id();
	$site = wpmu_create_blog( 'example.test', '/endpoint-fixture/', 'Endpoint fixture', 1 );
	shasmomo_integration_check( ! is_wp_error( $site ), 'second site created' );
	shasmomo_integration_check( $home_site_id === get_current_blog_id(), 'site creation restores initial context' );
	// Seed after installation: site initialization may invalidate runtime caches.
	$first_token = $token->invoke( $api, true );
	shasmomo_integration_check( ! is_wp_error( $first_token ) && $first_token === $token->invoke( $api, false ), 'site A caches its fixture token before switching' );
	switch_to_blog( $site );
	try {
		shasmomo_integration_check( false === get_transient( $base_key ), 'site B cannot read site A token transient' );
		$second_token = $token->invoke( $api, false );
		shasmomo_integration_check( $second_token !== $first_token, 'site B obtains a separate fixture token' );
	} finally {
		restore_current_blog();
	}
	shasmomo_integration_check( $home_site_id === get_current_blog_id(), 'site context restored after second-site test' );
	shasmomo_integration_check( $first_token === $token->invoke( $api, false ), 'restoring site A restores its own token cache' );
} finally {
	remove_action( 'requests-requests.before_request', $transport, 10 );
	remove_filter( 'shasmomo_base_url', $filter );
}
