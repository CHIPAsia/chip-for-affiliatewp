<?php
/**
 * CHIP Send payout state machine: submit, apply, requery, sweep, batch fan-out.
 *
 * @package CHIPforAffiliateWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Cannot access directly.

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
		return new WP_Error( 'chip_missing_credentials', __( 'Please enter your CHIP Send API credentials in AffiliateWP → Settings → Payouts → CHIP Send before attempting to process payments.', 'chip-for-affiliatewp' ) );
	}

	$data = chip_affiliatewp_payout_data( $payout );

	// Idempotency guard: this payout already carries a CHIP send instruction.
	if ( ! empty( $data['instruction_id'] ) ) {
		return true;
	}

	/*
	 * Serialize submission per payout. The instruction_id guard above is a
	 * read-then-write, so two workers handling the same payout at once — a
	 * duplicate scheduled action, or a requery racing a webhook — could both
	 * see it empty and both send money. The lock closes that window; the
	 * second worker returns early and lets the first one finish.
	 */
	$lock_name = 'chip_affiliatewp_submit_' . absint( $payout_id );
	$lock_held = false;

	if ( 'mysql' === ( $GLOBALS['wpdb']->is_mysql ? 'mysql' : 'other' ) ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- advisory lock, not a data read.
		$lock_held = '1' === (string) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $lock_held ) {
			// Another worker owns this submission.
			return true;
		}
	}

	try {
		return chip_affiliatewp_submit_payout_locked( $payout_id, $payout );
	} finally {
		if ( $lock_held ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releases the advisory lock above.
			$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}
}

/**
 * Submits a payout while its submission lock is held.
 *
 * Split out so the lock is always released, including when a failure path
 * returns early.
 *
 * @param int    $payout_id Payout ID.
 * @param object $payout    Payout row.
 * @return true|WP_Error
 */
function chip_affiliatewp_submit_payout_locked( $payout_id, $payout ) {
	// Re-read: another worker may have completed this payout while we waited.
	$payout = affwp_get_payout( $payout_id );

	if ( ! $payout ) {
		return new WP_Error( 'chip_invalid_payout', __( 'The specified payout does not exist.', 'chip-for-affiliatewp' ) );
	}

	if ( ! empty( chip_affiliatewp_payout_data( $payout )['instruction_id'] ) ) {
		return true;
	}

	if ( (float) $payout->amount <= 0 ) {
		return chip_affiliatewp_fail_payout( $payout_id, __( 'Payout amount must be greater than zero.', 'chip-for-affiliatewp' ), 'chip_invalid_amount' );
	}

	/*
	 * CHIP Send only settles MYR, and the API takes a bare number with no
	 * currency field — a store configured in USD would send "100.00" that CHIP
	 * reads as RM100. Refuse rather than silently pay a wrong amount.
	 */
	if ( 'MYR' !== chip_affiliatewp_currency() ) {
		return chip_affiliatewp_fail_payout(
			$payout_id,
			sprintf(
				/* translators: %s: the store's currency code. */
				__( 'CHIP Send pays out in MYR only, but this store is set to %s. Change the currency in AffiliateWP settings to send payouts.', 'chip-for-affiliatewp' ),
				chip_affiliatewp_currency()
			),
			'chip_currency_unsupported'
		);
	}

	$bank_account = chip_affiliatewp_ensure_bank_account( $payout->affiliate_id );

	if ( is_wp_error( $bank_account ) ) {
		return chip_affiliatewp_fail_payout( $payout_id, $bank_account->get_error_message(), $bank_account->get_error_code() );
	}

	if ( empty( $bank_account['status'] ) || 'verified' !== $bank_account['status'] ) {
		/* translators: 1: Bank account status */
		return chip_affiliatewp_fail_payout( $payout_id, sprintf( __( 'Bank account is not verified yet (status: %s).', 'chip-for-affiliatewp' ), (string) chip_affiliatewp_array_value( $bank_account, 'status', 'unknown' ) ), 'chip_bank_account_unverified' );
	}

	$payment_email = affwp_get_affiliate_payment_email( $payout->affiliate_id );

	if ( empty( $payment_email ) ) {
		$user          = get_userdata( affwp_get_affiliate_user_id( $payout->affiliate_id ) );
		$payment_email = is_a( $user, 'WP_User' ) ? $user->user_email : '';
	}

	if ( empty( $payment_email ) ) {
		return chip_affiliatewp_fail_payout( $payout_id, __( 'This affiliate has no payment email on file.', 'chip-for-affiliatewp' ), 'chip_no_email' );
	}

	$reference    = chip_affiliatewp_instruction_reference( $payout_id );
	$referral_ids = chip_affiliatewp_payout_referral_ids( $payout );

	/*
	 * Re-check eligibility immediately before the money moves. A payout row
	 * keeps the referral list captured when the batch was built, and a referral
	 * can be revoked in between — a refund integration (WooCommerce, EDD, RCP)
	 * pulling back a commission, or an admin marking it unpaid. Paying here
	 * would send money the merchant has already reversed and, because the
	 * referral is no longer unpaid, nothing downstream would notice.
	 *
	 * A referral must also still belong to this payout. AffiliateWP records the
	 * owning payout on the referral, so one that has since been attached
	 * elsewhere must not be paid from here as well.
	 *
	 * Anything failing either check is dropped; if that leaves nothing, the
	 * payout fails and the referrals are released rather than partially paid.
	 */
	$payable_ids = array();

	foreach ( $referral_ids as $referral_id ) {
		$referral = affwp_get_referral( $referral_id );

		if ( ! $referral || 'unpaid' !== $referral->status ) {
			continue;
		}

		// 0 means "not attached to any payout"; anything else must be this one.
		if ( ! empty( $referral->payout_id ) && absint( $referral->payout_id ) !== absint( $payout_id ) ) {
			continue;
		}

		$payable_ids[] = $referral_id;
	}

	if ( empty( $payable_ids ) ) {
		return chip_affiliatewp_fail_payout( $payout_id, __( 'None of the referrals in this payout are awaiting payment any more, so nothing was sent.', 'chip-for-affiliatewp' ), 'chip_referrals_no_longer_payable' );
	}

	if ( count( $payable_ids ) !== count( $referral_ids ) ) {
		/*
		 * Part of the payout was revoked. Recompute the amount from what is
		 * still payable so the affiliate is not overpaid, and record the
		 * reduction so the merchant can see why the figure differs from the
		 * batch preview.
		 */
		$amount = 0.0;

		foreach ( $payable_ids as $referral_id ) {
			$referral = affwp_get_referral( $referral_id );
			$amount  += (float) $referral->amount;
		}

		if ( $amount <= 0 ) {
			return chip_affiliatewp_fail_payout( $payout_id, __( 'The referrals left in this payout have no payable amount.', 'chip-for-affiliatewp' ), 'chip_invalid_amount' );
		}

		$referral_ids = $payable_ids;

		affiliate_wp()->affiliates->payouts->update(
			$payout_id,
			array(
				'amount'    => chip_affiliatewp_format_amount( $amount ),
				'referrals' => implode( ',', $referral_ids ),
			),
			'',
			'payout'
		);

		// The in-memory row still holds the pre-reduction figure.
		$payout->amount    = chip_affiliatewp_format_amount( $amount );
		$payout->referrals = implode( ',', $referral_ids );
	}

	$body = array(
		'bank_account_id' => (int) $bank_account['id'],
		'amount'          => chip_affiliatewp_format_amount( $payout->amount ),
		'email'           => $payment_email,
		'description'     => chip_affiliatewp_sanitize_description(
			sprintf(
				/* translators: %s: payout ID */
				__( 'Affiliate commission payout No.%s', 'chip-for-affiliatewp' ),
				$payout_id
			)
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
			return chip_affiliatewp_fail_payout( $payout_id, $response->get_error_message(), $response->get_error_code() );
		}
	}

	if ( empty( $response['id'] ) ) {
		return chip_affiliatewp_fail_payout( $payout_id, __( 'CHIP Send did not return a send instruction ID.', 'chip-for-affiliatewp' ), 'chip_instruction_failed' );
	}

	$data['instruction_id'] = (int) $response['id'];
	$data['reference']      = $reference;
	$data['state']          = (string) chip_affiliatewp_array_value( $response, 'state', 'received' );
	$data['receipt_url']    = chip_affiliatewp_safe_receipt_url( chip_affiliatewp_array_value( $response, 'receipt_url', '' ) );
	$data['referral_ids']   = $referral_ids;
	$data['last_checked']   = gmdate( 'Y-m-d H:i:s' );
	$data['poll_count']     = 0;
	$data['mode']           = affiliate_wp()->settings->get( 'chip_test_mode' ) ? 'test' : 'live';

	// The instruction was accepted, so any earlier failure no longer applies.
	unset( $data['error'] );

	$update = array(
		'description'          => wp_json_encode( $data ),
		'service_id'           => (int) $response['id'],
		'service_invoice_link' => $data['receipt_url'],
	);

	/*
	 * A resubmission after a failure must move the payout back into
	 * processing. Without this the row stays 'failed' even though CHIP has
	 * accepted the instruction, so the payout list lies until the webhook
	 * or the sweep happens to resolve it. Never downgrade an already-paid
	 * payout (a duplicate-reference adoption can land here).
	 */
	if ( 'paid' !== $payout->status ) {
		$update['status'] = 'processing';
	}

	affiliate_wp()->affiliates->payouts->update(
		$payout_id,
		$update,
		'',
		'payout'
	);

	// Keep the referral state consistent with an in-flight payout.
	foreach ( $referral_ids as $referral_id ) {
		$referral = affwp_get_referral( $referral_id );

		if ( $referral && 'paid' !== $referral->status ) {
			affwp_set_referral_status( $referral_id, 'unpaid' );
		}
	}

	// The instruction was accepted for processing; initial webhook may lag, so schedule a check.
	chip_affiliatewp_schedule_check( $payout_id, 120 );

	return true;
}

/**
 * Recounts the payout batch a payout belongs to.
 *
 * AffiliateWP derives a batch's roll-up status from its payouts: the batch
 * leaves `processing` only when its last payout reaches a terminal state. The
 * bundled methods call this whenever one of their payouts changes state, so
 * the batch panel does not sit on a stale "Processing" label after every
 * payout has actually landed.
 *
 * @param int $payout_id Payout ID.
 * @return void
 */
function chip_affiliatewp_recount_batch_for_payout( $payout_id ) {
	$payout = affwp_get_payout( $payout_id );

	if ( ! $payout || empty( $payout->batch_id ) ) {
		return;
	}

	if ( ! isset( affiliate_wp()->affiliates->payout_batches ) ) {
		return;
	}

	affiliate_wp()->affiliates->payout_batches->recount( absint( $payout->batch_id ) );
}

/**
 * Marks a CHIP payout as failed, recording the reason.
 *
 * The payout's referrals are released back to unpaid so the payout can be
 * retried after whatever the problem was has been fixed.
 *
 * @param int    $payout_id  Payout ID.
 * @param string $reason     Human-readable failure reason.
 * @param string $error_code Optional. Machine-readable error code used for
 *                           failure classification. Default empty string.
 * @return WP_Error
 */
function chip_affiliatewp_fail_payout( $payout_id, $reason, $error_code = '' ) {
	$payout = affwp_get_payout( $payout_id );

	$data                 = $payout ? chip_affiliatewp_payout_data( $payout ) : array();
	$data['error']        = $reason;
	$data['last_checked'] = gmdate( 'Y-m-d H:i:s' );

	if ( $payout ) {
		$update = array(
			'status'      => 'failed',
			'description' => wp_json_encode( $data ),
		);

		/*
		 * Classify the failure so AffiliateWP can drive the Retry button, the
		 * automatic retry sweep, and the action-required email. Without a
		 * class every failure reads as UNKNOWN and the affiliate is never
		 * told what to fix.
		 */
		if ( function_exists( 'chip_affiliatewp_classify_failure' ) ) {
			$update['failure_class'] = chip_affiliatewp_classify_failure( $error_code, $reason );
		}

		affiliate_wp()->affiliates->payouts->update(
			$payout_id,
			$update,
			'',
			'payout'
		);

		foreach ( chip_affiliatewp_payout_referral_ids( $payout ) as $referral_id ) {
			affwp_set_referral_status( $referral_id, 'unpaid' );
		}

		// A failure is terminal for the batch roll-up: recount so the batch can
		// settle instead of staying on "Processing" behind a dead payout.
		chip_affiliatewp_recount_batch_for_payout( $payout_id );

		/*
		 * Dispatch the action-required email when the affiliate can fix the
		 * problem. All gating (the setting toggle, the cooldown, and the
		 * affiliate_action_required class check) lives in AffiliateWP.
		 */
		if ( function_exists( 'affwp_maybe_send_failure_email' ) ) {
			affwp_maybe_send_failure_email( (int) $payout_id );
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

	// Paid payouts are fully resolved; redelivered webhooks are acknowledged no-ops.
	if ( 'paid' === $payout->status ) {
		return true;
	}

	$state = (string) chip_affiliatewp_array_value( $instruction, 'state' );

	if ( '' === $state ) {
		return false;
	}

	// A failed payout is not necessarily terminal at CHIP: the instruction may
	// exist there and later complete (e.g. the create call timed out after the
	// server accepted it). Let a completed/rejected delivery heal the record so
	// AffiliateWP's auto-retry of failed payouts cannot pay the referral twice.
	if ( 'failed' === $payout->status && ! in_array( $state, array( 'completed', 'rejected', 'deleted' ), true ) ) {
		// Still in flight or unknown: acknowledge the delivery without changes.
		return true;
	}

	$data                 = chip_affiliatewp_payout_data( $payout );
	$data['state']        = $state;
	$data['last_checked'] = gmdate( 'Y-m-d H:i:s' );

	/*
	 * Only a terminal state supersedes an earlier failure note. An in-flight
	 * delivery keeps the note so the admin can still see why the payout was
	 * retried in the first place.
	 */
	if ( in_array( $state, array( 'completed', 'rejected', 'deleted' ), true ) ) {
		unset( $data['error'] );
	}

	if ( ! empty( $instruction['id'] ) ) {
		$data['instruction_id'] = (int) $instruction['id'];
	}

	if ( ! empty( $instruction['reference'] ) ) {
		$data['reference'] = (string) $instruction['reference'];
	}

	$receipt_url = chip_affiliatewp_safe_receipt_url( chip_affiliatewp_array_value( $instruction, 'receipt_url', '' ) );

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

			// Terminal for the batch roll-up: let the batch leave processing.
			chip_affiliatewp_recount_batch_for_payout( $payout_id );

			return true;

		case 'rejected':
		case 'deleted':
			$rejection_reason = (string) chip_affiliatewp_array_value( $instruction, 'rejection_reason', '' );

			/* translators: 1: Send instruction state, 2: Optional rejection reason */
			$reason = sprintf( __( 'CHIP Send instruction %1$s. %2$s', 'chip-for-affiliatewp' ), $state, $rejection_reason );

			chip_affiliatewp_fail_payout( $payout_id, trim( $reason ), 'chip_instruction_' . $state );

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
 * Fetches a CHIP Send instruction by ID.
 *
 * @param int $instruction_id CHIP Send instruction ID.
 * @return array|WP_Error Instruction payload, or WP_Error on failure.
 */
function chip_affiliatewp_get_instruction( $instruction_id ) {
	return chip_affiliatewp_request( 'GET', '/send/send_instructions/' . rawurlencode( (string) $instruction_id ) );
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

	if ( 'paid' === $payout->status ) {
		return;
	}

	$data = chip_affiliatewp_payout_data( $payout );

	if ( empty( $data['instruction_id'] ) && ! empty( $payout->service_id ) ) {
		// The instruction ID survived in the payouts table (e.g. data JSON was
		// rewritten by a failure) but not in the payout meta: adopt it.
		$data['instruction_id'] = (int) $payout->service_id;
	}

	if ( empty( $data['instruction_id'] ) ) {
		// Nothing submitted yet; let the sweep retry the submission instead.
		chip_affiliatewp_submit_payout( $payout_id );

		return;
	}

	// A failed payout that carries an instruction must reconcile instead of
	// being retried: the instruction may have completed after a transient
	// failure (timeout between us and CHIP). Requery and let apply_instruction
	// heal the record so the affiliate cannot be paid twice.
	$response = chip_affiliatewp_get_instruction( (int) $data['instruction_id'] );

	if ( is_wp_error( $response ) ) {
		// Mode flipped after submission: the payout would otherwise be polled
		// against the wrong host forever. Resolve against the mode it was
		// submitted in before giving up this pass.
		$stored_mode = (string) chip_affiliatewp_array_value( $data, 'mode' );

		if ( in_array( $stored_mode, array( 'test', 'live' ), true ) ) {
			$probe = chip_affiliatewp_request(
				'GET',
				'/send/send_instructions/' . rawurlencode( (string) $data['instruction_id'] ),
				array(),
				array(),
				$stored_mode
			);

			if ( ! is_wp_error( $probe ) ) {
				$response = $probe;
			}
		}
	}

	if ( is_wp_error( $response ) ) {
		$data['poll_count']   = (int) chip_affiliatewp_array_value( $data, 'poll_count', 0 );
		$data['last_checked'] = gmdate( 'Y-m-d H:i:s' );
		chip_affiliatewp_update_payout_data( $payout_id, $data );

		if ( $reschedule ) {
			chip_affiliatewp_schedule_check( $payout_id, 300 );
		}

		return;
	}

	$reached_terminal = chip_affiliatewp_apply_instruction( $payout_id, $response );

	if ( ! $reached_terminal ) {
		$data                 = chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) );
		$attempts             = (int) chip_affiliatewp_array_value( $data, 'poll_count', 0 );
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
 * Covers missed webhooks and submissions that never landed. Bounded on
 * purpose: each requery costs one API call, so an unbounded sweep on a busy
 * store would hold the cron request open for minutes and risk a timeout.
 *
 * The per-payout cooldown is applied *before* the budget, so payouts checked
 * in a previous run do not consume this run's quota and block the rest of the
 * queue. Ordering oldest-first means a long-stuck payout is retried before a
 * fresh one, and the cooldown rotates the window so everything is reached.
 *
 * @return void
 */
function chip_affiliatewp_sweep_processing_payouts() {
	/**
	 * Filters how many processing payouts a single sweep requeries.
	 *
	 * @param int $limit Maximum payouts per run.
	 */
	$limit = (int) apply_filters( 'chip_affiliatewp_sweep_limit', 50 );

	if ( $limit < 1 ) {
		return;
	}

	/*
	 * Read a wider window than the budget so cooldown filtering still leaves
	 * candidates. Capped so the query itself stays cheap.
	 */
	$window = max( $limit * 4, 200 );

	$payouts = affiliate_wp()->affiliates->payouts->get_payouts(
		array(
			'payout_method' => 'chip',
			'status'        => 'processing',
			'number'        => $window,
			'orderby'       => 'payout_id',
			'order'         => 'ASC',
		)
	);

	if ( empty( $payouts ) ) {
		return;
	}

	$cooldown = 10 * MINUTE_IN_SECONDS;
	$checked  = 0;

	foreach ( $payouts as $payout ) {
		if ( $checked >= $limit ) {
			break;
		}

		if ( ! is_object( $payout ) || empty( $payout->payout_id ) ) {
			continue;
		}

		// Respect the per-payout backoff when a recent check already happened.
		$data    = chip_affiliatewp_payout_data( $payout );
		$against = chip_affiliatewp_parse_utc( chip_affiliatewp_array_value( $data, 'last_checked', '' ) );

		if ( $against && ( time() - $against ) < $cooldown ) {
			continue;
		}

		chip_affiliatewp_check_payout_status( (int) $payout->payout_id, false );

		++$checked;
	}
}

/**
 * Submits every CHIP payout of a completed payout batch.
 *
 * Submissions are fanned out one Action Scheduler action per payout (the same
 * pattern Stripe uses) so a large batch cannot hit the PHP time limit part
 * way through a long chain of synchronous CHIP round-trips.
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

	$delay = 0;

	foreach ( $payouts as $payout ) {
		as_schedule_single_action(
			time() + $delay,
			'chip_affiliatewp_submit_payout_action',
			array( 'payout_id' => (int) $payout->payout_id ),
			'chip-affiliatewp'
		);

		// Stagger submissions so concurrent bank-account lookups do not pile up.
		$delay += 5;
	}
}

add_action( 'chip_affiliatewp_submit_payout_action', 'chip_affiliatewp_run_scheduled_submission' );

/**
 * Handles the legacy "pay everyone via CHIP Send" entry point.
 *
 * AffiliateWP exposes two batch entry points. The current one submits
 * `payout_methods[]` (plural) and always routes through the async batch
 * processor. The legacy one submits a singular `payout_method` and instead
 * fires `affwp_process_payout_{method}` for the method to handle. Without a
 * listener here, that entry point (method-specific links and custom
 * integrations) creates nothing at all — the button appears to do nothing.
 *
 * The work itself is delegated to the core batch processor so CHIP payouts are
 * built, counted, and finalized exactly like every other method's; our
 * submission step hooks the batch completion action.
 *
 * @param string   $start          Referrals start date.
 * @param string   $end            Referrals end date.
 * @param string   $minimum        Minimum payout amount.
 * @param int|bool $affiliate_id   Affiliate ID, or false for all affiliates.
 * @param string   $payout_method  Payout method key.
 * @param bool     $bypass_holding Whether to bypass the holding period.
 * @return void
 */
function chip_affiliatewp_process_bulk_payout( $start, $end, $minimum, $affiliate_id, $payout_method, $bypass_holding ) {
	if ( ! current_user_can( 'manage_payouts' ) ) {
		wp_die( esc_html__( 'You do not have permission to process payouts.', 'chip-for-affiliatewp' ) );
	}

	if ( ! chip_affiliatewp_is_payout_method_enabled( true, 'chip' ) ) {
		wp_die( esc_html__( 'Please enable CHIP Send and add your API credentials before attempting to process payments.', 'chip-for-affiliatewp' ) );
	}

	if ( ! class_exists( '\AffWP\Payouts\Batch_Payout_Processor' ) ) {
		require_once AFFILIATEWP_PLUGIN_DIR . 'includes/payouts/class-batch-payout-processor.php';
	}

	/*
	 * The legacy payload is singular, but the processor speaks the plural
	 * filter form. Map it across so the batch is built and dispatched through
	 * the same path as the current form.
	 */
	$data = array(
		'user_name'      => $affiliate_id ? affwp_get_affiliate_username( (int) $affiliate_id ) : '',
		'from'           => $start,
		'to'             => $end,
		'minimum'        => $minimum,
		'bypass_holding' => (int) $bypass_holding,
		'payout_method'  => 'chip',
	);

	$result = \AffWP\Payouts\Batch_Payout_Processor::enqueue_async_batch( $data );

	if ( is_wp_error( $result ) ) {
		wp_safe_redirect(
			affwp_admin_url(
				'payouts',
				array( 'affwp_notice' => 'payout_batch_empty' )
			)
		);
		exit;
	}

	wp_safe_redirect(
		affwp_admin_url(
			'payouts',
			array(
				'tab'          => 'batches',
				'batch_id'     => (int) $result,
				'affwp_notice' => 'batch_submitted',
			)
		)
	);
	exit;
}
add_action( 'affwp_process_payout_chip', 'chip_affiliatewp_process_bulk_payout', 10, 6 );

/**
 * Runs a scheduled single-payout submission.
 *
 * @param int $payout_id Payout ID.
 * @return void
 */
function chip_affiliatewp_run_scheduled_submission( $payout_id ) {
	if ( ! affiliate_wp()->settings->get( 'chip_payouts' ) || ! chip_affiliatewp_has_credentials() ) {
		return;
	}

	chip_affiliatewp_submit_payout( absint( $payout_id ) );
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
		return new WP_Error( 'chip_missing_credentials', __( 'Please enter your CHIP Send API credentials in AffiliateWP → Settings → Payouts → CHIP Send before attempting to process payments.', 'chip-for-affiliatewp' ) );
	}

	$reference    = chip_affiliatewp_reference_prefix() . '-R-' . $referral_id;
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
		$user          = get_userdata( affwp_get_affiliate_user_id( $referral->affiliate_id ) );
		$payment_email = is_a( $user, 'WP_User' ) ? $user->user_email : '';
	}

	if ( empty( $payment_email ) ) {
		return new WP_Error( 'chip_no_email', __( 'This affiliate account does not have a payment email.', 'chip-for-affiliatewp' ) );
	}

	$instruction_description = chip_affiliatewp_sanitize_description(
		'' !== (string) $referral->description
			? (string) $referral->description
			/* translators: %d: Referral ID. */
			: sprintf( __( 'Commission for referral No.%d', 'chip-for-affiliatewp' ), $referral_id )
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
