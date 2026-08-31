<?php
/**
 * Delayed bank-account verification simulation.
 *
 * CHIP Send does not always verify a bank account instantly — when the bank's
 * verification service is slow or unavailable, the account stays unverified
 * for a while and only later flips to verified (via webhook) or is retried by
 * CHIP's own polling. This script simulates that flow against the plugin's
 * real functions using the stub harness:
 *
 *   Phase 1. Payout submitted while the bank account is NOT verified ->
 *            submission fails safely, no instruction sent, reason recorded.
 *   Phase 2. A later bank_account_status webhook delivery -> acknowledged
 *            without side effects (the plugin intentionally does not track
 *            bank state from that event alone).
 *   Phase 3. Resubmission after CHIP verifies the account -> succeeds, and
 *            the instruction is recorded.
 *   Phase 4. Requery heals the payout once CHIP completes the instruction ->
 *            payout paid, referral paid, exactly once.
 *
 * Run: php -f tests/integration/delayed-verification.php
 */

define( 'CHIP_AFFILIATEWP_HARNESS_SKIP_RUNNER', true );

require __DIR__ . '/../test-harness.php';

echo "\n\n===== delayed-verification simulation =====\n";

$pass = 0;
$fail = 0;
function vcheck( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS  {$label}\n"; }
	else        { $fail++; echo "FAIL  {$label}\n"; }
}

reset_state();
$GLOBALS['__options']['chip_test_mode']       = 1;
$GLOBALS['__options']['chip_payouts']         = 1;
$GLOBALS['__options']['chip_test_api_key']    = 'ktest';
$GLOBALS['__options']['chip_test_secret_key'] = 'stest';
$GLOBALS['__options']['chip_webhook_secret']  = 'fixedharnesssecret000000000000000000';

// Webhook key pair for phase 2 signed deliveries.
$keypair = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
openssl_pkey_export( $keypair, $GLOBALS['__delayed_priv_pem'] );
$key_details = openssl_pkey_get_details( $keypair );
$GLOBALS['__options']['chip_webhook_public_key'] = $key_details['key'];

// Affiliate 3 (user 7) has bank details whose CHIP verification is still in
// flight: get_bank_details returns the data, but CHIP reports the account as
// unverified during phase 1.
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7]          = new Fake_User( 7, 'delayed@test.dev' );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__bank_verify_pending'] = true;

// Phase 1: payout while verification is still in flight.
$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 31 ),
		'amount'        => '1.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][31] = new Fake_Referral( 31, 3, '1.00', 'unpaid', $payout_id );

// make_bank_lookup_fail: emulate CHIP's unverified account by making the
// ensure_bank_account call raise the plugin's own verification guard.
// We do this by returning a bank account record with status 'pending'.
$GLOBALS['__http_hooks'] = true;
// Intercept: harness FakeReferrer — inject pending-status response.
// (The harness queue is processed FIFO; queue a verified=false bank lookup.)
$GLOBALS['__chip_bank_lookup_override'] = array( 'id' => 557, 'status' => 'pending' );

$result = chip_affiliatewp_submit_payout( $payout_id );

// Phase 1 assertions.
vcheck( 'phase 1: submission fails while bank unverified', is_wp_error( $result ) || 'failed' === affwp_get_payout( $payout_id )->status );
vcheck( 'phase 1: no send instruction sent', 0 === count( $GLOBALS['__http_log'] ) );
vcheck( 'phase 1: payout never marked paid', 'paid' !== affiliate_wp()->affiliates->payouts->get_item( $payout_id )->status );
$description = json_decode( (string) affwp_get_payout( $payout_id )->description, true );
vcheck( 'phase 1: reason records the unverified bank status', false !== strpos( (string) ( $description['error'] ?? '' ), 'not verified' ) );

// Phase 2: a bank_account_status webhook delivery arrives later (e.g. CHIP
// eventually reports 'verified'). Properly signed with the webhook key pair
// from the harness, exactly like a real CHIP delivery.
$before_http = count( $GLOBALS['__http_log'] );
$webhook_body = json_encode( array( 'id' => 557, 'status' => 'verified' ) );
$request = new Fake_Request();
$request->body = $webhook_body;
openssl_sign( $webhook_body, $delayed_sig, $GLOBALS['__delayed_priv_pem'], OPENSSL_ALGO_SHA512 );
$request->headers['HTTP_EVENT_TYPE']  = 'bank_account_status';
$request->headers['HTTP_X_SIGNATURE'] = base64_encode( $delayed_sig );
$resp = call_user_func( 'chip_affiliatewp_handle_webhook', $request );
vcheck( 'phase 2: bank_account_status delivery acknowledged', ! is_wp_error( $resp ) );
vcheck( 'phase 2: payout untouched by the bank event alone', 'failed' === affwp_get_payout( $payout_id )->status || 'processing' === affwp_get_payout( $payout_id )->status );
vcheck( 'phase 2: no outbound API calls in response', count( $GLOBALS['__http_log'] ) === $before_http );

// Phase 3: operator resubmits after verification completes in CHIP.
// The bank lookup now returns a verified account.
$GLOBALS['__chip_bank_lookup_override'] = array( 'id' => 557, 'status' => 'verified' );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/bank_accounts', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/bank_accounts', 'code' => 200, 'body' => array( 'id' => 557, 'status' => 'verified', 'reference' => '34-BANK-3' ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'code' => 200, 'body' => array( 'id' => 990, 'state' => 'received' ) );

affiliate_wp()->affiliates->payouts->update( $payout_id, array( 'status' => 'processing' ), '', 'payout' );
$result = chip_affiliatewp_submit_payout( $payout_id );

vcheck( 'phase 3: resubmission succeeds after verification', ! is_wp_error( $result ) );
$row      = affwp_get_payout( $payout_id );
$row_data = json_decode( (string) $row->description, true );
vcheck( 'phase 3: instruction recorded', (int) ( $row_data['instruction_id'] ?? 0 ) === 990 );

// Phase 4: requery heals the payout once CHIP completes it.
$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array(
	'match' => '/send/send_instructions/990',
	'code'  => 200,
	'body'  => array( 'id' => 990, 'state' => 'completed', 'receipt_url' => 'https://staging.chip-in.asia/receipts/send/dv1' ),
);
chip_affiliatewp_check_payout_status( $payout_id, false );
vcheck( 'phase 4: payout paid via requery after delayed verification', 'paid' === affiliate_wp()->affiliates->payouts->get_item( $payout_id )->status );
vcheck( 'phase 4: referral marked paid', 'paid' === $GLOBALS['__referral_rows'][31]->status );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );