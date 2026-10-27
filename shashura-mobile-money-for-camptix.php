<?php
/**
 * Plugin Name:       Shashura Mobile Money for CampTix
 * Description:       Accept MTN and Airtel Mobile Money (Uganda) in CampTix through the Shashura gateway.
 * Version:           1.0.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Cipherwave Software Limited
 * License:           GPL-2.0-or-later
 * Text Domain:       shashura-mobile-money-for-camptix
 *
 * @package CampTix_Shashura
 */

defined( 'ABSPATH' ) || exit;

define( 'CTX_SHASHURA_VERSION', '1.0.1' );
define( 'CTX_SHASHURA_DIR', plugin_dir_path( __FILE__ ) );
define( 'CTX_SHASHURA_URL', plugin_dir_url( __FILE__ ) );

require_once CTX_SHASHURA_DIR . 'includes/class-shashura-api.php';
require_once CTX_SHASHURA_DIR . 'includes/class-shashura-phone.php';

/**
 * CampTix fires camptix_load_addons on init (priority 8). The payment method extends a
 * CampTix class, so it is only loaded from there.
 */
add_action( 'camptix_load_addons', function () {
	if ( ! class_exists( 'CampTix_Payment_Method' ) || ! function_exists( 'camptix_register_addon' ) ) {
		return;
	}
	require_once CTX_SHASHURA_DIR . 'includes/class-shashura-payment-method.php';
	camptix_register_addon( 'CampTix_Payment_Method_Shashura' );
} );

add_action( 'admin_notices', function () {
	if ( ! class_exists( 'CampTix_Plugin' ) && current_user_can( 'activate_plugins' ) ) {
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Shashura Mobile Money for CampTix needs the CampTix plugin to be installed and active.', 'shashura-mobile-money-for-camptix' )
			. '</p></div>';
	}
} );
