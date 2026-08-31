<?php
/**
 * Plugin Name: CHIP for AffiliateWP
 * Description: Pay affiliate commissions via CHIP Send payouts.
 * Version: 2.0.0
 * Author: Chip In Sdn Bhd
 * Author URI: https://www.chip-in.asia
 * Requires PHP: 7.1
 * Requires at least: 4.7
 *
 * Requires Plugins: affiliate-wp
 *
 * Copyright: © 2024-2026 CHIP
 * License: GNU General Public License v3.0
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package CHIPforAffiliateWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CHIP_AFFILIATEWP_VERSION', '2.0.0' );

/**
 * Returns the CHIP Send API base URL for the current mode.
 *
 * @return string Base URL without trailing slash.
 */
function chip_affiliatewp_api_base_url() {
	if ( affiliate_wp()->settings->get( 'chip_test_mode' ) ) {
		return 'https://staging-api.chip-in.asia/api';
	}

	return 'https://api.chip-in.asia/api';
}

/**
 * Returns the settings key matching the configured environment.
 *
 * @param string $suffix Settings key suffix, e.g. "api_key".
 * @return string Settings key such as "chip_test_api_key".
 */
function chip_affiliatewp_setting_key( $suffix ) {
	$mode = affiliate_wp()->settings->get( 'chip_test_mode' ) ? 'test' : 'live';

	return 'chip_' . $mode . '_' . $suffix;
}

/**
 * Returns the configured API credentials for the current mode.
 *
 * @return array { api_key: string, secret_key: string }
 */
function chip_affiliatewp_credentials() {
	return array(
		'api_key'    => (string) affiliate_wp()->settings->get( chip_affiliatewp_setting_key( 'api_key' ) ),
		'secret_key' => (string) affiliate_wp()->settings->get( chip_affiliatewp_setting_key( 'secret_key' ) ),
	);
}

/**
 * Whether valid-looking CHIP Send credentials are configured.
 *
 * @return bool
 */
function chip_affiliatewp_has_credentials() {
	$credentials = chip_affiliatewp_credentials();

	return '' !== $credentials['api_key'] && '' !== $credentials['secret_key'];
}

/**
 * Reads a value from an array without notices.
 *
 * @param array      $data    Source array.
 * @param string|int $key     Key to read.
 * @param mixed      $default Default value.
 * @return mixed
 */
function chip_affiliatewp_array_value( $data, $key, $default = '' ) {
	return isset( $data[ $key ] ) ? $data[ $key ] : $default;
}

/**
 * Multibyte-safe substring.
 *
 * @param string $text  Input text.
 * @param int    $limit Maximum length.
 * @return string
 */
function chip_affiliatewp_substr( $text, $limit ) {
	if ( function_exists( 'mb_substr' ) ) {
		return mb_substr( $text, 0, $limit );
	}

	return substr( $text, 0, $limit );
}

/**
 * Sends a signed request to the CHIP Send API.
 *
 * Every request carries a fresh epoch and an HMAC-SHA512 checksum of
 * "<epoch><api_key>" signed with the API secret.
 *
 * @param string $method HTTP method, "GET" or "POST".
 * @param string $path   API path beginning with a slash, e.g. "/send/send_instructions".
 * @param array  $body   Optional JSON payload for POST requests.
 * @param array  $query  Optional query parameters.
 * @return array|WP_Error Decoded JSON response, or WP_Error on failure.
 */
function chip_affiliatewp_request( $method, $path, $body = array(), $query = array() ) {
	$credentials = chip_affiliatewp_credentials();

	if ( '' === $credentials['api_key'] || '' === $credentials['secret_key'] ) {
		return new WP_Error( 'chip_missing_credentials', __( 'CHIP Send API credentials are not configured.', 'chip-for-affiliatewp' ) );
	}

	$url = chip_affiliatewp_api_base_url() . $path;

	if ( ! empty( $query ) ) {
		$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
	}

	$epoch    = (string) time();
	$checksum = hash_hmac( 'sha512', $epoch . $credentials['api_key'], $credentials['secret_key'] );

	$args = array(
		'method'  => $method,
		'timeout' => 30,
		'headers' => array(
			'Authorization' => 'Bearer ' . $credentials['api_key'],
			'epoch'         => $epoch,
			'checksum'      => $checksum,
			'Content-Type'  => 'application/json',
		),
	);

	if ( ! empty( $body ) ) {
		$args['body'] = wp_json_encode( $body );
	}

	$response = wp_remote_request( $url, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$raw  = wp_remote_retrieve_body( $response );
	$data = json_decode( $raw, true );

	if ( $code < 200 || $code >= 300 ) {
		$detail = '';

		if ( is_array( $data ) ) {
			foreach ( array( 'message', 'error', 'errors' ) as $key ) {
				if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
					$detail = $data[ $key ];
					break;
				}
			}
		}

		return new WP_Error(
			'chip_api_error',
			sprintf(
				/* translators: 1: HTTP status code, 2: API error detail */
				__( 'CHIP Send API error (HTTP %1$s): %2$s', 'chip-for-affiliatewp' ),
				$code,
				'' !== $detail ? $detail : __( 'request failed', 'chip-for-affiliatewp' )
			)
		);
	}

	if ( ! is_array( $data ) ) {
		return new WP_Error( 'chip_api_invalid_response', __( 'CHIP Send API returned an unexpected response.', 'chip-for-affiliatewp' ) );
	}

	return $data;
}

/**
 * Reads the affiliate's saved payout bank details.
 *
 * @param int $affiliate_id Affiliate ID.
 * @return array { account_number: string, bank_code: string }
 */
function chip_affiliatewp_get_bank_details( $affiliate_id ) {
	$user_id = affwp_get_affiliate_user_id( $affiliate_id );

	return array(
		'account_number' => (string) get_user_meta( $user_id, 'payment_account_number', true ),
		'bank_code'      => (string) get_user_meta( $user_id, 'payment_bank_code', true ),
	);
}

/**
 * Returns the two-character reference prefix setting, sanitized.
 *
 * @return string
 */
function chip_affiliatewp_reference_prefix() {
	$prefix = (string) affiliate_wp()->settings->get( 'chip_reference_prefix' );

	return substr( preg_replace( '/[^A-Za-z0-9.-]/', '', $prefix ), 0, 2 );
}

/**
 * Returns a stable per-affiliate bank account reference, derived from the
 * account details themselves.
 *
 * A reference derived from the account number and bank code meansCHIP can
 * reject duplicate submissions for identical recipients, while corrected
 * details produce a fresh reference instead of colliding with a rejected
 * registration.
 *
 * @param int $affiliate_id Affiliate ID.
 * @return string
 */
function chip_affiliatewp_bank_reference( $affiliate_id ) {
	$details = chip_affiliatewp_get_bank_details( $affiliate_id );
	$hash    = substr( md5( $details['bank_code'] . '|' . $details['account_number'] ), 0, 6 );

	return substr( chip_affiliatewp_reference_prefix() . '-AFF-' . $affiliate_id . '-' . $hash, 0, 40 );
}

/**
 * Returns a stable send instruction reference for a payout.
 *
 * @param int $payout_id Payout ID.
 * @return string
 */
function chip_affiliatewp_instruction_reference( $payout_id ) {
	return substr( chip_affiliatewp_reference_prefix() . '-PO-' . $payout_id, 0, 40 );
}

/**
 * Retrieves the affiliate's CHIP Send bank account registered under its stable reference.
 *
 * @param int $affiliate_id Affiliate ID.
 * @return array|null Bank account record, or null when none exists.
 */
function chip_affiliatewp_get_bank_account( $affiliate_id ) {
	$reference = chip_affiliatewp_bank_reference( $affiliate_id );

	$response = chip_affiliatewp_request(
		'GET',
		'/send/bank_accounts',
		array(),
		array(
			'page'      => 1,
			'limit'     => 25,
			'reference' => $reference,
		)
	);

	if ( is_wp_error( $response ) || empty( $response['results'] ) || ! is_array( $response['results'] ) ) {
		return null;
	}

	foreach ( $response['results'] as $account ) {
		if ( isset( $account['reference'] ) && $reference === (string) $account['reference'] ) {
			return $account;
		}
	}

	return null;
}

/**
 * Adds the affiliate's bank account on CHIP Send, or returns the existing one.
 *
 * The unique per-details reference makes the submission idempotent: CHIP
 * rejects a duplicate registration of the same recipient, and this plugin
 * looks the account up first so repeat payouts reuse the existing record.
 *
 * @param int $affiliate_id Affiliate ID.
 * @return array|WP_Error Bank account record with at least "id" and "status".
 */
function chip_affiliatewp_ensure_bank_account( $affiliate_id ) {
	$existing = chip_affiliatewp_get_bank_account( $affiliate_id );

	if ( is_array( $existing ) && ! empty( $existing['id'] ) ) {
		$deleted_at = chip_affiliatewp_array_value( $existing, 'deleted_at' );

		if ( empty( $deleted_at ) ) {
			return $existing;
		}
	}

	$details = chip_affiliatewp_get_bank_details( $affiliate_id );

	if ( '' === $details['account_number'] || '' === $details['bank_code'] ) {
		return new WP_Error( 'chip_missing_bank_details', __( 'This affiliate has no bank account details on file.', 'chip-for-affiliatewp' ) );
	}

	$response = chip_affiliatewp_request(
		'POST',
		'/send/bank_accounts',
		array(
			'account_number' => $details['account_number'],
			'bank_code'      => $details['bank_code'],
			'name'           => chip_affiliatewp_substr( (string) affwp_get_affiliate_name( $affiliate_id ), 128 ),
			'reference'      => chip_affiliatewp_bank_reference( $affiliate_id ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( empty( $response['id'] ) ) {
		return new WP_Error( 'chip_invalid_bank_account', __( 'CHIP Send did not return a bank account ID.', 'chip-for-affiliatewp' ) );
	}

	return $response;
}

/**
 * Decodes the JSON payload stored on a payout.
 *
 * @param object|int $payout Payout object or ID.
 * @return array
 */
function chip_affiliatewp_payout_data( $payout ) {
	$payout = is_object( $payout ) ? $payout : affwp_get_payout( $payout );

	if ( ! $payout ) {
		return array();
	}

	$data = json_decode( (string) $payout->description, true );

	return is_array( $data ) ? $data : array();
}

/**
 * Persists payout metadata.
 *
 * @param int   $payout_id Payout ID.
 * @param array $data      Full metadata payload to store.
 * @return bool
 */
function chip_affiliatewp_update_payout_data( $payout_id, $data ) {
	return (bool) affiliate_wp()->affiliates->payouts->update(
		$payout_id,
		array( 'description' => wp_json_encode( $data ) ),
		'',
		'payout'
	);
}

/**
 * Returns the referral IDs attached to a payout.
 *
 * @param object $payout Payout object.
 * @return int[]
 */
function chip_affiliatewp_payout_referral_ids( $payout ) {
	return array_filter( array_map( 'absint', explode( ',', (string) $payout->referrals ) ) );
}

/**
 * Lists CHIP Send instructions filtered by reference.
 *
 * Returns the first matching instruction, or null when none exists. Used to
 * recover an instruction that was already created when a submission retry
 * hits a duplicate-reference rejection.
 *
 * @param string $reference Reference value.
 * @return array|null
 */
function chip_affiliatewp_list_instruction_by_reference( $reference ) {
	$response = chip_affiliatewp_request(
		'GET',
		'/send/send_instructions',
		array(),
		array(
			'page'      => 1,
			'limit'     => 25,
			'reference' => $reference,
		)
	);

	if ( is_wp_error( $response ) || empty( $response['results'] ) || ! is_array( $response['results'] ) ) {
		return null;
	}

	foreach ( $response['results'] as $instruction ) {
		if ( isset( $instruction['reference'] ) && $reference === (string) $instruction['reference'] ) {
			return $instruction;
		}
	}

	return null;
}

/**
 * Formats a payout amount for the CHIP Send API.
 *
 * @param string|int|float $amount Amount.
 * @return string Amount with up to two decimal places, e.g. "100.00".
 */
function chip_affiliatewp_format_amount( $amount ) {
	return sprintf( '%0.2f', (float) $amount );
}

/**
 * Submits a CHIP payout to the CHIP Send API.
 *
 * Idempotent: a payout that already carries a send instruction ID is skipped
 * so repeated batch completions, cron runs, or admin retries never send the
 * money twice.
 *
 * @param int $payout_id Payout ID.
 * @return true|WP_Error
 */
function chip_affiliatewp_submit_payout( $payout_id ) {
	$payout = affwp_get_payout( $payout_id );

	if ( ! $payout ) {
		return new WP_Error( 'chip_invalid_payout', __( 'The specified payout does not exist.', 'chip-for-affiliatewp' ) );
	}

	if ( 'chip' !== $payout->payout_method ) {
		return new WP_Error( 'chip_wrong_method', __( 'This payout is not a CHIP Send payout.', 'chip-for-affiliatewp' ) );
	}

	if ( ! chip_affiliatewp_has_credentials() ) {
		return new WP_Error( 'chip_missing_credentials', __( 'Please enter your CHIP Send API credentials in Affiliates > Settings > Commissions > CHIP Send before attempting to process payments.', 'chip-for-affiliatewp' ) );
	}

	$data = chip_affiliatewp_payout_data( $payout );

	// Idempotency guard: this payout already carries a CHIP send instruction.
	if ( ! empty( $data['instruction_id'] ) ) {
		return true;
	}

	if ( (float) $payout->amount <= 0 ) {
		return chip_affiliatewp_fail_payout( $payout_id, __( 'Payout amount must be greater than zero.', 'chip-for-affiliatewp' ) );
	}

	$bank_account = chip_affiliatewp_ensure_bank_account( $payout->affiliate_id );

	if ( is_wp_error( $bank_account ) ) {
		return chip_affiliatewp_fail_payout( $payout_id, $bank_account->get_error_message() );
	}

	if ( empty( $bank_account['status'] ) || 'verified' !== $bank_account['status'] ) {
		/* translators: 1: Bank account status */
		return chip_affiliatewp_fail_payout( $payout_id, sprintf( __( 'Bank account is not verified yet (status: %s).', 'chip-for-affiliatewp' ), (string) chip_affiliatewp_array_value( $bank_account, 'status', 'unknown' ) ) );
	}

	$payment_email = affwp_get_affiliate_payment_email( $payout->affiliate_id );

	if ( empty( $payment_email ) ) {
		$user = get_userdata( affwp_get_affiliate_user_id( $payout->affiliate_id ) );
		$payment_email = is_a( $user, 'WP_User' ) ? $user->user_email : '';
	}

	if ( empty( $payment_email ) ) {
		return chip_affiliatewp_fail_payout( $payout_id, __( 'This affiliate has no payment email on file.', 'chip-for-affiliatewp' ) );
	}

	$reference = chip_affiliatewp_instruction_reference( $payout_id );
	$referral_ids = chip_affiliatewp_payout_referral_ids( $payout );

	$body = array(
		'bank_account_id' => (int) $bank_account['id'],
		'amount'          => chip_affiliatewp_format_amount( $payout->amount ),
		'email'           => $payment_email,
		'description'     => chip_affiliatewp_substr(
			sprintf(
				/* translators: %s: payout ID */
				__( 'Affiliate commission payout #%s', 'chip-for-affiliatewp' ),
				$payout_id
			),
			140
		),
		'reference'       => $reference,
	);

	if ( affiliate_wp()->settings->get( 'chip_send_recipient_receipt' ) ) {
		$body['send_recipient_receipt'] = true;
	}

	$response = chip_affiliatewp_request( 'POST', '/send/send_instructions', $body );

	if ( is_wp_error( $response ) ) {
		/*
		 * A duplicate-reference rejection means the instruction for this
		 * payout already exists (a retried submission after an unclear
		 * response, or a webhook that attached the payout row). Locate it
		 * and adopt its ID instead of re-sending the money.
		 */
		$existing = chip_affiliatewp_list_instruction_by_reference( $reference );

		if ( is_array( $existing ) && ! empty( $existing['id'] ) ) {
			$response = $existing;
		} else {
			return chip_affiliatewp_fail_payout( $payout_id, $response->get_error_message() );
		}
	}

	if ( empty( $response['id'] ) ) {
		return chip_affiliatewp_fail_payout( $payout_id, __( 'CHIP Send did not return a send instruction ID.', 'chip-for-affiliatewp' ) );
	}

	$data['instruction_id'] = (int) $response['id'];
	$data['reference']      = $reference;
	$data['state']          = (string) chip_affiliatewp_array_value( $response, 'state', 'received' );
	$data['receipt_url']    = (string) chip_affiliatewp_array_value( $response, 'receipt_url', '' );
	$data['referral_ids']   = $referral_ids;
	$data['last_checked']   = gmdate( 'Y-m-d H:i:s' );
	$data['poll_count']     = 0;
	$data['mode']           = affiliate_wp()->settings->get( 'chip_test_mode' ) ? 'test' : 'live';

	affiliate_wp()->affiliates->payouts->update(
		$payout_id,
		array(
			'description'          => wp_json_encode( $data ),
			'service_id'           => (int) $response['id'],
			'service_invoice_link' => $data['receipt_url'],
		),
		'',
		'payout'
	);

	// The instruction was accepted for processing; initial webhook may lag, so schedule a check.
	chip_affiliatewp_schedule_check( $payout_id, 120 );

	return true;
}

/**
 * Marks a CHIP payout as failed, recording the reason.
 *
 * The payout's referrals are released back to unpaid so the payout can be
 * retried after whatever the problem was has been fixed.
 *
 * @param int    $payout_id Payout ID.
 * @param string $reason    Human-readable failure reason.
 * @return WP_Error
 */
function chip_affiliatewp_fail_payout( $payout_id, $reason ) {
	$payout = affwp_get_payout( $payout_id );

	$data = $payout ? chip_affiliatewp_payout_data( $payout ) : array();
	$data['error']        = $reason;
	$data['last_checked'] = gmdate( 'Y-m-d H:i:s' );

	if ( $payout ) {
		affiliate_wp()->affiliates->payouts->update(
			$payout_id,
			array(
				'status'      => 'failed',
				'description' => wp_json_encode( $data ),
			),
			'',
			'payout'
		);

		foreach ( chip_affiliatewp_payout_referral_ids( $payout ) as $referral_id ) {
			affwp_set_referral_status( $referral_id, 'unpaid' );
		}
	}

	return new WP_Error( 'chip_payout_failed', $reason );
}

/**
 * Schedules a payout status check when Action Scheduler is available.
 *
 * @param int $payout_id Payout ID.
 * @param int $delay     Delay in seconds.
 * @return void
 */
function chip_affiliatewp_schedule_check( $payout_id, $delay ) {
	if ( function_exists( 'as_schedule_single_action' ) ) {
		as_schedule_single_action(
			time() + $delay,
			'chip_affiliatewp_check_payout_status',
			array( 'payout_id' => (int) $payout_id ),
			'chip-for-affiliatewp'
		);
	}
}

/**
 * Applies a CHIP Send instruction state to the local payout record.
 *
 * Shared by the webhook handler and the requery fallback so both paths
 * converge on the same transitions.
 *
 * @param int   $payout_id   Payout ID.
 * @param array $instruction Send instruction payload, at least with "state" and "id".
 * @return bool True when the payout reached a terminal state.
 */
function chip_affiliatewp_apply_instruction( $payout_id, $instruction ) {
	$payout = affwp_get_payout( $payout_id );

	if ( ! $payout || 'chip' !== $payout->payout_method ) {
		return false;
	}

	// Terminal payouts are already resolved; redelivered webhooks are acknowledged no-ops.
	if ( in_array( $payout->status, array( 'paid', 'failed' ), true ) ) {
		return true;
	}

	$state = (string) chip_affiliatewp_array_value( $instruction, 'state' );

	if ( '' === $state ) {
		return false;
	}

	$data = chip_affiliatewp_payout_data( $payout );
	$data['state']        = $state;
	$data['last_checked'] = gmdate( 'Y-m-d H:i:s' );

	if ( ! empty( $instruction['id'] ) ) {
		$data['instruction_id'] = (int) $instruction['id'];
	}

	if ( ! empty( $instruction['reference'] ) ) {
		$data['reference'] = (string) $instruction['reference'];
	}

	$receipt_url = (string) chip_affiliatewp_array_value( $instruction, 'receipt_url', '' );

	if ( '' !== $receipt_url ) {
		$data['receipt_url'] = $receipt_url;
	}

	switch ( $state ) {
		case 'completed':
			affiliate_wp()->affiliates->payouts->update(
				$payout_id,
				array(
					'status'               => 'paid',
					'description'          => wp_json_encode( $data ),
					'service_id'           => (int) chip_affiliatewp_array_value( $instruction, 'id', $data['instruction_id'] ?? 0 ),
					'service_invoice_link' => (string) chip_affiliatewp_array_value( $data, 'receipt_url', '' ),
				),
				'',
				'payout'
			);

			foreach ( chip_affiliatewp_payout_referral_ids( $payout ) as $referral_id ) {
				affwp_set_referral_status( $referral_id, 'paid' );
			}

			return true;

		case 'rejected':
		case 'deleted':
			$rejection_reason = (string) chip_affiliatewp_array_value( $instruction, 'rejection_reason', '' );

			/* translators: 1: Send instruction state, 2: Optional rejection reason */
			$reason = sprintf( __( 'CHIP Send instruction %1$s. %2$s', 'chip-for-affiliatewp' ), $state, $rejection_reason );

			chip_affiliatewp_fail_payout( $payout_id, trim( $reason ) );

			return true;
	}

	// received / enquiring / executing / reviewing / accepted: still in flight.
	if ( ! empty( $instruction['rejection_reason'] ) ) {
		$data['note'] = (string) $instruction['rejection_reason'];
	}

	chip_affiliatewp_update_payout_data( $payout_id, $data );

	return false;
}

/**
 * Requeries the CHIP Send instruction attached to a payout.
 *
 * Heals missed webhooks: the authoritative state is read back from the API.
 *
 * @param int  $payout_id Payout ID.
 * @param bool $reschedule Whether to reschedule a follow-up check when still pending.
 * @return void
 */
function chip_affiliatewp_check_payout_status( $payout_id, $reschedule = true ) {
	$payout = affwp_get_payout( $payout_id );

	if ( ! $payout || 'chip' !== $payout->payout_method ) {
		return;
	}

	if ( in_array( $payout->status, array( 'paid', 'failed' ), true ) ) {
		return;
	}

	$data = chip_affiliatewp_payout_data( $payout );

	if ( empty( $data['instruction_id'] ) ) {
		// Nothing submitted yet; let the sweep retry the submission instead.
		chip_affiliatewp_submit_payout( $payout_id );

		return;
	}

	$response = chip_affiliatewp_request( 'GET', '/send/send_instructions/' . rawurlencode( (string) $data['instruction_id'] ) );

	if ( is_wp_error( $response ) ) {
		$data['poll_count'] = (int) chip_affiliatewp_array_value( $data, 'poll_count', 0 );
		$data['last_checked'] = gmdate( 'Y-m-d H:i:s' );
	 chip_affiliatewp_update_payout_data( $payout_id, $data );

		if ( $reschedule ) {
			chip_affiliatewp_schedule_check( $payout_id, 300 );
		}

		return;
	}

	$reached_terminal = chip_affiliatewp_apply_instruction( $payout_id, $response );

	if ( ! $reached_terminal ) {
		$data     = chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) );
		$attempts = (int) chip_affiliatewp_array_value( $data, 'poll_count', 0 );
		$data['poll_count']   = $attempts + 1;
		$data['last_checked'] = gmdate( 'Y-m-d H:i:s' );
		chip_affiliatewp_update_payout_data( $payout_id, $data );

		// Cap Action Scheduler re-checks at one day; the hourly sweep keeps healing afterwards.
		if ( $reschedule && $attempts < 48 ) {
			chip_affiliatewp_schedule_check( $payout_id, 15 * MINUTE_IN_SECONDS );
		}
	}
}

/**
 * Hourly sweep: requery and retry CHIP payouts stuck in processing.
 *
 * Covers missed webhooks and submissions that never landed.
 *
 * @return void
 */
function chip_affiliatewp_sweep_processing_payouts() {
	$payouts = affiliate_wp()->affiliates->payouts->get_payouts(
		array(
			'payout_method' => 'chip',
			'status'        => 'processing',
			'number'        => -1,
			'orderby'       => 'payout_id',
			'order'         => 'ASC',
		)
	);

	if ( empty( $payouts ) ) {
		return;
	}

	foreach ( $payouts as $payout ) {
		if ( ! is_object( $payout ) || empty( $payout->payout_id ) ) {
			continue;
		}

		// Respect the per-payout backoff when a recent check already happened.
		$data     = chip_affiliatewp_payout_data( $payout );
		$against  = strtotime( (string) chip_affiliatewp_array_value( $data, 'last_checked', '' ) );
		$cooldown = 10 * MINUTE_IN_SECONDS;

		if ( $against && ( time() - $against ) < $cooldown ) {
			continue;
		}

		chip_affiliatewp_check_payout_status( (int) $payout->payout_id, false );
	}
}

/**
 * Submits every CHIP payout of a completed payout batch.
 *
 * @param int $batch_id Payout batch ID.
 * @return void
 */
function chip_affiliatewp_process_generated_batch( $batch_id ) {
	$batch_id = absint( $batch_id );

	if ( empty( $batch_id ) ) {
		return;
	}

	if ( ! chip_affiliatewp_has_credentials() ) {
		return;
	}

	$payouts = affiliate_wp()->affiliates->payouts->get_payouts(
		array(
			'batch_id'      => $batch_id,
			'payout_method' => 'chip',
			'status'        => 'processing',
			'number'        => -1,
		)
	);

	if ( empty( $payouts ) ) {
		return;
	}

	foreach ( $payouts as $payout ) {
		chip_affiliatewp_submit_payout( (int) $payout->payout_id );
	}
}

/**
 * Pays a single referral via CHIP Send (AffiliateWP single-referral handler).
 *
 * @param int $referral_id Referral ID.
 * @return true|WP_Error
 */
function chip_affiliatewp_pay_single_referral( $referral_id ) {
	$referral = affwp_get_referral( $referral_id );

	if ( ! $referral ) {
		return new WP_Error( 'chip_invalid_referral', __( 'The specified referral does not exist.', 'chip-for-affiliatewp' ) );
	}

	if ( 'unpaid' !== $referral->status ) {
		return new WP_Error( 'chip_referral_not_unpaid', __( 'A payment cannot be processed for this referral since it is not marked as Unpaid.', 'chip-for-affiliatewp' ) );
	}

	if ( ! empty( $referral->payout_id ) ) {
		return new WP_Error( 'chip_referral_has_payout', __( 'This referral is already attached to a payout. Resolve that payout to pay it again.', 'chip-for-affiliatewp' ) );
	}

	if ( empty( $referral->affiliate_id ) ) {
		return new WP_Error( 'chip_no_affiliate', __( 'There is no affiliate connected to this referral.', 'chip-for-affiliatewp' ) );
	}

	if ( ! chip_affiliatewp_has_credentials() ) {
		return new WP_Error( 'chip_missing_credentials', __( 'Please enter your CHIP Send API credentials in Affiliates > Settings > Commissions > CHIP Send before attempting to process payments.', 'chip-for-affiliatewp' ) );
	}

	$reference  = chip_affiliatewp_reference_prefix() . '-R-' . $referral_id;
	$bank_account = chip_affiliatewp_ensure_bank_account( $referral->affiliate_id );

	if ( is_wp_error( $bank_account ) ) {
		return $bank_account;
	}

	if ( empty( $bank_account['status'] ) || 'verified' !== $bank_account['status'] ) {
		/* translators: 1: Bank account status */
		return new WP_Error( 'chip_bank_account_unverified', sprintf( __( 'Bank account is not verified yet (status: %s).', 'chip-for-affiliatewp' ), (string) chip_affiliatewp_array_value( $bank_account, 'status', 'unknown' ) ) );
	}

	$payment_email = affwp_get_affiliate_payment_email( $referral->affiliate_id );

	if ( empty( $payment_email ) ) {
		$user = get_userdata( affwp_get_affiliate_user_id( $referral->affiliate_id ) );
		$payment_email = is_a( $user, 'WP_User' ) ? $user->user_email : '';
	}

	if ( empty( $payment_email ) ) {
		return new WP_Error( 'chip_no_email', __( 'This affiliate account does not have a payment email.', 'chip-for-affiliatewp' ) );
	}

	$instruction_description = chip_affiliatewp_substr(
		(string) $referral->description !== ''
			? (string) $referral->description
			: sprintf( __( 'Commission for referral #%d', 'chip-for-affiliatewp' ), $referral_id ),
		140
	);

	$body = array(
		'bank_account_id' => (int) $bank_account['id'],
		'amount'          => chip_affiliatewp_format_amount( $referral->amount ),
		'email'           => $payment_email,
		'description'     => $instruction_description,
		'reference'       => substr( $reference, 0, 40 ),
	);

	if ( affiliate_wp()->settings->get( 'chip_send_recipient_receipt' ) ) {
		$body['send_recipient_receipt'] = true;
	}

	$response = chip_affiliatewp_request( 'POST', '/send/send_instructions', $body );

	if ( is_wp_error( $response ) ) {
		// A duplicate-reference rejection means the instruction already
		// exists; adopt it instead of failing or re-sending.
		$existing = chip_affiliatewp_list_instruction_by_reference( substr( $reference, 0, 40 ) );

		if ( is_array( $existing ) && ! empty( $existing['id'] ) ) {
			$response = $existing;
		} else {
			return $response;
		}
	}

	if ( empty( $response['id'] ) ) {
		return new WP_Error( 'chip_instruction_failed', __( 'CHIP Send did not return a send instruction ID.', 'chip-for-affiliatewp' ) );
	}

	$state = (string) chip_affiliatewp_array_value( $response, 'state', 'received' );

	$payout_id = affwp_add_payout(
		array(
			'affiliate_id'         => $referral->affiliate_id,
			'referrals'            => $referral->ID,
			'amount'               => $referral->amount,
			'payout_method'        => 'chip',
			'status'               => 'processing',
			'service_id'           => (int) $response['id'],
			'service_invoice_link' => (string) chip_affiliatewp_array_value( $response, 'receipt_url', '' ),
			'description'          => wp_json_encode(
				array(
					'instruction_id' => (int) $response['id'],
					'reference'      => substr( $reference, 0, 40 ),
					'state'          => $state,
					'receipt_url'    => (string) chip_affiliatewp_array_value( $response, 'receipt_url', '' ),
					'referral_ids'   => array( $referral_id ),
					'last_checked'   => gmdate( 'Y-m-d H:i:s' ),
					'poll_count'     => 0,
					'mode'           => affiliate_wp()->settings->get( 'chip_test_mode' ) ? 'test' : 'live',
				)
			),
		)
	);

	if ( ! $payout_id ) {
		return new WP_Error( 'chip_payout_not_created', __( 'The payout record could not be created. The referral may already have an active payout.', 'chip-for-affiliatewp' ) );
	}

	chip_affiliatewp_schedule_check( (int) $payout_id, 120 );

	return true;
}

/**
 * Builds the plugin webhook URL.
 *
 * @return string
 */
function chip_affiliatewp_webhook_url() {
	return rest_url( 'chip-affiliatewp/v1/webhook' );
}

/**
 * Returns the option keys of the auto-registered webhook for a mode.
 *
 * @param string $mode "test" or "live".
 * @return array { id: string, key: string, checked: string }
 */
function chip_affiliatewp_webhook_option_keys( $mode ) {
	return array(
		'id'      => 'chip_webhook_id_' . $mode,
		'key'     => 'chip_webhook_key_' . $mode,
		'checked' => 'chip_webhook_checked_' . $mode,
	);
}

/**
 * Whether the plugin's CHIP Send webhook is configured for the current mode.
 *
 * A webhook counts as configured when its ID and verification public key
 * are both known — either auto-registered by this plugin or supplied
 * manually via the Webhook Public Key setting.
 *
 * @return bool
 */
function chip_affiliatewp_webhook_configured() {
	$mode = affiliate_wp()->settings->get( 'chip_test_mode' ) ? 'test' : 'live';
	$keys = chip_affiliatewp_webhook_option_keys( $mode );

	if ( '' !== (string) affiliate_wp()->settings->get( 'chip_webhook_public_key' ) ) {
		return true;
	}

	return ! empty( affiliate_wp()->settings->get( $keys['id'], '' ) ) && ! empty( affiliate_wp()->settings->get( $keys['key'], '' ) );
}

/**
 * Checks whether this site's webhook URL is publicly reachable.
 *
 * Sends a harmless unsigned probe to the local REST endpoint; any HTTP
 * response (even a 4xx from the signature check) proves DNS, TLS and the
 * server are reachable from outside the admin session. Loopback failures
 * are cached briefly so the check is not repeated on every save.
 *
 * @return true|WP_Error True when reachable, WP_Error describing the failure.
 */
function chip_affiliatewp_site_publicly_reachable() {
	$url       = chip_affiliatewp_webhook_url();
	$cache_key = 'chip_affiliatewp_webhook_reachable';

	$cached = get_transient( $cache_key );

	if ( 'yes' === $cached ) {
		return true;
	}

	if ( 'no' === $cached ) {
		return new WP_Error( 'chip_webhook_unreachable', __( 'The webhook URL is not publicly reachable from this site.', 'chip-for-affiliatewp' ) );
	}

	$response = wp_remote_post(
		$url,
		array(
			'timeout'    => 10,
			'sslverify'  => true,
			'body'       => '{}',
			'headers'    => array( 'Content-Type' => 'application/json' ),
			'user-agent' => 'CHIP-AffiliateWP/' . CHIP_AFFILIATEWP_VERSION . ' (probe)',
		)
	);

	if ( is_wp_error( $response ) && 0 === (int) wp_remote_retrieve_response_code( $response ) ) {
		set_transient( $cache_key, 'no', 10 * MINUTE_IN_SECONDS );

		return new WP_Error(
			'chip_webhook_unreachable',
			sprintf(
				/* translators: 1: Webhook URL, 2: Technical error message */
				__( 'The webhook URL (%1$s) is not reachable: %2$s. The webhook was not registered — fix site reachability or configure payouts without webhooks (the hourly requery sweep still works).', 'chip-for-affiliatewp' ),
				$url,
				$response->get_error_message()
			)
		);
	}

	set_transient( $cache_key, 'yes', 10 * MINUTE_IN_SECONDS );

	return true;
}

/**
 * Ensures a CHIP Send webhook points at this site for the current mode.
 *
 * Idempotent: reuses an existing webhook with the same callback URL, updates
 * a stale same-named webhook whose URL no longer matches (for example after
 * the site address changed), and only creates a new webhook when the site's
 * webhook URL is publicly reachable — an unreachable site must not get a
 * registered webhook that would only collect delivery failures.
 *
 * On success, stores the webhook ID and its verification public key so
 * inbound deliveries can be verified without manual setup.
 *
 * @param bool $force Skip stored-ID fast path (used after errors).
 * @return true|WP_Error
 */
function chip_affiliatewp_ensure_webhook( $force = false ) {
	if ( ! affiliate_wp()->settings->get( 'chip_payouts' ) ) {
		return new WP_Error( 'chip_payouts_disabled', __( 'CHIP Send payout method is not enabled.', 'chip-for-affiliatewp' ) );
	}

	if ( ! chip_affiliatewp_has_credentials() ) {
		return new WP_Error( 'chip_missing_credentials', __( 'CHIP Send API credentials are not configured.', 'chip-for-affiliatewp' ) );
	}

	$mode = affiliate_wp()->settings->get( 'chip_test_mode' ) ? 'test' : 'live';
	$keys = chip_affiliatewp_webhook_option_keys( $mode );
	$url  = chip_affiliatewp_webhook_url();

	// 1. Stored webhook still valid?
	if ( ! $force ) {
		$stored_id = absint( affiliate_wp()->settings->get( $keys['id'], '' ) );

		if ( $stored_id ) {
			$details = chip_affiliatewp_request( 'GET', '/webhooks/' . $stored_id );

			if ( is_wp_error( $details ) ) {
				// Gone or errored; fall through to discovery below.
				affiliate_wp()->settings->set( array( $keys['id'] => '' ) );
			} elseif ( $url === (string) chip_affiliatewp_array_value( $details, 'callback_url' ) ) {
				$public_key = (string) chip_affiliatewp_array_value( $details, 'public_key', '' );

				if ( '' !== $public_key ) {
					affiliate_wp()->settings->set( array( $keys['key'] => $public_key ) );
				}

				affiliate_wp()->settings->set( array( $keys['checked'] => time() ) );

				return true;
			}
		}
	}

	// 2. Find an existing webhook with our callback URL — never register twice.
	$list = chip_affiliatewp_request( 'GET', '/webhooks' );

	$existing_id   = 0;
	$stale_id      = 0;
	$webhook_email = (string) get_option( 'admin_email' );
	$event_hooks   = array( 'send_instruction_status', 'bank_account_status' );

	if ( ! is_wp_error( $list ) ) {
		$rows = array();

		if ( isset( $list['results'] ) && is_array( $list['results'] ) ) {
			$rows = $list['results'];
		} elseif ( isset( $list[0] ) && is_array( $list[0] ) ) {
			$rows = $list;
		}

		foreach ( $rows as $row ) {
			$row_url = (string) chip_affiliatewp_array_value( $row, 'callback_url' );

			if ( $url === $row_url ) {
				$existing_id = absint( chip_affiliatewp_array_value( $row, 'id' ) );
				break;
			}

			if ( ! $stale_id && 'AffiliateWP Payouts' === (string) chip_affiliatewp_array_value( $row, 'name' ) ) {
				$stale_id = absint( chip_affiliatewp_array_value( $row, 'id' ) );
			}
		}
	}

	// 3. Only touch the remote server when this site is publicly reachable.
	$reachable = chip_affiliatewp_site_publicly_reachable();

	if ( is_wp_error( $reachable ) ) {
		affiliate_wp()->settings->set( array( $keys['checked'] => time() ) );

		return $reachable;
	}

	$body = array(
		'name'         => 'AffiliateWP Payouts',
		'callback_url' => $url,
		'email'        => $webhook_email,
		'event_hooks'  => $event_hooks,
	);

	if ( $existing_id ) {
		$response = chip_affiliatewp_request( 'PATCH', '/webhooks/' . $existing_id, $body );
	} elseif ( $stale_id ) {
		// Same-named webhook from an older site URL; repoint it here.
		$response = chip_affiliatewp_request( 'PATCH', '/webhooks/' . $stale_id, $body );
	} else {
		$response = chip_affiliatewp_request( 'POST', '/webhooks', $body );
	}

	if ( is_wp_error( $response ) ) {
		// A conflict during create means the webhook already exists; re-discover it.
		if ( ! $existing_id && ! $stale_id ) {
			$retry = chip_affiliatewp_request( 'GET', '/webhooks' );

			if ( ! is_wp_error( $retry ) && isset( $retry['results'] ) && is_array( $retry['results'] ) ) {
				foreach ( $retry['results'] as $row ) {
					if ( $url === (string) chip_affiliatewp_array_value( $row, 'callback_url' ) ) {
						$existing_id = absint( chip_affiliatewp_array_value( $row, 'id' ) );
						break;
					}
				}

				if ( $existing_id ) {
					$response = chip_affiliatewp_request( 'GET', '/webhooks/' . $existing_id );
				}
			}
		}

		if ( is_wp_error( $response ) ) {
			affiliate_wp()->settings->set( array( $keys['checked'] => time() ) );

			return $response;
		}
	}

	$webhook_id = absint( chip_affiliatewp_array_value( $response, 'id', $existing_id ?: $stale_id ) );

	if ( empty( $webhook_id ) ) {
		return new WP_Error( 'chip_webhook_invalid_response', __( 'CHIP Send did not return a webhook ID.', 'chip-for-affiliatewp' ) );
	}

	// PATCH responses may omit the public key; fetch the full record.
	$public_key = (string) chip_affiliatewp_array_value( $response, 'public_key', '' );

	if ( '' === $public_key ) {
		$details = chip_affiliatewp_request( 'GET', '/webhooks/' . $webhook_id );

		if ( ! is_wp_error( $details ) ) {
			$public_key = (string) chip_affiliatewp_array_value( $details, 'public_key', '' );
		}
	}

	affiliate_wp()->settings->set(
		array(
			$keys['id']      => $webhook_id,
			$keys['key']     => $public_key,
			$keys['checked'] => time(),
		),
		true
	);

	return true;
}

/**
 * Resolves the webhook public key for the current mode.
 *
 * Manual key (Webhook Public Key setting) wins; otherwise the key captured
 * by auto-registration.
 *
 * @return string PEM public key, or empty string when unavailable.
 */
function chip_affiliatewp_webhook_public_key() {
	$manual = trim( (string) affiliate_wp()->settings->get( 'chip_webhook_public_key' ) );

	if ( '' !== $manual ) {
		return $manual;
	}

	$mode = affiliate_wp()->settings->get( 'chip_test_mode' ) ? 'test' : 'live';
	$keys = chip_affiliatewp_webhook_option_keys( $mode );

	return trim( (string) affiliate_wp()->settings->get( $keys['key'], '' ) );
}

/**
 * Handles inbound CHIP Send webhooks.
 *
 * Deliveries are verified with the RSA X-Signature (SHA512, PKCS#1 v1.5,
 * base64) against the per-webhook public key, then processed exactly once:
 * an advisory-style lock plus terminal-state short-circuits make redeliveries
 * and out-of-order events safe.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function chip_affiliatewp_handle_webhook( $request ) {
	$raw = (string) $request->get_body();
	$signature = (string) $request->get_header( 'X-Signature' );
	$event_type = (string) $request->get_header( 'Event-Type' );

	$public_key = chip_affiliatewp_webhook_public_key();

	if ( '' === $public_key ) {
		return new WP_Error( 'chip_webhook_unconfigured', __( 'Webhook signature verification is not configured yet.', 'chip-for-affiliatewp' ), array( 'status' => 503 ) );
	}

	if ( '' === $signature ) {
		return new WP_Error( 'chip_webhook_missing_signature', __( 'Missing signature.', 'chip-for-affiliatewp' ), array( 'status' => 401 ) );
	}

	$key_object = openssl_pkey_get_public( $public_key );

	if ( false === $key_object ) {
		return new WP_Error( 'chip_webhook_invalid_key', __( 'The configured webhook public key is not valid.', 'chip-for-affiliatewp' ), array( 'status' => 503 ) );
	}

	$verification = openssl_verify( $raw, (string) base64_decode( $signature ), $key_object, OPENSSL_ALGO_SHA512 );

	if ( 1 !== $verification ) {
		return new WP_Error( 'chip_webhook_invalid_signature', __( 'Signature verification failed.', 'chip-for-affiliatewp' ), array( 'status' => 401 ) );
	}

	$payload = json_decode( $raw, true );

	if ( ! is_array( $payload ) ) {
		return new WP_Error( 'chip_webhook_invalid_payload', __( 'Malformed payload.', 'chip-for-affiliatewp' ), array( 'status' => 400 ) );
	}

	// Defensive unwrap in case deliveries ever switch to an envelope with event/data keys.
	if ( isset( $payload['data'] ) && is_array( $payload['data'] ) && ! isset( $payload['state'] ) && ! isset( $payload['status'] ) ) {
		$payload = $payload['data'];
	}

	if ( is_array( $payload ) && isset( $payload[0] ) && is_array( $payload[0] ) ) {
		$payload = $payload[0];
	}

	// Bank account and budget allocation events carry no local state to update.
	if ( 'bank_account_status' === $event_type || 'budget_allocation_status' === $event_type
		|| ( ! isset( $payload['state'] ) && isset( $payload['status'] ) ) ) {
		return rest_ensure_response( array( 'handled' => 'ignored' ) );
	}

	if ( 'send_instruction_status' !== $event_type && ! isset( $payload['state'] ) ) {
		return rest_ensure_response( array( 'handled' => 'ignored' ) );
	}

	if ( empty( $payload['id'] ) || ! isset( $payload['state'] ) ) {
		return new WP_Error( 'chip_webhook_incomplete', __( 'Payload missing send instruction ID or state.', 'chip-for-affiliatewp' ), array( 'status' => 400 ) );
	}

	chip_affiliatewp_process_instruction_webhook( $payload );

	return rest_ensure_response( array( 'handled' => true ) );
}

/**
 * Maps a send instruction webhook payload to the local payout and applies it.
 *
 * Resolution order: the instruction ID stored in payout metadata first, then
 * the deterministic reference embedded in the instruction.
 *
 * @param array $payload Webhook payload.
 * @return void
 */
function chip_affiliatewp_process_instruction_webhook( $payload ) {
	global $wpdb;

	$instruction_id = absint( chip_affiliatewp_array_value( $payload, 'id' ) );
	$reference      = (string) chip_affiliatewp_array_value( $payload, 'reference' );

	$payout_id = 0;

	// Fast path: a payout already stores this instruction ID.
	if ( $instruction_id ) {
		$payout_id = chip_affiliatewp_find_payout_by_instruction_id( $instruction_id );
	}

	// Reference path: "<prefix>-PO-<payout_id>" or "<prefix>-R-<referral_id>".
	if ( ! $payout_id && preg_match( '/-(PO|R)-(\d+)$/', $reference, $matches ) ) {
		if ( 'PO' === $matches[1] ) {
			$payout_id = absint( $matches[2] );
		} else {
			$referral = affwp_get_referral( absint( $matches[2] ) );

			if ( $referral && ! empty( $referral->payout_id ) ) {
				$payout_id = absint( $referral->payout_id );
			} elseif ( $referral ) {
				// Single-referral run: the payout row was never created. Create it now.
				$payout_id = affwp_add_payout(
					array(
						'affiliate_id'  => $referral->affiliate_id,
						'referrals'     => $referral->ID,
						'amount'        => $referral->amount,
						'payout_method' => 'chip',
						'status'        => 'processing',
						'service_id'    => $instruction_id,
						'description'   => wp_json_encode(
							array(
								'instruction_id' => $instruction_id,
								'reference'      => $reference,
								'state'          => (string) chip_affiliatewp_array_value( $payload, 'state', '' ),
								'referral_ids'   => array( (int) $referral->ID ),
								'recovered'      => true,
							)
						),
					)
				);
			}
		}
	}

	if ( ! $payout_id ) {
		return;
	}

	// Serialize processing per instruction so duplicate or racing deliveries cannot double-apply.
	$lock_name  = 'chip_affiliatewp_' . md5( 'instruction_' . ( $instruction_id ? $instruction_id : $payout_id ) );
	$lock_value = 'mysql' === ( $GLOBALS['wpdb']->is_mysql ? 'mysql' : 'other' ) ? $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) ) : null;

	if ( null !== $lock_value && '1' !== (string) $lock_value ) {
		// Another worker is already handling this exact delivery.
		return;
	}

	try {
		chip_affiliatewp_apply_instruction( $payout_id, $payload );
	} finally {
		if ( null !== $lock_value && '1' === (string) $lock_value ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}
}

/**
 * Finds a CHIP payout by its stored send instruction ID.
 *
 * @param int $instruction_id CHIP Send instruction ID.
 * @return int Payout ID, or 0 when not found.
 */
function chip_affiliatewp_find_payout_by_instruction_id( $instruction_id ) {
	$payouts = affiliate_wp()->affiliates->payouts->get_payouts(
		array(
			'payout_method' => 'chip',
			'status'        => array( 'processing', 'paid', 'failed', 'unpaid' ),
			'service_id'    => $instruction_id,
			'number'        => 1,
			'fields'        => 'payout_id',
		)
	);

	if ( ! empty( $payouts ) ) {
		$found = is_array( $payouts ) ? array_shift( $payouts ) : $payouts;

		return absint( $found );
	}

	return 0;
}

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
	$handlers['chip'] = 'chip_affiliatewp_pay_single_referral';

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

/**
 * Registers the inbound webhook REST route.
 *
 * @return void
 */
function chip_affiliatewp_register_rest_route() {
	register_rest_route(
		'chip-affiliatewp/v1',
		'/webhook',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'chip_affiliatewp_handle_webhook',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'chip_affiliatewp_register_rest_route' );


/**
 * Adds the affiliate bank details fields on the Edit Affiliate screen.
 *
 * @param AffWP\Affiliate $affiliate Affiliate object.
 * @return void
 */
function chip_affiliatewp_affiliate_bank_fields( $affiliate ) {
	if ( ! affiliate_wp()->settings->get( 'chip_payouts' ) ) {
		return;
	}

	$details = chip_affiliatewp_get_bank_details( $affiliate->affiliate_id );
	?>
	<tr class="form-row form-required">
		<th scope="row">
			<label for="payment_bank_code"><?php esc_html_e( 'Bank Code', 'chip-for-affiliatewp' ); ?></label>
		</th>
		<td>
			<select name="payment_bank_code" id="payment_bank_code">
				<?php foreach ( chip_affiliatewp_bank_codes() as $bank_code => $label ) : ?>
					<option value="<?php echo esc_attr( $bank_code ); ?>" <?php selected( $details['bank_code'], $bank_code ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<p class="description"><?php esc_html_e( 'Bank code for the affiliate payout sent via CHIP Send.', 'chip-for-affiliatewp' ); ?></p>
		</td>
	</tr>
	<tr class="form-row form-required">
		<th scope="row">
			<label for="payment_account_number"><?php esc_html_e( 'Bank Account Number', 'chip-for-affiliatewp' ); ?></label>
		</th>
		<td>
			<input class="regular-text" type="text" name="payment_account_number" id="payment_account_number" value="<?php echo esc_attr( $details['account_number'] ); ?>" />
			<p class="description"><?php esc_html_e( 'Account number for the affiliate payout sent via CHIP Send.', 'chip-for-affiliatewp' ); ?></p>
		</td>
	</tr>
	<?php
}
add_action( 'affwp_edit_affiliate_end', 'chip_affiliatewp_affiliate_bank_fields' );

/**
 * Saves the affiliate bank details from the Edit Affiliate screen.
 *
 * @param AffWP\Affiliate $affiliate Affiliate object.
 * @param array           $args      Update arguments.
 * @param array           $data      Raw submitted data.
 * @return void
 */
function chip_affiliatewp_save_bank_details( $affiliate, $args, $data ) {
	if ( empty( $data['payment_bank_code'] ) && empty( $data['payment_account_number'] ) ) {
		return;
	}

	if ( isset( $data['payment_account_number'] ) ) {
		update_user_meta(
			$affiliate->user_id,
			'payment_account_number',
			sanitize_text_field( $data['payment_account_number'] )
		);
	}

	if ( isset( $data['payment_bank_code'] ) ) {
		update_user_meta(
			$affiliate->user_id,
			'payment_bank_code',
			sanitize_text_field( $data['payment_bank_code'] )
		);
	}
}
add_action( 'affwp_pre_update_affiliate', 'chip_affiliatewp_save_bank_details', 10, 3 );

/**
 * Returns the Malaysian bank codes supported by CHIP Send.
 *
 * @return array Map of bank code => label.
 */
function chip_affiliatewp_bank_codes() {
	return array(
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
	);
}

/**
 * Schedules the hourly sweep on plugin activation.
 *
 * @return void
 */
function chip_affiliatewp_activate() {
	if ( ! wp_next_scheduled( 'chip_affiliatewp_hourly_sweep' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'chip_affiliatewp_hourly_sweep' );
	}
}
register_activation_hook( __FILE__, 'chip_affiliatewp_activate' );

/**
 * Clears the scheduled sweep on plugin deactivation.
 *
 * @return void
 */
function chip_affiliatewp_deactivate() {
	wp_clear_scheduled_hook( 'chip_affiliatewp_hourly_sweep' );
}
register_deactivation_hook( __FILE__, 'chip_affiliatewp_deactivate' );
