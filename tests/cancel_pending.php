<?php
/**
 * Cancel / late-approval inventory regression for #31.
 *
 * In-memory mocks, no real Mobile Money network requests.
 */
require __DIR__ . '/bootstrap.php';

$ctx = fixture();
$m   = method();

// Only confirmed final, unpaid results permit cancellation.
foreach ( array( 'sent', 'pending', 'lost', 'paid', 'mismatch', 'reconciliation_required' ) as $state ) {
	check(
		! invoke( $m, 'can_cancel', array( 'attempts' => array( array( 'status' => $state ) ) ) ),
		'cancellation refuses unresolved/collected attempt: ' . $state
	);
}
foreach ( array( 'failed', 'rejected' ) as $state ) {
	check(
		invoke( $m, 'can_cancel', array( 'attempts' => array( array( 'status' => $state ) ) ) ),
		'cancellation allows confirmed unsuccessful attempt: ' . $state
	);
}
check( invoke( $m, 'can_cancel', array( 'attempts' => array() ) ), 'cancellation allows an order without a payment prompt' );
check(
	! invoke(
		$m,
		'can_cancel',
		array( 'attempts' => array( array( 'status' => 'failed' ), array( 'status' => 'pending' ) ) )
	),
	'a failed newest prompt must not override an older outstanding prompt'
);

// Real settlement path: an unsettled prompt does not get turned into a
// CampTix cancellation merely because the buyer clicked Cancel.
$ctx = fixture();
$m   = method();
Shasmomo_API::$response = array(
	'state' => 'pending',
	'raw_state' => 'PENDING',
	'amount' => 100,
	'external_ref' => 'mock',
	'tid' => 'mock-tid',
	'network_ref' => '',
);
$would_cancel = false;
$state = invoke( $m, 'settle', $ctx, false, true, function ( $fresh, $rec ) use ( $m, &$would_cancel ) {
	$would_cancel = invoke( $m, 'can_cancel', $rec );
} );
check( 'pending' === $state, 'pending enquiry remains pending' );
check( ! $would_cancel, 'cancel callback preserves reservation while the prompt is pending' );
check( ! $GLOBALS['camptix']->reports, 'no completed/cancelled CampTix result was recorded' );
check( ! $GLOBALS['wpdb']->locks, 'order lock released after pending cancellation check' );
