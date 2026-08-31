<?php
/**
 * Admin UI: settings section, notices, webhook URL hint.
 *
 * @package CHIPforAffiliateWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Cannot access directly.

/**
 * Registers the CHIP payout method label.
 *
 * @param array $payout_methods Registered payout methods.
 * @return array
 */
function chip_affiliatewp_register_payout_method( $payout_methods ) {
	if ( affiliate_wp()->settings->get( 'chip_payouts' ) ) {
		$payout_methods['chip'] = __( 'CHIP Send', 'chip-for-affiliatewp' );
	}

	return $payout_methods;
}
add_filter( 'affwp_payout_methods', 'chip_affiliatewp_register_payout_method' );

/**
 * Forces a "processing" initial status for CHIP batch payouts.
 *
 * CHIP Send instructions settle asynchronously; referrals must stay unpaid
 * until CHIP confirms completion via webhook or requery.
 *
 * @param string $status        Default initial status.
 * @param string $payout_method Payout method identifier.
 * @return string
 */
function chip_affiliatewp_batch_initial_status( $status, $payout_method ) {
	if ( 'chip' === $payout_method && chip_affiliatewp_has_credentials() ) {
		return 'processing';
	}

	return $status;
}
add_filter( 'affwp_batch_payout_initial_status', 'chip_affiliatewp_batch_initial_status', 10, 2 );

/**
 * Submits CHIP payouts once the payout batch completes.
 */
add_action( 'affwp_batch_generate_payouts_completed', 'chip_affiliatewp_process_generated_batch' );

/**
 * Registers the single-referral payout handler for the "chip" method.
 *
 * @param array $handlers Map of payout-method slug => callable.
 * @return array
 */
function chip_affiliatewp_register_single_referral_handler( $handlers ) {
	if ( affiliate_wp()->settings->get( 'chip_payouts' ) ) {
		$handlers['chip'] = 'chip_affiliatewp_pay_single_referral';
	}

	return $handlers;
}
add_filter( 'affwp_single_referral_payout_handlers', 'chip_affiliatewp_register_single_referral_handler' );

/**
 * Runs the status requery for a scheduled payout check.
 *
 * @param int $payout_id Payout ID.
 * @return void
 */
function chip_affiliatewp_run_scheduled_check( $payout_id ) {
	chip_affiliatewp_check_payout_status( (int) $payout_id, true );
}
add_action( 'chip_affiliatewp_check_payout_status', 'chip_affiliatewp_run_scheduled_check' );

/**
 * Runs the hourly sweep of processing payouts.
 */
add_action( 'chip_affiliatewp_hourly_sweep', 'chip_affiliatewp_sweep_processing_payouts' );


/**
 * Runs the hourly sweep of processing payouts.
 */
add_action( 'chip_affiliatewp_hourly_sweep', 'chip_affiliatewp_sweep_processing_payouts' );


/**
 * Adds the CHIP Send settings to the Commissions tab.
 *
 * @param array $settings Commissions settings.
 * @return array
 */
function chip_affiliatewp_register_settings( $settings ) {
	$settings['chip_payouts'] = array(
		'name' => __( 'CHIP Send', 'chip-for-affiliatewp' ),
		'desc' => __( 'Enable the CHIP Send payout method', 'chip-for-affiliatewp' ),
		'type' => 'checkbox',
	);

	$settings['chip_test_mode'] = array(
		'name' => __( 'CHIP Send Test Mode', 'chip-for-affiliatewp' ),
		'desc' => __( 'Use the CHIP Send staging environment', 'chip-for-affiliatewp' ),
		'type' => 'checkbox',
	);

	$settings['chip_live_api_key'] = array(
		'name' => __( 'Live API Key', 'chip-for-affiliatewp' ),
		'desc' => __( 'The CHIP Send live API key.', 'chip-for-affiliatewp' ),
		'type' => 'text',
	);

	$settings['chip_live_secret_key'] = array(
		'name' => __( 'Live Secret Key', 'chip-for-affiliatewp' ),
		'desc' => __( 'The CHIP Send live secret key. Used only for signing; it is never sent to CHIP.', 'chip-for-affiliatewp' ),
		'type' => 'text',
	);

	$settings['chip_test_api_key'] = array(
		'name' => __( 'Test API Key', 'chip-for-affiliatewp' ),
		'desc' => __( 'The CHIP Send test API key.', 'chip-for-affiliatewp' ),
		'type' => 'text',
	);

	$settings['chip_test_secret_key'] = array(
		'name' => __( 'Test Secret Key', 'chip-for-affiliatewp' ),
		'desc' => __( 'The CHIP Send test secret key. Used only for signing; it is never sent to CHIP.', 'chip-for-affiliatewp' ),
		'type' => 'text',
	);

	$settings['chip_reference_prefix'] = array(
		'name' => __( 'Reference Prefix', 'chip-for-affiliatewp' ),
		'desc' => __( 'Two characters used to prefix CHIP Send references.', 'chip-for-affiliatewp' ),
		'type' => 'text',
	);

	$settings['chip_send_recipient_receipt'] = array(
		'name' => __( 'Send Recipient Receipt', 'chip-for-affiliatewp' ),
		'desc' => __( 'Email a CHIP receipt to the affiliate on every payout.', 'chip-for-affiliatewp' ),
		'type' => 'checkbox',
	);

	$settings['chip_webhook_public_key'] = array(
		'name' => __( 'Webhook Public Key', 'chip-for-affiliatewp' ),
		'desc' => __( 'PEM public key of the CHIP Send webhook used to verify inbound deliveries. Register the webhook URL shown below in the CHIP portal.', 'chip-for-affiliatewp' ),
		'type' => 'textarea',
	);

	return $settings;
}
add_filter( 'affwp_settings_commissions', 'chip_affiliatewp_register_settings' );

/**
 * Registers the CHIP Send settings section on the Commissions tab.
 *
 * @return void
 */
function chip_affiliatewp_register_settings_section() {
	affiliate_wp()->settings->register_section(
		'commissions',
		'chip_send',
		__( 'CHIP Send Payment Method', 'chip-for-affiliatewp' ),
		apply_filters(
			'affiliatewp_register_section_chip_send',
			array(
				'chip_payouts',
				'chip_test_mode',
				'chip_live_api_key',
				'chip_live_secret_key',
				'chip_test_api_key',
				'chip_test_secret_key',
				'chip_reference_prefix',
				'chip_send_recipient_receipt',
				'chip_webhook_public_key',
			)
		),
		''
	);
}
add_action( 'affiliatewp_after_register_admin_sections', 'chip_affiliatewp_register_settings_section' );

/**
 * Renders the webhook URL beneath the Webhook Public Key setting.
 *
 * @param array $args Setting field args.
 * @return void
 */
function chip_affiliatewp_settings_webhook_url( $args ) {
	unset( $args );
	?>
	<table class="form-table">
		<tr>
			<th scope="row">
				<label><?php esc_html_e( 'Webhook URL', 'chip-for-affiliatewp' ); ?></label>
			</th>
			<td>
				<code><?php echo esc_html( chip_affiliatewp_webhook_url() ); ?></code>
				<p class="description">
					<?php esc_html_e( 'Register this URL as a CHIP Send webhook (event hooks: send_instruction_status). Paste the webhook public key from the CHIP portal into the Webhook Public Key field above.', 'chip-for-affiliatewp' ); ?>
				</p>
				<p class="description">
					<?php esc_html_e( 'Missing a webhook delivery is not fatal: payouts left in processing are requeried hourly from the CHIP Send API.', 'chip-for-affiliatewp' ); ?>
				</p>
			</td>
		</tr>
	</table>
	<?php
}
add_action( 'affwp_after_setting_field_chip_webhook_public_key', 'chip_affiliatewp_settings_webhook_url' );

/**
 * Auto-registers the CHIP Send webhook after settings are saved.
 *
 * Runs on every settings save while CHIP Send is enabled; chip_affiliatewp_ensure_webhook()
 * is idempotent and cheap when everything already matches (one GET). Never
 * fatal: failures are surfaced as an admin notice only.
 *
 * @param array $old_value Previous settings.
 * @param array $new_value New settings.
 * @return void
 */
function chip_affiliatewp_auto_register_webhook( $old_value, $new_value ) {
	unset( $old_value, $new_value );

	if ( ! affiliate_wp()->settings->get( 'chip_payouts' ) || ! chip_affiliatewp_has_credentials() ) {
		return;
	}

	chip_affiliatewp_ensure_webhook();
}
add_action( 'update_option_affwp_settings', 'chip_affiliatewp_auto_register_webhook', 10, 2 );

/**
 * Collects webhook setup problems to show in the admin.
 *
 * @return string[] Human-readable messages; empty when everything is fine.
 */
function chip_affiliatewp_webhook_setup_notices() {
	$notices = array();

	if ( ! affiliate_wp()->settings->get( 'chip_payouts' ) ) {
		return $notices;
	}

	if ( ! chip_affiliatewp_has_credentials() ) {
		$notices[] = __( 'CHIP Send API credentials are not set yet — payouts are disabled until they are configured.', 'chip-for-affiliatewp' );

		return $notices;
	}

	if ( chip_affiliatewp_webhook_configured() ) {
		return $notices;
	}

	$mode = affiliate_wp()->settings->get( 'chip_test_mode' ) ? 'test' : 'live';
	$keys = chip_affiliatewp_webhook_option_keys( $mode );

	$checked = absint( affiliate_wp()->settings->get( $keys['checked'], '' ) );

	if ( $checked && ( time() - $checked ) < HOUR_IN_SECONDS ) {
		// A recent attempt failed; do not retry on every page load.
		return $notices;
	}

	$result = chip_affiliatewp_ensure_webhook();

	if ( ! is_wp_error( $result ) ) {
		return $notices;
	}

	$notices[] = sprintf(
		/* translators: 1: Error message */
		__( 'CHIP Send webhook is not set up yet (%1$s). Payouts still work — statuses are requeried hourly — but confirmations arrive faster with the webhook.', 'chip-for-affiliatewp' ),
		$result->get_error_message()
	);

	return $notices;
}

/**
 * Renders one-time setup notices on AffiliateWP admin screens.
 *
 * @return void
 */
function chip_affiliatewp_admin_notices() {
	if ( ! current_user_can( 'manage_referrals' ) ) {
		return;
	}

	if ( ! affiliate_wp()->settings->get( 'chip_payouts' ) ) {
		return;
	}

	foreach ( chip_affiliatewp_webhook_setup_notices() as $notice ) {
		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			esc_html( $notice )
		);
	}
}
add_action( 'admin_notices', 'chip_affiliatewp_admin_notices' );
