<?php

class Chip_For_Affiliatewp_Helper {

  public static function get_current_chip_send_mode( $synonym = false ) {
    
    $mode = 'live';
    if ( affiliate_wp()->settings->get( 'chip_test_mode' ) ) {
      $mode = 'test';
    }

    if ( $synonym ) {
      $mode = $mode == 'live' ? 'production' : 'staging';
    }

    return $mode;

  }
  public static function get_chip_configuration( $configuration_name ) {
    
    if ( $configuration_name == 'reference_prefix' ) {
      $configuration_name = 'chip_reference_prefix';
    } else {
      $configuration_name = 'chip_' . self::get_current_chip_send_mode() . "_{$configuration_name}";
    }
    return affiliate_wp()->settings->get( $configuration_name );

  }
  public static function chip_has_api_credentials(): bool {
    
    $api_key = self::get_chip_configuration( 'api_key' );
    $secret_key = self::get_chip_configuration('secret_key');

    if ( empty( $api_key ) OR empty( $secret_key ) ) {
      return false;
    }
  
    return true;

  }
  public static function chip_send_pay_referral( $referral_id ) {
    
    if ( !self::chip_has_api_credentials() ) {
      return;
    }
  
    global $wpdb;
  
    $data = array(
      'referral_id' => $referral_id,
      'send_status' => 'pending_start'
    );
  
    $table_name = $wpdb->prefix.'affiliate_wp_referrals_chip';
  
    if ( !$wpdb->insert( $table_name, $data ) ) {
      return new WP_Error( 'error_duplicate', __( 'Duplicate send request has been sent', 'chip-for-affiliatewp' ) );
    }
  
    $reference_prefix = substr(affiliate_wp()->settings->get('chip_reference_prefix'), 0, 2);
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

    $api_key = self::get_chip_configuration( 'api_key' );
    $secret_key = self::get_chip_configuration( 'secret_key' );

    $chip_send_api = new Chip_For_Affiliatewp_Send_Api($api_key, $secret_key, self::get_current_chip_send_mode(true));
    $response = $chip_send_api->create_bank_account($body);

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
  
    $email = affwp_get_affiliate_payment_email( $referral->affiliate_id );
  
    $user = get_userdata($user_id);
  
    $reference_prefix = substr(affiliate_wp()->settings->get('chip_reference_prefix'), 0, 2);
  
    $body = [
      'amount' => $referral->amount,
      'bank_account_id' => $response['id'],
      'description' => substr($referral->description,0, 140),
      'email' => $email ?? $user->user_email,
      'reference' => substr($reference_prefix . '-'.$referral_id, 0, 40)
    ];
  
    $wpdb->update($table_name,array('send_status' => 'pending_send_instruction'),array('referral_id' => $referral_id),array('%s'));
  
    $response = $chip_send_api->create_send_instruction($body);
  
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

  public static function send_bulk_payment( $affiliate_id, $payout ) {

    global $wpdb;

    $table_name = $wpdb->prefix.'affiliate_wp_referrals_chip';

    $placeholders = implode( ',', array_fill( 0, count( $payout['referrals']), '%d' ) );

    $query = $wpdb->prepare( "SELECT * FROM your_table_name WHERE column_a IN ($placeholders)",...$payout['referrals'] );

    $row = $wpdb->get_row( $query );

    if ( $row ) {
      return;
    }

    foreach( $payout['referrals'] as $ref_id ) {
      $data = array(
        'referral_id' => $ref_id,
        'send_status' => 'pending_start'
      );

      $table_name = $wpdb->prefix.'affiliate_wp_referrals_chip';
      if ( ! $wpdb->insert( $table_name, $data ) ) {
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

    foreach( $payout['referrals'] as $ref_id ) {
      $wpdb->update( $table_name, array( 'send_status' => 'pending_bank_verification' ), array( 'referral_id' => $ref_id ), array( '%s' ) );
    }

    $api_key = self::get_chip_configuration( 'api_key' );
    $secret_key = self::get_chip_configuration( 'secret_key' );

    $chip_send_api = new Chip_For_Affiliatewp_Send_Api($api_key, $secret_key, self::get_current_chip_send_mode(true));
    $response = $chip_send_api->create_bank_account($body);

    if ( ! isset( $response['status'] ) ) {
      foreach( $payout['referrals'] as $ref_id ) {
        $wpdb->delete( $table_name, array( 'referral_id' => $ref_id), array( '%d' ) );
      }
      return new WP_Error( 'error', __( 'There is an error with bank account verification', 'affwp-payouts' ) );
    }

    if ( $response['status'] == 'rejected' ) {
      foreach( $payout['referrals'] as $ref_id ) {
        $wpdb->delete( $table_name, array( 'referral_id' => $ref_id ), array( '%d' ) );
      }
      return new WP_Error( 'bank_account_reject', __( 'Bank account has been rejected', 'affwp-payouts' ) );
    }

    if ( $response['status'] != 'verified' ) {
      foreach( $payout['referrals'] as $ref_id ) {
        $wpdb->delete( $table_name,array( 'referral_id' => $ref_id ), array( '%d' ) );
      }
      return new WP_Error( 'bank_account_unverified', __( 'Bank account is pending verification', 'affwp-payouts' ) );
    }

    foreach( $payout['referrals'] as $ref_id ) {
      $wpdb->update( $table_name, array( 'send_status' => 'bank_verification_successful' ), array('referral_id' => $ref_id), array( '%s' ) );
    }

    $reference_prefix = substr( affiliate_wp()->settings->get( 'chip_reference_prefix' ), 0, 2 );

    $body = [
      'amount' => $payout['amount'],
      'bank_account_id' => $response['id'],
      'description' => substr( $payout['description'], 0, 140 ),
      'email' => $payout['email'],
      'reference' => substr( $reference_prefix . '-'.implode( "|", $payout['referrals'] ), 0, 40 )
    ];

    foreach( $payout['referrals'] as $ref_id ) {
      $wpdb->update( $table_name, array( 'send_status' => 'pending_send_instruction' ), array( 'referral_id' => $ref_id ), array( '%s' ) );
    }

    $response = $chip_send_api->create_send_instruction( $body );

    if ( ! isset( $response['state'] ) OR !in_array( $response['state'], [ 'completed', 'executing' ] ) ) {
      foreach( $payout['referrals'] as $ref_id ) {
        $wpdb->delete( $table_name, array( 'referral_id' => $ref_id ) ,array( '%d' ) );
      }
      return new WP_Error( 'send_instruction_failed', __( 'Send instruction failed', 'affwp-payouts' ) );
    }

    foreach( $payout['referrals'] as $ref_id ) {
      $wpdb->update( $table_name, array( 'send_status' => 'completed' ), array( 'referral_id' => $ref_id ), array( '%s' ) );
    }

    $send_status = 'paid';

    if ( $response['state'] == 'executing' ) {
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

}