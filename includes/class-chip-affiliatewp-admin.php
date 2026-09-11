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
 * Registers CHIP Send as a payment-method card on the Payouts tab.
 *
 * AffiliateWP 2.29+ renders each payout provider from the
 * AffiliateWP_Payment_Methods registry, populated on the
 * `affwp_register_payment_methods` action. Registering here makes CHIP Send
 * appear exactly like the bundled Stripe / PayPal cards — same status badge,
 * same Configure button, same collapsible settings panel.
 *
 * The card status mirrors the state a merchant can act on:
 * - active          → method enabled and credentials present
 * - setup_required  → enabled but credentials missing
 * - available       → not enabled yet
 *
 * @return void
 */
function chip_affiliatewp_register_payment_method_card() {
	if ( ! class_exists( 'AffiliateWP_Payment_Methods' ) ) {
		return;
	}

	$enabled   = (bool) affiliate_wp()->settings->get( 'chip_payouts' );
	$has_creds = chip_affiliatewp_has_credentials();
	$test_mode = (bool) affiliate_wp()->settings->get( 'chip_test_mode' );

	/*
	 * Card status follows the same vocabulary as the bundled methods:
	 * 'active' shows the mode badge, 'setup_required' keeps the Configure
	 * button available while the method is off or unconfigured. The core
	 * badge lookup only knows the bundled method ids, so 'setup_required'
	 * renders without a misleading badge for this method.
	 */
	$status = ( $enabled && $has_creds ) ? 'active' : 'setup_required';

	$config = array(
		'name'              => __( 'CHIP Send', 'chip-for-affiliatewp' ),
		'description'       => __( 'Pay affiliate commissions straight to Malaysian bank accounts', 'chip-for-affiliatewp' ),
		'icon'              => CHIP_AFFILIATEWP_URL . 'assets/logo.svg',
		'status'            => $status,
		'type'              => 'addon',
		'settings_callback' => 'chip_affiliatewp_render_settings_panel',
		'has_new_settings'  => true,
	);

	if ( 'active' === $status ) {
		$config['status_label'] = $test_mode
			? __( 'Test Mode', 'chip-for-affiliatewp' )
			: __( 'Live Mode', 'chip-for-affiliatewp' );
	}

	AffiliateWP_Payment_Methods::register( 'chip', $config );
}
add_action( 'affwp_register_payment_methods', 'chip_affiliatewp_register_payment_method_card' );

/**
 * Renders a labelled field using the native AffiliateWP input component.
 *
 * Falls back to plain markup when the component library is unavailable
 * (older AffiliateWP releases), so the panel still renders correctly.
 *
 * @param array $args {
 *     @type string $name   Input name attribute.
 *     @type string $label  Visible field label.
 *     @type string $desc   Optional help text.
 *     @type string $value  Current value.
 *     @type bool   $secret Credential-style input hints.
 *     @type string $width  'full' | 'narrow' | 'auto'.
 * }
 * @return void
 */
function chip_affiliatewp_ui_input( $args ) {
	$args = wp_parse_args(
		$args,
		array(
			'name'   => '',
			'label'  => '',
			'desc'   => '',
			'value'  => '',
			'secret' => false,
			'width'  => 'full',
		)
	);

	if ( function_exists( 'affwp_input' ) ) {
		if ( '' !== $args['label'] ) {
			printf(
				'<label for="%1$s" class="block mb-1 text-sm font-medium text-gray-900">%2$s</label>',
				esc_attr( $args['name'] ),
				esc_html( $args['label'] )
			);
		}

		affwp_input(
			array(
				'name'   => $args['name'],
				'value'  => $args['value'],
				'type'   => 'text',
				'secret' => (bool) $args['secret'],
				'width'  => $args['width'],
			)
		);

		if ( '' !== $args['desc'] ) {
			printf( '<p class="mt-1 text-xs text-gray-600">%s</p>', esc_html( $args['desc'] ) );
		}

		return;
	}

	printf(
		'<table class="form-table"><tr><th scope="row"><label for="%1$s">%2$s</label></th><td>'
			. '<input type="%3$s" id="%1$s" name="%1$s" value="%4$s" class="regular-text" />'
			. '%5$s</td></tr></table>',
		esc_attr( $args['name'] ),
		esc_html( $args['label'] ),
		$args['secret'] ? 'password' : 'text',
		esc_attr( $args['value'] ),
		'' !== $args['desc'] ? '<p class="description">' . esc_html( $args['desc'] ) . '</p>' : ''
	);
}

/**
 * Renders the CHIP Send settings panel inside its Payouts-tab card.
 *
 * Uses the native AffiliateWP UI components so the panel matches the
 * Stripe / PayPal panels. Fields post as `affwp_settings[...]` inside the
 * Payouts tab form and are stored by the standard settings save path.
 *
 * The enable toggle lives here rather than on the card row: the card template
 * renders toggles only for its bundled method ids, so a third-party method
 * exposes its on/off switch inside its own settings panel.
 *
 * @return void
 */
function chip_affiliatewp_render_settings_panel() {
	$enabled   = (bool) affiliate_wp()->settings->get( 'chip_payouts' );
	$test_mode = (bool) affiliate_wp()->settings->get( 'chip_test_mode' );

	if ( function_exists( 'affwp_toggle' ) ) {
		/*
		 * Hidden 0-value companion: an unchecked checkbox posts nothing, and
		 * the Payouts tab has no declared settings array, so the generic
		 * sanitizer never coerces checkbox values. The explicit 0 keeps the
		 * toggle switchable back off.
		 */
		printf( '<input type="hidden" name="affwp_settings[chip_payouts]" value="0" />' );
		affwp_toggle(
			array(
				'name'    => 'affwp_settings[chip_payouts]',
				'label'   => __( 'Enable CHIP Send', 'chip-for-affiliatewp' ),
				'checked' => $enabled,
				'color'   => 'blue',
			)
		);
	}

	$input = 'chip_affiliatewp_ui_input';
	$input(
		array(
			'name'  => 'affwp_settings[chip_live_api_key]',
			'label' => __( 'Live API Key', 'chip-for-affiliatewp' ),
			'desc'  => __( 'Found in the CHIP portal under Control → Settings → Applications.', 'chip-for-affiliatewp' ),
			'value' => (string) affiliate_wp()->settings->get( 'chip_live_api_key' ),
		)
	);

	$input(
		array(
			'name'   => 'affwp_settings[chip_live_secret_key]',
			'label'  => __( 'Live Secret Key', 'chip-for-affiliatewp' ),
			'desc'   => __( 'Used only for signing requests on your server; it is never sent to CHIP.', 'chip-for-affiliatewp' ),
			'value'  => (string) affiliate_wp()->settings->get( 'chip_live_secret_key' ),
			'secret' => true,
		)
	);

	$input(
		array(
			'name'  => 'affwp_settings[chip_test_api_key]',
			'label' => __( 'Test API Key', 'chip-for-affiliatewp' ),
			'value' => (string) affiliate_wp()->settings->get( 'chip_test_api_key' ),
		)
	);

	$input(
		array(
			'name'   => 'affwp_settings[chip_test_secret_key]',
			'label'  => __( 'Test Secret Key', 'chip-for-affiliatewp' ),
			'value'  => (string) affiliate_wp()->settings->get( 'chip_test_secret_key' ),
			'secret' => true,
		)
	);

	$input(
		array(
			'name'  => 'affwp_settings[chip_reference_prefix]',
			'label' => __( 'Reference Prefix', 'chip-for-affiliatewp' ),
			'desc'  => __( 'Two characters used to prefix CHIP Send references.', 'chip-for-affiliatewp' ),
			'value' => (string) chip_affiliatewp_reference_prefix(),
			'width' => 'narrow',
		)
	);

	if ( function_exists( 'affwp_toggle' ) ) {
		/*
		 * Hidden 0-value companions: an unchecked checkbox posts nothing, and
		 * the settings sanitizer only coerces values for keys it knows about,
		 * so the explicit 0 keeps the toggle switchable back off.
		 */
		printf( '<input type="hidden" name="affwp_settings[chip_test_mode]" value="0" />' );
		affwp_toggle(
			array(
				'name'    => 'affwp_settings[chip_test_mode]',
				'label'   => __( 'Test Mode', 'chip-for-affiliatewp' ),
				'checked' => $test_mode,
				'color'   => 'blue',
			)
		);

		printf( '<input type="hidden" name="affwp_settings[chip_send_recipient_receipt]" value="0" />' );
		affwp_toggle(
			array(
				'name'    => 'affwp_settings[chip_send_recipient_receipt]',
				'label'   => __( 'Email the affiliate a CHIP receipt on every payout', 'chip-for-affiliatewp' ),
				'checked' => (bool) affiliate_wp()->settings->get( 'chip_send_recipient_receipt' ),
				'color'   => 'blue',
			)
		);
	}

	if ( function_exists( 'affwp_callout' ) ) {
		$webhook_url = chip_affiliatewp_webhook_url();
		$configured  = chip_affiliatewp_webhook_configured();

		affwp_callout(
			array(
				'tone'    => $configured ? 'info' : 'warning',
				'heading' => $configured
					? __( 'Webhook connected', 'chip-for-affiliatewp' )
					: __( 'Webhook not set up yet', 'chip-for-affiliatewp' ),
				'content' => $configured
					? __( 'CHIP Send delivers payout status updates to this site and every delivery is verified against the webhook public key.', 'chip-for-affiliatewp' )
					: __( 'Payouts still settle — statuses are requeried hourly — but confirmations arrive faster with the webhook. Save your credentials to register it automatically.', 'chip-for-affiliatewp' ),
			)
		);

		if ( function_exists( 'affwp_copy_button' ) ) {
			affwp_copy_button(
				array(
					'content'     => $webhook_url,
					'button_text' => __( 'Copy webhook URL', 'chip-for-affiliatewp' ),
					'variant'     => 'secondary',
				)
			);
		}

		unset( $webhook_url, $configured );
	}

	unset( $enabled, $test_mode );
}

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
 * Reports per-affiliate readiness for CHIP Send in the Payouts preview.
 *
 * AffiliateWP asks each method whether an affiliate can actually be paid
 * before rendering the batch preview rows. Without an answer here the core
 * default is 'ready', which would promise a payout that fails later because
 * the affiliate has no bank details on file. Reporting the real state means
 * the preview shows the same "not connected" badge Stripe / PayPal produce.
 *
 * @param string $status       Preflight status: 'ready', 'invite', or 'blocked'.
 * @param string $method       Payout method key.
 * @param int    $affiliate_id Affiliate ID.
 * @return string
 */
function chip_affiliatewp_preflight_payout_status( $status, $method, $affiliate_id ) {
	if ( 'chip' !== $method ) {
		return $status;
	}

	$details = chip_affiliatewp_get_bank_details( $affiliate_id );

	if ( '' === $details['account_number'] || '' === $details['bank_code'] ) {
		return 'blocked';
	}

	return 'ready';
}
add_filter( 'affwp_preflight_payout_status', 'chip_affiliatewp_preflight_payout_status', 10, 3 );

/**
 * Appends a bank-details readiness indicator to the Affiliates list.
 *
 * The bundled Stripe method annotates the payout-method column with a status
 * dot so an admin can see at a glance who is payable. CHIP Send mirrors that
 * using the same core indicator helper: green when the affiliate has bank
 * details on file, grey (with a tooltip) when they cannot be paid yet.
 *
 * @param string $value     Rendered payout-method cell value.
 * @param object $affiliate Affiliate row object.
 * @return string
 */
function chip_affiliatewp_affiliate_table_payout_method( $value, $affiliate ) {
	if ( ! function_exists( 'affwp_render_payout_method_status_indicator' ) ) {
		return $value;
	}

	if ( ! isset( $affiliate->affiliate_id ) ) {
		return $value;
	}

	if ( function_exists( 'affwp_get_affiliate_effective_method' )
		&& 'chip' !== affwp_get_affiliate_effective_method( $affiliate ) ) {
		return $value;
	}

	$details = chip_affiliatewp_get_bank_details( (int) $affiliate->affiliate_id );
	$ready   = '' !== $details['account_number'] && '' !== $details['bank_code'];

	$indicator = affwp_render_payout_method_status_indicator(
		array(
			'dot_class' => $ready ? 'bg-green-500' : 'bg-gray-300',
			'label'     => $ready
				? __( 'Ready', 'chip-for-affiliatewp' )
				: __( 'No bank details', 'chip-for-affiliatewp' ),
			'tooltip'   => array(
				/* translators: %s: payout method name. */
				'title'   => sprintf( __( '%s: bank details', 'chip-for-affiliatewp' ), __( 'CHIP Send', 'chip-for-affiliatewp' ) ),
				'content' => $ready
					? __( 'This affiliate has bank details on file and can be paid via CHIP Send.', 'chip-for-affiliatewp' )
					: __( 'This affiliate has no bank code or account number on file, so a CHIP Send payout would fail.', 'chip-for-affiliatewp' ),
				'type'    => $ready ? 'success' : 'info',
			),
		)
	);

	return sprintf( '<span class="inline-flex items-center gap-2">%1$s%2$s</span>', $value, $indicator );
}
add_filter( 'affwp_affiliate_table_payout_method', 'chip_affiliatewp_affiliate_table_payout_method', 15, 2 );

/**
 * Adds a note to the CHIP Send section of the batch payout preview.
 *
 * Mirrors the per-method notes the bundled providers render (for example
 * PayPal's invite explanation) so the preview explains CHIP Send's behaviour
 * in the same place and the same style.
 *
 * @param array $method_data Method section data from the preview renderer.
 * @return void
 */
function chip_affiliatewp_preview_payout_note( $method_data ) {
	unset( $method_data );

	$test_mode = (bool) affiliate_wp()->settings->get( 'chip_test_mode' );

	if ( function_exists( 'affwp_callout' ) ) {
		affwp_callout(
			array(
				'tone'    => 'info',
				'content' => $test_mode
					? __( 'CHIP Send is in Test Mode — payouts go to the CHIP Send staging environment and no real money moves.', 'chip-for-affiliatewp' )
					: __( 'CHIP Send pays each affiliate directly to their bank account. Payouts stay in Processing until CHIP confirms the transfer, then the referral is marked Paid.', 'chip-for-affiliatewp' ),
			)
		);

		return;
	}

	printf(
		'<p class="description">%s</p>',
		esc_html(
			$test_mode
				? __( 'CHIP Send is in Test Mode — payouts go to the CHIP Send staging environment and no real money moves.', 'chip-for-affiliatewp' )
				: __( 'CHIP Send pays each affiliate directly to their bank account. Payouts stay in Processing until CHIP confirms the transfer, then the referral is marked Paid.', 'chip-for-affiliatewp' )
		)
	);
}
add_action( 'affwp_preview_payout_note_chip', 'chip_affiliatewp_preview_payout_note' );

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
 * Registers the CHIP Send settings so the save path sanitizes them by type.
 *
 * The Payouts tab renders custom card content rather than a generic settings
 * list, so there is no `affwp_settings_payouts` array in core to extend. The
 * keys are declared here anyway — through the standard
 * `affwp_settings_payouts_sanitize` filter the tab applies — so text fields
 * get `sanitize_text_field` treatment and the plugin reads predictable shapes.
 *
 * @param array $input Submitted Payouts-tab settings.
 * @return array
 */
function chip_affiliatewp_sanitize_settings( $input ) {
	if ( ! is_array( $input ) ) {
		return $input;
	}

	$chip_keys = array(
		'chip_payouts',
		'chip_test_mode',
		'chip_live_api_key',
		'chip_live_secret_key',
		'chip_test_api_key',
		'chip_test_secret_key',
		'chip_reference_prefix',
		'chip_send_recipient_receipt',
		'chip_webhook_public_key',
	);

	foreach ( $chip_keys as $key ) {
		if ( ! isset( $input[ $key ] ) ) {
			continue;
		}

		if ( in_array( $key, array( 'chip_payouts', 'chip_test_mode', 'chip_send_recipient_receipt' ), true ) ) {
			$input[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;

			continue;
		}

		if ( 'chip_webhook_public_key' === $key ) {
			$input[ $key ] = trim( (string) $input[ $key ] );

			continue;
		}

		if ( 'chip_reference_prefix' === $key ) {
			$prefix = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $input[ $key ] ) );

			$input[ $key ] = substr( $prefix, 0, 2 );

			continue;
		}

		// Credentials: plain text, never re-encoded.
		$input[ $key ] = sanitize_text_field( (string) $input[ $key ] );
	}

	return $input;
}
add_filter( 'affwp_settings_payouts_sanitize', 'chip_affiliatewp_sanitize_settings' );

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
