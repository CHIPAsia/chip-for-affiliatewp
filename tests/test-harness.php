<?php
/**
 * Stub harness: smoke-tests chip-for-affiliatewp.php logic without WordPress.
 *
 * Stubs the minimal WP/AffiliateWP surface used by the plugin, loads the
 * plugin file, and exercises: checksum signing, amount formatting, reference
 * building, webhook verification, state transitions, and idempotency.
 *
 * Usage: php test-harness.php
 */

error_reporting( E_ALL );

// ---------------------------------------------------------------------------
// WordPress stubs
// ---------------------------------------------------------------------------

$GLOBALS['__options']      = array();
$GLOBALS['__user_meta']    = array();
$GLOBALS['__transients']   = array();
$GLOBALS['__http_queue']   = array(); // queued responses: array( 'url-contains' => array( 'code' =>, 'body' => ) )
$GLOBALS['__http_log']     = array();
$GLOBALS['__actions']      = array(); // hook name => array of callbacks
$GLOBALS['__filters']      = array();
$GLOBALS['__schedule']     = array();

function add_action( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['__actions'][ $hook ][] = $cb;
}

function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['__filters'][ $hook ][] = $cb;
}

function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['__actions'][ $hook ] ?? array() as $cb ) {
		call_user_func_array( $cb, $args );
	}
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['__filters'][ $hook ] ?? array() as $cb ) {
		$value = call_user_func_array( $cb, array_merge( array( $value ), $args ) );
	}
	return $value;
}

function register_activation_hook( $file, $cb ) {
	$GLOBALS['__activate_cb'] = $cb;
}

function register_deactivation_hook( $file, $cb ) {
	$GLOBALS['__deactivate_cb'] = $cb;
}

function wp_schedule_event( $ts, $rec, $hook ) {
	$GLOBALS['__schedule'][ $hook ] = $ts;
	return true;
}

function wp_next_scheduled( $hook ) {
	return $GLOBALS['__schedule'][ $hook ] ?? false;
}

function wp_clear_scheduled_hook( $hook ) {
	unset( $GLOBALS['__schedule'][ $hook ] );
}

function rest_url( $path = '' ) {
	return 'https://example.test/' . ltrim( $path, '/' );
}

class WP_REST_Server {
	const CREATABLE = 'POST';
}

function register_rest_route( $ns, $path, $args ) {
	$GLOBALS['__rest_routes'][ $ns . $path ] = $args;
}

function get_rest_url() {
	return 'https://example.test/wp-json/';
}

function __( $text, $domain = null ) {
	return $text;
}

function esc_html__( $text, $domain = null ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_url( $text ) {
	return $text;
}

function wp_json_encode( $data ) {
	return json_encode( $data );
}

function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, (array) $args );
}

function absint( $value ) {
	return absint_impl( $value );
}

function absint_impl( $value ) {
	return abs( (int) $value );
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function current_time( $type ) {
	return time();
}

function get_userdata( $user_id ) {
	return isset( $GLOBALS['__users'][ $user_id ] ) ? $GLOBALS['__users'][ $user_id ] : false;
}

function get_user_meta( $user_id, $key, $single = false ) {
	return $GLOBALS['__user_meta'][ $user_id ][ $key ] ?? '';
}

function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['__user_meta'][ $user_id ][ $key ] = $value;
	return true;
}

class WP_Error {
	public $code;
	public $message;
	public $data;

	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function wp_remote_request( $url, $args ) {
	$GLOBALS['__http_log'][] = array(
		'url'     => $url,
		'method'  => $args['method'] ?? 'GET',
		'headers' => $args['headers'] ?? array(),
		'body'    => $args['body'] ?? null,
	);

	foreach ( $GLOBALS['__http_queue'] as $idx => $entry ) {
		if ( false !== strpos( $url, $entry['match'] ) ) {
			// Optional host constraint: only match when the URL hits the named host.
			if ( ! empty( $entry['url_host'] ) && false === strpos( $url, $entry['url_host'] ) ) {
				continue;
			}
			unset( $GLOBALS['__http_queue'][ $idx ] );
			$GLOBALS['__http_queue'] = array_values( $GLOBALS['__http_queue'] );
			return array(
				'response' => array( 'code' => $entry['code'] ),
				'body'     => is_string( $entry['body'] ) ? $entry['body'] : json_encode( $entry['body'] ),
			);
		}
	}

	return new WP_Error( 'http_unmocked', 'No mock for ' . $url );
}

function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) ? ( $response['response']['code'] ?? 0 ) : 0;
}

function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) ? ( $response['body'] ?? '' ) : '';
}

function add_query_arg( $args, $url ) {
	return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . http_build_query( $args );
}

$GLOBALS['__transients'] = array();

function get_transient( $key ) {
	return $GLOBALS['__transients'][ $key ] ?? false;
}

function set_transient( $key, $value, $expiry = 0 ) {
	$GLOBALS['__transients'][ $key ] = $value;
	return true;
}

$GLOBALS['__options_store'] = array();

function get_option( $name, $default = false ) {
	return $GLOBALS['__options_store'][ $name ] ?? $default;
}

function update_option( $name, $value ) {
	$GLOBALS['__options_store'][ $name ] = $value;
	return true;
}

function current_user_can( $cap ) {
	return true;
}

$GLOBALS['__probe_response'] = null; // WP_Error or array('code'=>..)

function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['__probe_calls'][] = $url;

	$response = $GLOBALS['__probe_response'] ?? array( 'response' => array( 'code' => 401 ), 'body' => '' );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	return $response;
}

function rawurlencode_deep( $v ) { return is_string( $v ) ? rawurlencode( $v ) : $v; }

// WP settings object.
class Fake_WP_Settings {
	public function get( $key, $default = false ) {
		return $GLOBALS['__options'][ $key ] ?? $default;
	}
	public function set( $settings, $save = false ) {
		foreach ( (array) $settings as $k => $v ) {
			$GLOBALS['__options'][ $k ] = $v;
		}
		return true;
	}
	public function register_section( ...$args ) {
		$GLOBALS['__registered_sections'][] = $args;
	}
}

// AffiliateWP fake core.
class Fake_AffiliateWP {
	public $settings;
	public $affiliates;

	public function __construct() {
		$this->settings = new Fake_WP_Settings();
		$this->affiliates = new Fake_Affiliates_Container();
	}
}

class Fake_Affiliates_Container {
	public $payouts;

	public function __construct() {
		$this->payouts = new Fake_Payouts_DB();
	}
}

$GLOBALS['__payout_rows'] = array();
$GLOBALS['__next_payout_id'] = 501;
$GLOBALS['__referral_rows'] = array();

class Fake_Payouts_DB {
	public function get_payouts( $args, $count = false ) {
		$rows = array_values(
			array_filter(
				$GLOBALS['__payout_rows'],
				function ( $p ) use ( $args ) {
					if ( ! empty( $args['payout_method'] ) && $p->payout_method !== $args['payout_method'] ) {
						return false;
					}
					if ( ! empty( $args['payout_id'] ) ) {
						$ids = is_array( $args['payout_id'] ) ? $args['payout_id'] : array( $args['payout_id'] );
						if ( ! in_array( $p->payout_id, array_map( 'intval', $ids ), true ) ) {
							return false;
						}
					}
					if ( ! empty( $args['batch_id'] ) && $p->batch_id !== (int) $args['batch_id'] ) {
						return false;
					}
					if ( ! empty( $args['service_id'] ) ) {
						$sids = is_array( $args['service_id'] ) ? $args['service_id'] : array( $args['service_id'] );
						if ( ! in_array( (int) $p->service_id, array_map( 'intval', $sids ), true ) ) {
							return false;
						}
					}
					if ( ! empty( $args['status'] ) ) {
						$valid = array( 'processing', 'paid', 'failed' );
						$st    = is_array( $args['status'] ) ? $args['status'] : array( $args['status'] );
						if ( array_diff( $st, $valid ) ) {
							return false;
						}
						if ( ! in_array( $p->status, $st, true ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);

		if ( true === $count ) {
			return count( $rows );
		}
		return $rows;
	}

	public function get_payout_ids_by_referrals( $referrals, $status = '' ) {
		$out = array();
		foreach ( $referrals as $rid ) {
			foreach ( $GLOBALS['__payout_rows'] as $p ) {
				if ( in_array( (int) $rid, array_map( 'intval', explode( ',', $p->referrals ) ), true ) ) {
					$out[] = $p->payout_id;
				}
			}
		}
		return $out;
	}

	public function add( $args ) {
		$id          = $GLOBALS['__next_payout_id']++;
		$row         = (object) array(
			'payout_id'            => $id,
			'affiliate_id'         => (int) ( $args['affiliate_id'] ?? 0 ),
			'referrals'            => implode( ',', array_map( 'intval', (array) ( $args['referrals'] ?? array() ) ) ),
			'amount'               => (string) ( $args['amount'] ?? '0' ),
			'payout_method'        => (string) ( $args['payout_method'] ?? '' ),
			'status'               => (string) ( $args['status'] ?? 'paid' ),
			'batch_id'             => (int) ( $args['batch_id'] ?? 0 ),
			'service_id'           => (int) ( $args['service_id'] ?? 0 ),
			'service_invoice_link' => (string) ( $args['service_invoice_link'] ?? '' ),
			'description'          => (string) ( $args['description'] ?? '' ),
		);
		$GLOBALS['__payout_rows'][ $id ] = $row;
		return $id;
	}

	public function update( $payout_id, $data, $where = '', $type = '' ) {
		if ( ! isset( $GLOBALS['__payout_rows'][ $payout_id ] ) ) {
			return false;
		}
		$row = $GLOBALS['__payout_rows'][ $payout_id ];
		foreach ( $data as $k => $v ) {
			$row->{$k} = $v;
		}
		return true;
	}

	public function get_item( $payout_id ) {
		return isset( $GLOBALS['__payout_rows'][ $payout_id ] )
			? $GLOBALS['__payout_rows'][ $payout_id ]
			: null;
	}
}

/**
 * Adds a processing payout row for a batch of tests.
 *
 * @param int    $payout_id    Payout ID to use.
 * @param int    $affiliate_id Affiliate ID.
 * @param string $amount       Amount.
 * @return int Payout ID.
 */
function harness_add_payout( $payout_id, $affiliate_id, $amount, $batch_id = 0, $status = 'processing' ) {
	$GLOBALS['__payout_rows'][ $payout_id ] = (object) array(
		'payout_id'            => $payout_id,
		'affiliate_id'         => $affiliate_id,
		'referrals'            => '',
		'amount'               => $amount,
		'payout_method'        => 'chip',
		'status'               => $status,
		'batch_id'             => $batch_id,
		'service_id'           => 0,
		'service_invoice_link' => '',
		'description'          => '',
	);
	return $payout_id;
}

/**
 * Plucks a column, mirroring WP's wp_list_pluck.
 *
 * @param array  $list List of arrays/objects.
 * @param string $key  Field to pluck.
 * @return array
 */
function wp_list_pluck( $list, $key ) {
	$out = array();
	foreach ( $list as $item ) {
		if ( is_object( $item ) ) {
			$out[] = $item->{$key};
		} else {
			$out[] = isset( $item[ $key ] ) ? $item[ $key ] : null;
		}
	}
	return $out;
}

class Fake_User {
	public $ID;
	public $user_email;

	public function __construct( $id, $email ) {
		$this->ID         = $id;
		$this->user_email = $email;
	}
}

class Fake_Referral {
	public $ID;
	public $affiliate_id;
	public $amount;
	public $status;
	public $description;
	public $payout_id;

	public function __construct( $id, $affiliate_id, $amount, $status = 'unpaid', $payout_id = 0 ) {
		$this->ID           = $id;
		$this->affiliate_id = $affiliate_id;
		$this->amount       = $amount;
		$this->status       = $status;
		$this->description  = 'Referral description';
		$this->payout_id    = $payout_id;
	}
}

function affiliate_wp() {
	static $inst = null;
	if ( ! $inst ) {
		$inst = new Fake_AffiliateWP();
	}
	return $inst;
}

function affwp_set_referral_status( $referral_id, $status ) {
	if ( isset( $GLOBALS['__referral_rows'][ $referral_id ] ) ) {
		$GLOBALS['__referral_rows'][ $referral_id ]->status = $status;
	}
	return true;
}

// Minimal OpenSSL-available web server request context.
function wp_unslash( $v ) {
	return $v;
}

function sanitize_key_slash( $v ) {
	return $v;
}

// ---------------------------------------------------------------------------
// AffiliateWP public API stubs used by the plugin
// ---------------------------------------------------------------------------

$GLOBALS['__affiliates_map'] = array(); // affiliate_id => user_id
$GLOBALS['__users']          = array();

function affwp_get_affiliate_user_id( $affiliate_id ) {
	return $GLOBALS['__affiliates_map'][ (int) $affiliate_id ] ?? 0;
}

function affwp_get_affiliate_name( $affiliate_id ) {
	return 'Test Affiliate ' . $affiliate_id;
}

function affwp_get_affiliate_payment_email( $affiliate_id ) {
	$uid = affwp_get_affiliate_user_id( $affiliate_id );
	$u   = isset( $GLOBALS['__users'][ $uid ] ) ? $GLOBALS['__users'][ $uid ] : false;
	return $u ? $u->user_email : '';
}

function affwp_get_referral( $referral_id ) {
	return $GLOBALS['__referral_rows'][ (int) $referral_id ] ?? false;
}

function affwp_get_payout( $payout ) {
	if ( is_object( $payout ) ) {
		return $payout;
	}
	return $GLOBALS['__payout_rows'][ (int) $payout ] ?? false;
}

function affwp_add_payout( $args ) {
	return affiliate_wp()->affiliates->payouts->add( $args );
}

function as_schedule_single_action( $ts, $hook, $args, $group ) {
	$GLOBALS['__as'][]             = array( $ts, $hook, $args );
	$GLOBALS['__as_scheduled'][]  = array(
		'timestamp' => $ts,
		'hook'      => $hook,
		'args'      => $args,
		'group'     => $group,
	);
	return 1;
}

function MINUTE_IN_SECONDS() { return 60; }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'OPENSSL_ALGO_SHA512' ) ) {
	define( 'OPENSSL_ALGO_SHA512', 'sha512' );
}

// ---------------------------------------------------------------------------
// Load plugin
// ---------------------------------------------------------------------------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require __DIR__ . '/../chip-for-affiliatewp.php';

// ---------------------------------------------------------------------------
// Fake REST request
// ---------------------------------------------------------------------------

class Fake_Request {
	public $body;
	public $headers = array();

	public function get_body() {
		return $this->body;
	}
	public function get_header( $name ) {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		return $this->headers[ $key ] ?? '';
	}
}

function rest_ensure_response( $data ) {
	return array( 'response' => $data );
}

// ---------------------------------------------------------------------------
// Test runner
// ---------------------------------------------------------------------------

$failures = array();
$passes   = 0;

function check( $name, $cond ) {
	global $failures, $passes;
	if ( $cond ) {
		$passes++;
		echo "PASS  {$name}\n";
	} else {
		$failures[] = $name;
		echo "FAIL  {$name}\n";
	}
}

function reset_state() {
	$GLOBALS['__options']        = array();
	$GLOBALS['__user_meta']      = array();
	$GLOBALS['__http_queue']     = array();
	$GLOBALS['__http_log']       = array();
	$GLOBALS['__payout_rows']    = array();
	$GLOBALS['__next_payout_id'] = 501;
	$GLOBALS['__referral_rows']  = array();
	$GLOBALS['__affiliates_map'] = array();
	$GLOBALS['__users']          = array();
	$GLOBALS['__schedule']       = array();
	$GLOBALS['__as']             = array();
	$GLOBALS['__transients']     = array();
	$GLOBALS['__probe_calls']    = array();
	$GLOBALS['__probe_response'] = null;

	$GLOBALS['__options']['chip_payouts']     = 1;
	$GLOBALS['__options']['chip_test_mode']   = 1;
	$GLOBALS['__options']['chip_test_api_key']   = 'e0645c9e-fcf2-4f29-a327-202f7ed3d969';
	$GLOBALS['__options']['chip_test_secret_key'] = 'a118729e-4243-4145-83b3-0b8cb213fe8e';
	$GLOBALS['__options']['chip_reference_prefix'] = 'XT';
}

echo "== Test 1: checksum signing matches docs algorithm ==\n";
// Docs: checksum = HEX(HMAC_SHA512(key = API secret, message = <epoch><api_key>)).
// Real clock is used, so recompute the expected value for this and the next second.
reset_state();
$GLOBALS['__http_queue'][] = array( 'match' => '/send/accounts', 'code' => 200, 'body' => array( 'ok' => true ) );
chip_affiliatewp_request( 'GET', '/send/accounts' );
$req    = $GLOBALS['__http_log'][0];
$expect = '45bee62dba8087ab1e7e767d92f8d6e26f8bd19ee5fd2fef6386bb9425976498a86ffdbddb7a49919998e993c20626196ea652320f438a9528d2b8c9d19ec266';
// Verify the docs worked example itself first.
$docs_epoch  = '1689826456';
$docs_expect = hash_hmac( 'sha512', $docs_epoch . 'e0645c9e-fcf2-4f29-a327-202f7ed3d969', 'a118729e-4243-4145-83b3-0b8cb213fe8e' );
check( 'docs worked example reproducible', $docs_expect === $expect );
// Now verify the request message is exactly epoch + api_key.
$t0        = (string) time();
$candidate = false;
foreach ( array( $t0, (string) ( (int) $t0 + 1 ), (string) ( (int) $t0 - 1 ) ) as $t ) {
	$exp = hash_hmac( 'sha512', $t . 'e0645c9e-fcf2-4f29-a327-202f7ed3d969', 'a118729e-4243-4145-83b3-0b8cb213fe8e' );
	if ( $exp === $req['headers']['checksum'] ) {
		$candidate = true;
		check( 'epoch header matches signed epoch', $req['headers']['epoch'] === $t );
	}
}
check( 'checksum = HMAC512(secret, epoch+api_key)', $candidate );
check( 'bearer = api key', $req['headers']['Authorization'] === 'Bearer e0645c9e-fcf2-4f29-a327-202f7ed3d969' );
check( 'staging base url', false !== strpos( $req['url'], 'staging-api.chip-in.asia/api/send/accounts' ) );

echo "\n== Test 2: mode switching ==\n";
reset_state();
$GLOBALS['__options']['chip_test_mode']      = 0;
$GLOBALS['__options']['chip_live_api_key']   = 'e0645c9e-fcf2-4f29-a327-202f7ed3d969';
$GLOBALS['__options']['chip_live_secret_key'] = 'a118729e-4243-4145-83b3-0b8cb213fe8e';
$GLOBALS['__http_queue'][] = array( 'match' => '/send/accounts', 'code' => 200, 'body' => array( 'ok' => 1 ) );
chip_affiliatewp_request( 'GET', '/send/accounts' );
check( 'live base url', false !== strpos( $GLOBALS['__http_log'][0]['url'], 'https://api.chip-in.asia/api/' ) );
check( 'live key used', $GLOBALS['__http_log'][0]['headers']['Authorization'] === 'Bearer e0645c9e-fcf2-4f29-a327-202f7ed3d969' );

echo "\n== Test 3: amount formatting ==\n";
check( 'int amount', chip_affiliatewp_format_amount( '100' ) === '100.00' );
check( 'float amount', chip_affiliatewp_format_amount( 12.44 ) === '12.44' );
check( '3dp normalized', chip_affiliatewp_format_amount( '10.999' ) === '11.00' );

echo "\n== Test 4: reference building ==\n";
reset_state();
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__affiliates_map'][3] = 7;
$ref1 = chip_affiliatewp_bank_reference( 3 );
$ref2 = chip_affiliatewp_bank_reference( 3 );
check( 'reference stable', $ref1 === $ref2 );
check( 'reference prefix', 0 === strpos( $ref1, 'XT-AFF-3-' ) );
check( 'reference length <= 40', strlen( $ref1 ) <= 40 );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380999999';
$ref3 = chip_affiliatewp_bank_reference( 3 );
check( 'reference changes with account number', $ref1 !== $ref3 );
check( 'instruction reference', 'XT-PO-777' === chip_affiliatewp_instruction_reference( 777 ) );

echo "\n== Test 5: bank account ensure (lookup then create) ==\n";
reset_state();
$GLOBALS['__http_queue'][] = array( 'match' => '/send/bank_accounts', 'code' => 200, 'body' => array( 'results' => array( array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) ) ) ) );
$acct = chip_affiliatewp_ensure_bank_account( 3 );
check( 'existing account returned', is_array( $acct ) && 84 === (int) $acct['id'] );
check( 'only one HTTP call (lookup)', 1 === count( $GLOBALS['__http_log'] ) );

// Now lookup finds nothing -> POST create.
reset_state();
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__http_queue'][] = array( 'match' => '/send/bank_accounts', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/bank_accounts', 'code' => 200, 'body' => array( 'id' => 84, 'status' => 'pending', 'reference' => 'XT-AFF-3-abc' ) );
$acct = chip_affiliatewp_ensure_bank_account( 3 );
check( 'created account returned', is_array( $acct ) && 84 === (int) $acct['id'] );
check( 'create POST includes reference', 2 === count( $GLOBALS['__http_log'] ) && false !== strpos( $GLOBALS['__http_log'][1]['body'], '"reference"' ) );

echo "\n== Test 6: submit payout (batch path) ==\n";
reset_state();
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7] = new Fake_User( 7, 'affiliate@test.dev' );
$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 11 ),
		'amount'        => '250.50',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][11] = new Fake_Referral( 11, 3, '250.50', 'unpaid', $payout_id );
// Bank lookup: none; create 84 verified; instruction created 9001 completed.
$GLOBALS['__http_queue'][] = array( 'match' => '/send/bank_accounts', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/bank_accounts', 'code' => 200, 'body' => array( 'id' => 84, 'status' => 'verified', 'reference' => 'XT' ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'code' => 200, 'body' => array( 'id' => 900, 'state' => 'received', 'receipt_url' => 'https://www.chip-in.asia/receipts/send/abc123' ) );
$result = chip_affiliatewp_submit_payout( $payout_id );
check( 'submit succeeded', true === $result );
$row = affwp_get_payout( $payout_id );
$data = json_decode( $row->description, true );
check( 'instruction id stored', 900 === (int) $data['instruction_id'] );
check( 'service_id stored', 900 === (int) $row->service_id );
check( 'receipt stored', 'https://www.chip-in.asia/receipts/send/abc123' === $data['receipt_url'] );
check( 'payout still processing', 'processing' === $row->status );
check( 'recheck scheduled', ! empty( $GLOBALS['__as'] ) );
check( 'referral NOT yet paid', 'unpaid' === $GLOBALS['__referral_rows'][11]->status );
check( 'amount in payload', false !== strpos( $GLOBALS['__http_log'][2]['body'], '"amount":"250.50"' ) );
check( 'reference in payload', false !== strpos( $GLOBALS['__http_log'][2]['body'], '"reference":"XT-PO-' . $payout_id . '"' ) );

echo "\n== Test 7: submit idempotency (already has instruction) ==\n";
$GLOBALS['__http_log'] = array();
$result2 = chip_affiliatewp_submit_payout( $payout_id );
check( 'second submit short-circuits', true === $result2 && 0 === count( $GLOBALS['__http_log'] ) );

echo "\n== Test 8: webhook completed -> paid ==\n";
reset_state();
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7] = new Fake_User( 7, 'affiliate@test.dev' );
$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 11, 12 ),
		'amount'        => '100.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
		'service_id'    => 900,
		'description'   => wp_json_encode( array( 'instruction_id' => 900, 'reference' => 'XT-PO-' . $payout_id, 'state' => 'executing' ) ),
	)
);
$GLOBALS['__referral_rows'][11] = new Fake_Referral( 11, 3, '50.00', 'unpaid', $payout_id );
$GLOBALS['__referral_rows'][12] = new Fake_Referral( 12, 3, '50.00', 'unpaid', $payout_id );
$payload = array(
	'id'          => 900,
	'state'       => 'completed',
	'reference'   => 'XT-PO-' . $payout_id,
	'receipt_url' => 'https://www.chip-in.asia/receipts/send/zzz',
);
$terminal = chip_affiliatewp_apply_instruction( $payout_id, $payload );
$row = affwp_get_payout( $payout_id );
check( 'apply returns terminal', true === $terminal );
check( 'payout paid', 'paid' === $row->status );
check( 'referral 11 paid', 'paid' === $GLOBALS['__referral_rows'][11]->status );
check( 'referral 12 paid', 'paid' === $GLOBALS['__referral_rows'][12]->status );
check( 'receipt updated', 'https://www.chip-in.asia/receipts/send/zzz' === $data['receipt_url'] || 'https://www.chip-in.asia/receipts/send/zzz' === json_decode( $row->description, true )['receipt_url'] );

echo "\n== Test 9: webhook dedup (redelivery is a no-op) ==\n";
$GLOBALS['__referral_rows'][11]->status = 'paid'; // already applied
$result3 = chip_affiliatewp_apply_instruction( $payout_id, $payload );
check( 'redelivery returns terminal', true === $result3 );
check( 'payout still paid exactly once', 'paid' === affwp_get_payout( $payout_id )->status );

echo "\n== Test 10: webhook rejected -> failed + referrals unpaid ==\n";
reset_state();
$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 21 ),
		'amount'        => '55.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
		'service_id'    => 901,
		'description'   => wp_json_encode( array( 'instruction_id' => 901 ) ),
	)
);
$GLOBALS['__referral_rows'][21] = new Fake_Referral( 21, 3, '55.00', 'unpaid', $payout_id );
chip_affiliatewp_apply_instruction( $payout_id, array( 'id' => 901, 'state' => 'rejected', 'rejection_reason' => 'Account closed' ) );
$row = affwp_get_payout( $payout_id );
check( 'payout failed', 'failed' === $row->status );
check( 'referral released to unpaid', 'unpaid' === $GLOBALS['__referral_rows'][21]->status );
check( 'failure reason recorded', false !== strpos( $row->description, 'rejected' ) );

echo "\n== Test 11: requery heals missed webhook ==\n";
reset_state();
$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 31 ),
		'amount'        => '80.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
		'service_id'    => 905,
		'description'   => wp_json_encode( array( 'instruction_id' => 905, 'state' => 'executing' ) ),
	)
);
$GLOBALS['__referral_rows'][31] = new Fake_Referral( 31, 3, '80.00', 'unpaid', $payout_id );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions/905', 'code' => 200, 'body' => array( 'id' => 905, 'state' => 'completed', 'receipt_url' => 'https://x.test/r' ) );
chip_affiliatewp_check_payout_status( $payout_id, false );
check( 'requery marked paid', 'paid' === affwp_get_payout( $payout_id )->status );
check( 'referral paid via requery', 'paid' === $GLOBALS['__referral_rows'][31]->status );

echo "\n== Test 12: non-instruction states stay processing ==\n";
reset_state();
$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 41 ),
		'amount'        => '20.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
		'service_id'    => 907,
		'description'   => wp_json_encode( array( 'instruction_id' => 907, 'state' => 'received' ) ),
	)
);
$GLOBALS['__referral_rows'][41] = new Fake_Referral( 41, 3, '20.00', 'unpaid', $payout_id );
chip_affiliatewp_apply_instruction( $payout_id, array( 'id' => 907, 'state' => 'executing' ) );
check( 'still processing after executing state', 'processing' === affwp_get_payout( $payout_id )->status );
check( 'state recorded', 'executing' === json_decode( affwp_get_payout( $payout_id )->description, true )['state'] );

echo "\n== Test 13: webhook signature verification ==\n";
reset_state();
// Generate keypair, sign a payload as CHIP would.
$keypair = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
openssl_pkey_export( $keypair, $priv_pem );
$details = openssl_pkey_get_details( $keypair );
$pub_pem = $details['key'];
$GLOBALS['__options']['chip_webhook_public_key'] = $pub_pem;

$body = json_encode( array( 'id' => 900, 'state' => 'completed', 'reference' => 'XT-PO-501' ) );
openssl_sign( $body, $sig, $priv_pem, OPENSSL_ALGO_SHA512 );
$request = new Fake_Request();
$request->body = $body;
$request->headers['HTTP_X_SIGNATURE'] = base64_encode( $sig );
$request->headers['HTTP_EVENT_TYPE'] = 'send_instruction_status';

// Seed payout for the webhook to find.
$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 11 ),
		'amount'        => '100.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
		'service_id'    => 900,
		'description'   => wp_json_encode( array( 'instruction_id' => 900, 'reference' => 'XT-PO-501' ) ),
	)
);
$GLOBALS['__referral_rows'][11] = new Fake_Referral( 11, 3, '100.00', 'unpaid', $payout_id );

// GET_LOCK stub: return 1 immediately via direct $wpdb->get_var override.
class Fake_WPDO {
	public $is_mysql = true;
	public function prepare( $q, ...$a ) { return $q; }
	public function get_var( $q ) { return '1'; }
	public function query( $q ) { return true; }
}
$GLOBALS['wpdb'] = new Fake_WPDO();

$resp = chip_affiliatewp_handle_webhook( $request );
check( 'valid signature accepted', is_array( $resp ) );
check( 'payout paid after webhook', 'paid' === affwp_get_payout( $payout_id )->status );
check( 'referral paid after webhook', 'paid' === $GLOBALS['__referral_rows'][11]->status );

// Tampered body rejected.
$request2 = new Fake_Request();
$request2->body = $body . 'x';
$request2->headers = $request->headers;
$resp2 = chip_affiliatewp_handle_webhook( $request2 );
check( 'tampered payload rejected', is_wp_error( $resp2 ) );

echo "\n== Test 14: batch payout hook path ==\n";
reset_state();
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7] = new Fake_User( 7, 'affiliate@test.dev' );
// Payout created by batch processor with processing status and NO submission yet.
$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 61 ),
		'amount'        => '300.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
		'batch_id'      => 9,
	)
);
$globals_referrals = 61;
$GLOBALS['__referral_rows'][61] = new Fake_Referral( 61, 3, '300.00', 'unpaid', $payout_id );
$GLOBALS['__as_scheduled'] = array();
do_action( 'affwp_batch_generate_payouts_completed', 9 );
check( 'batch schedules one AS action per payout', 1 === count( $GLOBALS['__as_scheduled'] ) );
check( 'batch does not submit inline', 0 === count( array_filter( $GLOBALS['__http_log'], function ( $l ) { return false !== strpos( $l['url'], 'send_instructions' ); } ) ) );
// Process the scheduled submission for real.
$GLOBALS['__http_queue'][] = array( 'match' => '/send/bank_accounts', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/bank_accounts', 'code' => 200, 'body' => array( 'id' => 84, 'status' => 'verified', 'reference' => 'XT' ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'code' => 200, 'body' => array( 'id' => 950, 'state' => 'received' ) );
call_user_func( 'chip_affiliatewp_run_scheduled_submission', $payout_id );
$row = affwp_get_payout( $payout_id );
check( 'batch payout submitted', 950 === (int) json_decode( $row->description, true )['instruction_id'] );

echo "\n== Test 15: duplicate instruction adoption on POST conflict ==\n";
reset_state();
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7] = new Fake_User( 7, 'affiliate@test.dev' );
$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 71 ),
		'amount'        => '45.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][71] = new Fake_Referral( 71, 3, '45.00', 'unpaid', $payout_id );
// Bank account exists.
$GLOBALS['__http_queue'][] = array( 'match' => '/send/bank_accounts', 'code' => 200, 'body' => array( 'results' => array( array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) ) ) ) );
// POST rejects as duplicate...
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'code' => 400, 'body' => array( 'message' => 'reference must be unique', 'code' => 400 ) );
// ...and the list-by-reference finds the original instruction.
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'code' => 200, 'body' => array( 'results' => array( array( 'id' => 888, 'state' => 'executing', 'reference' => 'XT-PO-' . $payout_id ) ) ) );
$result = chip_affiliatewp_submit_payout( $payout_id );
$row = affwp_get_payout( $payout_id );
check( 'conflict resolved by adopting existing instruction', true === $result && 888 === (int) json_decode( $row->description, true )['instruction_id'] );

echo "\n== Test 16: sweep respects cooldown and status filter ==\n";
reset_state();
$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 81 ),
		'amount'        => '15.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
		'service_id'    => 960,
		'description'   => wp_json_encode( array( 'instruction_id' => 960, 'last_checked' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) ),
	)
);
chip_affiliatewp_sweep_processing_payouts();
check( 'cooldown respected (no http)', 0 === count( $GLOBALS['__http_log'] ) );

// Older last_checked -> requery runs.
update_desc( $payout_id, array( 'instruction_id' => 960, 'last_checked' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions/960', 'code' => 200, 'body' => array( 'id' => 960, 'state' => 'completed' ) );
chip_affiliatewp_sweep_processing_payouts();
check( 'stale payout requeried and completed', 'paid' === affwp_get_payout( $payout_id )->status );

function update_desc( $payout_id, $data ) {
	$row = affwp_get_payout( $payout_id );
	$row->description = wp_json_encode( $data );
}

echo "\n== Test 17: single referral pay ==\n";
reset_state();
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7] = new Fake_User( 7, 'affiliate@test.dev' );
$GLOBALS['__referral_rows'][91] = new Fake_Referral( 91, 3, '75.25', 'unpaid', 0 );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/bank_accounts', 'code' => 200, 'body' => array( 'results' => array( array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) ) ) ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'code' => 200, 'body' => array( 'id' => 700, 'state' => 'received' ) );
$result = chip_affiliatewp_pay_single_referral( 91 );
check( 'single pay succeeded', true === $result );
$created = array_filter( $GLOBALS['__payout_rows'], function ( $p ) { return 'processing' === $p->status && 700 === (int) $p->service_id; } );
check( 'payout record created with service_id', 1 === count( $created ) );
check( 'referral stays unpaid until confirmed', 'unpaid' === $GLOBALS['__referral_rows'][91]->status );

echo "\n== Test 18: activation/deactivation hooks ==\n";
reset_state();
call_user_func( $GLOBALS['__activate_cb'] );
check( 'hourly sweep scheduled', ! empty( $GLOBALS['__schedule']['chip_affiliatewp_hourly_sweep'] ) );
call_user_func( $GLOBALS['__deactivate_cb'] );
check( 'sweep cleared', empty( $GLOBALS['__schedule']['chip_affiliatewp_hourly_sweep'] ) );

echo "\n== Test 19: webhook auto-registration (reachable site) ==\n";
reset_state();
$GLOBALS['__probe_response'] = array( 'response' => array( 'code' => 401 ), 'body' => '' );
$GLOBALS['__options_store']['admin_email'] = 'admin@test.dev';
// Probe OK (401 is still "reachable"), then list empty, create returns full record.
$GLOBALS['__http_queue'][] = array( 'match' => '/webhooks', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/webhooks', 'code' => 200, 'body' => array( 'id' => 55, 'name' => 'AffiliateWP Payouts', 'callback_url' => chip_affiliatewp_webhook_url(), 'public_key' => 'PUBKEY-TEST-55' ) );
$result = chip_affiliatewp_ensure_webhook();
check( 'webhook registered', true === $result );
check( 'webhook id stored', 55 === (int) affiliate_wp()->settings->get( 'chip_webhook_id_test', '' ) );
check( 'public key stored', 'PUBKEY-TEST-55' === (string) affiliate_wp()->settings->get( 'chip_webhook_key_test', '' ) );

// Idempotent: second ensure sees stored webhook via fast path (GET /webhooks/55 only).
$GLOBALS['__http_log'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/webhooks/55', 'code' => 200, 'body' => array( 'id' => 55, 'callback_url' => chip_affiliatewp_webhook_url(), 'public_key' => 'PUBKEY-TEST-55' ) );
$result = chip_affiliatewp_ensure_webhook();
check( 'fast path reuses stored webhook', true === $result && 1 === count( $GLOBALS['__http_log'] ) );

// Webhook resolved from auto-registration verifies payload keys.
check( 'webhook_configured true', true === chip_affiliatewp_webhook_configured() );
check( 'public key resolver returns stored key', 'PUBKEY-TEST-55' === chip_affiliatewp_webhook_public_key() );

echo "\n== Test 20: unreachable site must NOT register webhook ==\n";
reset_state();
$GLOBALS['__options_store']['admin_email'] = 'admin@test.dev';
$GLOBALS['__probe_response'] = new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
$GLOBALS['__http_queue'][] = array( 'match' => '/webhooks', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/webhooks', 'code' => 200, 'body' => array( 'id' => 99, 'callback_url' => chip_affiliatewp_webhook_url(), 'public_key' => 'SHOULD-NOT-HAPPEN' ) );
$result = chip_affiliatewp_ensure_webhook();
check( 'registration refused when unreachable', is_wp_error( $result ) && 'chip_webhook_unreachable' === $result->get_error_code() );
check( 'no webhook id stored', '' === (string) affiliate_wp()->settings->get( 'chip_webhook_id_test', '' ) );
$methods_used = array_map( function ( $l ) { return $l['method']; }, $GLOBALS['__http_log'] );
check( 'no register/update call made to CHIP only listed', ! in_array( 'POST', $methods_used, true ) && ! in_array( 'PATCH', $methods_used, true ) );

// Probe failure cached: repeated attempts fail fast without a probe call.
$GLOBALS['__probe_calls'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/webhooks', 'code' => 200, 'body' => array( 'results' => array() ) );
$result = chip_affiliatewp_ensure_webhook( true );
check( 'cached unreachability respected', is_wp_error( $result ) && 0 === count( array_filter( $GLOBALS['__probe_calls'] ) ) );

echo "\n== Test 21: duplicate webhook discovery (re-list finds same URL) ==\n";
reset_state();
$GLOBALS['__probe_response'] = array( 'response' => array( 'code' => 401 ), 'body' => '' );
$GLOBALS['__options_store']['admin_email'] = 'admin@test.dev';
// List shows an existing webhook with our URL -> PATCH it, not POST.
$GLOBALS['__http_queue'][] = array( 'match' => '/webhooks', 'code' => 200, 'body' => array( 'results' => array( array( 'id' => 71, 'callback_url' => chip_affiliatewp_webhook_url() ) ) ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/webhooks/71', 'code' => 200, 'body' => array( 'id' => 71, 'callback_url' => chip_affiliatewp_webhook_url(), 'public_key' => 'PUBKEY-71' ) );
$GLOBALS['__http_log'] = array();
$result = chip_affiliatewp_ensure_webhook( true );
check( 'existing url webhook reused', true === $result && 71 === (int) affiliate_wp()->settings->get( 'chip_webhook_id_test', '' ) );
$methods_used = array_map( function ( $l ) { return $l['method']; }, $GLOBALS['__http_log'] );
check( 'list used GET then PATCH (no POST create)', ! in_array( 'POST', $methods_used, true ) );

echo "\n== Test 22: webhook admin notices ==\n";
reset_state();
$GLOBALS['__probe_response'] = new WP_Error( 'http_request_failed', 'down' );
$notices = chip_affiliatewp_webhook_setup_notices();
check( 'notice when unreachable and no webhook', 1 === count( $notices ) );
$GLOBALS['__probe_response'] = null;
$GLOBALS['__http_queue'][] = array( 'match' => '/webhooks', 'code' => 200, 'body' => array( 'id' => 80, 'callback_url' => chip_affiliatewp_webhook_url(), 'public_key' => 'K80' ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/webhooks', 'code' => 200, 'body' => array( 'results' => array( array( 'id' => 80, 'callback_url' => chip_affiliatewp_webhook_url() ) ) ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/webhooks/80', 'code' => 200, 'body' => array( 'id' => 80, 'callback_url' => chip_affiliatewp_webhook_url(), 'public_key' => 'K80' ) );
$notices = chip_affiliatewp_webhook_setup_notices();
check( 'no notice once webhook configured', 0 === count( $notices ) );

echo "\n== Test 23: failed payout can heal to paid (double-pay guard) ==\n";
reset_state();
$fail_payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 14 ),
		'amount'        => '10.00',
		'payout_method' => 'chip',
		'status'        => 'failed',
	)
);
$GLOBALS['__referral_rows'][14] = new Fake_Referral( 14, 3, '10.00', 'unpaid', $fail_payout_id );
affiliate_wp()->affiliates->payouts->update(
	$fail_payout_id,
	array( 'service_id' => 7001 ),
	'',
	'payout'
);
// Late 'completed' webhook for an instruction the plugin thought had failed.
$result = chip_affiliatewp_apply_instruction(
	$fail_payout_id,
	array( 'id' => 7001, 'state' => 'completed', 'receipt_url' => 'https://staging.chip-in.asia/receipts/send/heal1' )
);
check( 'completed heals failed payout', true === $result );
check( 'payout now paid', 'paid' === affiliate_wp()->affiliates->payouts->get_item( $fail_payout_id )->status );
check( 'referral healed to paid', 'paid' === $GLOBALS['__referral_rows'][14]->status );
// Non-terminal state on a failed payout must NOT resurrect it, just acknowledge.
$fail2 = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 15 ),
		'amount'        => '5.00',
		'payout_method' => 'chip',
		'status'        => 'failed',
	)
);
$GLOBALS['__referral_rows'][15] = new Fake_Referral( 15, 3, '5.00', 'unpaid', $fail2 );
check(
	'executing delivery on failed payout acknowledged without changes',
	true === chip_affiliatewp_apply_instruction( $fail2, array( 'id' => 7002, 'state' => 'executing' ) )
		&& 'failed' === affiliate_wp()->affiliates->payouts->get_item( $fail2 )->status
);

echo "\n== Test 24: requery only uses valid payout statuses (unpaid is a referral status) ==\n";
reset_state();
$GLOBALS['__options']['chip_test_mode']     = 1;
$GLOBALS['__options']['chip_payouts']       = 1;
$GLOBALS['__options']['chip_test_api_key']  = 'ktest';
$GLOBALS['__options']['chip_test_secret_key'] = 'stest';
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7] = new Fake_User( 7, 'affiliate@test.dev' );
$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 16 ),
		'amount'        => '2.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][16] = new Fake_Referral( 16, 3, '2.00', 'unpaid', $payout_id );
affiliate_wp()->affiliates->payouts->update( $payout_id, array( 'service_id' => 7100 ), '', 'payout' );
$GLOBALS['__http_queue'][] = array(
	'match' => '/send/send_instructions/7100',
	'code'  => 200,
	'body'  => array( 'id' => 7100, 'state' => 'completed', 'receipt_url' => 'https://staging.chip-in.asia/receipts/send/rq1' ),
);
chip_affiliatewp_check_payout_status( $payout_id, false );
check( 'processing payout resolved by service_id lookup path', 'paid' === affiliate_wp()->affiliates->payouts->get_item( $payout_id )->status );

echo "\n== Test 25: batch submissions fan out to scheduled actions (no inline HTTP) ==\n";
reset_state();
$GLOBALS['__options']['chip_test_mode']       = 1;
$GLOBALS['__options']['chip_payouts']         = 1;
$GLOBALS['__options']['chip_test_api_key']    = 'ktest';
$GLOBALS['__options']['chip_test_secret_key'] = 'stest';
for ( $i = 21; $i <= 23; $i++ ) {
	harness_add_payout( $i, 3, '1.00', 42 );
}
$GLOBALS['__as_scheduled'] = array();
chip_affiliatewp_process_generated_batch( 42 );
check( 'one scheduled action per processing payout', 3 === count( $GLOBALS['__as_scheduled'] ) );
$delay_sequence = wp_list_pluck( $GLOBALS['__as_scheduled'], 'timestamp' );
check( 'submissions staggered in time', $delay_sequence[0] <= $delay_sequence[1] && $delay_sequence[1] <= $delay_sequence[2] );
check( 'no inline submission (http log empty)', 0 === count( $GLOBALS['__http_log'] ) );

echo "\n== Test 26: stored mode survives a live test-mode flip ==\n";
reset_state();
$GLOBALS['__options']['chip_test_mode']       = 1;
$GLOBALS['__options']['chip_payouts']         = 1;
$GLOBALS['__options']['chip_test_api_key']    = 'ktest';
$GLOBALS['__options']['chip_test_secret_key'] = 'stest';
$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 24 ),
		'amount'        => '3.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][24] = new Fake_Referral( 24, 3, '3.00', 'unpaid', $payout_id );
affiliate_wp()->affiliates->payouts->update(
	$payout_id,
	array( 'service_id' => 7200, 'description' => wp_json_encode( array( 'instruction_id' => 7200, 'mode' => 'test' ) ) ),
	'',
	'payout'
);
// Admin flips to live mode with live credentials; test host still answers.
$GLOBALS['__options']['chip_test_mode']        = 0;
$GLOBALS['__options']['chip_live_api_key']     = 'klive';
$GLOBALS['__options']['chip_live_secret_key']  = 'slive';
$GLOBALS['__http_queue'][] = array(
	'match'    => '/send/send_instructions/7200',
	'url_host' => 'staging-api.chip-in.asia',
	'code'     => 200,
	'body'     => array( 'id' => 7200, 'state' => 'completed', 'receipt_url' => 'https://staging.chip-in.asia/receipts/send/mode1' ),
);
chip_affiliatewp_check_payout_status( $payout_id, false );
check(
	'payout resolved against its stored mode after mode flip',
	'paid' === affiliate_wp()->affiliates->payouts->get_item( $payout_id )->status
);

echo "\n==============================\n";
echo "PASSES: {$passes}  FAILURES: " . count( $failures ) . "\n";
if ( $failures ) {
	echo "Failed:\n  - " . implode( "\n  - ", $failures ) . "\n";
	exit( 1 );
}
exit( 0 );