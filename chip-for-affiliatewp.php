<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://www.chip-in.asia
 * @since             1.0.0
 * @package           Chip_For_Affiliatewp
 *
 * @wordpress-plugin
 * Plugin Name:       CHIP for AffiliateWP
 * Plugin URI:        https://www.chip-in.asia
 * Description:       CHIP Send integration for AffiliateWP referral payment
 * Version:           1.0.0
 * Author:            CHIP IN SDN BHD
 * Author URI:        https://www.chip-in.asia/
 * Copyright:         © 2024 CHIP
 * License:           GNU General Public License v3.0
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       chip-for-affiliatewp
 * Domain Path:       /languages
 * Requires Plugins:  affiliate-wp
 * Requires PHP:      8.1
 * Requires at least: 4.7
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
  die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'CHIP_FOR_AFFILIATEWP_VERSION', '1.0.0' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-chip-for-affiliatewp-activator.php
 */
function activate_chip_for_affiliatewp() {
  require_once plugin_dir_path( __FILE__ ) . 'includes/class-chip-for-affiliatewp-activator.php';
  Chip_For_Affiliatewp_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-chip-for-affiliatewp-deactivator.php
 */
function deactivate_chip_for_affiliatewp() {
  require_once plugin_dir_path( __FILE__ ) . 'includes/class-chip-for-affiliatewp-deactivator.php';
  Chip_For_Affiliatewp_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_chip_for_affiliatewp' );
register_deactivation_hook( __FILE__, 'deactivate_chip_for_affiliatewp' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-chip-for-affiliatewp.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_chip_for_affiliatewp() {

  $plugin = new Chip_For_Affiliatewp();
  $plugin->run();

}
run_chip_for_affiliatewp();
