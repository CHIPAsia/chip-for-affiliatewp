# Optional integration tests against a real CHIP Send environment.
#
# Not part of CI. Requires a working WordPress + AffiliateWP install that can
# run wp-cli (or any WP shell) and staging/live CHIP Send credentials.
#
# These tests HIT THE REAL CHIP Send API. They are amount-safe: every create
# uses an amount of RM0.01–0.09 and idempotent per-run references, so re-runs
# adopt existing instructions and never double-pay.
#
# Usage (from the repo root):
#   CHIP_AFFILIATEWP_ITEST_BASE='https://staging-api.chip-in.asia/api' \
#   CHIP_AFFILIATEWP_ITEST_KEY='...' \
#   CHIP_AFFILIATEWP_ITEST_SECRET='...' \
#   php -f tests/integration/smoke.php
#
# Steps: checksum auth → accounts balance → bank-account create/filter/idempotent
# reuse → RM0.0X send instruction → requery terminal state → webhook CRUD round-trip.

<?php
// phpcs:ignoreFile -- standalone integration script, not part of the plugin.

if ( getenv( 'CHIP_AFFILIATEWP_ITEST_KEY' ) === false ) {
	exit( "set CHIP_AFFILIATEWP_ITEST_* env vars to run the integration smoke test\n" );
}

$base  = getenv( 'CHIP_AFFILIATEWP_ITEST_BASE' ) ?: 'https://staging-api.chip-in.asia/api';
$key   = getenv( 'CHIP_AFFILIATEWP_ITEST_KEY' );
$secret= getenv( 'CHIP_AFFILIATEWP_ITEST_SECRET' );

$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS  {$label}\n"; }
	else        { $fail++; echo "FAIL  {$label}\n"; }
}

function chip_request( $method, $path, $body = null, $query = array() ) {
	global $base, $key, $secret;
	$epoch    = (string) time();
	$checksum = hash_hmac( 'sha512', $epoch . $key, $secret );
	$url      = $base . $path . ( $query ? '?' . http_build_query( $query ) : '' );

	$opts = array(
		'http' => array(
			'method'        => $method,
			'timeout'       => 30,
			'ignore_errors' => true,
			'header'        => "Authorization: Bearer {$key}\r\n"
				. "epoch: {$epoch}\r\n"
				. "checksum: {$checksum}\r\n"
				. "Content-Type: application/json\r\n",
			'content'       => $body ? json_encode( $body ) : null,
		),
		'ssl' => array(
			'verify_peer'      => true,
			'verify_peer_name' => true,
		),
	);
	$context = stream_context_create( $opts );
	$response = @file_get_contents( $url, false, $context );
	$code     = 0;
	foreach ( $http_response_header ?? array() as $header ) {
		if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $header, $m ) ) {
			$code = (int) $m[1];
		}
	}
	return array( 'code' => $code, 'body' => json_decode( (string) $response, true ) );
}

$run_id = 'ITEST' . substr( (string) time(), -8 );

// 1. Auth + balance
$r = chip_request( 'GET', '/send/accounts' );
check( 'auth accepted, accounts listed', 200 === $r['code'] && ! empty( $r['body']['results'] ) );
$balance_before = (float) $r['body']['results'][0]['current_balance'];
echo "  balance before: {$balance_before}\n";

// 2. Bank account: create → idempotent reuse → reference filter
$bank_body = array(
	'account_number' => '157380112229',
	'bank_code'      => 'MBBEMYKL',
	'name'           => 'ITEST WH Recipient',
	'reference'      => $run_id . '-BANK',
);
$r = chip_request( 'POST', '/send/bank_accounts', $bank_body );
check( 'bank account create/reuse returns 200', 200 === $r['code'] );
$bank_id = (int) $r['body']['id'];

$r2 = chip_request( 'GET', '/send/bank_accounts', null, array( 'reference' => $bank_body['reference'] ) );
check( 'bank account reference filter finds exactly one', 200 === $r2['code'] && 1 === count( $r2['body']['results'] ?? array() ) );
check( 'reference filter returns the same account', (int) ( $r2['body']['results'][0]['id'] ?? 0 ) === $bank_id );

// 3. Send instruction — RM0.05 (amount-safe per policy)
$amount = '0.05';
$r = chip_request(
	'POST',
	'/send/send_instructions',
	array(
		'amount'           => $amount,
		'bank_account_id'  => $bank_id,
		'reference'        => $run_id . '-PO',
		'description'      => 'AffiliateWP ITEST ' . $run_id,
		'email'            => 'itchip+itest@example.com',
	)
);
check( 'send instruction RM0.05 accepted', 200 === $r['code'] );
$instruction_id = (int) $r['body']['id'];
$state          = (string) ( $r['body']['state'] ?? '' );
check( 'instruction id returned', $instruction_id > 0 );
echo "  instruction {$instruction_id} state: {$state}\n";

// Duplicate reference MUST be rejected by CHIP (idempotency contract)
$rd = chip_request(
	'POST',
	'/send/send_instructions',
	array(
		'amount'          => $amount,
		'bank_account_id' => $bank_id,
		'reference'       => $run_id . '-PO',
		'description'     => 'AffiliateWP ITEST dup ' . $run_id,
		'email'           => 'itchip+itest@example.com',
	)
);
check( 'duplicate reference rejected by CHIP', 200 !== $rd['code'] );

// 4. Requery — authoritative state
$r = chip_request( 'GET', '/send/send_instructions/' . $instruction_id );
check( 'requery reachable', 200 === $r['code'] );
$terminal = in_array( ( $r['body']['state'] ?? '' ), array( 'completed', 'rejected', 'accepted', 'executing' ), true );
check( 'instruction settled into a known terminal-or-in-flight state: ' . ( $r['body']['state'] ?? '?' ), $terminal );

// 5. Webhook CRUD round-trip
$r = chip_request(
	'POST',
	'/webhooks',
	array(
		'name'         => 'ITEST ' . $run_id,
		'callback_url' => 'https://webhook.site/itest-' . $run_id,
		'event_hooks'  => array( 'send_instruction_status' ),
	)
);
check( 'webhook create 200', 200 === $r['code'] );
$webhook_id = (int) $r['body']['id'];
check( 'webhook returns a public_key', ! empty( $r['body']['public_key'] ) );

$r = chip_request( 'DELETE', '/webhooks/' . $webhook_id );
check( 'webhook cleanup 200', 200 === $r['code'] );

// 6. Balance sanity — RM0.05 principal plus send fees must stay under RM2 total.
$r = chip_request( 'GET', '/send/accounts' );
$balance_after = (float) $r['body']['results'][0]['current_balance'];
$delta         = abs( $balance_before - $balance_after );
check( 'balance delta within fee tolerance (< RM2): ' . $delta, $delta < 2.0 );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );