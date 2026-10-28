<?php
/**
 * Uninstall: removes this plugin's settings, locks and temporary data.
 *
 * Payment records on CampTix attendees (_shashura_* meta) are kept on purpose: they are part
 * of the event's accounts. Use Tools → Erase Personal Data to remove a buyer's details.
 *
 * @package CampTix_Shashura
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

function shasmomo_uninstall_site() {
	global $wpdb;

	// The settings live inside CampTix's own options.
	$options = get_option( 'camptix_options' );
	if ( is_array( $options ) ) {
		unset( $options['payment_options_shashura'] );
		if ( isset( $options['payment_methods'] ) && is_array( $options['payment_methods'] ) ) {
			unset( $options['payment_methods']['shashura'] );
		}
		update_option( 'camptix_options', $options );
	}

	// Order locks and transients (API token, rate limits and other short-lived data).
	$patterns = array(
		$wpdb->esc_like( 'shasmomo_lock_' ) . '%',
		$wpdb->esc_like( '_transient_shasmomo_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_shasmomo_' ) . '%',
	);
	foreach ( $patterns as $pattern ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of rows with a known prefix on uninstall.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $shasmomo_site_id ) {
		switch_to_blog( $shasmomo_site_id );
		shasmomo_uninstall_site();
		restore_current_blog();
	}
} else {
	shasmomo_uninstall_site();
}
