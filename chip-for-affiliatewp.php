<?php
/**
 * Plugin Name: CHIP for AffiliateWP
 * Description: Pay affiliate commissions via CHIP Send payouts.
 * Version: 1.0.0
 * Author: wanzulnet
 * Author URI: https://profiles.wordpress.org/wanzulnet/
 * Requires PHP: 7.1
 * Requires at least: 4.7
 *
 * Copyright: © 2024-2026 CHIP
 * License: GNU General Public License v3.0
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package CHIPforAffiliateWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Cannot access directly.

// Define plugin constants.
define( 'CHIP_AFFILIATEWP_VERSION', '1.0.0' );
define( 'CHIP_AFFILIATEWP_FILE', __FILE__ );
define( 'CHIP_AFFILIATEWP_BASENAME', plugin_basename( CHIP_AFFILIATEWP_FILE ) );
define( 'CHIP_AFFILIATEWP_URL', plugin_dir_url( CHIP_AFFILIATEWP_FILE ) );
define( 'CHIP_AFFILIATEWP_PATH', plugin_dir_path( CHIP_AFFILIATEWP_FILE ) );

/**
 * Loads the plugin text domain for translations.
 *
 * @return void
 */
function chip_affiliatewp_load_textdomain() {
	load_plugin_textdomain( 'chip-for-affiliatewp', false, dirname( CHIP_AFFILIATEWP_BASENAME ) . '/languages' );
}
add_action( 'init', 'chip_affiliatewp_load_textdomain' );

// Include plugin modules.
require_once CHIP_AFFILIATEWP_PATH . 'includes/chip-affiliatewp-functions.php';
require_once CHIP_AFFILIATEWP_PATH . 'includes/class-chip-affiliatewp-api.php';
require_once CHIP_AFFILIATEWP_PATH . 'includes/class-chip-affiliatewp-bank-accounts.php';
require_once CHIP_AFFILIATEWP_PATH . 'includes/class-chip-affiliatewp-payouts.php';
require_once CHIP_AFFILIATEWP_PATH . 'includes/class-chip-affiliatewp-webhooks.php';
require_once CHIP_AFFILIATEWP_PATH . 'includes/class-chip-affiliatewp-admin.php';

// Activation / deactivation hooks.
require_once CHIP_AFFILIATEWP_PATH . 'includes/chip-affiliatewp-lifecycle.php';
register_deactivation_hook( __FILE__, 'chip_affiliatewp_deactivate' );