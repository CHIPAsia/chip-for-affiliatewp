<?php
/**
 * Plugin activation and deactivation routines.
 *
 * @package CHIPforAffiliateWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Cannot access directly.

/**
 * Returns the Action Scheduler group used by this plugin's scheduled work.
 *
 * @return string
 */
function chip_affiliatewp_as_group() {
	return 'chip-affiliatewp';
}

/**
 * Schedules the recurring payout sweep.
 *
 * The sweep runs through Action Scheduler rather than WP-Cron: sites that
 * disable WP-Cron (many hosts do, and this is the norm on managed platforms)
 * would otherwise never run the sweep at all, leaving payouts stuck in
 * processing when a webhook delivery is missed. Action Scheduler is bundled
 * with AffiliateWP and is already how AffiliateWP schedules its own work.
 *
 * @return void
 */
function chip_affiliatewp_schedule_sweep() {
	if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
		// Action Scheduler unavailable: fall back to WP-Cron so the plugin still
		// works on a site that runs cron normally.
		if ( ! wp_next_scheduled( 'chip_affiliatewp_hourly_sweep' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'chip_affiliatewp_hourly_sweep' );
		}

		return;
	}

	if ( false !== as_next_scheduled_action( 'chip_affiliatewp_hourly_sweep', array(), chip_affiliatewp_as_group() ) ) {
		return;
	}

	as_schedule_recurring_action(
		time() + HOUR_IN_SECONDS,
		HOUR_IN_SECONDS,
		'chip_affiliatewp_hourly_sweep',
		array(),
		chip_affiliatewp_as_group()
	);
}

/**
 * Cancels every scheduled sweep, on both schedulers.
 *
 * @return void
 */
function chip_affiliatewp_unschedule_sweep() {
	wp_clear_scheduled_hook( 'chip_affiliatewp_hourly_sweep' );

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'chip_affiliatewp_hourly_sweep', array(), chip_affiliatewp_as_group() );
	}
}

/**
 * Schedules the recurring sweep on plugin activation.
 *
 * @return void
 */
function chip_affiliatewp_activate() {
	chip_affiliatewp_schedule_sweep();
}
register_activation_hook( __FILE__, 'chip_affiliatewp_activate' );

/**
 * Clears the scheduled sweep on plugin deactivation.
 *
 * @return void
 */
function chip_affiliatewp_deactivate() {
	chip_affiliatewp_unschedule_sweep();
}
