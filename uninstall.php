<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    die;
}

global $wpdb;
$table_name = $wpdb->prefix . 'affiliate_wp_referrals_chip';
$wpdb->query("DROP TABLE IF EXISTS $table_name");

delete_option('chip_send_test_balance');
delete_option('chip_send_live_balance');