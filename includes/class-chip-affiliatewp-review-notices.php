<?php
/**
 * Instructions CHIP Send has parked for manual review.
 *
 * CHIP Send documents `reviewing` as "requires further attention. Contact your
 * account manager for troubleshooting" — the instruction is neither moving nor
 * refused, and nothing at CHIP will move it without a human. Treating it as
 * just another in-flight state leaves the payout polling forever under a
 * "Processing" label while the affiliate waits and nobody is told.
 *
 * This file surfaces those payouts to the merchant: a card on the settings
 * panel, and a one-time email per payout so the merchant learns about it
 * without having to watch the payouts list.
 *
 * @package CHIPforAffiliateWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Cannot access directly.

/**
 * Instruction states that mean "a human must act".
 *
 * @return string[]
 */
function chip_affiliatewp_review_states() {
	/**
	 * Filters the CHIP Send instruction states that need merchant attention.
	 *
	 * @param string[] $states Lowercase state names.
	 */
	return (array) apply_filters( 'chip_affiliatewp_review_states', array( 'reviewing' ) );
}

/**
 * Whether an instruction state needs a human.
 *
 * @param string $state Instruction state.
 * @return bool
 */
function chip_affiliatewp_state_needs_review( $state ) {
	return in_array( strtolower( (string) $state ), chip_affiliatewp_review_states(), true );
}

/**
 * Returns payouts whose instruction is parked in a review state.
 *
 * Most processing payouts are ordinary in-flight rows, so the query reads wider
 * than the number asked for. The read window is bounded independently of the
 * requested count — a store with a long history should never turn a settings
 * page load or an hourly job into an unbounded scan.
 *
 * @param int $limit Maximum payouts to return.
 * @return array[] Each entry: payout_id, affiliate_id, amount, state, since.
 */
function chip_affiliatewp_payouts_awaiting_review( $limit = 20 ) {
	if ( ! function_exists( 'affiliate_wp' ) ) {
		return array();
	}

	/**
	 * Filters how many review-state payouts are returned.
	 *
	 * @param int $limit Maximum payouts.
	 */
	$limit = max( 1, absint( apply_filters( 'chip_affiliatewp_review_list_limit', $limit ) ) );

	/*
	 * Reading a payout's state means a meta lookup per row, and the settings
	 * panel is opened often enough that doing that on every load is wasteful.
	 * The list only changes when an instruction changes state, and every such
	 * change bumps the version below, so the TTL is a safety net rather than
	 * the mechanism.
	 *
	 * The key carries a version because callers ask for different counts; a
	 * flush that deleted one literal key would leave every other size stale.
	 */
	$cache_key = 'chip_affiliatewp_review_list_' . chip_affiliatewp_review_cache_version() . '_' . $limit;
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	/**
	 * Filters how many processing payouts are read to find review-state ones.
	 *
	 * @param int $window Rows to read.
	 */
	$window = max( $limit, absint( apply_filters( 'chip_affiliatewp_review_scan_window', 200 ) ) );

	$payouts = affiliate_wp()->affiliates->payouts->get_payouts(
		array(
			'payout_method' => 'chip',
			'status'        => 'processing',
			'number'        => $window,
			'orderby'       => 'date',
			'order'         => 'DESC',
		)
	);

	$found = array();

	foreach ( (array) $payouts as $payout ) {
		if ( count( $found ) >= $limit ) {
			break;
		}

		$data = chip_affiliatewp_payout_data( $payout );

		if ( empty( $data['instruction_id'] ) ) {
			continue;
		}

		if ( ! chip_affiliatewp_state_needs_review( (string) ( $data['state'] ?? '' ) ) ) {
			continue;
		}

		$found[] = array(
			'payout_id'    => absint( $payout->payout_id ?? $payout->ID ?? 0 ),
			'affiliate_id' => (int) $payout->affiliate_id,
			'amount'       => (string) $payout->amount,
			'state'        => strtolower( (string) $data['state'] ),
			'since'        => (string) ( $data['last_checked'] ?? '' ),
		);
	}

	// A short TTL only; every instruction-state change clears this directly.
	set_transient( $cache_key, $found, 5 * MINUTE_IN_SECONDS );

	return $found;
}

/**
 * Returns the current review-cache version.
 *
 * The list is cached per requested size. Bumping a single option invalidates
 * every size at once, which is simpler and safer than trying to delete each
 * literal key: a key that is not deleted keeps serving a stale list.
 *
 * @return int
 */
function chip_affiliatewp_review_cache_version() {
	return max( 1, absint( get_option( 'chip_affiliatewp_review_cache_version', 1 ) ) );
}

/**
 * Invalidates the cached review list.
 *
 * Called whenever an instruction's state is recorded, so the panel reflects a
 * payout leaving (or entering) review immediately rather than after the TTL.
 *
 * @return void
 */
function chip_affiliatewp_flush_review_list_cache() {
	update_option( 'chip_affiliatewp_review_cache_version', chip_affiliatewp_review_cache_version() + 1, false );

	/**
	 * Fires after the review list cache is invalidated.
	 */
	do_action( 'chip_affiliatewp_flush_review_list_cache' );
}

/**
 * Emails the merchant once about each payout parked in a review state.
 *
 * Runs on the hourly sweep. One email per payout, remembered in payout meta, so
 * a payout that stays parked does not mail the merchant every hour.
 *
 * Payouts already notified are skipped while COLLECTING rather than while
 * sending: the listing is bounded and newest-first, so filtering afterwards
 * would let a long queue keep re-reading the same recent rows and never reach
 * the older ones still waiting for their first notice.
 *
 * @return int Number of emails sent.
 */
function chip_affiliatewp_notify_review_payouts() {
	/**
	 * Filters how many review notices one sweep may send.
	 *
	 * @param int $limit Maximum emails per run.
	 */
	$limit = max( 1, absint( apply_filters( 'chip_affiliatewp_review_notify_limit', 20 ) ) );

	$pending = array();

	foreach ( chip_affiliatewp_payouts_awaiting_review( 200 ) as $row ) {
		$payout = affwp_get_payout( $row['payout_id'] );

		if ( ! $payout ) {
			continue;
		}

		$data = chip_affiliatewp_payout_data( $payout );

		if ( ! empty( $data['review_notified'] ) ) {
			continue;
		}

		$pending[] = array(
			'payout' => $payout,
			'data'   => $data,
			'row'    => $row,
		);

		if ( count( $pending ) >= $limit ) {
			break;
		}
	}

	$sent = 0;

	foreach ( $pending as $item ) {
		$payout = $item['payout'];
		$data   = $item['data'];

		/**
		 * Filters whether the review notice is emailed to the merchant.
		 *
		 * @param bool   $send   Whether to send. Default true.
		 * @param object $payout Payout row.
		 */
		if ( ! apply_filters( 'chip_affiliatewp_send_review_notice', true, $payout ) ) {
			continue;
		}

		$recipient = chip_affiliatewp_merchant_email();

		if ( '' === $recipient ) {
			continue;
		}

		$payout_id      = absint( $payout->payout_id ?? $payout->ID ?? 0 );
		$instruction_id = (int) ( $data['instruction_id'] ?? 0 );

		$subject = sprintf(
			/* translators: %s: site name. */
			__( '[%s] A CHIP Send payout needs your attention', 'chip-for-affiliatewp' ),
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$body = sprintf(
			/* translators: 1: payout ID, 2: formatted amount, 3: instruction ID, 4: payouts screen URL. */
			__(
				"A CHIP Send payout is waiting on manual review at CHIP.\n\nPayout: #%1\$d\nAmount: %2\$s\nCHIP Send instruction: %3\$d\n\nCHIP Send reports this instruction as \"reviewing\", which means it needs attention from your CHIP account manager before it can complete. It will not move on its own, and the affiliate has not been paid yet.\n\nPlease contact your CHIP account manager and quote the instruction ID above.\n\nYour payouts: %4\$s",
				'chip-for-affiliatewp'
			),
			$payout_id,
			chip_affiliatewp_format_money( $payout->amount ),
			$instruction_id,
			admin_url( 'admin.php?page=affiliate-wp-payouts' )
		);

		$sent_ok = wp_mail( $recipient, $subject, $body );

		if ( $sent_ok ) {
			$data['review_notified'] = gmdate( 'Y-m-d H:i:s' );

			chip_affiliatewp_update_payout_data( $payout_id, $data );

			++$sent;
		}
	}

	return $sent;
}

/**
 * Returns the address to notify about an operational problem.
 *
 * Falls back to the site admin address, which WordPress always has.
 *
 * @return string
 */
function chip_affiliatewp_merchant_email() {
	/**
	 * Filters the address notified when a payout needs merchant attention.
	 *
	 * @param string $email Email address.
	 */
	$email = (string) apply_filters( 'chip_affiliatewp_merchant_email', get_option( 'admin_email' ) );

	return is_email( $email ) ? $email : '';
}
