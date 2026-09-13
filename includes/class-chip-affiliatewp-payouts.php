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
 * Reads the plugin's metadata for a payout.
 *
 * Prefers the payout meta table. Falls back to the description column so rows
 * written before the move still resolve.
 *
 * @param object|int $payout Payout object or ID.
 * @return array
 */
function chip_affiliatewp_payout_data( $payout ) {
	$payout = is_object( $payout ) ? $payout : affwp_get_payout( $payout );

	if ( ! $payout ) {
		return array();
	}

	$payout_id = absint( $payout->payout_id ?? $payout->ID ?? 0 );

	if ( $payout_id && function_exists( 'affwp_get_payout_meta' ) ) {
		$data = affwp_get_payout_meta( $payout_id, 'chip_payout_data', true );

		if ( is_array( $data ) ) {
			return $data;
		}
	}

	// Legacy: state stored as JSON in the description column.
	$legacy = json_decode( (string) $payout->description, true );

	return is_array( $legacy ) ? $legacy : array();
}

/**
 * Persists payout metadata.
 *
 * The state lives in the payout meta table (the same place Stripe keeps its
 * rail) rather than in the description column. AffiliateWP renders a failed
 * payout's raw description as its "Error" message in the admin drawer, so
 * keeping JSON there showed the merchant a JSON blob instead of a sentence.
 *
 * @param int   $payout_id Payout ID.
 * @param array $data      Full metadata payload to store.
 * @return bool
 */
function chip_affiliatewp_update_payout_data( $payout_id, $data ) {
	$payout_id = absint( $payout_id );

	if ( ! $payout_id || ! function_exists( 'affwp_update_payout_meta' ) ) {
		return false;
	}

	affwp_update_payout_meta( $payout_id, 'chip_payout_data', $data );

	/*
	 * The review list is cached. A state written here decides whether this
	 * payout belongs on that list, so drop the cache rather than let the panel
	 * keep showing a payout as under review after it has completed.
	 */
	if ( function_exists( 'chip_affiliatewp_flush_review_list_cache' ) ) {
		chip_affiliatewp_flush_review_list_cache();
	}

	/*
	 * A failed payout's description is shown verbatim as the error message, so
	 * keep a human-readable reason there and nothing else. On any other status
	 * the description is a notes field, so leave it untouched.
	 */
	$payout = affwp_get_payout( $payout_id );

	if ( $payout && 'failed' === $payout->status ) {
		$reason = isset( $data['error'] ) ? (string) $data['error'] : '';

		affiliate_wp()->affiliates->payouts->update(
			$payout_id,
			array( 'description' => $reason ),
			'',
			'payout'
		);
	}

	return true;
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

	if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && 'mysql' === ( $GLOBALS['wpdb']->is_mysql ? 'mysql' : 'other' ) ) {
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

	/*
	 * Checked after formatting, because that is the figure CHIP is sent: a
	 * payout of 0.001 formats to "0.00", which CHIP refuses. Catching it here
	 * turns a rejected submission into a clear, actionable failure.
	 */
	if ( (float) chip_affiliatewp_format_amount( $payout->amount ) <= 0 ) {
		return chip_affiliatewp_fail_payout(
			$payout_id,
			sprintf(
				/* translators: %s: the payout amount. */
				__( 'This payout rounds to %s, below the smallest amount CHIP Send can transfer.', 'chip-for-affiliatewp' ),
				chip_affiliatewp_format_amount( $payout->amount )
			),
			'chip_invalid_amount'
		);
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

	/*
	 * Resolve the mode once and use it for the submission and for the record.
	 * Reading the setting again after the POST would let a mode change in
	 * between store a mode the instruction does not live in — the instruction
	 * would be at one CHIP host and every later requery aimed at the other.
	 */
	$mode = chip_affiliatewp_current_mode();

	$bank_account = chip_affiliatewp_ensure_bank_account( $payout->affiliate_id );

	if ( is_wp_error( $bank_account ) ) {
		return chip_affiliatewp_fail_payout(
			$payout_id,
			$bank_account->get_error_message(),
			$bank_account->get_error_code(),
			chip_affiliatewp_error_http_status( $bank_account )
		);
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

	$data         = chip_affiliatewp_payout_data( $payout );
	$attempt      = chip_affiliatewp_payout_attempt( $data );
	$reference    = chip_affiliatewp_instruction_reference( $payout_id, $attempt );
	$referral_ids = chip_affiliatewp_payout_referral_ids( $payout );

	/*
	 * Ask CHIP whether this reference already exists BEFORE sending. The
	 * reference is the idempotency key, so a hit means an instruction for this
	 * attempt is already there — either a previous submission whose response we
	 * never saw, or one created from the same reference another way. Adopting
	 * it is what stops a second payment.
	 *
	 * A rejection is the one case where adopting is wrong: the stored
	 * instruction is dead and CHIP will refuse the reference forever. That is
	 * handled below by advancing the attempt, so this lookup never adopts a
	 * refused instruction.
	 */
	$existing = chip_affiliatewp_list_instruction_by_reference( $reference, $mode );

	if ( is_array( $existing ) && ! empty( $existing['id'] ) ) {
		$existing_state = strtolower( (string) ( $existing['state'] ?? '' ) );

		if ( ! chip_affiliatewp_state_is_terminal( $existing_state ) ) {
			/*
			 * Live instruction for this attempt: adopt it rather than sending
			 * again. The instruction may have completed while we were unaware.
			 *
			 * Only if it is this payout's own. A reference is unique per CHIP
			 * account rather than per site, so another installation sharing the
			 * credentials can hold the same reference for a different payment.
			 */
			if ( ! chip_affiliatewp_instruction_belongs_to_payout( $payout, $existing ) ) {
				return chip_affiliatewp_fail_payout(
					$payout_id,
					__( 'A different payment already uses this reference at CHIP, so this payout cannot be sent under it. Each site sharing a CHIP account needs its own reference prefix.', 'chip-for-affiliatewp' ),
					'chip_reference_conflict'
				);
			}

			if ( ! chip_affiliatewp_instruction_belongs_to_payout( $payout, $existing ) ) {
				return chip_affiliatewp_fail_payout(
					$payout_id,
					__( 'A different payment already uses this reference at CHIP, so this payout cannot be sent under it. Each site sharing a CHIP account needs its own reference prefix.', 'chip-for-affiliatewp' ),
					'chip_reference_conflict'
				);
			}

			chip_affiliatewp_adopt_instruction( $payout_id, $payout, $existing, $reference );

			return true;
		}

		/*
		 * The reference is taken by a dead instruction. Advance to a fresh
		 * attempt so this submission uses a reference CHIP will accept.
		 */
		$attempt   = chip_affiliatewp_payout_attempt( $data ) + 1;
		$reference = chip_affiliatewp_instruction_reference( $payout_id, $attempt );
	}

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

		if ( (float) chip_affiliatewp_format_amount( $amount ) <= 0 ) {
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

	$response = chip_affiliatewp_request( 'POST', '/send/send_instructions', $body, array(), $mode );

	if ( is_wp_error( $response ) ) {
		/*
		 * A duplicate-reference rejection means the instruction for this
		 * attempt already exists — a submission whose response we never saw,
		 * or a webhook that attached the payout row first. Adopt it instead of
		 * re-sending, which is what keeps a retry from paying twice.
		 */
		$existing = chip_affiliatewp_list_instruction_by_reference( $reference, $mode );

		if ( is_array( $existing ) && ! empty( $existing['id'] ) ) {
			$existing_state = strtolower( (string) ( $existing['state'] ?? '' ) );

			if ( chip_affiliatewp_state_is_terminal( $existing_state ) ) {
				/*
				 * The reference is burnt by a dead instruction. Report it under
				 * the instruction-state code so fail_payout advances the
				 * attempt, giving the retry a reference CHIP has never seen.
				 */
				return chip_affiliatewp_fail_payout(
					$payout_id,
					$response->get_error_message(),
					'chip_instruction_' . $existing_state,
					chip_affiliatewp_error_http_status( $response )
				);
			}

			chip_affiliatewp_adopt_instruction( $payout_id, $payout, $existing, $reference );

			return true;
		}

		return chip_affiliatewp_fail_payout(
			$payout_id,
			$response->get_error_message(),
			$response->get_error_code(),
			chip_affiliatewp_error_http_status( $response )
		);
	}

	if ( empty( $response['id'] ) ) {
		return chip_affiliatewp_fail_payout( $payout_id, __( 'CHIP Send did not return a send instruction ID.', 'chip-for-affiliatewp' ), 'chip_instruction_failed' );
	}

	$data['instruction_id']  = (int) $response['id'];
	$data['attempt']         = $attempt;
	$data['state']           = (string) chip_affiliatewp_array_value( $response, 'state', 'received' );
	$data['receipt_url']     = chip_affiliatewp_safe_receipt_url( chip_affiliatewp_array_value( $response, 'receipt_url', '' ) );
	$data['bank_account_id'] = (int) $bank_account['id'];
	$data['last_checked']   = gmdate( 'Y-m-d H:i:s' );
	$data['poll_count']     = 0;
	$data['mode']           = $mode;

	// The instruction was accepted, so any earlier failure no longer applies.
	unset( $data['error'], $data['error_status'] );

	chip_affiliatewp_update_payout_data( $payout_id, $data );

	$update = array(
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
 * Whether a found instruction actually belongs to this payout.
 *
 * A reference is unique per CHIP merchant account, not per site. Two
 * installations sharing one CHIP account — a staging site pointed at the same
 * credentials, or a merchant running two stores — can therefore mint the same
 * reference for different payouts, because the reference is built from the
 * payout's own ID and a short site prefix. Adopting on the reference alone
 * would attach this payout to another site's instruction: the payout would take
 * that instruction's state, and a completed one would mark these referrals paid
 * for money that went somewhere else.
 *
 * The amount, and the destination account when both sides state one, are what
 * must agree. The instruction id is not used here: a payout adopting an
 * instruction has not recorded one yet, which is the whole point of adopting.
 *
 * @param object $payout      Payout row.
 * @param array  $instruction Instruction payload from CHIP.
 * @return bool
 */
function chip_affiliatewp_instruction_belongs_to_payout( $payout, $instruction ) {
	$expected_amount = (float) chip_affiliatewp_format_amount( $payout->amount );
	$actual_amount   = (float) chip_affiliatewp_format_amount( chip_affiliatewp_array_value( $instruction, 'amount', 0 ) );

	if ( abs( $expected_amount - $actual_amount ) > 0.001 ) {
		return false;
	}

	/*
	 * The destination account. A payout whose bank account does not match is
	 * paying a different recipient.
	 *
	 * The row does not carry the account id — its service_id is the instruction
	 * id — so it is asked for when needed rather than read here.
	 */
	$instruction_account = absint( chip_affiliatewp_array_value( $instruction, 'bank_account_id' ) );

	if ( ! $instruction_account ) {
		// Not stated: the amount agreeing is as far as this can be taken.
		return true;
	}

	/*
	 * If the payout already names the same instruction, the destination is
	 * settled: this is the instruction it was submitted under.
	 */
	$known_instruction = absint( chip_affiliatewp_array_value( $instruction, 'id' ) );
	$recorded          = absint( $payout->service_id );

	if ( $recorded && $known_instruction && $recorded === $known_instruction ) {
		return true;
	}

	$affiliate_id = absint( $payout->affiliate_id );

	if ( ! $affiliate_id ) {
		return false;
	}

	$account = chip_affiliatewp_ensure_bank_account( $affiliate_id );

	if ( is_wp_error( $account ) || empty( $account['id'] ) ) {
		return false;
	}

	return absint( $account['id'] ) === $instruction_account;
}

/**
 * Adopts an existing CHIP instruction for a payout that already has a row.
 *
 * @param int    $payout_id Payout ID.
 * @param object $payout    Payout row.
 * @param array  $instruction Instruction payload from CHIP.
 * @param string $reference Reference the instruction was found under.
 * @return void
 */
function chip_affiliatewp_adopt_instruction( $payout_id, $payout, $instruction, $reference ) {
	$data = chip_affiliatewp_payout_data( $payout );

	$data['instruction_id'] = (int) $instruction['id'];
	$data['receipt_url']    = chip_affiliatewp_safe_receipt_url( chip_affiliatewp_array_value( $instruction, 'receipt_url', '' ) );
	$data['last_checked']   = gmdate( 'Y-m-d H:i:s' );

	// The instruction exists, so any earlier failure note no longer applies.
	unset( $data['error'], $data['error_status'] );

	chip_affiliatewp_update_payout_data( $payout_id, $data );

	$update = array(
		'service_id'           => (int) $instruction['id'],
		'service_invoice_link' => $data['receipt_url'],
	);

	if ( 'paid' !== $payout->status ) {
		$update['status'] = 'processing';
	}

	affiliate_wp()->affiliates->payouts->update( $payout_id, $update, '', 'payout' );

	/*
	 * The adopted instruction may already be terminal — a submission whose
	 * response we never saw could have completed or been refused since. Apply
	 * its state now so the payout does not wait on a webhook that has already
	 * been delivered and discarded.
	 */
	chip_affiliatewp_apply_instruction( $payout_id, $instruction );
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
 * @param int      $payout_id  Payout ID.
 * @param string   $reason     Human-readable failure reason.
 * @param string   $error_code Optional. Machine-readable error code used for
 *                             failure classification. Default empty string.
 * @param int|null $http_status Optional. HTTP status from the API response,
 *                             when the failure came from one. Default null.
 * @return WP_Error
 */
function chip_affiliatewp_fail_payout( $payout_id, $reason, $error_code = '', $http_status = null ) {
	$payout = affwp_get_payout( $payout_id );

	$data                 = $payout ? chip_affiliatewp_payout_data( $payout ) : array();
	$data['error']        = $reason;
	$data['last_checked'] = gmdate( 'Y-m-d H:i:s' );

	if ( null !== $http_status ) {
		$data['error_status'] = (int) $http_status;
	}

	/*
	 * Advance the attempt when CHIP has definitively refused or removed the
	 * instruction. The reference is the idempotency key and is stored at CHIP
	 * permanently: reusing it on the next try would be refused, and adoption
	 * would find this same dead instruction and park the payout on it — the
	 * retry would look successful while no money moved. A new attempt number
	 * yields a reference CHIP has never seen, so the retry sends for real.
	 *
	 * An instruction CHIP no longer holds is in the same position: it cannot
	 * settle, and its reference is spent.
	 *
	 * An instruction that is merely unverified or in flight is left alone: it
	 * still exists at CHIP and resolves on its own, and keeping its reference
	 * is what makes a repeat submission adopt instead of double-pay.
	 */
	$terminal_at_chip = in_array(
		strtolower( (string) $error_code ),
		array( 'chip_instruction_rejected', 'chip_instruction_deleted', 'chip_instruction_not_found' ),
		true
	);

	if ( $terminal_at_chip ) {
		unset( $data['instruction_id'] );
		$data['attempt'] = chip_affiliatewp_payout_attempt( $data ) + 1;
	}

	/*
	 * A failed payout has left review for good: the instruction is dead and the
	 * payout will not move again without a retry. Clear the in-review state so
	 * it does not describe a stay that is over — and so a later retry that gets
	 * parked again is reported as the fresh problem it is.
	 */
	unset( $data['review_notified'], $data['note'] );

	/*
	 * Drop the receipt. CHIP issues one as soon as an instruction exists, so a
	 * payout that was later refused still holds a link to a receipt for a
	 * transfer that did not happen — and that link is shown to the merchant as
	 * the payout's "invoice" on a failed row. A receipt is evidence of payment,
	 * so it must not outlive the payout's claim to have been paid.
	 */
	unset( $data['receipt_url'] );

	if ( $payout ) {
		/*
		 * State goes to payout meta; the description carries the plain reason,
		 * because AffiliateWP renders a failed payout's description verbatim as
		 * the error message in the admin drawer. A hint naming the screen to fix
		 * is appended for merchant-side problems, whose raw provider text does
		 * not say where to act — and CHIP has no dashboard URL the drawer's
		 * button can open.
		 */
		chip_affiliatewp_update_payout_data( $payout_id, $data );

		$message = $reason;

		if ( function_exists( 'chip_affiliatewp_failure_hint' ) ) {
			$hint = chip_affiliatewp_failure_hint( $error_code, $http_status );

			if ( '' !== $hint ) {
				$message = trim( $reason . ' ' . $hint );
			}
		}

		$update = array(
			'status'      => 'failed',
			'description' => $message,
		);

		/*
		 * Clear the row's instruction ID too. The requery path restores a
		 * missing meta instruction_id from this column, so leaving it behind
		 * would resurrect the dead instruction on the next sweep and undo the
		 * cleanup above.
		 *
		 * The receipt link goes with it: it is rendered as this payout's
		 * invoice, and a failed payout must not carry a link to a receipt for
		 * money that never moved.
		 */
		if ( $terminal_at_chip ) {
			$update['service_id'] = 0;
		}

		$update['service_invoice_link'] = '';

		/*
		 * Classify the failure so AffiliateWP can drive the Retry button, the
		 * automatic retry sweep, and the action-required email. Without a
		 * class every failure reads as UNKNOWN and the affiliate is never
		 * told what to fix.
		 */
		if ( function_exists( 'chip_affiliatewp_classify_failure' ) ) {
			$update['failure_class'] = chip_affiliatewp_classify_failure( $error_code, $reason, $http_status );
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
 * Skips when an identical check is already pending. Webhooks, requeries and
 * retries all call this for the same payout, and without the guard every
 * delivery would stack another action — a busy payout could accumulate dozens
 * of near-identical requeries, each costing an API call.
 *
 * @param int $payout_id Payout ID.
 * @param int $delay     Delay in seconds.
 * @return void
 */
function chip_affiliatewp_schedule_check( $payout_id, $delay ) {
	if ( ! function_exists( 'as_schedule_single_action' ) ) {
		return;
	}

	$payout_id = absint( $payout_id );
	$args      = array( 'payout_id' => $payout_id );

	// Action Scheduler's own dedupe: a pending check for this payout is enough.
	if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'chip_affiliatewp_check_payout_status', $args, chip_affiliatewp_as_group() ) ) {
		return;
	}

	if ( function_exists( 'as_next_scheduled_action' ) && false !== as_next_scheduled_action( 'chip_affiliatewp_check_payout_status', $args, chip_affiliatewp_as_group() ) ) {
		return;
	}

	as_schedule_single_action(
		time() + $delay,
		'chip_affiliatewp_check_payout_status',
		$args,
		chip_affiliatewp_as_group()
	);
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
	if ( 'failed' === $payout->status && ! chip_affiliatewp_state_is_settled( $state ) ) {
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
	if ( chip_affiliatewp_state_is_settled( $state ) ) {
		unset( $data['error'], $data['error_status'] );
	}

	/*
	 * CHIP's note about an in-flight instruction is not sticky. It is sent
	 * while the instruction is parked and comes back as null once the problem
	 * is resolved, so a note we already hold has to be replaced or cleared to
	 * match what CHIP is saying now — keeping it would show the merchant a
	 * reason that has since been fixed, and they would chase it again.
	 *
	 * The field is unbounded at the source — CHIP allows up to 64 KiB — and the
	 * note is stored on the payout meta, rendered in the admin review list, and
	 * quoted in the merchant email. An outsized reason would bloat all three,
	 * so it is trimmed to something a person can actually read.
	 */
	$note = trim( (string) chip_affiliatewp_array_value( $instruction, 'rejection_reason', '' ) );
	$note = chip_affiliatewp_sanitize_note( $note );

	if ( chip_affiliatewp_state_is_settled( $state ) || '' === $note ) {
		unset( $data['note'] );
	} else {
		$data['note'] = $note;
	}

	/*
	 * The review notification is per stay in review, not per payout. Leaving
	 * review (settling, or being refused) ends the current stay, so the flag is
	 * cleared: a payout that returns to review later is a fresh problem with a
	 * fresh reason, and the merchant must be told about it. Keeping the flag
	 * would silently suppress that second notification.
	 */
	if ( ! chip_affiliatewp_state_needs_review( $state ) ) {
		unset( $data['review_notified'] );
	}

	if ( ! empty( $instruction['id'] ) ) {
		$data['instruction_id'] = (int) $instruction['id'];
	}

	$receipt_url = chip_affiliatewp_safe_receipt_url( chip_affiliatewp_array_value( $instruction, 'receipt_url', '' ) );

	if ( '' !== $receipt_url ) {
		$data['receipt_url'] = $receipt_url;
	}

	switch ( $state ) {
		case 'completed':
			chip_affiliatewp_update_payout_data( $payout_id, $data );

			affiliate_wp()->affiliates->payouts->update(
				$payout_id,
				array(
					'status'               => 'paid',
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
	chip_affiliatewp_update_payout_data( $payout_id, $data );

	return false;
}

/**
 * Remembers that a reference is burnt for a referral.
 *
 * The single-referral path submits before a payout row exists, so the attempt
 * number has nowhere else to live. Storing it on the referral keeps the next
 * attempt off a reference CHIP has already refused.
 *
 * @param object $referral  Referral object.
 * @param string $reference Reference that CHIP refused.
 * @return void
 */
function chip_affiliatewp_note_burnt_reference( $referral, $reference ) {
	$referral_id = absint( $referral->ID ?? 0 );

	if ( ! $referral_id ) {
		return;
	}

	$burnt = (array) affwp_get_referral_meta( $referral_id, 'chip_burnt_references', true );

	if ( ! is_array( $burnt ) ) {
		$burnt = array();
	}

	$burnt[] = (string) $reference;

	affwp_update_referral_meta( $referral_id, 'chip_burnt_references', array_values( array_unique( $burnt ) ) );
}

/**
 * Returns the references CHIP has refused for a referral.
 *
 * @param int $referral_id Referral ID.
 * @return string[]
 */
function chip_affiliatewp_burnt_references( $referral_id ) {
	$burnt = affwp_get_referral_meta( absint( $referral_id ), 'chip_burnt_references', true );

	return is_array( $burnt ) ? array_map( 'strval', $burnt ) : array();
}

/**
 * Creates the payout row for an instruction CHIP already holds.
 *
 * The single-referral path submits before any payout row exists, so adoption
 * there has to create the row around the found instruction rather than update
 * one.
 *
 * @param object      $referral    Referral object.
 * @param array       $instruction Instruction payload from CHIP.
 * @param string      $reference   Reference the instruction was found under.
 * @param string|null $mode        Optional. Mode the instruction lives in.
 * @return int Payout ID, or 0 when the row could not be created.
 */
function chip_affiliatewp_adopt_referral_instruction( $referral, $instruction, $reference, $mode = null ) {
	$instruction_id = (int) ( $instruction['id'] ?? 0 );

	if ( ! $instruction_id ) {
		return 0;
	}

	/*
	 * Record the mode the instruction was actually found in, not the site's
	 * mode at adoption time. A referral submitted in test mode and adopted
	 * after a switch to live would otherwise be recorded as live, and every
	 * later requery would resolve a staging instruction against the live host.
	 */
	$mode = in_array( $mode, array( 'test', 'live' ), true ) ? $mode : chip_affiliatewp_current_mode();

	$receipt_url = chip_affiliatewp_safe_receipt_url( chip_affiliatewp_array_value( $instruction, 'receipt_url', '' ) );

	$payout_id = affwp_add_payout(
		array(
			'affiliate_id'         => $referral->affiliate_id,
			'referrals'            => $referral->ID,
			'amount'               => $referral->amount,
			'payout_method'        => 'chip',
			'status'               => 'processing',
			'service_id'           => $instruction_id,
			'service_invoice_link' => $receipt_url,
		)
	);

	if ( ! $payout_id ) {
		return 0;
	}

	chip_affiliatewp_update_payout_data(
		(int) $payout_id,
		array(
			'instruction_id'  => $instruction_id,
			'reference'       => (string) $reference,
			'state'           => (string) chip_affiliatewp_array_value( $instruction, 'state', 'received' ),
			'receipt_url'     => $receipt_url,
			'bank_account_id' => absint( chip_affiliatewp_array_value( $instruction, 'bank_account_id', 0 ) ),
			'referral_ids'   => array( (int) $referral->ID ),
			'last_checked'   => gmdate( 'Y-m-d H:i:s' ),
			'poll_count'     => 0,
			'mode'           => $mode,
		)
	);

	/*
	 * The adopted instruction may already be terminal, and its webhook was
	 * delivered long ago, so apply the state now rather than waiting for a
	 * delivery that will not come again.
	 */
	chip_affiliatewp_apply_instruction( (int) $payout_id, $instruction );

	return (int) $payout_id;
}

/**
 * Fetches a CHIP Send instruction by ID.
 *
 * @param int         $instruction_id CHIP Send instruction ID.
 * @param string|null $mode           Optional. Mode the instruction was created
 *                                    in. Defaults to the site-wide setting.
 * @return array|WP_Error Instruction payload, or WP_Error on failure.
 */
function chip_affiliatewp_get_instruction( $instruction_id, $mode = null ) {
	return chip_affiliatewp_request( 'GET', '/send/send_instructions/' . rawurlencode( (string) $instruction_id ), array(), array(), $mode );
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

	/*
	 * A failed payout carrying an instruction must reconcile instead of being
	 * retried: the instruction may have completed after a transient failure
	 * (timeout between us and CHIP). Requery and let apply_instruction heal the
	 * record so the affiliate cannot be paid twice.
	 *
	 * Requery against the mode this payout was SUBMITTED in, not the site-wide
	 * setting. A merchant flipping to Test Mode would otherwise poll a live
	 * instruction against staging: the ID does not exist there, so the payout
	 * stalls on 404s and the failure looks like a CHIP outage.
	 */
	$stored_mode = (string) chip_affiliatewp_array_value( $data, 'mode', '' );
	$stored_mode = in_array( $stored_mode, array( 'test', 'live' ), true ) ? $stored_mode : null;

	$response = chip_affiliatewp_get_instruction( (int) $data['instruction_id'], $stored_mode );

	if ( is_wp_error( $response ) ) {
		$status = function_exists( 'chip_affiliatewp_error_http_status' )
			? (int) chip_affiliatewp_error_http_status( $response )
			: 0;

		/*
		 * A 404 means the instruction does not exist at CHIP, and it will not
		 * appear later — the record is gone. Treating it as a transient outage
		 * leaves the payout requerying a dead id forever: the capped Action
		 * Scheduler checks run out, then the hourly sweep keeps polling for the
		 * life of the store, one API call each time, for an instruction that
		 * can never answer.
		 *
		 * Fail the payout instead and release the referrals, so the merchant
		 * sees a settled failure they can act on rather than a payout that sits
		 * in processing indefinitely.
		 */
		if ( 404 === $status ) {
			chip_affiliatewp_fail_payout(
				$payout_id,
				sprintf(
					/* translators: %s: CHIP Send instruction ID. */
					__( 'CHIP Send has no record of instruction %s, so it can no longer be tracked. Any funds it was meant to move were not sent.', 'chip-for-affiliatewp' ),
					(string) $data['instruction_id']
				),
				'chip_instruction_not_found',
				$status
			);

			return;
		}

		/*
		 * Count the failed check too. Without it the cap below is never
		 * reached on this path: an unreachable CHIP would reschedule a check
		 * every five minutes indefinitely, accumulating Action Scheduler rows
		 * for a payout that is going nowhere. Counting failures means the
		 * same cap ends this path, and the hourly sweep takes over.
		 */
		$attempts = (int) chip_affiliatewp_array_value( $data, 'poll_count', 0 );

		$data['poll_count']   = $attempts + 1;
		$data['last_checked'] = gmdate( 'Y-m-d H:i:s' );

		chip_affiliatewp_update_payout_data( $payout_id, $data );

		if ( $reschedule && $attempts < 48 ) {
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

		/*
		 * Cap Action Scheduler re-checks at one day; the hourly sweep keeps
		 * healing afterwards.
		 *
		 * A state CHIP has parked for manual review is not rescheduled: it will
		 * not change without a human, so a 15-minute poll would spend a day of
		 * requests on a row that cannot move. The hourly sweep still picks it
		 * up on its much longer review cooldown, and the merchant has been told.
		 */
		if ( $reschedule && $attempts < 48 && ! chip_affiliatewp_state_needs_review( (string) ( $data['state'] ?? '' ) ) ) {
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

	/*
	 * An instruction CHIP has parked for manual review will not move until a
	 * human intervenes. Requerying it on the ordinary cooldown would burn the
	 * sweep's per-run budget on rows that cannot change, starving the payouts
	 * that are genuinely in flight — an older reviewed payout sorts first, so
	 * it would win a slot every single run. Check these rarely instead; the
	 * merchant is told about them separately.
	 */
	$review_cooldown = (int) apply_filters( 'chip_affiliatewp_review_requery_cooldown', 6 * HOUR_IN_SECONDS );

	$checked = 0;

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

		/*
		 * Named for the cooldown, not the query window above: reusing $window
		 * here would silently discard the row limit after the first iteration,
		 * and anything reading it later would get seconds instead of rows.
		 */
		$cooldown_for_payout = chip_affiliatewp_state_needs_review( (string) ( $data['state'] ?? '' ) )
			? $review_cooldown
			: $cooldown;

		if ( $against && ( time() - $against ) < $cooldown_for_payout ) {
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
		$payout_id = (int) $payout->payout_id;
		$args      = array( 'payout_id' => $payout_id );

		/*
		 * Skip a payout that already has a submission queued. This hook fires
		 * whenever the batch completes, which can happen more than once for the
		 * same batch (saved again, or retried), and scheduling a second action
		 * per payout adds rows that only ever re-read the same locked row.
		 *
		 * Submitting twice is not a payment risk — the submission path takes an
		 * advisory lock and returns early once an instruction exists — but the
		 * queue should not grow for it.
		 */
		if ( function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( 'chip_affiliatewp_submit_payout_action', $args, chip_affiliatewp_as_group() ) ) {
			continue;
		}

		if ( function_exists( 'as_next_scheduled_action' )
			&& false !== as_next_scheduled_action( 'chip_affiliatewp_submit_payout_action', $args, chip_affiliatewp_as_group() ) ) {
			continue;
		}

		as_schedule_single_action(
			time() + $delay,
			'chip_affiliatewp_submit_payout_action',
			$args,
			chip_affiliatewp_as_group()
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

	/*
	 * CHIP Send only settles MYR, and the API takes a bare number with no
	 * currency field — a store configured in USD would send "100.00" that CHIP
	 * reads as RM100. The batch path refuses this too; without the same check
	 * here, paying a single referral from the Referrals screen would quietly
	 * send the wrong amount.
	 */
	if ( 'MYR' !== chip_affiliatewp_currency() ) {
		return new WP_Error(
			'chip_currency_unsupported',
			sprintf(
				/* translators: %s: the store's currency code. */
				__( 'CHIP Send pays out in MYR only, but this store is set to %s. Change the currency in AffiliateWP settings to send payouts.', 'chip-for-affiliatewp' ),
				chip_affiliatewp_currency()
			)
		);
	}

	if ( (float) chip_affiliatewp_format_amount( $referral->amount ) <= 0 ) {
		return new WP_Error(
			'chip_invalid_amount',
			sprintf(
				/* translators: %s: the referral amount. */
				__( 'This referral rounds to %s, below the smallest amount CHIP Send can transfer.', 'chip-for-affiliatewp' ),
				chip_affiliatewp_format_amount( $referral->amount )
			)
		);
	}

	/*
	 * The mode is resolved once and threaded through the lookups and adoption
	 * below. Reading the setting at each point would let a merchant's mode
	 * switch mid-flow land the instruction and its record in different
	 * environments.
	 */
	$mode = chip_affiliatewp_current_mode();

	/*
	 * The reference is the idempotency key at CHIP and is stored permanently,
	 * so it carries an attempt number for the same reason the batch path does:
	 * a repeat within an attempt is refused and adopted (safe after an unclear
	 * response), while an attempt whose instruction CHIP has refused needs a
	 * reference CHIP has never seen, or the retry would be refused too and
	 * adoption would park the referral on a dead instruction.
	 *
	 * The attempt is derived from how many instructions CHIP already holds for
	 * this referral, so it survives without needing a payout row to store it.
	 */
	$base_reference = chip_affiliatewp_reference_prefix() . '-R-' . $referral_id;
	$attempt        = 1;
	$reference      = $base_reference;
	$burnt          = chip_affiliatewp_burnt_references( $referral_id );

	/**
	 * Filters how many burnt references are skipped before giving up.
	 *
	 * Each refusal burns one reference for this referral. The sweep below walks
	 * past the burnt ones, and it needs a ceiling: CHIP refuses a reference
	 * permanently, so an unbounded walk would issue one API call per burnt
	 * reference on every submission.
	 *
	 * @param int $limit Maximum attempt number to reach.
	 */
	$attempt_limit = max( 2, absint( apply_filters( 'chip_affiliatewp_reference_attempt_limit', 50 ) ) );

	// Skip any reference CHIP has already refused for this referral.
	while ( $attempt < $attempt_limit && in_array( $reference, $burnt, true ) ) {
		++$attempt;
		$reference = substr( $base_reference . '-' . $attempt, 0, 40 );
	}

	/*
	 * Every reference up to the ceiling is spent, so there is nothing left to
	 * send under. Say so rather than submitting a reference CHIP has already
	 * refused: that fails identically, and each attempt burns another one, so
	 * the referral would never be paid and nothing would explain why.
	 */
	if ( $attempt >= $attempt_limit && in_array( $reference, $burnt, true ) ) {
		return new WP_Error(
			'chip_reference_exhausted',
			sprintf(
				/* translators: %d: number of attempts tried. */
				__( 'CHIP has refused every reference tried for this referral (%d so far). Contact your CHIP account manager about the recipient before trying again.', 'chip-for-affiliatewp' ),
				$attempt_limit
			)
		);
	}

	// Count existing instructions for this referral to pick the attempt number.
	$probe = chip_affiliatewp_list_instruction_by_reference( substr( $reference, 0, 40 ), $mode );

	if ( is_array( $probe ) && ! empty( $probe['id'] ) ) {
		$probe_state = strtolower( (string) ( $probe['state'] ?? '' ) );

		if ( ! chip_affiliatewp_state_is_terminal( $probe_state ) ) {
			// A live instruction already exists for this referral: adopt it.
			chip_affiliatewp_adopt_referral_instruction( $referral, $probe, $reference, $mode );

			return true;
		}

		// The stored instruction is dead; move to a reference CHIP has not seen.
		chip_affiliatewp_note_burnt_reference( $referral, $reference );

		$attempt   = max( 2, $attempt + 1 );
		$reference = substr( $base_reference . '-' . $attempt, 0, 40 );

		// Skip past any earlier dead attempts for this referral.
		while ( $attempt < $attempt_limit ) {
			$candidate = chip_affiliatewp_list_instruction_by_reference( $reference, $mode );

			if ( ! is_array( $candidate ) || empty( $candidate['id'] ) ) {
				break;
			}

			if ( ! chip_affiliatewp_state_is_terminal( (string) ( $candidate['state'] ?? '' ) ) ) {
				chip_affiliatewp_adopt_referral_instruction( $referral, $candidate, $reference, $mode );

				return true;
			}

			chip_affiliatewp_note_burnt_reference( $referral, $reference );

			++$attempt;
			$reference = substr( $base_reference . '-' . $attempt, 0, 40 );
		}
	}

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

	$response = chip_affiliatewp_request( 'POST', '/send/send_instructions', $body, array(), $mode );

	if ( is_wp_error( $response ) ) {
		/*
		 * A duplicate-reference rejection means the instruction already exists;
		 * adopt it instead of failing or re-sending. A refused instruction is
		 * the exception — it is dead and its reference is burnt, so adopting it
		 * would create a payout that can never settle. Surface the failure and
		 * let the next attempt use a fresh reference.
		 */
		$existing = chip_affiliatewp_list_instruction_by_reference( substr( $reference, 0, 40 ), $mode );

		if ( is_array( $existing ) && ! empty( $existing['id'] ) ) {
			$existing_state = strtolower( (string) ( $existing['state'] ?? '' ) );

			if ( chip_affiliatewp_state_is_terminal( $existing_state ) ) {
				/*
				 * The reference is burnt by a dead instruction. Remember that
				 * so the next attempt uses a fresh one; without this the retry
				 * would keep hitting the same refusal.
				 */
				chip_affiliatewp_note_burnt_reference( $referral, $reference );

				return $response;
			}

			$adopted = chip_affiliatewp_adopt_referral_instruction( $referral, $existing, $reference, $mode );

			if ( $adopted ) {
				return true;
			}

			return $response;
		}

		return $response;
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
		)
	);

	if ( ! $payout_id ) {
		return new WP_Error( 'chip_payout_not_created', __( 'The payout record could not be created. The referral may already have an active payout.', 'chip-for-affiliatewp' ) );
	}

	// State lives in payout meta; the description stays a notes field.
	chip_affiliatewp_update_payout_data(
		(int) $payout_id,
		array(
			'instruction_id' => (int) $response['id'],
			'reference'      => substr( $reference, 0, 40 ),
			'state'          => $state,
			'receipt_url'    => chip_affiliatewp_safe_receipt_url( chip_affiliatewp_array_value( $response, 'receipt_url', '' ) ),
			'referral_ids'   => array( $referral_id ),
			'last_checked'   => gmdate( 'Y-m-d H:i:s' ),
			'poll_count'     => 0,
			'mode'           => affiliate_wp()->settings->get( 'chip_test_mode' ) ? 'test' : 'live',
		)
	);

	chip_affiliatewp_schedule_check( (int) $payout_id, 120 );

	return true;
}
