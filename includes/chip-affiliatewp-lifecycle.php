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

	/*
	 * Action Scheduler is available, so the WP-Cron event is now redundant.
	 * Clear it: a site that ran an earlier version kept the WP-Cron event when
	 * scheduling moved to Action Scheduler, and both stayed active — the sweep
	 * ran twice an hour, doubling the payout scan and the API traffic for no
	 * benefit. The cooldown keeps it from requerying the same payout twice, but
	 * the work of finding them is done twice.
	 */
	if ( wp_next_scheduled( 'chip_affiliatewp_hourly_sweep' ) ) {
		wp_clear_scheduled_hook( 'chip_affiliatewp_hourly_sweep' );
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
	/*
	 * Every action this plugin schedules, not just the sweep. Deactivating with
	 * payout checks or queued submissions still pending would leave Action
	 * Scheduler firing callbacks for a plugin that is no longer loaded, and a
	 * later reactivation would inherit a backlog of stale work.
	 */
	$hooks = array(
		'chip_affiliatewp_hourly_sweep',
		'chip_affiliatewp_check_payout_status',
		'chip_affiliatewp_submit_payout_action',
	);

	foreach ( $hooks as $hook ) {
		wp_clear_scheduled_hook( $hook );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, array(), chip_affiliatewp_as_group() );
		}
	}
}

/**
 * Schedules the recurring sweep on plugin activation.
 *
 * The hook is registered from the main plugin file, not from here: WordPress
 * fires `activate_{plugin_basename}`, so registering with this include's path
 * would listen on a name the activation never triggers and the sweep would
 * never be scheduled.
 *
 * @return void
 */
function chip_affiliatewp_activate() {
	chip_affiliatewp_schedule_sweep();
}

/**
 * Clears the scheduled sweep on plugin deactivation.
 *
 * Registered from the main plugin file for the same reason as activation.
 *
 * @return void
 */
function chip_affiliatewp_deactivate() {
	chip_affiliatewp_unschedule_sweep();
}
