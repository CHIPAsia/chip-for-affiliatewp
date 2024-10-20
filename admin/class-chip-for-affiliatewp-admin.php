<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://www.chip-in.asia
 * @since      1.0.0
 *
 * @package    Chip_For_Affiliatewp
 * @subpackage Chip_For_Affiliatewp/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Chip_For_Affiliatewp
 * @subpackage Chip_For_Affiliatewp/admin
 * @author     CHIP IN SDN BHD <support@chip-in.asia>
 */
class Chip_For_Affiliatewp_Admin {

  /**
   * The ID of this plugin.
   *
   * @since    1.0.0
   * @access   private
   * @var      string    $plugin_name    The ID of this plugin.
   */
  private $plugin_name;

  /**
   * The version of this plugin.
   *
   * @since    1.0.0
   * @access   private
   * @var      string    $version    The current version of this plugin.
   */
  private $version;

  /**
   * Initialize the class and set its properties.
   *
   * @since    1.0.0
   * @param      string    $plugin_name       The name of this plugin.
   * @param      string    $version    The version of this plugin.
   */
  public function __construct( $plugin_name, $version ) {

    $this->plugin_name = $plugin_name;
    $this->version = $version;

  }

  /**
   * Register the stylesheets for the admin area.
   *
   * @since    1.0.0
   */
  public function enqueue_styles() {

    /**
     * This function is provided for demonstration purposes only.
     *
     * An instance of this class should be passed to the run() function
     * defined in Chip_For_Affiliatewp_Loader as all of the hooks are defined
     * in that particular class.
     *
     * The Chip_For_Affiliatewp_Loader will then create the relationship
     * between the defined hooks and the functions defined in this
     * class.
     */

    wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/chip-for-affiliatewp-admin.css', array(), $this->version, 'all' );

  }

  /**
   * Register the JavaScript for the admin area.
   *
   * @since    1.0.0
   */
  public function enqueue_scripts() {

    /**
     * This function is provided for demonstration purposes only.
     *
     * An instance of this class should be passed to the run() function
     * defined in Chip_For_Affiliatewp_Loader as all of the hooks are defined
     * in that particular class.
     *
     * The Chip_For_Affiliatewp_Loader will then create the relationship
     * between the defined hooks and the functions defined in this
     * class.
     */

    wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/chip-for-affiliatewp-admin.js', array( 'jquery' ), $this->version, false );

  }

  public function add_bank_account_settings( $affiliate ) {
    
    $payment_account_number = get_user_meta(
      $affiliate->user_id,
      'payment_account_number',
      true
    );
  
    $payment_bank_code = get_user_meta(
      $affiliate->user_id,
      'payment_bank_code',
      true
    );
  
    if ( affiliate_wp()->settings->get( 'chip_payouts' ) ) : ?>
      <tr class="form-row form-required">
      <th scope="row">
        <label for="payment_bank_code"><?php _e( 'Bank Code', 'affiliate-wp' ); ?></label>
      </th>
      
      <td>
        <select name="payment_bank_code" id="payment_bank_code">
          <?php
            $send_bank_codes = Chip_For_Affiliatewp_Send_Api::get_bank_list();
          ?>
  
          <?php foreach ( $send_bank_codes as $bank_code => $label ) : ?>
            <option value="<?php echo esc_attr( $bank_code ); ?>" <?php selected( $payment_bank_code, $bank_code ); ?>>
              <?php echo esc_html( $label ); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p class="description"><?php _e( 'Bank code for payment to be transfered using CHIP Send.', 'affiliate-wp' ); ?></p>
      </td>
      </tr>
      <tr class="form-row form-required">
  
      <th scope="row">
        <label for="payment_account_number"><?php _e( 'Bank Account Number', 'affiliate-wp' ); ?></label>
      </th>
      
      <td>
        <input class="regular-text" type="text" name="payment_account_number" id="payment_account_number" value="<?php echo esc_attr( $payment_account_number ); ?>"/>
        <p class="description"><?php _e( 'Account number for payment to be transfered using CHIP Send.', 'affiliate-wp' ); ?></p>
      </td>
      
      </tr>
    <?php endif;

  }

  public function register_section_payment_methods( $settings ) {
    
    if ( affwp_get_currency() != 'MYR' ) {
      return $settings;
    }
  
    $settings []= 'chip_payouts';
    return $settings;

  }

  public function after_register_admin_sections() {
    
    affiliate_wp()->settings->register_section(
      'commissions',
      'chip_payouts',
      __( 'CHIP Send Payment Method', 'affiliate-wp' ),
      apply_filters(
        'affiliatewp_register_section_chip_send',
        array(
          'chip_test_mode',
          'chip_live_api_key',
          'chip_live_secret_key',
          'chip_test_api_key',
          'chip_test_secret_key',
          'chip_reference_prefix',
        )
      ),
      '',
      array(
        'required_field' => 'chip_payouts',
        'value'          => true
      ),
    );

  }

  public function notices_registry_init( $notice_registry ) {
    
    $notice_registry->add_notice( 'chip_send_balance_refreshed', array(
      'class'         => 'updated',
      'message'       => 'CHIP Send Balance refresh success',
      'dismissible'   => true,
      // 'dismiss_label' => _x( 'Close', 'chip-send-test-balance', 'chip-for-affiliatewp' ),
    ) );
  
    $notice_registry->add_notice( 'chip_send_balance_not_updated', array(
      'class'         => 'notice notice-warning',
      'message'       => 'CHIP Send Balance refresh failed',
      'dismissible'   => true,
    ) );
  
    $notice_registry->add_notice( 'chip_send_instruction_success', array(
      'class'         => 'updated',
      'message'       => 'CHIP Send Instruction succcess!',
      'dismissible'   => true,
    ) );

    $notice_registry->add_notice( 'chip_bulk_send_instruction_success', array(
      'class'         => 'updated',
      'message'       => 'CHIP Bulk Send Instruction initiated. All task has been scheduled via WP Cron.',
      'dismissible'   => true,
    ) );

    $notice_registry->add_notice( 'chip_send_instruction_failed', array(
      'class'         => 'notice notice-warning',
      'message'       => 'CHIP Send Instruction failed!' . (isset($_GET['message']) ? ' ' . $_GET['message'] : ''),
      'dismissible'   => true,
    ) );

  }

  public function add_hidden_chip_page() {

    add_submenu_page( 
      null, 
      'CHIP Test Refresh Balance',
      'CHIP Test Refresh Balance',
      'manage_options', 
      'chip_test_refresh_balance', 
      [ $this, 'refresh_chip_send_test_balance' ] );
    
    add_submenu_page( 
      null, 
      'CHIP Live Refresh Balance',
      'CHIP Live Refresh Balance',
      'manage_options', 
      'chip_live_refresh_balance', 
      [ $this, 'refresh_chip_send_live_balance' ] );

  }

  public function refresh_chip_send_test_balance() {

    $this->render_refresh_balance_page('test');

  }

  public function refresh_chip_send_live_balance() {

    $this->render_refresh_balance_page();

  }

  private function render_refresh_balance_page($mode = 'live') {
    
    $api_key = affiliate_wp()->settings->get("chip_{$mode}_api_key");
    $secret_key = affiliate_wp()->settings->get("chip_{$mode}_secret_key");

    $notice = 'chip_send_balance_not_updated';

    if (!empty($api_key) AND !empty($secret_key)) {
      $chip_send_api = new Chip_For_Affiliatewp_Send_Api($api_key, $secret_key, Chip_For_Affiliatewp_Helper::get_current_chip_send_mode(true));
      $response = $chip_send_api->get_send_account();

      if (isset($response['results']) AND !empty($response['results'])) {
        $current_balance = $response['results'][0]['current_balance'];
        
        update_option( "chip_send_{$mode}_balance", $current_balance, false );
    
        $notice = 'chip_send_balance_refreshed';
      }
    }
    
    wp_safe_redirect(
      affwp_admin_url(
        'settings',
        [
          'tab'          => 'commissions',
          'affwp_notice' => $notice,
        ]
      )
    );
    exit;

  }

  public function settings_commissions( $settings ) {
    
    if ( affwp_get_currency() != 'MYR' ) {
      return $settings;
    }
  
    $settings['chip_payouts'] = [
      'name'            => __( 'CHIP Send', 'affiliate-wp' ),
      'desc'            => __( 'Enable the CHIP Send Payouts payment method', 'affiliate-wp' ),
      'type'            => 'checkbox',
    ];
  
    $settings['chip_test_mode'] = [
      'name'            => __( 'CHIP Send Test Mode', 'affiliate-wp' ),
      'desc'            => __( 'Use CHIP Send Staging Mode', 'affiliate-wp' ),
      'type'            => 'checkbox',
    ];
  
    $settings['chip_live_api_key'] = [
      'name'            => __( 'Live API Key', 'affiliate-wp' ),
      'desc'            => __( '<p>Insert CHIP Send Live API Key that are provided by CHIP.</p>', 'affiliate-wp' ),
      'type'            => 'text',
    ];

    $live_balance = number_format(get_option( 'chip_send_live_balance' ), 2);
    $settings['chip_live_api_key']['desc'] .= sprintf(__( '<p>Your current CHIP Send balance is: <b>RM %s</b>. <a href="%s">Click here</a> to refresh</p>', 'affiliate-wp' ), $live_balance, esc_url( menu_page_url( 'chip_live_refresh_balance', false ) ));
  
    $settings['chip_live_secret_key'] = [
      'name'            => __( 'Live Secret Key', 'affiliate-wp' ),
      'desc'            => __( 'Insert CHIP Send Live Secret Key that are provided by CHIP.', 'affiliate-wp' ),
      'type'            => 'text',
    ];

    $settings['chip_test_api_key'] = [
      'name'            => __( 'Test API Key', 'affiliate-wp' ),
      'desc'            => __( '<p>Insert CHIP Send Test API Key that are provided by CHIP.</p>', 'affiliate-wp' ),
      'type'            => 'text',
    ];

    $test_balance = number_format(get_option( 'chip_send_test_balance' ), 2);
    $settings['chip_test_api_key']['desc'] .= sprintf(__( '<p>Your current CHIP Send balance is: <b>RM %s</b>. <a href="%s">Click here</a> to refresh</p>', 'affiliate-wp' ), $test_balance, esc_url( menu_page_url( 'chip_test_refresh_balance', false ) ));
  
    $settings['chip_test_secret_key'] = [
      'name'            => __( 'Test Secret Key', 'affiliate-wp' ),
      'desc'            => __( '<p>Insert CHIP Send Test Secret Key that are provided by CHIP.</p>', 'affiliate-wp' ),
      'type'            => 'text',
    ];
  
    $settings['chip_reference_prefix'] = [
      'name'            => __( 'Reference Prefix', 'affiliate-wp' ),
      'desc'            => __( 'Insert reference prefix. Limit to 2 character only', 'affiliate-wp' ),
      'type'            => 'text',
      'std'             => substr( bin2hex( random_bytes( 2 ) ), 0, 2 ),
    ];

    return $settings;

  }

  public function pre_update_affiliate( $affiliate, $args, $data ) {
    
    $payment_account_number = sanitize_text_field( $data['payment_account_number'] );
  
    update_user_meta(
      $affiliate->user_id,
      'payment_account_number',
      $payment_account_number
    );
  
    $payment_bank_code = sanitize_text_field( $data['payment_bank_code'] );
    
    update_user_meta(
      $affiliate->user_id,
      'payment_bank_code',
      $payment_bank_code
    );

  }

  public function payout_methods( $payout_methods ) {
    
    if (affwp_get_currency() != 'MYR') {
      return $payout_methods;
    }
  
    $payout_methods['chip'] = __( 'CHIP Send', 'affiliate-wp' );
    return $payout_methods;

  }

  public function referrals_bulk_actions( $actions ) {
    
    if (affwp_get_currency() != 'MYR') {
      return $actions;
    }
  
    $actions['pay_now_chip'] = __( 'Pay Now via CHIP', 'affwp-chip-payouts' );
    return $actions;

  }

  public function referral_action_links( $links, $referral ) {
    
    if ( affwp_get_currency() != 'MYR' ) {
      return $links;
    }

    if( 'unpaid' == $referral->status AND current_user_can( 'manage_referrals' ) ) {
      $user_id = affwp_get_affiliate_user_id( $referral->affiliate_id );
  
      $payment_account_number = get_user_meta(
        $user_id,
        'payment_account_number',
        true
      );
  
      $payment_bank_code = get_user_meta(
        $user_id,
        'payment_bank_code',
        true
      );

      if ( $payment_account_number AND $payment_bank_code ) {
        $link_label = __( 'Pay Now via CHIP', 'chip-for-affiliatewp' );
        $links[] = '<a href="' . esc_url( add_query_arg( array( 'affwp_action' => 'pay_now_chip', 'referral_id' => $referral->referral_id, 'affiliate_id' => $referral->affiliate_id ) ) ) . '">' . $link_label . '</a>';
      }
    }
    return $links;

  }

  public function pay_now_chip( $data ) {
    $referral_id  = absint( $data['referral_id'] );

    if( empty( $referral_id ) ) {
      return;
    }
  
    if( ! current_user_can( 'manage_referrals' ) ) {
      wp_die( __( 'You do not have permission to process payments', 'affwp-payouts' ) );
    }
  
    $transfer = Chip_For_Affiliatewp_Helper::chip_send_pay_referral( $referral_id );
  
    if( is_wp_error( $transfer ) ) {
      wp_safe_redirect( admin_url( 'admin.php?page=affiliate-wp-referrals&affwp_notice=chip_send_instruction_failed&message=' . urlencode( $transfer->get_error_message() ) . '&code=' . urlencode( $transfer->get_error_code() ) ) ); exit;
    }
  
    wp_safe_redirect( admin_url( 'admin.php?page=affiliate-wp-referrals&affwp_notice=chip_send_instruction_success&referral=' . $referral_id ) ); exit;

  }

  public function process_payout_chip( $start, $end, $minimum, $affiliate_id, $payout_method ) {
    if ( ! current_user_can( 'manage_payouts' ) ) {
      wp_die( __( 'You do not have permission to process payouts', 'affwp-chip-payouts' ) );
    }

    if ( ! Chip_For_Affiliatewp_Helper::chip_has_api_credentials() ) {
      return;
    }
    
    $args = array(
      'status'       => 'unpaid',
      'number'       => -1,
      'affiliate_id' => $affiliate_id,
      'date'         => array(
        'start' => $start,
        'end'   => $end,
      ),
    );

    // Final  affiliate / referral data to be paid out.
    $data = array();

    // The affiliates that have earnings to be paid.
    $affiliates = array();

    // Retrieve the referrals from the database.
    $referrals = affiliate_wp()->referrals->get_referrals( $args );

    if ( $referrals ) {

      foreach ( $referrals as $referral ) {

        $affiliate = affwp_get_affiliate( $referral->affiliate_id );
        if ( ! $affiliate->user ) {
          continue;
        }

        if ( in_array( $referral->affiliate_id, $affiliates ) ) {

          // Add the amount to an affiliate that already has a referral in the export.
          $amount = $data[ $referral->affiliate_id ]['amount'] + $referral->amount;

          $data[ $referral->affiliate_id ]['amount']      = $amount;
          $data[ $referral->affiliate_id ]['referrals'][] = $referral->referral_id;

        } else {

          $email = affwp_get_affiliate_payment_email( $referral->affiliate_id );

          $data[ $referral->affiliate_id ] = array(
            'email'     => $email,
            'amount'    => $referral->amount,
            'currency'  => ! empty( $referral->currency ) ? $referral->currency : affwp_get_currency(),
            'referrals' => array( $referral->referral_id ),
          );

          $affiliates[] = $referral->affiliate_id;

        }
      }

      $payouts = array();

      $i = 0;

      foreach ( $data as $affiliate_id => $payout ) {

        if ( $minimum > 0 && $payout['amount'] < $minimum ) {

          // Ensure the minimum amount was reached.
          unset( $data[ $affiliate_id ] );

          // Skip to the next affiliate.
          continue;
        }

        $payouts[ $affiliate_id ] = array(
          'email'       => $payout['email'],
          'amount'      => $payout['amount'],
          /* translators: 1: Referrals start date, 2: Referrals end date, 3: Home URL */
          'description' => sprintf( __( 'Payment for referrals between %1$s and %2$s from %3$s', 'affwp-chip-payouts' ), $start, $end, home_url() ),
          'referrals'   => $payout['referrals'],
        );

        $i++;
      }

      $redirect_args = array(
        'affwp_notice' => 'chip_bulk_send_instruction_success',
        'message'      => 'Bulk payment initiated. It will processed by batch.',
      );

      wp_schedule_single_event( time(), 'chip_schedule_bulk_payment', array( $payouts ) );

      $redirect = affwp_admin_url( 'referrals', $redirect_args );

      // A header is used here instead of wp_redirect() due to the esc_url() bug that removes [] from URLs.
      header( 'Location:' . $redirect );
      exit;
    }
  }

  public function process_bulk_action_pay_now_chip( $referral_id ) {

    if( empty( $referral_id ) ) {
      return;
    }
  
    if( ! current_user_can( 'manage_referrals' ) ) {
      return;
    }
  
    if ( !Chip_For_Affiliatewp_Helper::chip_has_api_credentials() ) {
      return;
    }
  
    Chip_For_Affiliatewp_Helper::chip_send_pay_referral( $referral_id );

  }

}
