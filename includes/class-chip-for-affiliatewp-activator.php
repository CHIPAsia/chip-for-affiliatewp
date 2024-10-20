<?php

/**
 * Fired during plugin activation
 *
 * @link       https://www.chip-in.asia
 * @since      1.0.0
 *
 * @package    Chip_For_Affiliatewp
 * @subpackage Chip_For_Affiliatewp/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Chip_For_Affiliatewp
 * @subpackage Chip_For_Affiliatewp/includes
 * @author     CHIP IN SDN BHD <support@chip-in.asia>
 */
class Chip_For_Affiliatewp_Activator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

    global $wpdb;

    $table_name = $wpdb->prefix . 'affiliate_wp_referrals_chip';

    $create_ddl = "CREATE TABLE $table_name (
      id BIGINT(20) NOT NULL AUTO_INCREMENT,
      referral_id BIGINT(20) NOT NULL,
      send_status VARCHAR(255) NOT NULL,
      PRIMARY KEY (referral_id),
      UNIQUE KEY `referrer` (`referral_id`)
    );";

    // Create the table if it doesn't exist
    maybe_create_table($table_name, $create_ddl);
	}

}
