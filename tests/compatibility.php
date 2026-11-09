<?php
// Separate processes let us test each CampTix API without redefining PHP classes.
if ( empty( $argv[1] ) ) {
	foreach ( array( 'legacy', 'current', 'missing' ) as $scenario ) {
		passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $scenario ), $status );
		if ( 0 !== $status ) {
			exit( $status );
		}
	}
	exit;
}
$scenario = $argv[1];
define( 'ABSPATH', __DIR__ );
function plugin_dir_path( $path ) { return dirname( $path ) . '/'; }
function plugin_dir_url( $path ) { return 'https://example.test/plugin/'; }
function add_action( $hook, $callback ) { $GLOBALS['hooks'][ $hook ][] = $callback; }
function current_user_can( $capability ) { return true; }
function esc_html__( $text, $domain ) { return htmlspecialchars( $text ); }
function camptix_register_addon( $class ) { $GLOBALS['registered'][] = $class; }
class CampTix_Payment_Method {}
if ( 'legacy' === $scenario ) {
	class CampTix_Plugin {
		function payment_result( $token, $result, $data = array() ) {
			throw new RuntimeException( 'Legacy payment_result must never be called' );
		}
	}
} elseif ( 'current' === $scenario ) {
	class CampTix_Plugin {
		public $interactive;
		function payment_result( $token, $result, $data = array(), $interactive = true ) {
			$this->interactive = $interactive;
			return 'recorded';
		}
	}
}
$GLOBALS['registered'] = array();
require dirname( __DIR__ ) . '/shashura-mobile-money-for-camptix.php';
foreach ( $GLOBALS['hooks']['camptix_load_addons'] as $callback ) {
	$callback();
}
ob_start();
foreach ( $GLOBALS['hooks']['admin_notices'] as $callback ) {
	$callback();
}
$notice = ob_get_clean();
if ( 'current' === $scenario ) {
	if ( array( 'Shasmomo_Payment_Method' ) !== $GLOBALS['registered'] || '' !== $notice ) {
		throw new RuntimeException( 'Supported CampTix must register without an error notice' );
	}
	$GLOBALS['camptix'] = new CampTix_Plugin();
	$method = new ReflectionMethod( 'Shasmomo_Payment_Method', 'report' );
	if ( PHP_VERSION_ID < 80100 ) { $method->setAccessible( true ); }
	$result = $method->invoke( new Shasmomo_Payment_Method(), 'test-token', 1, array(), false );
	if ( 'recorded' !== $result || false !== $GLOBALS['camptix']->interactive ) {
		throw new RuntimeException( 'Background result must return using non-interactive mode' );
	}
} elseif ( $GLOBALS['registered'] || '' === $notice ) {
	throw new RuntimeException( 'Unsupported or missing CampTix must not register and must explain why' );
}
echo "PASS CampTix compatibility: $scenario\n";
