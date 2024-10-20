<?php

class Chip_For_Affiliatewp_Cron {

  private $plugin_name;
  private $version;
  public function __construct( $plugin_name, $version ) {

    $this->plugin_name = $plugin_name;
    $this->version = $version;

  }

  public function schedule_bulk_payment( $payouts ) {

    foreach( $payouts as $affiliate_id => $payout ) {
      wp_schedule_single_event( time(), 'chip_send_bulk_payment', array( $affiliate_id, $payout ) );
    }

  }

  public function send_bulk_payment( $affiliate_id, $payout ) {

    Chip_For_Affiliatewp_Helper::send_bulk_payment( $affiliate_id, $payout );

  }

}