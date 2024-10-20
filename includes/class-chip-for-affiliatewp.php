<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://www.chip-in.asia
 * @since      1.0.0
 *
 * @package    Chip_For_Affiliatewp
 * @subpackage Chip_For_Affiliatewp/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Chip_For_Affiliatewp
 * @subpackage Chip_For_Affiliatewp/includes
 * @author     CHIP IN SDN BHD <support@chip-in.asia>
 */
class Chip_For_Affiliatewp {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Chip_For_Affiliatewp_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		if ( defined( 'CHIP_FOR_AFFILIATEWP_VERSION' ) ) {
			$this->version = CHIP_FOR_AFFILIATEWP_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->plugin_name = 'chip-for-affiliatewp';

		$this->load_dependencies();
    $this->load_services();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();

	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Chip_For_Affiliatewp_Loader. Orchestrates the hooks of the plugin.
	 * - Chip_For_Affiliatewp_i18n. Defines internationalization functionality.
	 * - Chip_For_Affiliatewp_Admin. Defines all hooks for the admin area.
	 * - Chip_For_Affiliatewp_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-chip-for-affiliatewp-loader.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-chip-for-affiliatewp-i18n.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-chip-for-affiliatewp-admin.php';

    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-chip-for-affiliatewp-helper.php';

    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-chip-for-affiliatewp-cron.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-chip-for-affiliatewp-public.php';

		$this->loader = new Chip_For_Affiliatewp_Loader();

	}

  private function load_services() {
    /**
     * The class responsible for calling API to CHIP Send services.
     */
    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'services/class-chip-for-affiliatewp-send-api.php';;

  }

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Chip_For_Affiliatewp_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new Chip_For_Affiliatewp_i18n();

		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );

	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {

		$plugin_admin = new Chip_For_Affiliatewp_Admin( $this->get_plugin_name(), $this->get_version() );
    $plugin_cron = new Chip_For_Affiliatewp_Cron( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );
    $this->loader->add_action( 'admin_menu', $plugin_admin, 'add_hidden_chip_page' );
    
    $this->loader->add_action( 'affwp_edit_affiliate_end', $plugin_admin, 'add_bank_account_settings' );
    $this->loader->add_action( 'affwp_notices_registry_init', $plugin_admin, 'notices_registry_init' );
    $this->loader->add_action( 'affwp_settings_commissions', $plugin_admin, 'settings_commissions' );
    $this->loader->add_action( 'affwp_pre_update_affiliate', $plugin_admin, 'pre_update_affiliate', 10, 3 );
    $this->loader->add_action( 'affwp_payout_methods', $plugin_admin, 'payout_methods' );
    $this->loader->add_action( 'affwp_referrals_bulk_actions', $plugin_admin, 'referrals_bulk_actions' );
    $this->loader->add_action( 'affwp_referral_action_links', $plugin_admin, 'referral_action_links', 10, 2  );
    $this->loader->add_action( 'affwp_pay_now_chip', $plugin_admin, 'pay_now_chip' );
    $this->loader->add_action( 'affwp_process_payout_chip', $plugin_admin, 'process_payout_chip' );
    $this->loader->add_action( 'affwp_referrals_do_bulk_action_pay_now_chip', $plugin_admin, 'process_bulk_action_pay_now_chip' );
    
    // add this to create bank account upon saving of details.
    // $this->loader->add_action( 'affwp_updated_affiliate', $plugin_admin, 'tambahpasni', 10, 2 );
    
    $this->loader->add_action( 'affiliatewp_register_section_payment_methods', $plugin_admin, 'register_section_payment_methods' );
    $this->loader->add_action( 'affiliatewp_after_register_admin_sections', $plugin_admin, 'after_register_admin_sections' );

    $this->loader->add_action( 'chip_schedule_bulk_payment', $plugin_cron, 'schedule_bulk_payment' );
    $this->loader->add_action( 'chip_send_bulk_payment', $plugin_cron, 'send_bulk_payment', 10, 2 );
	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks() {

		$plugin_public = new Chip_For_Affiliatewp_Public( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Chip_For_Affiliatewp_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

}
