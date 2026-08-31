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
 * Schedules the hourly sweep on plugin activation.
 *
 * @return void
 */
function chip_affiliatewp_activate() {
	if ( ! wp_next_scheduled( 'chip_affiliatewp_hourly_sweep' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'chip_affiliatewp_hourly_sweep' );
	}
}
register_activation_hook( __FILE__, 'chip_affiliatewp_activate' );

/**
 * Clears the scheduled sweep on plugin deactivation.
 *
 * @return void
 */
function chip_affiliatewp_deactivate() {
	wp_clear_scheduled_hook( 'chip_affiliatewp_hourly_sweep' );
}