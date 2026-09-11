<?php
/**
 * Plugin Name: CHIP for AffiliateWP
 * Description: Pay affiliate commissions via CHIP Send payouts.
 * Version: 1.0.0
 * Author: CHIP IN SDN BHD
 * Author URI: https://chip-in.asia
 * Requires PHP: 7.1
 * Requires at least: 5.8
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

/**
 * Checks that AffiliateWP is present before the plugin wires itself up.
 *
 * Every hook and helper in this plugin resolves through AffiliateWP's API, so
 * loading without it would fatal on the first call. Bail out cleanly and tell
 * the merchant what to install instead.
 *
 * @return bool True when AffiliateWP is available.
 */
function chip_affiliatewp_dependencies_met() {
	return class_exists( 'Affiliate_WP' ) || function_exists( 'affiliate_wp' );
}

/**
 * Reports the missing dependency on the Plugins screen.
 *
 * @return void
 */
function chip_affiliatewp_missing_dependency_notice() {
	if ( chip_affiliatewp_dependencies_met() || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'CHIP Send for AffiliateWP needs AffiliateWP to be installed and active. Payouts stay disabled until it is.', 'chip-for-affiliatewp' )
	);
}

if ( ! chip_affiliatewp_dependencies_met() ) {
	add_action( 'admin_notices', 'chip_affiliatewp_missing_dependency_notice' );

	return;
}

require_once CHIP_AFFILIATEWP_PATH . 'includes/class-chip-affiliatewp-api.php';
require_once CHIP_AFFILIATEWP_PATH . 'includes/class-chip-affiliatewp-account.php';
require_once CHIP_AFFILIATEWP_PATH . 'includes/class-chip-affiliatewp-bank-accounts.php';
require_once CHIP_AFFILIATEWP_PATH . 'includes/class-chip-affiliatewp-payouts.php';
require_once CHIP_AFFILIATEWP_PATH . 'includes/class-chip-affiliatewp-webhooks.php';
require_once CHIP_AFFILIATEWP_PATH . 'includes/class-chip-affiliatewp-admin.php';
require_once CHIP_AFFILIATEWP_PATH . 'includes/class-chip-affiliatewp-failures.php';

// Activation / deactivation hooks.
require_once CHIP_AFFILIATEWP_PATH . 'includes/chip-affiliatewp-lifecycle.php';
register_deactivation_hook( __FILE__, 'chip_affiliatewp_deactivate' );

/**
 * Keeps the recurring sweep scheduled.
 *
 * Activation only fires on the request that turns the plugin on, and an
 * Action Scheduler action can be lost (a wiped queue, a migration, a host
 * that clears scheduled work). Re-asserting on an admin-adjacent hook means a
 * missing sweep heals itself instead of silently leaving payouts unresolved.
 *
 * @return void
 */
function chip_affiliatewp_ensure_sweep_scheduled() {
	if ( ! function_exists( 'chip_affiliatewp_schedule_sweep' ) ) {
		return;
	}

	if ( ! affiliate_wp()->settings->get( 'chip_payouts' ) ) {
		return;
	}

	chip_affiliatewp_schedule_sweep();
}
add_action( 'admin_init', 'chip_affiliatewp_ensure_sweep_scheduled' );
