<?php
/**
 * Uninstall cleanup for CHIP for AffiliateWP.
 *
 * Removes plugin settings (including stored API keys) and deletes the
 * auto-registered CHIP Send webhook so CHIP stops delivering to this site.
 *
 * @package CHIPforAffiliateWP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Load the plugin bootstrap so chip_affiliatewp_request() and the settings
// object are available. During uninstall the plugin is not active, so include
// AffiliateWP's core loader first (same guard as the plugin header requires).
if ( defined( 'WP_PLUGIN_DIR' ) ) {
	$chip_affwp_core = WP_PLUGIN_DIR . '/affiliate-wp/affiliate-wp.php';

	if ( file_exists( $chip_affwp_core ) ) {
		include_once $chip_affwp_core;
	}
}

require WP_UNINSTALL_PLUGIN;

$chip_option_names = array();

foreach ( array( 'test', 'live' ) as $chip_mode ) {
	foreach ( array( 'api_key', 'secret_key', 'webhook_id', 'webhook_public_key', 'webhook_setup' ) as $chip_suffix ) {
		$chip_option_names[] = 'chip_' . $chip_mode . '_' . $chip_suffix;
	}
}

$chip_option_names = array_merge(
	$chip_option_names,
	array( 'chip_payouts', 'chip_test_mode', 'chip_reference_prefix', 'chip_send_recipient_receipt' )
);

// Standalone options the plugin writes outside AffiliateWP's settings.
$chip_option_names[] = 'chip_affiliatewp_review_cache_version';

// Best effort: delete the CHIP Send webhooks this plugin registered so the
// site stops receiving webhook deliveries. Failures are ignored — the
// webhook can also be removed from the CHIP portal manually.
if ( class_exists( 'Affiliate_WP' ) && function_exists( 'chip_affiliatewp_request' ) ) {
	foreach ( array( 'test', 'live' ) as $chip_target_mode ) {
		$chip_webhook_id = (string) affiliate_wp()->settings->get( 'chip_' . $chip_target_mode . '_webhook_id', '' );

		if ( '' === $chip_webhook_id ) {
			continue;
		}

		chip_affiliatewp_request(
			'DELETE',
			'/webhooks/' . rawurlencode( $chip_webhook_id ),
			array(),
			array(),
			$chip_target_mode
		);
	}
}

if ( function_exists( 'affwp_get_affiliate' ) && function_exists( 'affwp_get_affiliates' ) ) {
	// Remove chip_* keys from the AffiliateWP settings option.
	$chip_affwp_settings = get_option( 'affwp_settings', array() );

	if ( is_array( $chip_affwp_settings ) ) {
		$chip_changed = false;

		foreach ( array_keys( $chip_affwp_settings ) as $chip_key ) {
			if ( is_string( $chip_key ) && 0 === strpos( $chip_key, 'chip_' ) ) {
				unset( $chip_affwp_settings[ $chip_key ] );
				$chip_changed = true;
			}
		}

		if ( $chip_changed ) {
			update_option( 'affwp_settings', $chip_affwp_settings );
		}
	}

	/*
	 * The "let affiliates choose" toggle adds this method to AffiliateWP's
	 * hidden-methods option. Left behind, it would keep CHIP hidden from
	 * affiliates on a later reinstall, when the setting no longer exists to
	 * turn it back on.
	 */
	$chip_hidden = get_option( 'affwp_hidden_affiliate_payout_methods', array() );

	if ( is_array( $chip_hidden ) && in_array( 'chip', $chip_hidden, true ) ) {
		update_option( 'affwp_hidden_affiliate_payout_methods', array_values( array_diff( $chip_hidden, array( 'chip' ) ) ) );
	}
}

foreach ( $chip_option_names as $chip_option_name ) {
	delete_option( $chip_option_name );
}

// Remove the payout data this plugin stores against each affiliate: the cached
// CHIP Send account record, and the bank details it was resolved from.
foreach ( array( 'chip_bank_account', 'payment_account_number', 'payment_bank_code' ) as $chip_meta_key ) {
	$chip_users = get_users(
		array(
			'meta_key' => $chip_meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-off uninstall sweep.
			'fields'   => 'ID',
			'number'   => 0,
		)
	);

	foreach ( $chip_users as $chip_user_id ) {
		delete_user_meta( (int) $chip_user_id, $chip_meta_key );
	}
}

// Clear cached account summaries and queued notices.
global $wpdb;

$chip_transients = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off uninstall sweep, nothing to cache.
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_chip_affiliatewp_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_chip_affiliatewp_' ) . '%'
	)
);

foreach ( $chip_transients as $chip_transient ) {
	$chip_key = preg_replace( '/^_transient(_timeout)?_/', '', (string) $chip_transient );

	delete_transient( $chip_key );
}

// Clear scheduled actions owned by the plugin.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'chip_affiliatewp_submit_payout_action' );
	as_unschedule_all_actions( 'chip_affiliatewp_run_check_action' );
	as_unschedule_all_actions( 'chip_affiliatewp_hourly_sweep' );
}

wp_clear_scheduled_hook( 'chip_affiliatewp_hourly_sweep' );
