<?php
require __DIR__ . '/bootstrap.php';
foreach ( array( 'cancel', 'timeout', 'failed' ) as $order_status ) {
	$ctx = fixture(); $m = method();
	$GLOBALS['status'] = $order_status;
	$cancelled = false;
	$state = invoke( $m, 'settle', $ctx, false, true, function () use ( &$cancelled ) { $cancelled = true; } );
	check( 'reconciliation' === $state, 'late paid ' . $order_status . ' stays unfulfilled' );
	check( ! $GLOBALS['camptix']->reports && ! $cancelled, 'no ticket or unpaid callback after ' . $order_status );
	$attempt = $GLOBALS['meta'][1]['_shashura_attempts'][0];
	check( 'paid' === $attempt['status'] && 100 === $attempt['paid_amount'] && ! empty( $attempt['fulfilment_hold'] ), 'collection evidence and durable hold retained' );
	check( 1 === count( $GLOBALS['mail'] ) && false !== strpos( $GLOBALS['mail'][0][2], 'mock-tid' ), 'actionable organiser alert' );
	check( 'reconciliation' === invoke( $m, 'settle', $ctx, false, true ) && 1 === count( $GLOBALS['mail'] ), 'late-payment replay is quiet' );
	check( empty( $GLOBALS['wpdb']->locks ), 'late-payment path releases order lock' );
	try { invoke( $m, 'guard_draft', invoke( $m, 'load', $ctx['token'] ) ); throw new Exception( 'Expected hold warning' ); }
	catch ( Rendered $e ) { check( true, 'buyer return shows reconciliation warning' ); }
}
$ctx = fixture(); $m = method();
$GLOBALS['meta'][1]['tix_timestamp'] = time() - DAY_IN_SECONDS - 1;
check( 'reconciliation' === invoke( $m, 'settle', $ctx, false, true ), 'expired draft cannot issue tickets before timeout sweep' );
$ctx = fixture(); $m = method();
unset( $GLOBALS['meta'][1]['tix_timestamp'] );
check( 'reconciliation' === invoke( $m, 'settle', $ctx, false, true ), 'unknown reservation age fails closed' );
$ctx = fixture(); $m = method();
$GLOBALS['during_enquiry'] = function () { $GLOBALS['status'] = 'timeout'; };
check( 'reconciliation' === invoke( $m, 'settle', $ctx, false, true ) && ! $GLOBALS['camptix']->reports, 'timeout during gateway enquiry is re-read before settlement' );
$ctx = fixture(); $m = method();
check( 'paid' === invoke( $m, 'settle', $ctx, false, true ), 'active reservation still completes' );
$ctx = fixture(); $m = method(); $GLOBALS['status'] = 'publish';
check( 'paid' === invoke( $m, 'settle', $ctx, false, true ) && ! $GLOBALS['mail'], 'already published order remains idempotent' );
$ctx = fixture(); $m = method();
$lock = invoke( $m, 'lock', $ctx['token'] );
check( 'busy' === invoke( $m, 'settle', $ctx, false, true ) && ! $GLOBALS['camptix']->reports, 'concurrent settlement cannot bypass order lock' );
$m->unlock( $lock );

$ctx = fixture(); $m = method();
$GLOBALS['meta'][1]['tix_session_expires_at'] = time() - 1;
check( 'reconciliation' === invoke( $m, 'settle', $ctx, false, true ), 'CampTix session release time takes precedence over checkout age' );
$ctx = fixture(); $m = method();
$GLOBALS['cache']['shasmomo_ids_' . $ctx['token']] = array( 1, 2 );
$GLOBALS['statuses'][2] = 'timeout';
check( 'reconciliation' === invoke( $m, 'settle', $ctx, false, true ), 'one released attendee prevents reissuing the whole multi-ticket order' );
