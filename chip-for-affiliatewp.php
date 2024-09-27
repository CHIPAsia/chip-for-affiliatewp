<?php

/**
 * Plugin Name: CHIP for AffiliateWP
 * Description: CHIP Send
 * Version: 1.0.0
 * Author: Chip In Sdn Bhd
 * Author URI: https://www.chip-in.asia
 * Requires PHP: 7.1
 * Requires at least: 4.7
 *
 * Requires Plugins: affiliate-wp
 *
 * Copyright: © 2024 CHIP
 * License: GNU General Public License v3.0
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

add_action( 'affwp_edit_affiliate_end', 'tambah_bank_account' );

function tambah_bank_account($affiliate) {
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
          $send_bank_codes = chip_send_get_bank_code();
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

add_filter( 'affiliatewp_register_section_payment_methods', 'tambah_chip_send_setting');
function tambah_chip_send_setting($setting) {
  if (affwp_get_currency() != 'MYR') {
    return $setting;
  }

  $setting []= 'chip_payouts';
  return $setting;
}

add_action('affiliatewp_after_register_admin_sections', 'tambah_chip_send_api_key_setting');

function tambah_chip_send_api_key_setting() {
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

add_filter( 'affwp_settings_commissions' , 'tambah_chip_send_ke_commission_setting_page');

function tambah_chip_send_ke_commission_setting_page($settings) {
  if (affwp_get_currency() != 'MYR') {
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
    'desc'            => __( 'Insert CHIP Send Live API Key that are provided by CHIP.', 'affiliate-wp' ),
    'type'            => 'text',
  ];

  $settings['chip_live_secret_key'] = [
    'name'            => __( 'Live Secret Key', 'affiliate-wp' ),
    'desc'            => __( 'Insert CHIP Send Live Secret Key that are provided by CHIP.', 'affiliate-wp' ),
    'type'            => 'text',
  ];

  $settings['chip_test_api_key'] = [
    'name'            => __( 'Test API Key', 'affiliate-wp' ),
    'desc'            => __( 'Insert CHIP Send Test API Key that are provided by CHIP.', 'affiliate-wp' ),
    'type'            => 'text',
  ];

  $settings['chip_test_secret_key'] = [
    'name'            => __( 'Test Secret Key', 'affiliate-wp' ),
    'desc'            => __( 'Insert CHIP Send Test Secret Key that are provided by CHIP.', 'affiliate-wp' ),
    'type'            => 'text',
  ];

  $settings['chip_reference_prefix'] = [
    'name'            => __( 'Reference Prefix', 'affiliate-wp' ),
    'desc'            => __( 'Insert reference prefix. Limit to 2 character only', 'affiliate-wp' ),
    'type'            => 'text',
    'std'             => substr(bin2hex(random_bytes(2)), 0, 2),
  ];
  return $settings;
}

add_action('affwp_pre_update_affiliate', 'test_pre_update_aff_wan', 10, 3);

function test_pre_update_aff_wan($affiliate, $args, $data) {

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

function chip_send_get_bank_code() {
  return [
    'ACDBMYK2' => 'AEON Bank (M) Berhad',
    'PHBMMYKL' => 'Affin Bank Berhad',
    'AGOBMYKL' => 'Agrobank',
    'RJHIMYKL' => 'Al-Rajhi',
    'MFBBMYKL' => 'Alliance Bank Malaysia Berhad',
    'ARBKMYKL' => 'Ambank Malaysia Berhad',
    'BIMBMYKL' => 'Bank Islam Malaysia Berhad',
    'BKRMMYKL' => 'Bank Kerjasama Rakyat Malaysia Berhad',
    'BMMBMYKL' => 'Bank Muamalat Malaysia Bhd',
    'BOFAMY2X' => 'Bank of America (M) Berhad',
    'BKCHMYKL' => 'Bank of China (M) Berhad',
    'BOTKMYKX' => 'Bank of Tokyo-Mitsubishi UFJ (M) Berhad',
    'BSNAMYK1' => 'Bank Simpanan Nasional Berhad',
    'BNPAMYKL' => 'BNP Paribas Malaysia Berhad',
    'PCBCMYKL' => 'China Construction Bank (M) Berhad',
    'CIBBMYKL' => 'CIMB Bank Berhad',
    'DEUTMYKL' => 'Deutsche Bank (Malaysia) Berhad',
    'FNXSMYNB' => 'Finexus Cards Sdn. Bhd.',
    'GXSPMYKL' => 'GX Bank Berhad',
    'HLBBMYKL' => 'Hong Leong Bank Berhad',
    'HBMBMYKL' => 'HSBC Bank Malaysia Berhad',
    'ICBKMYKL' => 'Industrial and Commercial Bank of China (M) Berhad',
    'CHASMYKX' => 'JP Morgan Chase Bank Berhad',
    'KFHOMYKL' => 'Kuwait Finance House',
    'MBBEMYKL' => 'Maybank Berhad',
    'AFBQMYKL' => 'MBSB BANK BERHAD',
    'MHCBMYKA' => 'Mizuho Bank (Malaysia) Berhad',
    'OCBCMYKL' => 'OCBC Bank Berhad',
    'PBBEMYKL' => 'Public Bank Berhad',
    'RHBBMYKL' => 'RHB Bank Berhad',
    'SCBLMYKX' => 'Standard Chartered Bank Malaysia Berhad',
    'SMBCMYKL' => 'Sumitomo Mitsui Banking Corporation (M) Berhad',
    'TNGDMYNB' => 'Touch `n Go eWallet',
    'UOVBMYKL' => 'United Overseas Bank Berhad (UOB)',
  ];
}

add_filter('affwp_payout_methods', 'add_chip_to_payout_method');

function add_chip_to_payout_method($payout_methods) {
  if (affwp_get_currency() != 'MYR') {
    return $payout_methods;
  }

  $payout_methods['chip'] = __( 'CHIP Send', 'affiliate-wp' );
  return $payout_methods;
}

add_filter( 'affwp_referrals_bulk_actions', 'bulk_action_chip_bayar' );

function bulk_action_chip_bayar( $actions ) {

  if (affwp_get_currency() != 'MYR') {
    return $actions;
  }

  $actions['pay_now'] = __( 'Pay Now via CHIP (tak siap lagi)', 'affwp-chip-payouts' );
  return $actions;
}

add_filter( 'affwp_referral_action_links', 'action_links_chip_send', 10, 2 );

function action_links_chip_send( $links, $referral ) {

  if (affwp_get_currency() != 'MYR') {
    return $links;
  }

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

  if( 'unpaid' == $referral->status && current_user_can( 'manage_referrals' ) && $payment_account_number && $payment_bank_code ) {
    $link_label = __( 'Pay Now via CHIP', 'chip-for-affiliatewp' );
    $links[] = '<a href="' . esc_url( add_query_arg( array( 'affwp_action' => 'pay_now_chip', 'referral_id' => $referral->referral_id, 'affiliate_id' => $referral->affiliate_id ) ) ) . '">' . $link_label . '</a>';
  }

  return $links;
}

add_action( 'affwp_pay_now_chip', 'process_pay_now_chip_send' );

function process_pay_now_chip_send($data) {
  $referral_id  = absint( $data['referral_id'] );

  if( empty( $referral_id ) ) {
    return;
  }

  if( ! current_user_can( 'manage_referrals' ) ) {
    wp_die( __( 'You do not have permission to process payments', 'affwp-payouts' ) );
  }

  $transfer = chip_send_pay_referral($referral_id);


  if( is_wp_error( $transfer ) ) {
    wp_safe_redirect( admin_url( 'admin.php?page=affiliate-wp-referrals&affwp_notice=chip_error&message=' . urlencode( $transfer->get_error_message() ) . '&code=' . urlencode( $transfer->get_error_code() ) ) ); exit;

  }

  wp_safe_redirect( admin_url( 'admin.php?page=affiliate-wp-referrals&affwp_notice=chip_success&referral=' . $referral_id ) ); exit;
}

function chip_send_pay_referral($referral_id) {
  global $wpdb;

  $data = array(
    'referral_id' => $referral_id,
    'send_status' => 'pending_start'
  );

  $table_name = $wpdb->prefix.'affiliate_wp_referrals_chip';
  $wpdb->insert($table_name, $data);

  if ($wpdb->last_error) {
    return new WP_Error( 'error_duplicate', __( 'Duplicate send request has been sent', 'chip-for-affiliatewp' ) );
  }

  $mode = 'live';
  $url = 'https://api.chip-in.asia';
  if (affiliate_wp()->settings->get('chip_test_mode')) {
    $mode = 'test';
    $url = 'https://staging-api.chip-in.asia';
  }

  $api_key = affiliate_wp()->settings->get('chip_'.$mode.'_api_key');
  $secret_key = affiliate_wp()->settings->get('chip_'.$mode.'_secret_key');

  $reference_prefix = substr(affiliate_wp()->settings->get('chip_reference_prefix'), 0, 2);

  $epoch = time();

  $str = $epoch . $api_key;
  $hmac = hash_hmac( 'sha512', $str, $secret_key );

  $endpoint = $url . '/api/send/bank_accounts';

  $header = [
    'Content-Type: application/json' , 
    "Authorization: Bearer $api_key",
    "Checksum: $hmac",
    "Epoch: $epoch",
  ];

  $referral = affwp_get_referral( $referral_id );

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

  $body = [
    'account_number' => $payment_account_number,
    'bank_code' => $payment_bank_code,
    'name' => substr(affwp_get_affiliate_name( $referral->affiliate_id ), 0, 128),
  ];

  $wpdb->update($table_name,array('send_status' => 'pending_bank_verification'),array('referral_id' => $referral_id),array('%s'));

  $process = curl_init( $endpoint );
  curl_setopt($process, CURLOPT_HEADER , 0);
  curl_setopt($process, CURLOPT_HTTPHEADER, $header);
  curl_setopt($process, CURLOPT_TIMEOUT, 30);
  curl_setopt($process, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($process, CURLOPT_POSTFIELDS, json_encode($body) );

  $return = curl_exec($process);
  curl_close($process);

  $response = json_decode($return, true);

  if (!isset($response['status'])) {
    $wpdb->delete($table_name,array('referral_id' => $referral_id),array('%d'));
    return new WP_Error( 'error', __( 'There is an error with bank account verification', 'affwp-payouts' ) );
  }

  if ($response['status'] == 'rejected') {
    $wpdb->delete($table_name,array('referral_id' => $referral_id),array('%d'));
    return new WP_Error( 'bank_account_reject', __( 'Bank account has been rejected', 'affwp-payouts' ) );
  }

  if ($response['status'] != 'verified') {
    $wpdb->delete($table_name,array('referral_id' => $referral_id),array('%d'));
    return new WP_Error( 'bank_account_unverified', __( 'Bank account is pending verification', 'affwp-payouts' ) );
  }

  $wpdb->update($table_name,array('send_status' => 'bank_verification_successful'),array('referral_id' => $referral_id),array('%s'));

  $endpoint = $url . '/api/send/send_instructions';

  $email = affwp_get_affiliate_payment_email( $referral->affiliate_id );

  $user = get_userdata($user_id);

  $reference_prefix = substr(affiliate_wp()->settings->get('chip_reference_prefix'), 0, 2);

  $body = [
    'amount' => $referral->amount,
    'bank_account_id' => $response['id'],
    'description' => substr($referral->description,0, 140),
    'email' => $email ?? $user->user_email,
    'reference' => substr($reference_prefix . '-'.$referral->payout_id, 0, 40)
  ];

  $wpdb->update($table_name,array('send_status' => 'pending_send_instruction'),array('referral_id' => $referral_id),array('%s'));

  $process = curl_init( $endpoint );
  curl_setopt($process, CURLOPT_HEADER , 0);
  curl_setopt($process, CURLOPT_HTTPHEADER, $header);
  curl_setopt($process, CURLOPT_TIMEOUT, 30);
  curl_setopt($process, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($process, CURLOPT_POSTFIELDS, json_encode($body) );

  $return = curl_exec($process);
  curl_close($process);

  $response = json_decode($return, true);

  if (!isset($response['state']) OR !in_array($response['state'], ['completed', 'executing'])) {
    $wpdb->delete($table_name,array('referral_id' => $referral_id),array('%d'));
    return new WP_Error( 'send_instruction_failed', __( 'Send instruction failed', 'affwp-payouts' ) );
  }

  $wpdb->update($table_name,array('send_status' => 'completed'),array('referral_id' => $referral_id),array('%s'));

  $send_status = 'paid';

  if ($response['state'] == 'executing' ) {
    $send_status = 'processing';
  }

  if ( function_exists( 'affwp_add_payout' ) ) {
    if ( $referral = affwp_get_referral( $referral_id ) ) {
      affwp_add_payout( array(
        'affiliate_id'  => $referral->affiliate_id,
        'referrals'     => $referral->ID,
        'amount'        => $referral->amount,
        'payout_method' => 'CHIP',
        'service_invoice_link' => $response['receipt_url'],
        'service_id' => $response['id'],
        'service_account' => "CHIP Send Balance",
        'description' => "Payment to: $payment_account_number ($payment_bank_code). ID: {$response['id']}",
        'status' => $send_status,
      ) );
    }
  } else {
    affwp_set_referral_status( $referral_id, 'paid' );
  }

  return 'completed';
}

add_action( 'admin_notices', 'admin_notices_chip_send'  );

function admin_notices_chip_send() {
  if( empty( $_REQUEST['affwp_notice' ] ) ) {
    return;
  }

  $affiliates  = ! empty( $_REQUEST['affiliate'] ) ? $_REQUEST['affiliate']                        : 0;
  $referral_id = ! empty( $_REQUEST['referral'] )  ? absint( $_REQUEST['referral'] )               : 0;
  $transfer_id = ! empty( $_REQUEST['transfer'] )  ? sanitize_text_field( $_REQUEST['transfer'] )  : '';
  $message     = ! empty( $_REQUEST['message'] )   ? urldecode( $_REQUEST['message'] )             : '';
  $code        = ! empty( $_REQUEST['code'] )      ? urldecode( $_REQUEST['code'] ) . ' '          : '';

  switch( $_REQUEST['affwp_notice'] ) {

    case 'chip_success' :

      echo '<div class="updated"><p>' . sprintf( __( 'Referral #%d paid out via CHIP successfully', 'affwp-chip-payouts' ), $referral_id, $transfer_id, $transfer_id ) . '</p></div>';
      break;

    case 'chip_error' :

      echo '<div class="error"><p><strong>' . __( 'Error:', 'affwp-chip-payouts' ) . '</strong>&nbsp;' . $code . esc_html( $message ) . '</p></div>';
      break;

  }
}

add_action( 'affwp_process_payout_chip', 'process_bulk_chip_payout', 10, 5 );

/**
	 * Payout referrals in bulk for a specified timeframe via CHIP.
	 *
	 *
	 * @param string $start         Referrals start date.
	 * @param string $end           Referrals end date data.
	 * @param float  $minimum       Minimum payout.
	 * @param int    $affiliate_id  Affiliate ID.
	 * @param string $payout_method Payout method.
	 *
	 * @return void
	 */
function process_bulk_chip_payout( $start, $end, $minimum, $affiliate_id, $payout_method ) {

		if ( ! current_user_can( 'manage_payouts' ) ) {
			wp_die( __( 'You do not have permission to process payouts', 'affwp-chip-payouts' ) );
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
				'affwp_notice' => 'chip_bulk_pay_success',
        'message'      => 'Bulk payment initiated. It will processed by batch.',
			);

      wp_schedule_single_event( time(), 'chip_schedule_bulk_payment', array($payouts) );

			// if ( is_wp_error( $success ) ) {

			// 	$redirect_args['affwp_notice'] = 'chip_error';
			// 	$redirect_args['message']      = $success->get_error_message();
			// 	$redirect_args['code']         = $success->get_error_code();

			// } else {

			// 	foreach ( $payouts as $affiliate_id => $payout ) {
			// 		if ( function_exists( 'affwp_add_payout' ) ) {
			// 			affwp_add_payout( array(
			// 				'affiliate_id'  => $affiliate_id,
			// 				'referrals'     => $payout['referrals'],
			// 				'amount'        => $payout['amount'],
			// 				'payout_method' => 'chip',
			// 			) );
			// 		} else {
			// 			foreach ( $payout['referrals'] as $referral ) {
			// 				affwp_set_referral_status( $referral, 'paid' );
			// 			}
			// 		}
			// 	}

			// }

			$redirect = affwp_admin_url( 'referrals', $redirect_args );

			// A header is used here instead of wp_redirect() due to the esc_url() bug that removes [] from URLs.
			header( 'Location:' . $redirect );
			exit;

		}

}

add_action( 'chip_schedule_bulk_payment', 'chip_schedule_bulk_payment', 10, 1 );
function chip_schedule_bulk_payment( $payouts ) {
  foreach( $payouts as $affiliate_id => $payout ) {
    wp_schedule_single_event( time(), 'chip_send_bulk_payment', array($affiliate_id, $payout) );
  }
}

add_action( 'chip_send_bulk_payment', 'chip_send_bulk_payment', 10, 2 );
function chip_send_bulk_payment($affiliate_id, $payout) {

  global $wpdb;

  $table_name = $wpdb->prefix.'affiliate_wp_referrals_chip';

  $placeholders = implode(',', array_fill(0, count($payout['referrals']), '%d'));

  $query = $wpdb->prepare("SELECT * FROM your_table_name WHERE column_a IN ($placeholders)",...$payout['referrals']);

  $row = $wpdb->get_row($query);

  if ($row) {
    return;
  }

  foreach($payout['referrals'] as $ref_id) {
    $data = array(
      'referral_id' => $ref_id,
      'send_status' => 'pending_start'
    );

    $table_name = $wpdb->prefix.'affiliate_wp_referrals_chip';
    $wpdb->insert($table_name, $data);
  
    if ($wpdb->last_error) {
      return new WP_Error( 'error_duplicate', __( 'Duplicate send request has been sent', 'chip-for-affiliatewp' ) );
    }
  }

  $user_id = affwp_get_affiliate_user_id( $affiliate_id );

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

  $body = [
    'account_number' => $payment_account_number,
    'bank_code' => $payment_bank_code,
    'name' => substr(affwp_get_affiliate_name( $affiliate_id ), 0, 128),
  ];

  foreach($payout['referrals'] as $ref_id) {
    $wpdb->update($table_name,array('send_status' => 'pending_bank_verification'),array('referral_id' => $ref_id),array('%s'));
  }

  $mode = 'live';
  $url = 'https://api.chip-in.asia';
  if (affiliate_wp()->settings->get('chip_test_mode')) {
    $mode = 'test';
    $url = 'https://staging-api.chip-in.asia';
  }

  $api_key = affiliate_wp()->settings->get('chip_'.$mode.'_api_key');
  $secret_key = affiliate_wp()->settings->get('chip_'.$mode.'_secret_key');

  $epoch = time();

  $str = $epoch . $api_key;
  $hmac = hash_hmac( 'sha512', $str, $secret_key );

  $endpoint = $url . '/api/send/bank_accounts';

  $header = [
    'Content-Type: application/json' , 
    "Authorization: Bearer $api_key",
    "Checksum: $hmac",
    "Epoch: $epoch",
  ];

  $process = curl_init( $endpoint );
  curl_setopt($process, CURLOPT_HEADER , 0);
  curl_setopt($process, CURLOPT_HTTPHEADER, $header);
  curl_setopt($process, CURLOPT_TIMEOUT, 30);
  curl_setopt($process, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($process, CURLOPT_POSTFIELDS, json_encode($body) );

  $return = curl_exec($process);
  curl_close($process);

  $response = json_decode($return, true);

  if (!isset($response['status'])) {
    foreach($payout['referrals'] as $ref_id) {
      $wpdb->delete($table_name,array('referral_id' => $ref_id),array('%d'));
    }
    return new WP_Error( 'error', __( 'There is an error with bank account verification', 'affwp-payouts' ) );
  }

  if ($response['status'] == 'rejected') {
    foreach($payout['referrals'] as $ref_id) {
      $wpdb->delete($table_name,array('referral_id' => $ref_id),array('%d'));
    }
    return new WP_Error( 'bank_account_reject', __( 'Bank account has been rejected', 'affwp-payouts' ) );
  }

  if ($response['status'] != 'verified') {
    foreach($payout['referrals'] as $ref_id) {
      $wpdb->delete($table_name,array('referral_id' => $ref_id),array('%d'));
    }
    return new WP_Error( 'bank_account_unverified', __( 'Bank account is pending verification', 'affwp-payouts' ) );
  }

  foreach($payout['referrals'] as $ref_id) {
    $wpdb->update($table_name,array('send_status' => 'bank_verification_successful'),array('referral_id' => $ref_id),array('%s'));
  }

  $endpoint = $url . '/api/send/send_instructions';
  $reference_prefix = substr(affiliate_wp()->settings->get('chip_reference_prefix'), 0, 2);

  $body = [
    'amount' => $payout['amount'],
    'bank_account_id' => $response['id'],
    'description' => substr($payout['description'],0, 140),
    'email' => $payout['email'],
    'reference' => substr($reference_prefix . '-'.implode("|", $payout['referrals']), 0, 40)
  ];

  foreach($payout['referrals'] as $ref_id) {
    $wpdb->update($table_name,array('send_status' => 'pending_send_instruction'),array('referral_id' => $ref_id),array('%s'));
  }

  $process = curl_init( $endpoint );
  curl_setopt($process, CURLOPT_HEADER , 0);
  curl_setopt($process, CURLOPT_HTTPHEADER, $header);
  curl_setopt($process, CURLOPT_TIMEOUT, 30);
  curl_setopt($process, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($process, CURLOPT_POSTFIELDS, json_encode($body) );

  $return = curl_exec($process);
  curl_close($process);

  $response = json_decode($return, true);

  if (!isset($response['state']) OR !in_array($response['state'], ['completed', 'executing'])) {
    foreach($payout['referrals'] as $ref_id) {
      $wpdb->delete($table_name,array('referral_id' => $ref_id),array('%d'));
    }
    return new WP_Error( 'send_instruction_failed', __( 'Send instruction failed', 'affwp-payouts' ) );
  }

  foreach($payout['referrals'] as $ref_id) {
    $wpdb->update($table_name,array('send_status' => 'completed'),array('referral_id' => $ref_id),array('%s'));
  }

  $send_status = 'paid';

  if ($response['state'] == 'executing' ) {
    $send_status = 'processing';
  }

  if ( function_exists( 'affwp_add_payout' ) ) {
    affwp_add_payout( array(
      'affiliate_id'  => $affiliate_id,
      'referrals'     => $payout['referrals'],
      'amount'        => $payout['amount'],
      'payout_method' => 'CHIP',
      'service_invoice_link' => $response['receipt_url'],
      'service_id' => $response['id'],
      'service_account' => "CHIP Send Balance",
      'description' => "Payment to: $payment_account_number ($payment_bank_code). ID: {$response['id']}",
      'status' => $send_status,
    ) );
  } else {
    foreach ( $payout['referrals'] as $referral ) {
      affwp_set_referral_status( $referral, 'paid' );
    }
  }
}

register_activation_hook(__FILE__,'chip_for_affiliatewp_create_table');

function chip_for_affiliatewp_create_table() {
  require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

  global $wpdb;

  $table_name = $wpdb->prefix . 'affiliate_wp_referrals_chip';

  $create_ddl = "CREATE TABLE $table_name (
    referral_id BIGINT(20) NOT NULL AUTO_INCREMENT,
    send_status VARCHAR(255) NOT NULL,
    PRIMARY KEY (referral_id)
  );";

  // Create the table if it doesn't exist
  maybe_create_table($table_name, $create_ddl);
}