<?php
/**
 * Plugin Name: CHIP for AffiliateWP
 * Description: Pay affiliate commissions via CHIP Send payouts.
 * Version: 1.2.0
 * Author: CHIP IN SDN BHD
 * Author URI: https://chip-in.asia
 * Requires PHP: 7.1
 * Requires at least: 7.1
 * Text Domain: chip-for-affiliatewp
 * Domain Path: /languages
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
define( 'CHIP_AFFILIATEWP_VERSION', '1.2.0' );
define( 'CHIP_AFFILIATEWP_FILE', __FILE__ );
define( 'CHIP_AFFILIATEWP_BASENAME', plugin_basename( CHIP_AFFILIATEWP_FILE ) );
define( 'CHIP_AFFILIATEWP_URL', plugin_dir_url( CHIP_AFFILIATEWP_FILE ) );
define( 'CHIP_AFFILIATEWP_PATH', plugin_dir_path( CHIP_AFFILIATEWP_FILE ) );

/*
 * Translations load just in time from the `Text Domain` and `Domain Path`
 * plugin headers (WordPress 4.6+), which also covers language packs installed
 * from translate.wordpress.org. Calling load_plugin_textdomain() on top of that
 * is discouraged, so the plugin declares the headers and does not load the
 * domain itself.
 */

// Include plugin modules.
require_once CHIP_AFFILIATEWP_PATH . 'includes/chip-affiliatewp-functions.php';

/**
 * The minimum AffiliateWP release this plugin can work on.
 *
 * The payment-method registry, the single-referral payout handlers, and the
 * payout metadata API are all 2.36 features. On an older release the method
 * simply never appears, which looks like a broken install rather than an
 * outdated dependency.
 */
define( 'CHIP_AFFILIATEWP_MIN_AFFWP', '2.36' );

/**
 * Returns the installed AffiliateWP version.
 *
 * Filterable so a site (or a test harness) can report the version explicitly
 * without redefining AffiliateWP's own constant.
 *
 * @return string Version string, or an empty string when unknown.
 */
function chip_affiliatewp_affwp_version() {
	$version = defined( 'AFFILIATEWP_VERSION' ) ? (string) AFFILIATEWP_VERSION : '';

	/**
	 * Filters the AffiliateWP version this plugin measures against.
	 *
	 * @param string $version Installed version, or an empty string.
	 */
	return (string) apply_filters( 'chip_affiliatewp_affwp_version', $version );
}

/**
 * Whether AffiliateWP is active and new enough.
 *
 * @return bool
 */
function chip_affiliatewp_dependencies_met() {
	if ( ! class_exists( 'Affiliate_WP' ) && ! function_exists( 'affiliate_wp' ) ) {
		return false;
	}

	$version = chip_affiliatewp_affwp_version();

	// An unknown version is not treated as outdated: the plugin works on what
	// it can detect, and a missing constant is not evidence of an old release.
	if ( '' !== $version && version_compare( $version, CHIP_AFFILIATEWP_MIN_AFFWP, '<' ) ) {
		return false;
	}

	return true;
}

/**
 * Whether AffiliateWP is active but too old to support this plugin.
 *
 * @return bool
 */
function chip_affiliatewp_dependency_outdated() {
	if ( ! class_exists( 'Affiliate_WP' ) && ! function_exists( 'affiliate_wp' ) ) {
		return false;
	}

	$version = chip_affiliatewp_affwp_version();

	if ( '' === $version ) {
		return false;
	}

	return version_compare( $version, CHIP_AFFILIATEWP_MIN_AFFWP, '<' );
}

/**
 * Warns when AffiliateWP is present but older than this plugin supports.
 *
 * @return void
 */
function chip_affiliatewp_outdated_dependency_notice() {
	if ( ! chip_affiliatewp_dependency_outdated() || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$args = array(
		'installed' => chip_affiliatewp_affwp_version(),
		'required'  => CHIP_AFFILIATEWP_MIN_AFFWP,
	);

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: installed AffiliateWP version, 2: required version */
				__( 'CHIP Send for AffiliateWP needs AffiliateWP %2$s or newer. You have %1$s, so the payout method is unavailable. Update AffiliateWP to use CHIP Send payouts.', 'chip-for-affiliatewp' ),
				$args['installed'],
				$args['required']
			)
		)
	);
}

/**
 * Reports the missing dependency on the Plugins screen.
 *
 * @return void
 */
function chip_affiliatewp_missing_dependency_notice() {
	if ( chip_affiliatewp_dependencies_met() || chip_affiliatewp_dependency_outdated() || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'CHIP Send for AffiliateWP needs AffiliateWP to be installed and active. Payouts stay disabled until it is.', 'chip-for-affiliatewp' )
	);
}

if ( ! chip_affiliatewp_dependencies_met() ) {
	add_action( 'admin_notices', 'chip_affiliatewp_missing_dependency_notice' );
	add_action( 'admin_notices', 'chip_affiliatewp_outdated_dependency_notice' );

	return;
}

require_once CHIP_AFFILIATEWP_PATH . 'includes/class-chip-affiliatewp-api.php';
require_once CHIP_AFFILIATEWP_PATH . 'includes/class-chip-affiliatewp-account.php';
require_once CHIP_AFFILIATEWP_PATH . 'includes/class-chip-affiliatewp-bank-accounts.php';
require_once CHIP_AFFILIATEWP_PATH . 'includes/class-chip-affiliatewp-payouts.php';
require_once CHIP_AFFILIATEWP_PATH . 'includes/class-chip-affiliatewp-webhooks.php';
require_once CHIP_AFFILIATEWP_PATH . 'includes/class-chip-affiliatewp-webhook-reset.php';
require_once CHIP_AFFILIATEWP_PATH . 'includes/class-chip-affiliatewp-affiliate-area.php';
require_once CHIP_AFFILIATEWP_PATH . 'includes/class-chip-affiliatewp-review-notices.php';
require_once CHIP_AFFILIATEWP_PATH . 'includes/class-chip-affiliatewp-admin.php';
require_once CHIP_AFFILIATEWP_PATH . 'includes/class-chip-affiliatewp-failures.php';

// Activation / deactivation hooks. Both are registered here, from the file
// WordPress names in the activate_/deactivate_ action, never from an include.
require_once CHIP_AFFILIATEWP_PATH . 'includes/chip-affiliatewp-lifecycle.php';
register_activation_hook( __FILE__, 'chip_affiliatewp_activate' );
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
