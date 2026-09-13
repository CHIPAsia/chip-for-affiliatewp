<?php
/**
 * CHIP Send account balance and budget allocation.
 *
 * CHIP Send pays out of an allocated budget, not straight from collected
 * funds: the merchant converts settlement balance into a Send limit before
 * payouts can be made. Merchants therefore need two numbers in front of them —
 * what is already allocated (`current_balance`) and what could be converted
 * (`convertible_balance_from_statement`) — plus the number of approvals the
 * conversion needs.
 *
 * @package CHIPforAffiliateWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Cannot access directly.

/**
 * Returns the CHIP Send account summary for a mode.
 *
 * Cached: the admin screens that show a balance are ordinary page loads, and
 * hitting the CHIP API on each one would add a round-trip to every render.
 * The transient is short-lived so a payout made elsewhere is reflected soon.
 *
 * @param string $mode "test" or "live".
 * @param bool   $force Skip the cache and re-request.
 * @return array|WP_Error Account summary, or an error when unavailable.
 */
function chip_affiliatewp_get_account_summary( $mode = 'live', $force = false ) {
	$mode      = 'test' === $mode ? 'test' : 'live';
	$cache_key = chip_affiliatewp_account_cache_key( $mode );

	if ( ! $force ) {
		$cached = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	if ( ! chip_affiliatewp_has_credentials( $mode ) ) {
		return new WP_Error( 'chip_missing_credentials', __( 'CHIP Send API credentials are not configured.', 'chip-for-affiliatewp' ) );
	}

	$response = chip_affiliatewp_request( 'GET', '/send/accounts', array(), array(), $mode );

	if ( is_wp_error( $response ) ) {
		// Cache failures briefly so a broken API does not add latency to every page load.
		set_transient( $cache_key, array( 'error' => $response->get_error_message() ), 2 * MINUTE_IN_SECONDS );

		return $response;
	}

	$summary = chip_affiliatewp_parse_account_summary( $response );

	set_transient( $cache_key, $summary, 5 * MINUTE_IN_SECONDS );

	return $summary;
}

/**
 * Normalizes the CHIP accounts response into the numbers the UI shows.
 *
 * The API returns a list wrapper whose first entry holds the account; the
 * fields are read defensively because they vary by account type.
 *
 * @param array $response Raw API response.
 * @return array {
 *     @type float       $current_balance    Allocated Send balance.
 *     @type float       $convertible        Balance available to convert.
 *     @type string      $currency           Currency code.
 *     @type int|float   $approvals_required Approvals needed to convert.
 *     @type string|null $error              Error message when the read failed.
 * }
 */
function chip_affiliatewp_parse_account_summary( $response ) {
	$account = $response;

	if ( isset( $response['results'] ) && is_array( $response['results'] ) && isset( $response['results'][0] ) ) {
		$account = $response['results'][0];
	}

	return array(
		'current_balance'    => (float) chip_affiliatewp_array_value( $account, 'current_balance', 0 ),
		'convertible'        => (float) chip_affiliatewp_array_value( $account, 'convertible_balance_from_statement', 0 ),
		'currency'           => strtoupper( (string) chip_affiliatewp_array_value( $account, 'currency', 'MYR' ) ),
		'approvals_required' => chip_affiliatewp_array_value( $account, 'settlement_convert_approvals_count', 0 ),
		'error'              => isset( $account['error'] ) ? (string) $account['error'] : null,
	);
}

/**
 * The transient key holding a mode's account summary.
 *
 * Named once because the summary is written under it and dropped under it: two
 * spellings of the same key leave a stale balance on screen after a conversion.
 *
 * @param string $mode "test" or "live"; anything else resolves to "live".
 * @return string
 */
function chip_affiliatewp_account_cache_key( $mode ) {
	return 'chip_affiliatewp_account_' . ( 'test' === $mode ? 'test' : 'live' );
}

/**
 * Requests a budget allocation, converting settlement balance into Send limit.
 *
 * CHIP sends the request to the configured approvers, who approve by email, so
 * this call starts a workflow rather than moving money immediately.
 *
 * @param float  $amount Amount to allocate.
 * @param string $mode   "test" or "live".
 * @return array|WP_Error Allocation response, or an error.
 */
function chip_affiliatewp_request_budget_allocation( $amount, $mode = 'live' ) {
	$amount = round( (float) $amount, 2 );

	/*
	 * A non-finite value passes a `<= 0` test — INF is greater than zero — and
	 * number_format() renders it as "inf", so it would be sent to CHIP as an
	 * amount. Requesting the balance conversion is the merchant's own screen,
	 * but the ceiling check above is skipped whenever the summary cannot be
	 * read (an API outage), so this cannot be left to it.
	 */
	if ( ! is_finite( $amount ) || $amount <= 0 ) {
		return new WP_Error( 'chip_invalid_amount', __( 'Enter an amount greater than zero.', 'chip-for-affiliatewp' ) );
	}

	$response = chip_affiliatewp_request(
		'POST',
		'/send/send_limits',
		array( 'amount' => number_format( $amount, 2, '.', '' ) ),
		array(),
		$mode
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	// The allocation changes the balance, so drop the cached summary.
	delete_transient( chip_affiliatewp_account_cache_key( $mode ) );

	return is_array( $response ) ? $response : array();
}
