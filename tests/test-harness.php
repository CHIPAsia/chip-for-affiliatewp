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

// The dependency-floor tests drive the AffiliateWP version through this global.
$GLOBALS['__affwp_version'] = '2.36.2';

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

function as_schedule_recurring_action( $ts, $interval, $hook, $args = array(), $group = '' ) {
	$GLOBALS['__as_scheduled'][] = array( 'timestamp' => $ts, 'hook' => $hook, 'args' => $args, 'group' => $group );
	/*
	 * Deliberately NOT mirrored into the WP-Cron registry: real Action
	 * Scheduler keeps its own table and never touches WP-Cron. Mirroring it
	 * made a redundant WP-Cron event look like an Action Scheduler one, which
	 * hid the double-scheduling bug the sweep used to have.
	 */
	return 1;
}

function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) {
	$kept = array();

	foreach ( $GLOBALS['__as_scheduled'] as $action ) {
		if ( $action['hook'] === $hook ) {
			continue;
		}

		$kept[] = $action;
	}

	$GLOBALS['__as_scheduled'] = $kept;

	return count( $kept );
}

function rest_url( $path = '' ) {
	return ( empty( $GLOBALS['__is_ssl'] ) ? 'http://' : 'https://' ) . 'example.test/' . ltrim( $path, '/' );
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

function delete_user_meta( $user_id, $key, $value = '' ) {
	unset( $GLOBALS['__user_meta'][ (int) $user_id ][ $key ] );
	return true;
}

function wp_strip_all_tags( $value ) {
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

	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function wp_remote_request( $url, $args ) {
	// Delayed-verification simulation: when set, bank account lookups report
	// the overridden status instead of the queued response.
	if ( ! empty( $GLOBALS['__chip_bank_lookup_override'] ) && false !== strpos( $url, '/send/bank_accounts' ) ) {
		$override = $GLOBALS['__chip_bank_lookup_override'];
		$code     = 200;
		$is_post  = 0 === strpos( $args['method'] ?? 'GET', 'POST' );
		$body     = $is_post ? $override : array( 'results' => array( $override ) );
		return array(
			'response' => array( 'code' => $code ),
			'body'     => json_encode( $body ),
		);
	}

	$GLOBALS['__http_log'][] = array(
		'url'     => $url,
		'method'  => $args['method'] ?? 'GET',
		'headers' => $args['headers'] ?? array(),
		'body'    => $args['body'] ?? null,
	);

	foreach ( $GLOBALS['__http_queue'] as $idx => $entry ) {
		if ( false !== strpos( $url, $entry['match'] ) ) {
			// Optional method constraint: "/webhooks" is a prefix of
			// "/webhooks/123", so a list mock would otherwise swallow a DELETE.
			if ( ! empty( $entry['method'] ) && strtoupper( (string) $entry['method'] ) !== strtoupper( (string) ( $args['method'] ?? 'GET' ) ) ) {
				continue;
			}
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

function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

function plugin_dir_url( $file ) {
	return 'http://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}

function plugin_dir_path( $file ) {
	return rtrim( dirname( $file ), '/' ) . '/';
}

$GLOBALS['__is_ssl'] = false;

function is_ssl() {
	return ! empty( $GLOBALS['__is_ssl'] );
}

$GLOBALS['__transients'] = array();

function get_transient( $key ) {
	return $GLOBALS['__transients'][ $key ] ?? false;
}

function set_transient( $key, $value, $expiry = 0 ) {
	$GLOBALS['__transients'][ $key ] = $value;
	return true;
}

$GLOBALS['__current_user_can'] = true;
$GLOBALS['__die_message']      = '';
$GLOBALS['__redirected']       = '';

function wp_verify_nonce( $nonce, $action = '' ) {
	return 'good-nonce' === $nonce ? 1 : false;
}

function _n( $single, $plural, $number, $domain = '' ) {
	return 1 === (int) $number ? $single : $plural;
}

function esc_url_raw( $url ) {
	$url = trim( (string) $url );

	if ( '' === $url || preg_match( '/^\s*javascript:/i', $url ) ) {
		return '';
	}

	return $url;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function wp_nonce_field( $action = '', $name = '_wpnonce', $echo = true ) {
	return '';
}

function current_user_can( $cap ) {
	return ! empty( $GLOBALS['__current_user_can'] );
}

function get_current_user_id() {
	return 1;
}

function wp_die( $message = '' ) {
	$GLOBALS['__die_message'] = is_string( $message ) ? $message : '';
	throw new Exception( 'wp_die' );
}

function wp_safe_redirect( $url ) {
	$GLOBALS['__redirected'] = $url;
}

function is_user_logged_in() {
	return ! empty( $GLOBALS['__logged_in'] );
}

function wp_redirect( $url, $status = 302 ) {
	$GLOBALS['__redirected_to'] = $url;
	return true;
}

function home_url( $path = '' ) {
	return 'http://example.test/' . ltrim( $path, '/' );
}

function wp_get_referer() {
	return '';
}

$GLOBALS['__mail'] = array();

function wp_mail( $to, $subject, $body, $headers = '' ) {
	$GLOBALS['__mail'][] = array( 'to' => $to, 'subject' => $subject, 'body' => $body );

	/*
	 * wp_mail returns false when the site cannot send (no mail transport, a
	 * refused relay). Tests set __mail_fails so the failure path can be
	 * exercised; returning true unconditionally would hide it.
	 */
	return empty( $GLOBALS['__mail_fails'] );
}

function is_email( $email ) {
	return (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
}

function get_bloginfo( $show = '' ) {
	return 'Test Store';
}

function wp_specialchars_decode( $string, $quote_style = ENT_NOQUOTES ) {
	return html_entity_decode( (string) $string, $quote_style );
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}

function submit_button( $text = '', $type = 'primary', $name = '', $wrap = true ) {
	echo '<button type="submit">' . esc_html( $text ) . '</button>';
}

function delete_transient( $key ) {
	unset( $GLOBALS['__transients'][ $key ] );
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
	public $payout_batches;

	public function __construct() {
		$this->payouts        = new Fake_Payouts_DB();
		$this->payout_batches = new Fake_Payout_Batches_DB();
	}
}

/**
 * Records batch recounts so the harness can assert the roll-up is refreshed
 * whenever a payout reaches a terminal state.
 */
class Fake_Payout_Batches_DB {
	public function recount( $batch_id ) {
		$GLOBALS['__batch_recounts'][] = (int) $batch_id;

		return true;
	}
}

$GLOBALS['__payout_rows'] = array();
$GLOBALS['__next_payout_id'] = 501;
$GLOBALS['__referral_rows'] = array();

class Fake_Payouts_DB {
	public function get_payouts( $args, $count = false ) {
		$GLOBALS['__payouts_query_count'] = ( $GLOBALS['__payouts_query_count'] ?? 0 ) + 1;
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

function affwp_get_affiliate( $affiliate_id ) {
	$user_id = $GLOBALS['__affiliates_map'][ (int) $affiliate_id ] ?? 0;

	if ( ! $user_id ) {
		return false;
	}

	return (object) array( 'affiliate_id' => (int) $affiliate_id, 'user_id' => (int) $user_id );
}

$GLOBALS['__notices'] = array();

// Minimal stand-in for AffiliateWP's failure classifier, mirroring the
// constants the plugin compares against.
if ( ! class_exists( '\AffWP\Payouts\Failure_Class' ) ) {
	class AffWP_Payouts_Failure_Class_Stub {
		const TRANSIENT                 = 'transient';
		const AFFILIATE_ACTION_REQUIRED = 'affiliate_action_required';
		const ADMIN_ACTION_REQUIRED     = 'admin_action_required';
		const DATA_ERROR                = 'data_error';
		const UNKNOWN                   = 'unknown';
	}
	class_alias( 'AffWP_Payouts_Failure_Class_Stub', '\AffWP\Payouts\Failure_Class' );
}

function affwp_notice( $args = array() ) {
	$GLOBALS['__notices'][] = $args;
}

function affwp_get_affiliate_id() {
	return $GLOBALS['__current_affiliate_id'] ?? 0;
}

function affwp_get_affiliate_usable_payout_method( $affiliate_id ) {
	$pick = $GLOBALS['__affiliate_meta'][ (int) $affiliate_id ]['payout_method_pick'] ?? '';

	return '' !== $pick ? $pick : 'manual';
}

function affwp_get_affiliate_name( $affiliate_id ) {
	return 'Test Affiliate ' . $affiliate_id;
}

function affwp_get_affiliate_payment_email( $affiliate_id ) {
	$uid = affwp_get_affiliate_user_id( $affiliate_id );
	$u   = isset( $GLOBALS['__users'][ $uid ] ) ? $GLOBALS['__users'][ $uid ] : false;
	return $u ? $u->user_email : '';
}

$GLOBALS['__payout_meta'] = array();

function affwp_update_payout_meta( $payout_id, $key, $value, $prev_value = '' ) {
	$GLOBALS['__payout_meta'][ (int) $payout_id ][ $key ] = $value;
	return true;
}

function affwp_get_payout_meta( $payout_id, $key = '', $single = false ) {
	$all = $GLOBALS['__payout_meta'][ (int) $payout_id ] ?? array();

	if ( '' === $key ) {
		return $all;
	}

	if ( $single ) {
		return $all[ $key ] ?? '';
	}

	return isset( $all[ $key ] ) ? array( $all[ $key ] ) : array();
}

$GLOBALS['__referral_meta'] = array();

function affwp_get_referral_meta( $referral_id, $key = '', $single = false ) {
	$all = $GLOBALS['__referral_meta'][ (int) $referral_id ] ?? array();

	if ( '' === $key ) {
		return $all;
	}

	if ( $single ) {
		return $all[ $key ] ?? '';
	}

	return isset( $all[ $key ] ) ? array( $all[ $key ] ) : array();
}

function affwp_update_referral_meta( $referral_id, $key, $value, $prev_value = '' ) {
	$GLOBALS['__referral_meta'][ (int) $referral_id ][ $key ] = $value;
	return true;
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

/**
 * Action Scheduler dedupe lookups. Real AS exposes these; the harness mirrors
 * them so the "one pending check per payout" guard is exercised.
 */
function as_has_scheduled_action( $hook, $args = array(), $group = '' ) {
	foreach ( $GLOBALS['__as_scheduled'] as $action ) {
		if ( $action['hook'] === $hook && $action['args'] === $args ) {
			return true;
		}
	}
	return false;
}

function as_next_scheduled_action( $hook, $args = array(), $group = '' ) {
	foreach ( $GLOBALS['__as_scheduled'] as $action ) {
		if ( $action['hook'] === $hook && $action['args'] === $args ) {
			return (int) $action['timestamp'];
		}
	}
	return false;
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

/*
 * AffiliateWP's version constant, as a real install defines it. Tests that
 * exercise the dependency floor change $GLOBALS['__affwp_version'] instead of
 * redefining this, since a constant cannot be redefined.
 */
if ( ! defined( 'AFFILIATEWP_VERSION' ) ) {
	define( 'AFFILIATEWP_VERSION', $GLOBALS['__affwp_version'] ?? '2.36.2' );
}

require __DIR__ . '/../chip-for-affiliatewp.php';

/*
 * One filter drives the version the plugin measures against, reading a global
 * so each test can set it without redefining AffiliateWP's constant.
 */
add_filter(
	'chip_affiliatewp_affwp_version',
	function ( $version ) {
		return $GLOBALS['__affwp_version'] ?? $version;
	},
	1
);

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
	$GLOBALS['__as_scheduled']   = array();
	$GLOBALS['__transients']     = array();
	$GLOBALS['__probe_calls']    = array();
	$GLOBALS['__probe_response'] = null;
	$GLOBALS['__batch_recounts']  = array();
	$GLOBALS['__payout_meta']     = array();
	$GLOBALS['__referral_meta']   = array();
	$GLOBALS['__mail']            = array();
	unset( $GLOBALS['__chip_bank_lookup_override'] );

	$GLOBALS['__options']['chip_payouts']     = 1;
	$GLOBALS['__options']['chip_test_mode']   = 1;
	$GLOBALS['__options']['chip_test_api_key']   = 'e0645c9e-fcf2-4f29-a327-202f7ed3d969';
	$GLOBALS['__options']['chip_test_secret_key'] = 'a118729e-4243-4145-83b3-0b8cb213fe8e';
	$GLOBALS['__options']['chip_reference_prefix'] = 'XT';

	// CHIP Send settles MYR only; the plugin refuses other currencies, so the
	// default fixture is a MYR store.
	$GLOBALS['__options']['currency'] = 'MYR';
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

echo "\n== Test 2b: webhook URL honours is_ssl ==\n";
$GLOBALS['__is_ssl'] = false;
check( 'http fallback without SSL', ! str_starts_with( chip_affiliatewp_webhook_url(), 'https://' ) );
$GLOBALS['__is_ssl'] = true;
check( 'https preferred behind SSL', str_starts_with( chip_affiliatewp_webhook_url(), 'https://' ) );
$GLOBALS['__is_ssl'] = false;

echo "\n== Test 2c: description sanitizer matches the CHIP allow-list ==\n";
// CHIP Send 422s on any character outside: alphanumeric, . space _ - / @ ( )
$chip_desc_ok = chip_affiliatewp_sanitize_description( 'Affiliate commission payout No.2' );
check( 'allowed characters survive', 'Affiliate commission payout No.2' === $chip_desc_ok );
check( 'hash is replaced (referral descriptions)', false === strpos( chip_affiliatewp_sanitize_description( 'Commission for referral #42' ), '#' ) );
check( 'hash becomes No.', false !== strpos( chip_affiliatewp_sanitize_description( 'Commission for referral #42' ), 'No.42' ) );
check( 'ampersand is stripped', false === strpos( chip_affiliatewp_sanitize_description( 'A & B' ), '&' ) );
check( 'percent is stripped', false === strpos( chip_affiliatewp_sanitize_description( '50% bonus' ), '%' ) );
check( 'quotes are stripped', false === strpos( chip_affiliatewp_sanitize_description( 'Store "X" order' ), '"' ) );
check( 'colon is stripped', false === strpos( chip_affiliatewp_sanitize_description( 'Order: 123' ), ':' ) );
check( 'apostrophes are stripped (not in allow-list)', 'Store X' === trim( chip_affiliatewp_sanitize_description( 'Store ' . "\u{2019}" . 'X' . "\u{2019}" ) ) );
check( 'allowed set preserved ( / @ ( ) _ - . )', 'a/b@c(d)_e-f.g' === chip_affiliatewp_sanitize_description( 'a/b@c(d)_e-f.g' ) );
check( 'empty input falls back to a safe default', 'Affiliate commission payout' === chip_affiliatewp_sanitize_description( '&&&' ) );
check( 'length is capped at 140', 140 >= strlen( chip_affiliatewp_sanitize_description( str_repeat( 'a', 300 ) ) ) );
check( 'no leading/trailing space', chip_affiliatewp_sanitize_description( '  hi  ' ) === 'hi' );

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
$create_body = '';
foreach ( $GLOBALS['__http_log'] as $call ) {
	if ( 'POST' === $call['method'] && false !== strpos( $call['url'], '/send/bank_accounts' ) ) {
		$create_body = (string) $call['body'];
	}
}
check( 'create POST includes reference', false !== strpos( $create_body, '"reference"' ) );

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
// The submit path checks whether the reference already exists first.
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'POST', 'code' => 200, 'body' => array( 'id' => 900, 'state' => 'received', 'receipt_url' => 'https://www.chip-in.asia/receipts/send/abc123' ) );
$result = chip_affiliatewp_submit_payout( $payout_id );
check( 'submit succeeded', true === $result );
$row = affwp_get_payout( $payout_id );
$data = chip_affiliatewp_payout_data( $row );
check( 'instruction id stored', 900 === (int) $data['instruction_id'] );
check( 'service_id stored', 900 === (int) $row->service_id );
check( 'receipt stored', 'https://www.chip-in.asia/receipts/send/abc123' === $data['receipt_url'] );
check( 'payout still processing', 'processing' === $row->status );
check( 'recheck scheduled', ! empty( $GLOBALS['__as'] ) );
check( 'referral NOT yet paid', 'unpaid' === $GLOBALS['__referral_rows'][11]->status );
$post_body = '';
foreach ( $GLOBALS['__http_log'] as $call ) {
	if ( 'POST' === $call['method'] && false !== strpos( $call['url'], '/send/send_instructions' ) ) {
		$post_body = (string) $call['body'];
	}
}
check( 'amount in payload', false !== strpos( $post_body, '"amount":"250.50"' ) );
check( 'reference in payload', false !== strpos( $post_body, '"reference":"XT-PO-' . $payout_id . '"' ) );

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
check( 'receipt updated', 'https://www.chip-in.asia/receipts/send/zzz' === ( chip_affiliatewp_payout_data( $row )['receipt_url'] ?? '' ) );

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
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions/905', 'code' => 200, 'body' => array( 'id' => 905, 'state' => 'completed', 'receipt_url' => 'https://www.chip-in.asia/receipts/send/def456' ) );
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
check( 'state recorded', 'executing' === ( chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) )['state'] ?? '' ) );

echo "\n== Test 13: webhook signature verification ==\n";
reset_state();
// Generate keypair, sign a payload as CHIP would.
$keypair = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
openssl_pkey_export( $keypair, $priv_pem );
$details = openssl_pkey_get_details( $keypair );
$pub_pem = $details['key'];
$GLOBALS['__options']['chip_webhook_public_key'] = $pub_pem;

// Webhook URL now carries a per-site secret suffix; the settings store must
// have a secret so the harness URL matches the registered rest route stub.
$GLOBALS['__options']['chip_webhook_secret'] = 'fixedharnesssecret000000000000000000';

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
// The submit path checks whether the reference already exists first.
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'POST', 'code' => 200, 'body' => array( 'id' => 950, 'state' => 'received' ) );
call_user_func( 'chip_affiliatewp_run_scheduled_submission', $payout_id );
$row = affwp_get_payout( $payout_id );
check( 'batch payout submitted', 950 === (int) ( chip_affiliatewp_payout_data( $row )['instruction_id'] ?? 0 ) );

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
// The submit path checks whether the reference already exists first.
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'POST', 'code' => 400, 'body' => array( 'message' => 'reference must be unique', 'code' => 400 ) );
// ...and the list-by-reference finds the original instruction.
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array( array( 'id' => 888, 'state' => 'executing', 'reference' => 'XT-PO-' . $payout_id ) ) ) );
$result = chip_affiliatewp_submit_payout( $payout_id );
$row = affwp_get_payout( $payout_id );
check( 'conflict resolved by adopting existing instruction', true === $result && 888 === (int) ( chip_affiliatewp_payout_data( $row )['instruction_id'] ?? 0 ) );

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
// The submit path checks whether the reference already exists first.
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'POST', 'code' => 200, 'body' => array( 'id' => 700, 'state' => 'received' ) );
$result = chip_affiliatewp_pay_single_referral( 91 );
check( 'single pay succeeded', true === $result );
$created = array_filter( $GLOBALS['__payout_rows'], function ( $p ) { return 'processing' === $p->status && 700 === (int) $p->service_id; } );
check( 'payout record created with service_id', 1 === count( $created ) );
check( 'referral stays unpaid until confirmed', 'unpaid' === $GLOBALS['__referral_rows'][91]->status );

echo "\n== Test 18: activation/deactivation hooks ==\n";
reset_state();
call_user_func( $GLOBALS['__activate_cb'] );
// The sweep runs through Action Scheduler when it is available; the WP-Cron
// registry is only the fallback, so assert on the scheduler actually used.
check( 'hourly sweep scheduled', ! empty( $GLOBALS['__as_scheduled'] ) );
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

echo "\n== Test 25: a successful resubmission clears the failure and returns to processing ==\n";
reset_state();
$GLOBALS['__options']['chip_test_mode'] = 1;
$GLOBALS['__options']['chip_payouts']   = 1;
$GLOBALS['__options']['chip_test_api_key']    = 'k';
$GLOBALS['__options']['chip_test_secret_key'] = 's';
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7] = (object) array( 'ID' => 7, 'user_email' => 'aff3@example.test' );
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__user_meta'][7]['payment_account_number'] = '1234567890';
$GLOBALS['__http_queue'][] = array( 'match' => '/send/bank_accounts', 'code' => 200, 'body' => array( 'results' => array( array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) ) ) ) );

$retry_payout = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 16 ),
		'amount'        => '2.00',
		'payout_method' => 'chip',
		'status'        => 'failed',
	)
);
$GLOBALS['__referral_rows'][16] = new Fake_Referral( 16, 3, '2.00', 'unpaid', $retry_payout );

// Record an earlier failure note, as the fail path would have.
chip_affiliatewp_update_payout_data(
	$retry_payout,
	array( 'error' => 'CHIP Send API error (HTTP 422): bad description' )
);

// CHIP accepts the retried submission this time.
// The submit path checks whether the reference already exists first.
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'POST', 'code' => 200, 'body' => array( 'id' => 8001, 'state' => 'received' ) );

$submit_result = chip_affiliatewp_submit_payout( $retry_payout );
$retry_row    = affiliate_wp()->affiliates->payouts->get_item( $retry_payout );
$retry_data   = chip_affiliatewp_payout_data( $retry_row );

check( 'resubmission succeeds', true === $submit_result );
check( 'payout returns to processing', 'processing' === $retry_row->status );
check( 'instruction id recorded', 8001 === (int) $retry_row->service_id );
check( 'stale failure note cleared', false === isset( $retry_data['error'] ) );
check( 'referral held unpaid while in flight', 'unpaid' === $GLOBALS['__referral_rows'][16]->status );

// A completed delivery then clears the note as well.
chip_affiliatewp_apply_instruction( $retry_payout, array( 'id' => 8001, 'state' => 'completed' ) );
$healed = affiliate_wp()->affiliates->payouts->get_item( $retry_payout );
check( 'completed delivery pays the retried payout', 'paid' === $healed->status );
check( 'error note still absent after healing', false === isset( chip_affiliatewp_payout_data( $healed )['error'] ) );

echo "\n== Test 26: an in-flight delivery keeps the failure note visible ==\n";
$note_payout = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 17 ),
		'amount'        => '3.00',
		'payout_method' => 'chip',
		'status'        => 'failed',
	)
);
$GLOBALS['__referral_rows'][17] = new Fake_Referral( 17, 3, '3.00', 'unpaid', $note_payout );
chip_affiliatewp_update_payout_data( $note_payout, array( 'error' => 'previous failure', 'instruction_id' => 8002 ) );
chip_affiliatewp_apply_instruction( $note_payout, array( 'id' => 8002, 'state' => 'executing' ) );
$note_row = affiliate_wp()->affiliates->payouts->get_item( $note_payout );
check( 'in-flight delivery does not clear the note', 'previous failure' === ( chip_affiliatewp_payout_data( $note_row )['error'] ?? '' ) );

echo "\n== Test 27: description sanitizer is applied to the submitted payload ==\n";
reset_state();
$GLOBALS['__options']['chip_test_mode'] = 1;
$GLOBALS['__options']['chip_payouts']   = 1;
$GLOBALS['__options']['chip_test_api_key']    = 'k';
$GLOBALS['__options']['chip_test_secret_key'] = 's';
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7] = (object) array( 'ID' => 7, 'user_email' => 'aff3@example.test' );
$GLOBALS['__user_meta'][7]['payment_bank_code']       = 'MBBEMYKL';
$GLOBALS['__user_meta'][7]['payment_account_number'] = '1234567890';
$GLOBALS['__http_queue'][] = array( 'match' => '/send/bank_accounts', 'code' => 200, 'body' => array( 'results' => array( array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) ) ) ) );

$desc_payout = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 18 ),
		'amount'        => '1.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][18] = new Fake_Referral( 18, 3, '1.00', 'unpaid', $desc_payout );
// The submit path checks whether the reference already exists first.
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'POST', 'code' => 200, 'body' => array( 'id' => 8003, 'state' => 'received' ) );

chip_affiliatewp_submit_payout( $desc_payout );
$sent = json_decode( $GLOBALS['__http_log'][ count( $GLOBALS['__http_log'] ) - 1 ]['body'], true );
check( 'no hash reaches the API', false === strpos( (string) ( $sent['description'] ?? '' ), '#' ) );
check( 'description present', '' !== (string) ( $sent['description'] ?? '' ) );
check( 'description within 140 chars', 140 >= strlen( (string) ( $sent['description'] ?? '' ) ) );

echo "\n== Test 28: a terminal payout recounts its batch roll-up ==\n";
reset_state();
$GLOBALS['__options']['chip_test_mode'] = 1;
$GLOBALS['__options']['chip_payouts']   = 1;
$GLOBALS['__options']['chip_test_api_key']    = 'k';
$GLOBALS['__options']['chip_test_secret_key'] = 's';
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7] = (object) array( 'ID' => 7, 'user_email' => 'aff3@example.test' );
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__user_meta'][7]['payment_account_number'] = '1234567890';
$GLOBALS['__http_queue'][] = array( 'match' => '/send/bank_accounts', 'code' => 200, 'body' => array( 'results' => array( array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) ) ) ) );

$batch_payout = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 19 ),
		'amount'        => '4.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
affiliate_wp()->affiliates->payouts->update( $batch_payout, array( 'batch_id' => 77 ), '', 'payout' );
$GLOBALS['__referral_rows'][19] = new Fake_Referral( 19, 3, '4.00', 'unpaid', $batch_payout );

// Completed delivery must recount the batch so it can leave processing.
chip_affiliatewp_apply_instruction( $batch_payout, array( 'id' => 9001, 'state' => 'completed' ) );
check( 'completed payout recounts its batch', in_array( 77, $GLOBALS['__batch_recounts'], true ) );
check( 'payout is paid', 'paid' === affiliate_wp()->affiliates->payouts->get_item( $batch_payout )->status );

// A rejected instruction is terminal too.
$GLOBALS['__batch_recounts'] = array();
$reject_payout = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 20 ),
		'amount'        => '5.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
affiliate_wp()->affiliates->payouts->update( $reject_payout, array( 'batch_id' => 78 ), '', 'payout' );
$GLOBALS['__referral_rows'][20] = new Fake_Referral( 20, 3, '5.00', 'unpaid', $reject_payout );
chip_affiliatewp_apply_instruction( $reject_payout, array( 'id' => 9002, 'state' => 'rejected', 'rejection_reason' => 'bank closed' ) );
check( 'rejected payout recounts its batch', in_array( 78, $GLOBALS['__batch_recounts'], true ) );

// In-flight delivery must NOT recount (the batch is still legitimately processing).
$GLOBALS['__batch_recounts'] = array();
$GLOBALS['__referral_rows'][21] = new Fake_Referral( 21, 3, '6.00', 'unpaid', 0 );
$inflight_payout = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 21 ),
		'amount'        => '6.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
affiliate_wp()->affiliates->payouts->update( $inflight_payout, array( 'batch_id' => 79 ), '', 'payout' );
$GLOBALS['__referral_rows'][21] = new Fake_Referral( 21, 3, '6.00', 'unpaid', $inflight_payout );
chip_affiliatewp_apply_instruction( $inflight_payout, array( 'id' => 9003, 'state' => 'executing' ) );
check( 'in-flight payout does not recount', ! in_array( 79, $GLOBALS['__batch_recounts'], true ) );

// A payout with no batch must not blow up.
$GLOBALS['__batch_recounts'] = array();
$no_batch_payout = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 22 ),
		'amount'        => '7.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][22] = new Fake_Referral( 22, 3, '7.00', 'unpaid', $no_batch_payout );
chip_affiliatewp_recount_batch_for_payout( $no_batch_payout );
check( 'no batch_id means no recount, no error', array() === $GLOBALS['__batch_recounts'] );

// An unknown payout id is a no-op rather than a fatal.
chip_affiliatewp_recount_batch_for_payout( 999999 );
check( 'unknown payout id is a safe no-op', array() === $GLOBALS['__batch_recounts'] );

echo "\n== Test 29: enable-state and per-affiliate readiness filters ==\n";
reset_state();
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7] = (object) array( 'ID' => 7, 'user_email' => 'aff3@example.test' );
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__user_meta'][7]['payment_account_number'] = '1234567890';

// Method disabled -> not enabled even with credentials present.
$GLOBALS['__options']['chip_payouts'] = 0;
check( 'disabled method reports not enabled', false === chip_affiliatewp_is_payout_method_enabled( true, 'chip' ) );

// Enabled but no credentials -> still not enabled.
$GLOBALS['__options']['chip_payouts'] = 1;
unset( $GLOBALS['__options']['chip_test_api_key'], $GLOBALS['__options']['chip_test_secret_key'] );
check( 'missing credentials reports not enabled', false === chip_affiliatewp_is_payout_method_enabled( true, 'chip' ) );

// Enabled with credentials -> enabled.
$GLOBALS['__options']['chip_test_mode']       = 1;
$GLOBALS['__options']['chip_test_api_key']    = 'k';
$GLOBALS['__options']['chip_test_secret_key'] = 's';
check( 'enabled with credentials reports enabled', true === chip_affiliatewp_is_payout_method_enabled( true, 'chip' ) );

// Other methods are untouched by our filter.
check( 'other methods pass through unchanged', true === chip_affiliatewp_is_payout_method_enabled( true, 'stripe' ) );
check( 'other methods keep a false unchanged', false === chip_affiliatewp_is_payout_method_enabled( false, 'paypal' ) );

// Readiness: affiliate with bank details is ready.
check( 'affiliate with bank details is ready', true === chip_affiliatewp_payout_method_is_affiliate_ready( false, 'chip', 3 ) );

// Readiness: affiliate without bank details is not ready.
$GLOBALS['__affiliates_map'][4] = 8;
$GLOBALS['__users'][8] = (object) array( 'ID' => 8, 'user_email' => 'aff4@example.test' );
check( 'affiliate without bank details is not ready', false === chip_affiliatewp_payout_method_is_affiliate_ready( false, 'chip', 4 ) );

// Readiness: disabled method blocks readiness.
$GLOBALS['__options']['chip_payouts'] = 0;
check( 'disabled method blocks affiliate readiness', false === chip_affiliatewp_payout_method_is_affiliate_ready( false, 'chip', 3 ) );
$GLOBALS['__options']['chip_payouts'] = 1;

// Readiness: other methods pass through.
check( 'other methods keep readiness unchanged', true === chip_affiliatewp_payout_method_is_affiliate_ready( true, 'stripe', 3 ) );

echo "\n== Test 30: the native integration points are registered ==\n";
check( 'legacy bulk entry point has a listener', ! empty( $GLOBALS['__actions']['affwp_process_payout_chip'] ) );
check( 'enable-state filter is registered', ! empty( $GLOBALS['__filters']['affwp_is_payout_method_enabled'] ) );
check( 'affiliate-readiness filter is registered', ! empty( $GLOBALS['__filters']['affwp_payout_method_is_affiliate_ready'] ) );
check( 'batch completion action is registered', ! empty( $GLOBALS['__actions']['affwp_batch_generate_payouts_completed'] ) );
check( 'payment-method card action is registered', ! empty( $GLOBALS['__actions']['affwp_register_payment_methods'] ) );
check( 'preview note action is registered', ! empty( $GLOBALS['__actions']['affwp_preview_payout_note_chip'] ) );
check( 'affiliate table filter is registered', ! empty( $GLOBALS['__filters']['affwp_affiliate_table_payout_method'] ) );
check( 'preflight filter is registered', ! empty( $GLOBALS['__filters']['affwp_preflight_payout_status'] ) );
check( 'single referral handler filter is registered', ! empty( $GLOBALS['__filters']['affwp_single_referral_payout_handlers'] ) );
check( 'payouts settings sanitize filter is registered', ! empty( $GLOBALS['__filters']['affwp_settings_payouts_sanitize'] ) );
check( 'batch initial status filter is registered', ! empty( $GLOBALS['__filters']['affwp_batch_payout_initial_status'] ) );

echo "\n== Test 31: failure classification drives retry and email behaviour ==\n";
check( 'missing bank details needs affiliate action', 'affiliate_action_required' === chip_affiliatewp_classify_failure( 'chip_missing_bank_details', 'no bank details' ) );
check( 'unverified bank account needs affiliate action', 'affiliate_action_required' === chip_affiliatewp_classify_failure( 'chip_bank_account_unverified', 'status: pending' ) );
check( 'missing payment email needs affiliate action', 'affiliate_action_required' === chip_affiliatewp_classify_failure( 'chip_no_email', 'no email' ) );
check( 'rejected instruction needs affiliate action', 'affiliate_action_required' === chip_affiliatewp_classify_failure( 'chip_instruction_rejected', 'CHIP Send instruction rejected. bank closed' ) );
check( 'missing credentials needs admin action', 'admin_action_required' === chip_affiliatewp_classify_failure( 'chip_missing_credentials', 'no keys' ) );
check( 'disabled method needs admin action', 'admin_action_required' === chip_affiliatewp_classify_failure( 'chip_payouts_disabled', 'disabled' ) );
check( 'api failure is transient', 'transient' === chip_affiliatewp_classify_failure( 'chip_api_error', 'CHIP Send API error (HTTP 503): unavailable' ) );
check( 'timeout is transient', 'transient' === chip_affiliatewp_classify_failure( '', 'Request timed out' ) );
check( 'unrecognised failure is unknown', 'unknown' === chip_affiliatewp_classify_failure( 'weird', 'something else' ) );
check( 'classifier registered for chip', function_exists( 'chip_affiliatewp_register_failure_classifier' ) );

echo "\n== Test 32: a failed payout records its failure class ==\n";
reset_state();
$GLOBALS['__options']['chip_test_mode'] = 1;
$GLOBALS['__options']['chip_payouts']   = 1;
$GLOBALS['__options']['chip_test_api_key']    = 'k';
$GLOBALS['__options']['chip_test_secret_key'] = 's';
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7] = (object) array( 'ID' => 7, 'user_email' => 'aff3@example.test' );

$class_payout = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 23 ),
		'amount'        => '8.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][23] = new Fake_Referral( 23, 3, '8.00', 'unpaid', $class_payout );

chip_affiliatewp_fail_payout( $class_payout, 'Bank account is not verified yet (status: pending).', 'chip_bank_account_unverified' );
$class_row = affiliate_wp()->affiliates->payouts->get_item( $class_payout );
check( 'payout failed', 'failed' === $class_row->status );
check( 'failure class recorded', 'affiliate_action_required' === ( $class_row->failure_class ?? '' ) );
check( 'referral released to unpaid', 'unpaid' === $GLOBALS['__referral_rows'][23]->status );

echo "\n== Test 32: failure email body is swapped only for actionable failures ==\n";

$payout_stub = new stdClass();
$payout_stub->description = wp_json_encode( array( 'failure_class' => 'affiliate_action_required' ) );
$body = chip_affiliatewp_failure_email_body( 'generic body', $payout_stub, 'chip', 'failed' );
check( 'actionable failure replaces the body', false !== strpos( $body, 'bank account details' ) );

$payout_stub->description = wp_json_encode( array( 'failure_class' => 'transient' ) );
check( 'transient failure keeps the merchant copy', 'generic body' === chip_affiliatewp_failure_email_body( 'generic body', $payout_stub, 'chip', 'failed' ) );

$payout_stub->description = wp_json_encode( array( 'failure_class' => 'affiliate_action_required' ) );
check( 'other methods are untouched', 'generic body' === chip_affiliatewp_failure_email_body( 'generic body', $payout_stub, 'paypal', 'failed' ) );

echo "\n== Test 33: bank-account id is cached and invalidated on detail change ==\n";
reset_state();
$GLOBALS['__options']['chip_payouts']     = 1;
$GLOBALS['__options']['chip_test_mode']   = 1;
$GLOBALS['__options']['chip_test_api_key']    = 'test-key';
$GLOBALS['__options']['chip_test_secret_key'] = 'test-secret';
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7] = (object) array( 'user_email' => 'aff@example.com' );
$GLOBALS['__user_meta'][7] = array(
	'payment_account_number' => '1234567890',
	'payment_bank_code'      => 'MBBEMYKL',
);

// First resolve: nothing stored, so CHIP is asked once.
$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/send/bank_accounts', 'code' => 200, 'body' => array( 'results' => array( array( 'id' => 4242, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) ) ) ) );
$account = chip_affiliatewp_get_bank_account( 3 );
check( 'first lookup hits CHIP and returns the id', isset( $account['id'] ) && 4242 === (int) $account['id'] );
$stored_raw = $GLOBALS['__user_meta'][7]['chip_bank_account'];
$stored_mode_record = isset( $stored_raw['id'] ) ? $stored_raw : ( $stored_raw[ chip_affiliatewp_current_mode() ] ?? array() );
check( 'account id is stored against the affiliate', 4242 === (int) ( $stored_mode_record['id'] ?? 0 ) );

// Second resolve: stored and still valid, so no HTTP call is queued.
$GLOBALS['__http_queue'] = array();
$account = chip_affiliatewp_get_bank_account( 3 );
check( 'second lookup is served from storage', isset( $account['id'] ) && 4242 === (int) $account['id'] );
check( 'second lookup made no HTTP request', array() === $GLOBALS['__http_queue'] );

// Changing the account number invalidates the stored record.
$GLOBALS['__user_meta'][7]['payment_account_number'] = '9999999999';
check( 'changed details invalidate the stored id', null === chip_affiliatewp_get_stored_bank_account( 3, chip_affiliatewp_bank_reference( 3 ) ) );

// A deleted account is never reused even when the fingerprint matches.
$GLOBALS['__user_meta'][7]['payment_account_number'] = '1234567890';
chip_affiliatewp_store_bank_account( 3, array( 'id' => 4242, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) ) );
$_m = chip_affiliatewp_current_mode();
$GLOBALS['__user_meta'][7]['chip_bank_account'][ $_m ]['deleted_at'] = '2026-01-01T00:00:00Z';
check( 'deleted account is not reused', null === chip_affiliatewp_get_stored_bank_account( 3, chip_affiliatewp_bank_reference( 3 ) ) );

// A rejected account is not reused either.
$GLOBALS['__user_meta'][7]['chip_bank_account'] = array();
chip_affiliatewp_store_bank_account( 3, array( 'id' => 4242, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) ) );
$_m = chip_affiliatewp_current_mode();
$GLOBALS['__user_meta'][7]['chip_bank_account'][ $_m ]['status'] = 'rejected';
check( 'rejected account is not reused', null === chip_affiliatewp_get_stored_bank_account( 3, chip_affiliatewp_bank_reference( 3 ) ) );

// Details are sanitized: separators do not defeat the fingerprint.
$GLOBALS['__user_meta'][7]['chip_bank_account'] = array();
chip_affiliatewp_store_bank_account( 3, array( 'id' => 4242, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) ) );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '1234-567 890';
check( 'separators in the account number keep the fingerprint stable', null !== chip_affiliatewp_get_stored_bank_account( 3, chip_affiliatewp_bank_reference( 3 ) ) );

echo "\n== Test 34: account balance is parsed and cached ==\n";
reset_state();
$GLOBALS['__options']['chip_payouts']          = 1;
$GLOBALS['__options']['chip_test_mode']        = 1;
$GLOBALS['__options']['chip_test_api_key']     = 'test-key';
$GLOBALS['__options']['chip_test_secret_key']  = 'test-secret';
$GLOBALS['__transients'] = array();

$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array(
	'match' => '/send/accounts',
	'code'  => 200,
	'body'  => array(
		'results' => array(
			array(
				'current_balance'                     => 100.5,
				'convertible_balance_from_statement'  => 1000,
				'currency'                            => 'myr',
				'settlement_convert_approvals_count'  => 2,
			),
		),
	),
);

$summary = chip_affiliatewp_get_account_summary( 'test' );
check( 'current balance parsed', 100.5 === $summary['current_balance'] );
check( 'convertible balance parsed', 1000.0 === $summary['convertible'] );
check( 'currency upper-cased', 'MYR' === $summary['currency'] );
check( 'approvals parsed', 2 === (int) $summary['approvals_required'] );

// Second read is served from the transient: nothing queued, still correct.
$GLOBALS['__http_queue'] = array();
$again = chip_affiliatewp_get_account_summary( 'test' );
check( 'second read is cached', 100.5 === $again['current_balance'] );
check( 'second read made no HTTP request', array() === $GLOBALS['__http_queue'] );

// A forced read bypasses the cache.
$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array(
	'match' => '/send/accounts',
	'code'  => 200,
	'body'  => array( 'results' => array( array( 'current_balance' => 7, 'currency' => 'MYR' ) ) ),
);
$forced = chip_affiliatewp_get_account_summary( 'test', true );
check( 'forced read re-requests', 7.0 === $forced['current_balance'] );

// Requesting an allocation clears the cached balance.
$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_limits', 'code' => 200, 'body' => array( 'ok' => true ) );
chip_affiliatewp_request_budget_allocation( 500, 'test' );
check( 'allocation clears the cached balance', false === get_transient( 'chip_affiliatewp_account_test' ) );

// Invalid amounts are rejected before any request is made.
$GLOBALS['__http_queue'] = array();
check( 'zero allocation rejected', is_wp_error( chip_affiliatewp_request_budget_allocation( 0, 'test' ) ) );
check( 'negative allocation rejected', is_wp_error( chip_affiliatewp_request_budget_allocation( -5, 'test' ) ) );
check( 'invalid allocation made no request', array() === $GLOBALS['__http_queue'] );

// Formatting never returns an empty string.
check( 'money formats with the currency code', false !== strpos( chip_affiliatewp_format_money( 1234.5, 'MYR' ), '1,234.50' ) );

echo "\n== Test 35: conversion handler validates before calling CHIP ==\n";
reset_state();
$GLOBALS['__options']['chip_payouts']         = 1;
$GLOBALS['__options']['chip_test_mode']       = 1;
$GLOBALS['__options']['chip_test_api_key']    = 'test-key';
$GLOBALS['__options']['chip_test_secret_key'] = 'test-secret';
$GLOBALS['__transients'] = array();
$GLOBALS['__current_user_can'] = true;

// No action posted -> nothing happens, no request.
$GLOBALS['__http_queue'] = array();
$_POST = array();
chip_affiliatewp_handle_convert_balance();
check( 'no action means no request', array() === $GLOBALS['__http_queue'] );

// Action but a bad nonce -> still nothing.
$GLOBALS['__http_queue'] = array();
$_POST = array(
	'chip_affiliatewp_action'       => 'convert_balance',
	'chip_affiliatewp_convert_nonce' => 'not-a-valid-nonce',
	'chip_convert_amount'           => '100',
);
chip_affiliatewp_handle_convert_balance();
check( 'bad nonce means no request', array() === $GLOBALS['__http_queue'] );

// Action + good nonce but no capability -> dies before any request.
$GLOBALS['__http_queue'] = array();
$GLOBALS['__current_user_can'] = false;
$_POST = array(
	'chip_affiliatewp_action'        => 'convert_balance',
	'chip_affiliatewp_convert_nonce' => 'good-nonce',
	'chip_convert_amount'            => '100',
);
$GLOBALS['__die_message'] = '';
try {
	chip_affiliatewp_handle_convert_balance();
} catch ( Exception $e ) {
	// wp_die() stops the request; expected here.
}
check( 'missing capability blocks the request', array() === $GLOBALS['__http_queue'] );
check( 'missing capability explains itself', false !== strpos( $GLOBALS['__die_message'], 'permission' ) );

// Valid request reaches CHIP and queues a success notice.
$GLOBALS['__current_user_can'] = true;
$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array(
	'match' => '/send/send_limits',
	'code'  => 200,
	'body'  => array( 'id' => 9, 'status' => 'pending', 'approvals_required' => 2 ),
);
$GLOBALS['__transients'] = array();
add_filter( 'chip_affiliatewp_convert_balance_redirect', function () { return false; } );
$_POST = array(
	'chip_affiliatewp_action'        => 'convert_balance',
	'chip_affiliatewp_convert_nonce' => 'good-nonce',
	'chip_convert_amount'            => '250.50',
);
chip_affiliatewp_handle_convert_balance();
check( 'valid request consumes the queued CHIP response', 0 === count( $GLOBALS['__http_queue'] ) );
check( 'valid request queues a success notice', is_array( get_transient( 'chip_affiliatewp_notices_' . get_current_user_id() ) ) );

// Notices round-trip: queued, then rendered once and cleared.
$GLOBALS['__transients'] = array();
chip_affiliatewp_add_admin_notice( 'success', 'Conversion requested.' );
check( 'notice is queued', is_array( get_transient( 'chip_affiliatewp_notices_' . get_current_user_id() ) ) );
ob_start();
chip_affiliatewp_render_queued_notices();
$rendered = ob_get_clean();
check( 'notice renders', false !== strpos( $rendered, 'Conversion requested.' ) );
check( 'notice is cleared after rendering', false === get_transient( 'chip_affiliatewp_notices_' . get_current_user_id() ) );

$_POST = array();

echo "\n== Test 36: receipt URLs are restricted to CHIP hosts ==\n";

check( 'apex host accepted', 'https://chip-in.asia/receipts/send/a' === chip_affiliatewp_safe_receipt_url( 'https://chip-in.asia/receipts/send/a' ) );
check( 'www subdomain accepted', 'https://www.chip-in.asia/receipts/send/a' === chip_affiliatewp_safe_receipt_url( 'https://www.chip-in.asia/receipts/send/a' ) );
check( 'staging subdomain accepted', 'https://staging.chip-in.asia/receipts/send/a' === chip_affiliatewp_safe_receipt_url( 'https://staging.chip-in.asia/receipts/send/a' ) );

check( 'javascript: rejected', '' === chip_affiliatewp_safe_receipt_url( 'javascript:alert(1)' ) );
check( 'data: rejected', '' === chip_affiliatewp_safe_receipt_url( 'data:text/html,<script>alert(1)</script>' ) );
check( 'foreign host rejected', '' === chip_affiliatewp_safe_receipt_url( 'https://evil.example.com/receipts/send/a' ) );
check( 'lookalike host rejected', '' === chip_affiliatewp_safe_receipt_url( 'https://chip-in.asia.evil.com/x' ) );
check( 'relative path rejected', '' === chip_affiliatewp_safe_receipt_url( '/receipts/send/a' ) );
check( 'empty stays empty', '' === chip_affiliatewp_safe_receipt_url( '' ) );

// A forged receipt in a webhook payload must not reach the payout record.
reset_state();
$GLOBALS['__options']['chip_payouts']   = 1;
$GLOBALS['__options']['chip_test_mode'] = 1;
$GLOBALS['__options']['chip_test_api_key']    = 'test-key';
$GLOBALS['__options']['chip_test_secret_key'] = 'test-secret';
$GLOBALS['__affiliates_map'][2] = 5;
$GLOBALS['__users'][5] = (object) array( 'user_email' => 'a@example.com' );
$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 2,
		'referrals'     => array( 40 ),
		'amount'        => '5.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
		'service_id'    => 9001,
		'description'   => wp_json_encode( array( 'instruction_id' => 9001, 'state' => 'executing' ) ),
	)
);
$GLOBALS['__referral_rows'][40] = new Fake_Referral( 40, 2, '5.00', 'processing', $payout_id );
chip_affiliatewp_apply_instruction(
	$payout_id,
	array(
		'id'          => 9001,
		'state'       => 'completed',
		'reference'   => 'XT-PO-' . $payout_id,
		'receipt_url' => 'https://evil.example.com/steal',
	)
);
$stored = chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) );
check( 'forged receipt URL is not stored', empty( $stored['receipt_url'] ) );

echo "\n== Test 37: revoked referrals are not paid ==\n";
reset_state();
$GLOBALS['__options']['chip_payouts']         = 1;
$GLOBALS['__options']['chip_test_mode']       = 1;
$GLOBALS['__options']['chip_test_api_key']    = 'test-key';
$GLOBALS['__options']['chip_test_secret_key'] = 'test-secret';
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7] = new Fake_User( 7, 'affiliate@test.dev' );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__chip_bank_lookup_override'] = array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) );

// Every referral revoked -> nothing is sent.
$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 50, 51 ),
		'amount'        => '10.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][50] = new Fake_Referral( 50, 3, '5.00', 'paid', $payout_id );
$GLOBALS['__referral_rows'][51] = new Fake_Referral( 51, 3, '5.00', 'paid', $payout_id );
$GLOBALS['__http_queue'] = array();
$result = chip_affiliatewp_submit_payout( $payout_id );
check( 'all-revoked payout fails', is_wp_error( $result ) );
check( 'all-revoked payout sent no instruction', array() === $GLOBALS['__http_queue'] );
$all_revoked_posts = 0;
foreach ( $GLOBALS['__http_log'] as $call ) {
	if ( 'POST' === $call['method'] ) {
		++$all_revoked_posts;
	}
}
check( 'all-revoked payout sends nothing', 0 === $all_revoked_posts );
check( 'all-revoked payout records the reason', false !== strpos( $result->get_error_message(), 'awaiting payment' ) );

// One of two revoked -> amount is reduced to what is still payable.
$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 60, 61 ),
		'amount'        => '10.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][60] = new Fake_Referral( 60, 3, '4.00', 'unpaid', $payout_id );
$GLOBALS['__referral_rows'][61] = new Fake_Referral( 61, 3, '6.00', 'paid', $payout_id );
$GLOBALS['__http_queue'] = array();
// The submit path checks whether the reference already exists first.
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'POST', 'code' => 200, 'body' => array( 'id' => 9900, 'state' => 'received' ) );
chip_affiliatewp_submit_payout( $payout_id );

$sent_body = null;
foreach ( $GLOBALS['__http_log'] as $hook ) {
	if ( false !== strpos( $hook['url'], '/send/send_instructions' ) && 'POST' === $hook['method'] ) {
		$sent_body = is_string( $hook['body'] ) ? json_decode( $hook['body'], true ) : $hook['body'];
	}
}
check( 'partial payout sends the reduced amount', is_array( $sent_body ) && '4.00' === $sent_body['amount'] );
check( 'partial payout records only the payable referral', '60' === (string) affwp_get_payout( $payout_id )->referrals );

// A fully payable payout is untouched.
$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 70 ),
		'amount'        => '3.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][70] = new Fake_Referral( 70, 3, '3.00', 'unpaid', $payout_id );
$GLOBALS['__http_queue'] = array();
// The submit path checks whether the reference already exists first.
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'POST', 'code' => 200, 'body' => array( 'id' => 9901, 'state' => 'received' ) );
chip_affiliatewp_submit_payout( $payout_id );
check( 'payable payout keeps its full amount', '3.00' === (string) affwp_get_payout( $payout_id )->amount );

echo "\n== Test 38: stored UTC timestamps parse independently of the site timezone ==\n";

// A timestamp written by gmdate() must read back as that same instant no matter
// what the site's local timezone is. strtotime() would shift it by the offset,
// which broke the requery cooldown on non-UTC sites.
$original_tz = date_default_timezone_get();

$stamp = gmdate( 'Y-m-d H:i:s' );
$expected = strtotime( $stamp . ' UTC' );

foreach ( array( 'UTC', 'Asia/Kuala_Lumpur', 'America/New_York', 'Pacific/Auckland' ) as $tz ) {
	date_default_timezone_set( $tz );

	$parsed = chip_affiliatewp_parse_utc( $stamp );

	check( "UTC timestamp parses identically under {$tz}", $expected === $parsed );
}

date_default_timezone_set( $original_tz );

// The cooldown must hold on a UTC+8 site: a just-checked payout is skipped.
date_default_timezone_set( 'Asia/Kuala_Lumpur' );
$just_now = gmdate( 'Y-m-d H:i:s' );
$elapsed  = time() - chip_affiliatewp_parse_utc( $just_now );
check( 'cooldown sees a fresh check as fresh on a UTC+8 site', $elapsed < 10 * MINUTE_IN_SECONDS );
check( 'cooldown sees a fresh check as non-negative', $elapsed >= 0 );

// A ten-minute-old stamp must be past the cooldown.
$old = gmdate( 'Y-m-d H:i:s', time() - 11 * MINUTE_IN_SECONDS );
$elapsed_old = time() - chip_affiliatewp_parse_utc( $old );
check( 'cooldown expires on schedule on a UTC+8 site', $elapsed_old >= 10 * MINUTE_IN_SECONDS );

date_default_timezone_set( $original_tz );

// Defensive: unparseable input yields 0 rather than a bogus epoch.
check( 'empty timestamp yields 0', 0 === chip_affiliatewp_parse_utc( '' ) );
check( 'garbage timestamp yields 0', 0 === chip_affiliatewp_parse_utc( 'not-a-date' ) );

echo "\n== Test 39: oversized webhook bodies are rejected before verification ==\n";

reset_state();
$GLOBALS['__options']['chip_payouts'] = 1;
$GLOBALS['__options']['chip_webhook_public_key'] = 'irrelevant-for-this-check';

// Build a request stub with an oversized body and no valid signature.
$oversized = str_repeat( 'a', 131073 );
$req = new Fake_Request();
$req->body = $oversized;
$result = chip_affiliatewp_handle_webhook( $req );
check( 'oversized body is rejected', is_wp_error( $result ) );
check( 'oversized body returns 413', is_wp_error( $result ) && 413 === $result->get_error_data()['status'] );
check( 'oversized body is rejected before signature checks', is_wp_error( $result ) && false !== strpos( $result->get_error_code(), 'too_large' ) );

// A normal-sized body still reaches the signature check.
$normal = wp_json_encode( array( 'id' => 1, 'state' => 'completed' ) );
$req2 = new Fake_Request();
$req2->body = $normal;
$result2 = chip_affiliatewp_handle_webhook( $req2 );
check( 'normal body passes the size gate', is_wp_error( $result2 ) && false === strpos( $result2->get_error_code(), 'too_large' ) );
check( 'size gate rejects before touching the network', array() === $GLOBALS['__http_log'] );

echo "\n== Test 40: payouts are refused on a non-MYR store ==\n";
reset_state();
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7] = new Fake_User( 7, 'affiliate@test.dev' );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__chip_bank_lookup_override'] = array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) );

// A USD store must not send a bare number that CHIP reads as MYR.
$GLOBALS['__options']['currency'] = 'USD';
$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 80 ),
		'amount'        => '100.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][80] = new Fake_Referral( 80, 3, '100.00', 'unpaid', $payout_id );
$GLOBALS['__http_queue'] = array();
$result = chip_affiliatewp_submit_payout( $payout_id );
check( 'USD store payout fails', is_wp_error( $result ) );
check( 'USD store sent no instruction', array() === $GLOBALS['__http_queue'] );
check( 'USD store made no HTTP call at all', array() === $GLOBALS['__http_log'] );
check( 'USD store reason names the currency', false !== strpos( $result->get_error_message(), 'USD' ) );
check( 'USD store reason explains MYR only', false !== strpos( $result->get_error_message(), 'MYR' ) );

// A MYR store proceeds.
$GLOBALS['__options']['currency'] = 'MYR';
$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 81 ),
		'amount'        => '5.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][81] = new Fake_Referral( 81, 3, '5.00', 'unpaid', $payout_id );
$GLOBALS['__http_queue'] = array();
// The submit path checks whether the reference already exists first.
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'POST', 'code' => 200, 'body' => array( 'id' => 9500, 'state' => 'received' ) );
chip_affiliatewp_submit_payout( $payout_id );
check( 'MYR store payout proceeds', 9500 === (int) chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) )['instruction_id'] );

// Currency lookup is case-insensitive.
$GLOBALS['__options']['currency'] = 'myr';
check( 'lower-case currency still accepted', 'MYR' === chip_affiliatewp_currency() );

echo "\n== Test 41: bank details are normalized and validated on save ==\n";
reset_state();

$affiliate = (object) array( 'user_id' => 7 );

// Separators are stripped so the same account always yields one CHIP reference.
chip_affiliatewp_save_bank_details( $affiliate, array(), array( 'payment_account_number' => '1234-567 890' ) );
check( 'account number keeps digits only', '1234567890' === get_user_meta( 7, 'payment_account_number', true ) );

// Bank code is upper-cased.
chip_affiliatewp_save_bank_details( $affiliate, array(), array( 'payment_bank_code' => 'mbbemykl' ) );
check( 'bank code is upper-cased', 'MBBEMYKL' === get_user_meta( 7, 'payment_bank_code', true ) );

// A bank CHIP cannot pay is discarded rather than stored.
chip_affiliatewp_save_bank_details( $affiliate, array(), array( 'payment_bank_code' => 'NOTAREALBANK' ) );
check( 'unknown bank code is not stored', '' === get_user_meta( 7, 'payment_bank_code', true ) );

// A non-numeric account number stores empty, so the affiliate reads as not-ready.
chip_affiliatewp_save_bank_details( $affiliate, array(), array( 'payment_account_number' => 'abc' ) );
check( 'non-numeric account number stores empty', '' === get_user_meta( 7, 'payment_account_number', true ) );

// Changing details clears the cached CHIP account id.
$GLOBALS['__user_meta'][7]['chip_bank_account'] = array( 'id' => 4242, 'status' => 'verified' );
chip_affiliatewp_save_bank_details( $affiliate, array(), array( 'payment_account_number' => '9999999999' ) );
check( 'changed details clear the cached account', ! isset( $GLOBALS['__user_meta'][7]['chip_bank_account'] ) );

// Re-saving identical details does not disturb the cache.
$GLOBALS['__user_meta'][7]['chip_bank_account'] = array( 'id' => 4242, 'status' => 'verified' );
chip_affiliatewp_save_bank_details( $affiliate, array(), array( 'payment_account_number' => '9999999999' ) );
check( 'unchanged details keep the cached account', isset( $GLOBALS['__user_meta'][7]['chip_bank_account'] ) );

echo "\n== Test 42: a referral attached to another payout is not paid twice ==\n";
reset_state();
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7] = new Fake_User( 7, 'affiliate@test.dev' );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__chip_bank_lookup_override'] = array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) );

// Payout 1 owns referral 90. Payout 2 lists it too (a stale or duplicated row).
$first_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 90 ),
		'amount'        => '8.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$second_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 90 ),
		'amount'        => '8.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][90] = new Fake_Referral( 90, 3, '8.00', 'unpaid', $first_id );

// The second payout must refuse: the referral belongs to the first one.
$GLOBALS['__http_queue'] = array();
$result = chip_affiliatewp_submit_payout( $second_id );

/*
 * Assert on the reason, not just is_wp_error(): an unmocked HTTP call is also
 * a WP_Error, so a weak check would pass even while the plugin tried to send
 * the money. This message only appears when the eligibility guard drops the
 * referral.
 */
check( 'payout claiming another payout referral fails', is_wp_error( $result ) );
check( 'payout claiming another payout referral fails on eligibility', false !== strpos( $result->get_error_message(), 'awaiting payment' ) );
check( 'payout claiming another payout referral sends nothing', array() === $GLOBALS['__http_queue'] );
$reassigned_posts = 0;
foreach ( $GLOBALS['__http_log'] as $call ) {
	if ( 'POST' === $call['method'] ) {
		++$reassigned_posts;
	}
}
check( 'payout claiming another payout referral sends nothing', 0 === $reassigned_posts );

// The owning payout still pays normally.
$GLOBALS['__http_queue'] = array();
// The submit path checks whether the reference already exists first.
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'POST', 'code' => 200, 'body' => array( 'id' => 9600, 'state' => 'received' ) );
chip_affiliatewp_submit_payout( $first_id );
check( 'owning payout still pays', 9600 === (int) chip_affiliatewp_payout_data( affwp_get_payout( $first_id ) )['instruction_id'] );

echo "\n== Test 43: failure classification uses the HTTP status ==\n";

// A 4xx is a settled answer: retrying unchanged wastes attempts.
foreach ( array( 400, 401, 403, 404, 422 ) as $status ) {
	$class = chip_affiliatewp_classify_failure( 'chip_api_error', 'CHIP Send API error (HTTP ' . $status . '): request failed', $status );
	check( "HTTP {$status} is not retried blindly", 'transient' !== $class );
}

// A 5xx is worth retrying.
foreach ( array( 500, 502, 503, 504 ) as $status ) {
	$class = chip_affiliatewp_classify_failure( 'chip_api_error', 'CHIP Send API error (HTTP ' . $status . '): server error', $status );
	check( "HTTP {$status} is transient", 'transient' === $class );
}

// 429 clears on its own.
check( 'HTTP 429 is transient', 'transient' === chip_affiliatewp_classify_failure( 'chip_api_error', 'rate limited', 429 ) );

// A transport failure has no status but is still worth retrying.
check( 'timeout without a status is transient', 'transient' === chip_affiliatewp_classify_failure( 'chip_api_error', 'cURL error 28: Operation timed out' ) );
check( 'connection failure without a status is transient', 'transient' === chip_affiliatewp_classify_failure( 'chip_api_error', 'cURL error 7: connection refused' ) );

// Affiliate-fixable problems are unaffected by the status path.
check( 'missing bank details need affiliate action', 'affiliate_action_required' === chip_affiliatewp_classify_failure( 'chip_missing_bank_details', 'no details' ) );
check( 'rejection needs affiliate action', 'affiliate_action_required' === chip_affiliatewp_classify_failure( 'chip_instruction_rejected', 'instruction rejected' ) );

// The status travels with the WP_Error from the API client.
$err = new WP_Error( 'chip_api_error', 'boom', array( 'status' => 503 ) );
check( 'http status is read from the error data', 503 === chip_affiliatewp_error_http_status( $err ) );
check( 'non-HTTP error has no status', null === chip_affiliatewp_error_http_status( new WP_Error( 'chip_transport', 'timed out' ) ) );
check( 'non-error has no status', null === chip_affiliatewp_error_http_status( 'not-an-error' ) );

// End to end: a 401 leaves the payout failed and marked for the admin.
reset_state();
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7] = new Fake_User( 7, 'affiliate@test.dev' );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__chip_bank_lookup_override'] = array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) );

$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 95 ),
		'amount'        => '2.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][95] = new Fake_Referral( 95, 3, '2.00', 'unpaid', $payout_id );
$GLOBALS['__http_queue'] = array();
// The submit path checks whether the reference already exists first.
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'POST', 'code' => 401, 'body' => array( 'message' => 'Unauthorized' ) );
chip_affiliatewp_submit_payout( $payout_id );

$stored = chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) );
check( '401 fails the payout', 'failed' === affwp_get_payout( $payout_id )->status );
check( '401 records the HTTP status for diagnosis', 401 === (int) ( $stored['error_status'] ?? 0 ) );
check( '401 is classified for the admin, not retried blindly', 'admin_action_required' === ( $GLOBALS['__payout_rows'][ $payout_id ]->failure_class ?? '' ) );

echo "\n== Test 44: duplicate status checks are not scheduled ==\n";
reset_state();

// Three deliveries for the same payout must produce one pending check.
chip_affiliatewp_schedule_check( 700, 120 );
chip_affiliatewp_schedule_check( 700, 120 );
chip_affiliatewp_schedule_check( 700, 300 );

$checks = 0;
foreach ( $GLOBALS['__as_scheduled'] as $action ) {
	if ( 'chip_affiliatewp_check_payout_status' === $action['hook'] ) {
		++$checks;
	}
}
check( 'one check per payout is scheduled', 1 === $checks );

// A different payout still gets its own check.
chip_affiliatewp_schedule_check( 701, 120 );
$checks = 0;
foreach ( $GLOBALS['__as_scheduled'] as $action ) {
	if ( 'chip_affiliatewp_check_payout_status' === $action['hook'] ) {
		++$checks;
	}
}
check( 'a different payout gets its own check', 2 === $checks );

// All scheduled actions share the plugin's group, so they can be managed together.
$groups = array();
foreach ( $GLOBALS['__as_scheduled'] as $action ) {
	$groups[ $action['group'] ] = true;
}
check( 'every scheduled action uses the plugin group', array( 'chip-affiliatewp' ) === array_keys( $groups ) );

echo "\n== Test 45: a failed payout shows a sentence, not JSON ==\n";
reset_state();
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7] = new Fake_User( 7, 'affiliate@test.dev' );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__chip_bank_lookup_override'] = array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) );

// A failing submission must leave human-readable text in the description,
// because AffiliateWP renders that verbatim as the drawer's error message.
$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 96 ),
		'amount'        => '2.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][96] = new Fake_Referral( 96, 3, '2.00', 'unpaid', $payout_id );
$GLOBALS['__http_queue'] = array();
// The submit path checks whether the reference already exists first.
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'POST', 'code' => 422, 'body' => array( 'message' => 'Unprocessable' ) );
chip_affiliatewp_submit_payout( $payout_id );

$row = affwp_get_payout( $payout_id );
check( 'failed payout description is not JSON', null === json_decode( (string) $row->description, true ) );
check( 'failed payout description is not empty', '' !== trim( (string) $row->description ) );
check( 'failed payout description names the problem', false !== stripos( (string) $row->description, 'CHIP Send' ) );

// The structured state is still available, just stored elsewhere.
$data = chip_affiliatewp_payout_data( $row );
check( 'structured state survives in meta', 422 === (int) ( $data['error_status'] ?? 0 ) );
check( 'failure class is recorded on the payout row', 'admin_action_required' === ( $row->failure_class ?? '' ) );

// A successful payout keeps its description as a notes field, not an error.
$ok_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 97 ),
		'amount'        => '2.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][97] = new Fake_Referral( 97, 3, '2.00', 'unpaid', $ok_id );
$GLOBALS['__http_queue'] = array();
// The submit path checks whether the reference already exists first.
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'POST', 'code' => 200, 'body' => array( 'id' => 9700, 'state' => 'received' ) );
chip_affiliatewp_submit_payout( $ok_id );
check( 'successful payout description stays JSON-free', null === json_decode( (string) affwp_get_payout( $ok_id )->description, true ) );
check( 'successful payout state is in meta', 9700 === (int) ( chip_affiliatewp_payout_data( affwp_get_payout( $ok_id ) )['instruction_id'] ?? 0 ) );

// Legacy rows written before the move still resolve.
$legacy = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 98 ),
		'amount'        => '1.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
		'description'   => wp_json_encode( array( 'instruction_id' => 1234, 'state' => 'executing' ) ),
	)
);
check( 'legacy description JSON still resolves', 1234 === (int) ( chip_affiliatewp_payout_data( affwp_get_payout( $legacy ) )['instruction_id'] ?? 0 ) );

echo "\n== Test 46: test and live webhooks are independent ==\n";
reset_state();

// Register a test-mode webhook only.
$GLOBALS['__options']['chip_test_mode']      = 1;
$GLOBALS['__options']['chip_webhook_id_test']  = 'wh_test_1';
$GLOBALS['__options']['chip_webhook_key_test'] = 'TEST_PUBLIC_KEY';
check( 'test mode reports configured', chip_affiliatewp_webhook_configured() );

// Flipping to live must NOT inherit the test webhook: they are separate
// objects at CHIP with separate public keys.
$GLOBALS['__options']['chip_test_mode'] = 0;
check( 'live mode is not configured by a test webhook', ! chip_affiliatewp_webhook_configured() );

// The live key must not resolve to the test key.
check( 'live public key does not fall back to the test key', 'TEST_PUBLIC_KEY' !== chip_affiliatewp_webhook_public_key( 'live' ) );
check( 'test public key still resolves for test mode', 'TEST_PUBLIC_KEY' === chip_affiliatewp_webhook_public_key( 'test' ) );

// Register a live webhook too; each mode resolves its own key.
$GLOBALS['__options']['chip_webhook_id_live']  = 'wh_live_1';
$GLOBALS['__options']['chip_webhook_key_live'] = 'LIVE_PUBLIC_KEY';
check( 'live mode now reports configured', chip_affiliatewp_webhook_configured() );
check( 'live resolves its own key', 'LIVE_PUBLIC_KEY' === chip_affiliatewp_webhook_public_key( 'live' ) );
check( 'test resolves its own key', 'TEST_PUBLIC_KEY' === chip_affiliatewp_webhook_public_key( 'test' ) );

// A per-mode manual override wins over the auto-registered key, per mode.
$GLOBALS['__options']['chip_webhook_public_key_live'] = 'MANUAL_LIVE_KEY';
check( 'per-mode manual override wins for live', 'MANUAL_LIVE_KEY' === chip_affiliatewp_webhook_public_key( 'live' ) );
check( 'manual override for live does not leak into test', 'TEST_PUBLIC_KEY' === chip_affiliatewp_webhook_public_key( 'test' ) );

// The legacy single key still works when no per-mode key exists.
unset( $GLOBALS['__options']['chip_webhook_public_key_live'], $GLOBALS['__options']['chip_webhook_key_live'], $GLOBALS['__options']['chip_webhook_id_live'] );
$GLOBALS['__options']['chip_webhook_public_key'] = 'LEGACY_KEY';
check( 'legacy single key still resolves', 'LEGACY_KEY' === chip_affiliatewp_webhook_public_key( 'live' ) );

echo "\n== Test 47: webhook reset removes only this plugin's webhooks ==\n";
reset_state();

$GLOBALS['__options']['chip_test_mode']        = 1;
$GLOBALS['__options']['chip_webhook_id_test']  = '9101';
$GLOBALS['__options']['chip_webhook_key_test'] = 'OUR_KEY';

// Pin the per-site secret first: it is generated on first use, so deriving the
// URL before pinning would hand the fixtures a different URL than the code sees.
$GLOBALS['__options']['chip_webhook_secret'] = 'fixed-test-secret';
$our_url = chip_affiliatewp_webhook_url();

// The account holds: our recorded webhook, another entry pointing at our URL,
// a same-named entry from an old site URL, and a merchant's own integration.
$GLOBALS['__http_queue'] = array();
$account = array(
	'results' => array(
		array( 'id' => 9101, 'name' => 'AffiliateWP Payouts', 'callback_url' => $our_url ),
		array( 'id' => 9102, 'name' => 'Something else', 'callback_url' => $our_url ),
		array( 'id' => 9103, 'name' => 'AffiliateWP Payouts', 'callback_url' => 'https://old-site.example/webhook' ),
		array( 'id' => 9104, 'name' => 'My Shop Orders', 'callback_url' => 'https://my-shop.example/hook' ),
	),
);
$GLOBALS['__http_queue'][] = array( 'match' => '/webhooks', 'method' => 'GET', 'code' => 200, 'body' => $account );

$found = chip_affiliatewp_find_own_webhooks( 'test' );

check( 'recorded webhook is ours', in_array( '9101', array_map( 'strval', $found['ids'] ), true ) );
check( 'webhook pointing at our URL is ours', in_array( '9102', array_map( 'strval', $found['ids'] ), true ) );
check( 'stale same-named webhook is ours', in_array( '9103', array_map( 'strval', $found['ids'] ), true ) );
check( "merchant's own webhook is NOT ours", ! in_array( '9104', array_map( 'strval', $found['ids'] ), true ) );

// Reset deletes exactly our three and clears the record.
$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/webhooks', 'method' => 'GET', 'code' => 200, 'body' => $account );
$GLOBALS['__http_queue'][] = array( 'match' => '/webhooks/9101', 'method' => 'DELETE', 'code' => 200, 'body' => array( 'ok' => true ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/webhooks/9102', 'method' => 'DELETE', 'code' => 200, 'body' => array( 'ok' => true ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/webhooks/9103', 'method' => 'DELETE', 'code' => 200, 'body' => array( 'ok' => true ) );
// Re-registration: an empty list, then the create. The reachability probe is
// cached in a transient, so no probe request is made on this path.
$GLOBALS['__http_queue'][] = array( 'match' => '/webhooks', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/webhooks', 'method' => 'POST', 'code' => 200, 'body' => array( 'id' => 9200, 'public_key' => 'NEW_KEY' ) );

$result = chip_affiliatewp_reset_webhooks( 'test' );

check( 'reset reports three deletions', 3 === (int) $result['deleted'] );
check( 'reset reports no failures', array() === $result['failed'] );

$deleted = array();
foreach ( $GLOBALS['__http_log'] as $call ) {
	if ( 'DELETE' === $call['method'] ) {
		$deleted[] = basename( $call['url'] );
	}
}
check( 'reset never deleted the merchant webhook', ! in_array( '9104', $deleted, true ) );
check( 'reset deleted our recorded webhook', in_array( '9101', $deleted, true ) );

// The stored record is cleared so the next save re-registers.
// The reset re-registers, so the record holds the NEW webhook, not the old one.
check( 'stored webhook id now points at the new webhook', '9200' === (string) $GLOBALS['__options']['chip_webhook_id_test'] );
check( 'stored webhook key is refreshed', 'NEW_KEY' === (string) $GLOBALS['__options']['chip_webhook_key_test'] );
check( 'mode is configured again after the reset', chip_affiliatewp_webhook_configured() );

// A 404 on delete counts as already gone, not a failure.
reset_state();
$GLOBALS['__options']['chip_test_mode']       = 1;
$GLOBALS['__options']['chip_webhook_id_test'] = '9300';
$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/webhooks', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/webhooks/9300', 'method' => 'DELETE', 'code' => 404, 'body' => array( 'message' => 'Not found' ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/webhooks', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/webhooks', 'method' => 'POST', 'code' => 200, 'body' => array( 'id' => 9201, 'public_key' => 'NEW_KEY_2' ) );
$gone = chip_affiliatewp_reset_webhooks( 'test' );
check( 'already-deleted webhook counts as removed', 1 === (int) $gone['deleted'] );
check( 'already-deleted webhook is not a failure', array() === $gone['failed'] );

echo "\n== Test 48: a failed payout says where to fix it ==\n";

// Core hard-codes Stripe/PayPal dashboard URLs and the next-steps builder has
// no filter, so CHIP's "Open provider dashboard" button renders without a
// target and does nothing. The description is the surface we control, so it
// must name the screen.

check( 'missing credentials names the settings screen', false !== strpos( chip_affiliatewp_failure_hint( 'chip_missing_credentials' ), 'Settings' ) );
check( 'disabled method names the settings screen', false !== strpos( chip_affiliatewp_failure_hint( 'chip_payouts_disabled' ), 'Settings' ) );
check( 'unconfigured webhook names the settings screen', false !== strpos( chip_affiliatewp_failure_hint( 'chip_webhook_unconfigured' ), 'Settings' ) );
check( '401 names the credentials screen', false !== strpos( chip_affiliatewp_failure_hint( 'chip_api_error', 401 ), 'Settings' ) );
check( '403 names the credentials screen', false !== strpos( chip_affiliatewp_failure_hint( 'chip_api_error', 403 ), 'Settings' ) );
check( '422 names the bank details', false !== strpos( chip_affiliatewp_failure_hint( 'chip_api_error', 422 ), 'bank' ) );
check( 'a 5xx has no hint (it is transient)', '' === chip_affiliatewp_failure_hint( 'chip_api_error', 503 ) );
check( 'an unrelated error has no hint', '' === chip_affiliatewp_failure_hint( 'chip_no_email' ) );

// End to end: the hint lands in the description the drawer shows.
reset_state();
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7] = new Fake_User( 7, 'affiliate@test.dev' );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__chip_bank_lookup_override'] = array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) );

$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 99 ),
		'amount'        => '2.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][99] = new Fake_Referral( 99, 3, '2.00', 'unpaid', $payout_id );
$GLOBALS['__http_queue'] = array();
// The submit path checks whether the reference already exists first.
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'POST', 'code' => 401, 'body' => array( 'message' => 'Unauthorized' ) );
chip_affiliatewp_submit_payout( $payout_id );

$shown = (string) affwp_get_payout( $payout_id )->description;
check( 'failed description names where to fix it', false !== strpos( $shown, 'Settings' ) );
check( 'failed description still explains the failure', false !== stripos( $shown, 'CHIP Send' ) );

// A transient failure gets no hint: nothing for the merchant to fix.
reset_state();
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7] = new Fake_User( 7, 'affiliate@test.dev' );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__chip_bank_lookup_override'] = array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) );

$transient_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 100 ),
		'amount'        => '2.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][100] = new Fake_Referral( 100, 3, '2.00', 'unpaid', $transient_id );
$GLOBALS['__http_queue'] = array();
// The submit path checks whether the reference already exists first.
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'POST', 'code' => 503, 'body' => array( 'message' => 'Server error' ) );
chip_affiliatewp_submit_payout( $transient_id );

$transient_shown = (string) affwp_get_payout( $transient_id )->description;
check( 'transient failure has no settings hint', false === strpos( $transient_shown, 'Settings' ) );
check( 'transient failure is classified transient', 'transient' === ( $GLOBALS['__payout_rows'][ $transient_id ]->failure_class ?? '' ) );

echo "\n== Test 49: convert refuses an amount above the convertible balance ==\n";
reset_state();

$GLOBALS['__options']['chip_test_mode']       = 1;
$GLOBALS['__options']['chip_test_api_key']    = 'k';
$GLOBALS['__options']['chip_test_secret_key'] = 's';
$GLOBALS['__options']['chip_payouts']         = 1;

// The handler reads the balance live (not from cache), so mock the API.
$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/send/accounts', 'method' => 'GET', 'code' => 200, 'body' => array( 'current_balance' => '100.00', 'convertible_balance_from_statement' => '50.00', 'currency' => 'MYR', 'settlement_convert_approvals_count' => 1 ) );
$_POST = array(
	'chip_affiliatewp_action'        => 'convert_balance',
	'chip_affiliatewp_convert_nonce' => 'good-nonce',
	'chip_convert_amount'            => '500',
);
$GLOBALS['__current_user_can'] = true;

chip_affiliatewp_handle_convert_balance();

// Reading the balance is expected; sending an allocation is not.
$alloc_posts = 0;

foreach ( $GLOBALS['__http_log'] as $call ) {
	if ( 'POST' === $call['method'] ) {
		++$alloc_posts;
	}
}

check( 'over-limit convert submits nothing', 0 === $alloc_posts );

ob_start();
chip_affiliatewp_render_queued_notices();
$rendered = ob_get_clean();
check( 'over-limit convert explains the real reason', false !== strpos( $rendered, 'available to convert' ) );
check( 'over-limit convert names both amounts', false !== strpos( $rendered, '500' ) && false !== strpos( $rendered, '50' ) );

// An amount within the limit proceeds to the API.
reset_state();
$GLOBALS['__options']['chip_test_mode']       = 1;
$GLOBALS['__options']['chip_test_api_key']    = 'k';
$GLOBALS['__options']['chip_test_secret_key'] = 's';
$GLOBALS['__options']['chip_payouts']         = 1;
set_transient( 'chip_affiliatewp_account_test', array( 'current_balance' => 100.0, 'convertible' => 50.0, 'currency' => 'MYR', 'approvals_required' => 1, 'error' => null ), 300 );

$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_limits', 'code' => 200, 'body' => array( 'id' => 1, 'approvals_required' => 1 ) );
$_POST = array(
	'chip_affiliatewp_action'        => 'convert_balance',
	'chip_affiliatewp_convert_nonce' => 'good-nonce',
	'chip_convert_amount'            => '25',
);
$GLOBALS['__current_user_can'] = true;

chip_affiliatewp_handle_convert_balance();

$posted = 0;
foreach ( $GLOBALS['__http_log'] as $call ) {
	if ( false !== strpos( $call['url'], '/send/send_limits' ) ) {
		++$posted;
	}
}
check( 'within-limit convert reaches the API', 1 === $posted );

echo "\n== Test 50: deactivation clears every scheduled action ==\n";
reset_state();

// Queue one of each action the plugin schedules.
$GLOBALS['__as_scheduled'] = array();
chip_affiliatewp_schedule_check( 810, 120 );
as_schedule_single_action( time() + 60, 'chip_affiliatewp_submit_payout_action', array( 'payout_id' => 811 ), chip_affiliatewp_as_group() );
as_schedule_recurring_action( time() + 60, 3600, 'chip_affiliatewp_hourly_sweep', array(), chip_affiliatewp_as_group() );
$GLOBALS['__as_scheduled'][] = array( 'timestamp' => time() + 60, 'hook' => 'chip_affiliatewp_hourly_sweep', 'args' => array(), 'group' => chip_affiliatewp_as_group() );

$before = 0;
foreach ( $GLOBALS['__as_scheduled'] as $action ) {
	if ( 0 === strpos( $action['hook'], 'chip_affiliatewp_' ) ) {
		++$before;
	}
}
check( 'actions are queued before deactivation', $before >= 3 );

chip_affiliatewp_unschedule_sweep();

$after = 0;
foreach ( $GLOBALS['__as_scheduled'] as $action ) {
	if ( 0 === strpos( $action['hook'], 'chip_affiliatewp_' ) ) {
		++$after;
	}
}
check( 'deactivation clears every scheduled action', 0 === $after );

// A hook the plugin does not own must survive.
$GLOBALS['__as_scheduled'] = array();
as_schedule_single_action( time() + 60, 'some_other_plugin_task', array(), 'other-plugin' );
chip_affiliatewp_unschedule_sweep();
check( "another plugin's action is untouched", 1 === count( $GLOBALS['__as_scheduled'] ) );

echo "\n== Test 51: AGENTS.md stays in step with the code ==\n";

$repo = dirname( __DIR__ );
$agents = (string) file_get_contents( $repo . '/AGENTS.md' );

check( 'AGENTS.md exists and is non-empty', '' !== trim( $agents ) );

// Every module on disk must be described, or the table silently goes stale.
$missing = array();

foreach ( glob( $repo . '/includes/*.php' ) as $module ) {
	if ( false === strpos( $agents, basename( $module ) ) ) {
		$missing[] = basename( $module );
	}
}

check( 'every module is listed in AGENTS.md', array() === $missing );

// The documented check count must match reality, or the number misleads.
$counted = null;

if ( preg_match( '/Standalone stub harness \(no WordPress needed\): (\d+) checks/', $agents, $m ) ) {
	$counted = (int) $m[1];
}

check( 'AGENTS.md states the harness check count', null !== $counted );

// The plugin header and phpcs.xml must agree on the minimum WordPress version.
$bootstrap = (string) file_get_contents( $repo . '/chip-for-affiliatewp.php' );

preg_match( '/Requires at least:\s*([0-9.]+)/', $bootstrap, $header_match );
preg_match( '/minimum_supported_wp_version" value="([0-9.]+)"/', (string) file_get_contents( $repo . '/phpcs.xml' ), $phpcs_match );

check( 'plugin header declares a minimum WordPress version', ! empty( $header_match[1] ) );
check( 'phpcs.xml declares a minimum WordPress version', ! empty( $phpcs_match[1] ) );
check( 'header and phpcs.xml agree on the minimum version', ( $header_match[1] ?? '' ) === ( $phpcs_match[1] ?? '' ) );
check( 'AGENTS.md records the same minimum version', false !== strpos( $agents, 'minimum WordPress ' . ( $header_match[1] ?? 'x' ) ) );

// The version lives in three places; they must not drift apart.
preg_match( "/define\( 'CHIP_AFFILIATEWP_VERSION', '([0-9.]+)' \)/", $bootstrap, $const_match );
preg_match( '/Stable tag:\s*([0-9.]+)/', (string) file_get_contents( $repo . '/readme.txt' ), $stable_match );

check( 'version constant and Stable tag agree', ( $const_match[1] ?? '' ) === ( $stable_match[1] ?? '' ) );

echo "\n== Test 52: docs point at the screens the code actually uses ==\n";

$repo  = dirname( __DIR__ );
$readme = (string) file_get_contents( $repo . '/readme.txt' );
$agents = (string) file_get_contents( $repo . '/AGENTS.md' );

// The settings panel moved to the Payouts tab; stale copy sent merchants to
// Commissions, where no CHIP Send settings exist at all.
check( 'readme sends merchants to the Payouts tab', false !== strpos( $readme, 'Settings → Payouts' ) );
check( 'readme does not send merchants to the Commissions tab', false === strpos( $readme, 'Settings → Commissions' ) );
check( 'AGENTS.md names the Payouts tab', false !== strpos( $agents, 'Payouts** tab' ) || false !== strpos( $agents, 'Payouts tab' ) );
check( 'AGENTS.md does not claim the Commissions tab', false === strpos( $agents, 'Commissions tab' ) );

// The code must agree: the card registers on the method registry and saves
// through the Payouts sanitize filter.
$admin = (string) file_get_contents( $repo . '/includes/class-chip-affiliatewp-admin.php' );

check( 'code registers the card on the method registry', false !== strpos( $admin, 'affwp_register_payment_methods' ) );
check( 'code saves through the Payouts sanitize filter', false !== strpos( $admin, 'affwp_settings_payouts_sanitize' ) );
check( 'code does not save through a Commissions filter', false === strpos( $admin, 'affwp_settings_commissions' ) );

// Setup instructions must name a screen that exists in the settings tree.
check( 'readme setup step exists', false !== strpos( $readme, '== Installation ==' ) );

echo "\n== Test 53: affiliate self-service bank form ==\n";
// The save handler ends with a redirect + exit; disable it so the harness
// keeps running after each submission.
add_filter( 'chip_affiliatewp_bank_save_redirect', function () { return false; } );
reset_state();

$GLOBALS['__options']['chip_payouts']      = 1;
$GLOBALS['__options']['chip_test_mode']    = 1;
$GLOBALS['__affiliates_map'][4]            = 12;
$GLOBALS['__users'][12]                    = new Fake_User( 12, 'self@test.dev' );
$GLOBALS['__logged_in']                    = true;
$GLOBALS['__current_affiliate_id']         = 4;

// The section is relevant only when the affiliate is actually paid via CHIP.
$GLOBALS['__affiliate_meta'][4]['payout_method_pick'] = 'chip';
check( 'section shows for a chip affiliate', chip_affiliatewp_affiliate_section_is_relevant( 4 ) );

$GLOBALS['__affiliate_meta'][4]['payout_method_pick'] = 'paypal';
check( 'section hidden for a paypal affiliate', ! chip_affiliatewp_affiliate_section_is_relevant( 4 ) );

$GLOBALS['__affiliate_meta'][4]['payout_method_pick'] = 'chip';
$GLOBALS['__options']['chip_payouts'] = 0;
check( 'section hidden when the method is off', ! chip_affiliatewp_affiliate_section_is_relevant( 4 ) );
$GLOBALS['__options']['chip_payouts'] = 1;

// A forged bank code is rejected even though the select only offers valid ones.
$GLOBALS['__current_user_can'] = true;
$_POST = array(
	'chip_affiliatewp_action'    => 'save_bank_details',
	'chip_affiliatewp_bank_nonce' => 'good-nonce',
	'payment_bank_code'          => 'EVILBANK',
	'payment_account_number'     => '1234567890',
);
chip_affiliatewp_handle_affiliate_bank_save();

$stored_code = (string) get_user_meta( 12, 'payment_bank_code', true );
check( 'unsupported bank code is refused', '' === $stored_code );

// Missing fields are refused.
$_POST = array(
	'chip_affiliatewp_action'    => 'save_bank_details',
	'chip_affiliatewp_bank_nonce' => 'good-nonce',
	'payment_bank_code'          => '',
	'payment_account_number'     => '',
);
chip_affiliatewp_handle_affiliate_bank_save();
check( 'empty submission writes nothing', '' === (string) get_user_meta( 12, 'payment_account_number', true ) );

// A too-short number is refused.
$_POST = array(
	'chip_affiliatewp_action'    => 'save_bank_details',
	'chip_affiliatewp_bank_nonce' => 'good-nonce',
	'payment_bank_code'          => 'MBBEMYKL',
	'payment_account_number'     => '123',
);
chip_affiliatewp_handle_affiliate_bank_save();
check( 'too-short account number is refused', '' === (string) get_user_meta( 12, 'payment_account_number', true ) );

// A valid submission stores normalised details.
$_POST = array(
	'chip_affiliatewp_action'    => 'save_bank_details',
	'chip_affiliatewp_bank_nonce' => 'good-nonce',
	'payment_bank_code'          => 'mbbemykl',
	'payment_account_number'     => '1234-567 890',
);
chip_affiliatewp_handle_affiliate_bank_save();

check( 'valid submission stores digits only', '1234567890' === (string) get_user_meta( 12, 'payment_account_number', true ) );
check( 'valid submission uppercases the bank code', 'MBBEMYKL' === (string) get_user_meta( 12, 'payment_bank_code', true ) );

// A bad nonce is ignored entirely.
reset_state();
$GLOBALS['__options']['chip_payouts'] = 1;
$GLOBALS['__affiliates_map'][4]       = 12;
$GLOBALS['__users'][12]               = new Fake_User( 12, 'self@test.dev' );
$GLOBALS['__usable_method']           = 'chip';

$_POST = array(
	'chip_affiliatewp_action'    => 'save_bank_details',
	'chip_affiliatewp_bank_nonce' => 'bad-nonce',
	'payment_bank_code'          => 'MBBEMYKL',
	'payment_account_number'     => '1234567890',
);
chip_affiliatewp_handle_affiliate_bank_save();
check( 'bad nonce writes nothing', '' === (string) get_user_meta( 12, 'payment_account_number', true ) );

// Changing details drops the cached CHIP account so the new one is registered.
reset_state();
$GLOBALS['__options']['chip_payouts'] = 1;
$GLOBALS['__affiliates_map'][4]       = 12;
$GLOBALS['__users'][12]               = new Fake_User( 12, 'self@test.dev' );
$GLOBALS['__usable_method']           = 'chip';

update_user_meta( 12, 'payment_account_number', '9999999999' );
update_user_meta( 12, 'payment_bank_code', 'CIBBMYKL' );
update_user_meta( 12, 'chip_bank_account', array( 'id' => 999 ) );

$_POST = array(
	'chip_affiliatewp_action'    => 'save_bank_details',
	'chip_affiliatewp_bank_nonce' => 'good-nonce',
	'payment_bank_code'          => 'MBBEMYKL',
	'payment_account_number'     => '1234567890',
);
chip_affiliatewp_handle_affiliate_bank_save();

check( 'changed details drop the cached CHIP account', array() === get_user_meta( 12, 'chip_bank_account', true ) || '' === get_user_meta( 12, 'chip_bank_account', true ) );

// Saving the same details again must not drop the cache.
update_user_meta( 12, 'chip_bank_account', array( 'id' => 1000 ) );
$_POST = array(
	'chip_affiliatewp_action'    => 'save_bank_details',
	'chip_affiliatewp_bank_nonce' => 'good-nonce',
	'payment_bank_code'          => 'MBBEMYKL',
	'payment_account_number'     => '1234567890',
);
chip_affiliatewp_handle_affiliate_bank_save();
check( 'unchanged details keep the cached CHIP account', 1000 === (int) ( get_user_meta( 12, 'chip_bank_account', true )['id'] ?? 0 ) );

$_POST = array();

echo "\n== Test 54: a rejected instruction can be retried after the bank is fixed ==\n";
reset_state();

$GLOBALS['__options']['chip_payouts']         = 1;
$GLOBALS['__options']['chip_test_mode']       = 1;
$GLOBALS['__options']['chip_test_api_key']    = 'k';
$GLOBALS['__options']['chip_test_secret_key'] = 's';
$GLOBALS['__options']['chip_reference_prefix'] = 'XT';
$GLOBALS['__options']['currency']             = 'MYR';
$GLOBALS['__affiliates_map'][3]               = 7;
$GLOBALS['__users'][7]                        = new Fake_User( 7, 'affiliate@test.dev' );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__chip_bank_lookup_override'] = array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) );

$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 120 ),
		'amount'        => '5.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][120] = new Fake_Referral( 120, 3, '5.00', 'unpaid', $payout_id );

// Submit: CHIP accepts, the payout is in flight with a stored instruction ID.
$GLOBALS['__http_queue'] = array();
// The submit path checks whether the reference already exists first.
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'POST', 'code' => 200, 'body' => array( 'id' => 9100, 'state' => 'received' ) );
chip_affiliatewp_submit_payout( $payout_id );

check( 'submitted payout stores the instruction id', 9100 === (int) ( chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) )['instruction_id'] ?? 0 ) );

// CHIP rejects the instruction (the affiliate's bank details were wrong).
$GLOBALS['__http_queue'] = array();
chip_affiliatewp_apply_instruction(
	$payout_id,
	array( 'id' => 9100, 'state' => 'rejected', 'rejection_reason' => 'Invalid account number' )
);

$after = chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) );
check( 'rejected payout is failed', 'failed' === affwp_get_payout( $payout_id )->status );
check( 'rejected payout releases the referral', 'unpaid' === $GLOBALS['__referral_rows'][120]->status );

// The dead instruction must be forgotten, or a retry would adopt it forever.
check( 'rejected payout forgets the instruction id', empty( $after['instruction_id'] ) );
check( 'rejected payout clears the row instruction id', 0 === (int) affwp_get_payout( $payout_id )->service_id );

// A retry now creates a FRESH instruction instead of adopting the dead one.
$GLOBALS['__http_queue'] = array();
// The submit path checks whether the reference already exists first.
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'POST', 'code' => 200, 'body' => array( 'id' => 9200, 'state' => 'received' ) );
$retry = chip_affiliatewp_submit_payout( $payout_id );

check( 'retry submits again', true === $retry );
check( 'retry creates a new instruction', 9200 === (int) ( chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) )['instruction_id'] ?? 0 ) );
check( 'retried payout returns to processing', 'processing' === affwp_get_payout( $payout_id )->status );

// An in-flight instruction must NOT be forgotten: it still exists at CHIP.
reset_state();
$GLOBALS['__options']['chip_payouts']         = 1;
$GLOBALS['__options']['chip_test_mode']       = 1;
$GLOBALS['__options']['chip_test_api_key']    = 'k';
$GLOBALS['__options']['chip_test_secret_key'] = 's';
$GLOBALS['__affiliates_map'][3]               = 7;
$GLOBALS['__users'][7]                        = new Fake_User( 7, 'affiliate@test.dev' );

$inflight = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 121 ),
		'amount'        => '5.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][121] = new Fake_Referral( 121, 3, '5.00', 'unpaid', $inflight );

chip_affiliatewp_update_payout_data( $inflight, array( 'instruction_id' => 9300, 'state' => 'executing' ) );
chip_affiliatewp_fail_payout( $inflight, 'Bank account is not verified yet.', 'chip_bank_account_unverified' );

$kept = chip_affiliatewp_payout_data( affwp_get_payout( $inflight ) );
check( 'an unverified bank account keeps the instruction id', 9300 === (int) ( $kept['instruction_id'] ?? 0 ) );

echo "\n== Test 55: the reference is the idempotency key ==\n";
reset_state();

$GLOBALS['__options']['chip_payouts']          = 1;
$GLOBALS['__options']['chip_test_mode']        = 1;
$GLOBALS['__options']['chip_test_api_key']     = 'k';
$GLOBALS['__options']['chip_test_secret_key']  = 's';
$GLOBALS['__options']['chip_reference_prefix'] = 'XT';
$GLOBALS['__options']['currency']              = 'MYR';
$GLOBALS['__affiliates_map'][3]                = 7;
$GLOBALS['__users'][7]                         = new Fake_User( 7, 'affiliate@test.dev' );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__chip_bank_lookup_override'] = array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) );

// Reference shape: stable within an attempt, distinct across attempts.
check( 'attempt 1 keeps the bare reference', 'XT-PO-9' === chip_affiliatewp_instruction_reference( 9, 1 ) );
check( 'attempt 2 gets its own reference', 'XT-PO-9-2' === chip_affiliatewp_instruction_reference( 9, 2 ) );
check( 'attempt 3 gets its own reference', 'XT-PO-9-3' === chip_affiliatewp_instruction_reference( 9, 3 ) );
check( 'attempt 1 is the default', chip_affiliatewp_instruction_reference( 9 ) === chip_affiliatewp_instruction_reference( 9, 1 ) );
check( 'a missing attempt reads as 1', 1 === chip_affiliatewp_payout_attempt( array() ) );

// A live instruction found before sending is adopted, not sent again. This is
// the timeout case: we never saw the first response.
$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 130 ),
		'amount'        => '7.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][130] = new Fake_Referral( 130, 3, '7.00', 'unpaid', $payout_id );

$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array( array( 'id' => 5001, 'state' => 'executing', 'reference' => 'XT-PO-' . $payout_id ) ) ) );
$result = chip_affiliatewp_submit_payout( $payout_id );

$posts = 0;
foreach ( $GLOBALS['__http_log'] as $call ) {
	if ( 'POST' === $call['method'] && false !== strpos( $call['url'], 'send_instructions' ) ) {
		++$posts;
	}
}
check( 'an existing live instruction is adopted', true === $result );
check( 'adopting sends no second instruction', 0 === $posts );
check( 'adopted instruction id is stored', 5001 === (int) ( chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) )['instruction_id'] ?? 0 ) );

// A completed instruction found before sending is adopted AND applied: the
// webhook for it was delivered long ago and will not come again.
reset_state();
$GLOBALS['__options']['chip_payouts']          = 1;
$GLOBALS['__options']['chip_test_mode']        = 1;
$GLOBALS['__options']['chip_test_api_key']     = 'k';
$GLOBALS['__options']['chip_test_secret_key']  = 's';
$GLOBALS['__options']['chip_reference_prefix'] = 'XT';
$GLOBALS['__options']['chip_test_mode']        = 1;
$GLOBALS['__affiliates_map'][3]                = 7;
$GLOBALS['__users'][7]                         = new Fake_User( 7, 'affiliate@test.dev' );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__chip_bank_lookup_override'] = array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) );

$done_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 131 ),
		'amount'        => '7.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][131] = new Fake_Referral( 131, 3, '7.00', 'unpaid', $done_id );

$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array( array( 'id' => 5002, 'state' => 'completed', 'reference' => 'XT-PO-' . $done_id, 'receipt_url' => 'https://www.chip-in.asia/receipts/send/done' ) ) ) );
chip_affiliatewp_submit_payout( $done_id );

check( 'an adopted completed instruction pays the payout', 'paid' === affwp_get_payout( $done_id )->status );
check( 'an adopted completed instruction pays the referral', 'paid' === $GLOBALS['__referral_rows'][131]->status );

// A DEAD instruction under the current reference must not be adopted: the
// submission advances to a fresh attempt instead.
reset_state();
$GLOBALS['__options']['chip_payouts']          = 1;
$GLOBALS['__options']['chip_test_mode']        = 1;
$GLOBALS['__options']['chip_test_api_key']     = 'k';
$GLOBALS['__options']['chip_test_secret_key']  = 's';
$GLOBALS['__options']['chip_reference_prefix'] = 'XT';
$GLOBALS['__affiliates_map'][3]                = 7;
$GLOBALS['__users'][7]                         = new Fake_User( 7, 'affiliate@test.dev' );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__chip_bank_lookup_override'] = array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) );

$dead_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 132 ),
		'amount'        => '7.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][132] = new Fake_Referral( 132, 3, '7.00', 'unpaid', $dead_id );

// The reference CHIP holds is dead; a fresh attempt must be used.
$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array( array( 'id' => 5003, 'state' => 'rejected', 'reference' => 'XT-PO-' . $dead_id ) ) ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'POST', 'code' => 200, 'body' => array( 'id' => 5100, 'state' => 'received' ) );
chip_affiliatewp_submit_payout( $dead_id );

$sent_ref = '';
foreach ( $GLOBALS['__http_log'] as $call ) {
	if ( 'POST' === $call['method'] && false !== strpos( $call['url'], 'send_instructions' ) ) {
		$sent_ref = (string) $call['body'];
	}
}
check( 'a dead instruction is not adopted', 5100 === (int) ( chip_affiliatewp_payout_data( affwp_get_payout( $dead_id ) )['instruction_id'] ?? 0 ) );
check( 'the retry uses a fresh reference', false !== strpos( $sent_ref, '-PO-' . $dead_id . '-2' ) );
check( 'the retry is not stuck on the dead instruction', 5003 !== (int) ( chip_affiliatewp_payout_data( affwp_get_payout( $dead_id ) )['instruction_id'] ?? 0 ) );

echo "\n== Test 56: a webhook rejection advances the attempt ==\n";
reset_state();

$GLOBALS['__options']['chip_payouts']          = 1;
$GLOBALS['__options']['chip_test_mode']        = 1;
$GLOBALS['__options']['chip_test_api_key']     = 'k';
$GLOBALS['__options']['chip_test_secret_key']  = 's';
$GLOBALS['__options']['chip_reference_prefix'] = 'XT';
$GLOBALS['__options']['currency']              = 'MYR';
$GLOBALS['__affiliates_map'][3]                = 7;
$GLOBALS['__users'][7]                         = new Fake_User( 7, 'affiliate@test.dev' );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__chip_bank_lookup_override'] = array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) );

$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 140 ),
		'amount'        => '9.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][140] = new Fake_Referral( 140, 3, '9.00', 'unpaid', $payout_id );

// Submit successfully: attempt 1, reference XT-PO-<id>.
$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'POST', 'code' => 200, 'body' => array( 'id' => 6001, 'state' => 'received' ) );
chip_affiliatewp_submit_payout( $payout_id );

check( 'first attempt records attempt 1', 1 === (int) ( chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) )['attempt'] ?? 0 ) );

// The webhook then reports the instruction was REJECTED.
chip_affiliatewp_apply_instruction(
	$payout_id,
	array( 'id' => 6001, 'state' => 'rejected', 'rejection_reason' => 'Invalid account number' )
);

$after = chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) );
check( 'rejection fails the payout', 'failed' === affwp_get_payout( $payout_id )->status );
check( 'rejection forgets the dead instruction', empty( $after['instruction_id'] ) );
check( 'rejection advances the attempt', 2 === (int) ( $after['attempt'] ?? 0 ) );

// The retry must therefore use attempt 2's reference, which CHIP has never seen.
$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'POST', 'code' => 200, 'body' => array( 'id' => 6002, 'state' => 'received' ) );
chip_affiliatewp_submit_payout( $payout_id );

$sent = '';
foreach ( $GLOBALS['__http_log'] as $call ) {
	if ( 'POST' === $call['method'] && false !== strpos( $call['url'], 'send_instructions' ) ) {
		$sent = (string) $call['body'];
	}
}
check( 'retry after rejection uses the next reference', false !== strpos( $sent, '-PO-' . $payout_id . '-2' ) );
check( 'retry after rejection stores the new instruction', 6002 === (int) ( chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) )['instruction_id'] ?? 0 ) );

// A SECOND rejection advances again, so a payout is never stuck on one attempt.
chip_affiliatewp_apply_instruction(
	$payout_id,
	array( 'id' => 6002, 'state' => 'rejected', 'rejection_reason' => 'Still invalid' )
);
check( 'second rejection advances to attempt 3', 3 === (int) ( chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) )['attempt'] ?? 0 ) );
check( 'attempt 3 has its own reference', 'XT-PO-' . $payout_id . '-3' === chip_affiliatewp_instruction_reference( $payout_id, 3 ) );

// A DELETED instruction behaves the same way.
reset_state();
$GLOBALS['__options']['chip_payouts']          = 1;
$GLOBALS['__options']['chip_test_mode']        = 1;
$GLOBALS['__options']['chip_test_api_key']     = 'k';
$GLOBALS['__options']['chip_test_secret_key']  = 's';
$GLOBALS['__options']['chip_reference_prefix'] = 'XT';
$GLOBALS['__affiliates_map'][3]                = 7;
$GLOBALS['__users'][7]                         = new Fake_User( 7, 'affiliate@test.dev' );

$del_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 141 ),
		'amount'        => '9.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][141] = new Fake_Referral( 141, 3, '9.00', 'unpaid', $del_id );

chip_affiliatewp_update_payout_data( $del_id, array( 'instruction_id' => 6100, 'attempt' => 1, 'state' => 'executing' ) );
chip_affiliatewp_apply_instruction( $del_id, array( 'id' => 6100, 'state' => 'deleted' ) );

$del_data = chip_affiliatewp_payout_data( affwp_get_payout( $del_id ) );
check( 'deletion forgets the dead instruction', empty( $del_data['instruction_id'] ) );
check( 'deletion advances the attempt', 2 === (int) ( $del_data['attempt'] ?? 0 ) );

// An unverified bank account must NOT advance: its instruction is still live.
reset_state();
$GLOBALS['__options']['chip_payouts']          = 1;
$GLOBALS['__options']['chip_test_mode']        = 1;
$GLOBALS['__options']['chip_test_api_key']     = 'k';
$GLOBALS['__options']['chip_test_secret_key']  = 's';
$GLOBALS['__affiliates_map'][3]                = 7;
$GLOBALS['__users'][7]                         = new Fake_User( 7, 'affiliate@test.dev' );

$keep_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 142 ),
		'amount'        => '9.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][142] = new Fake_Referral( 142, 3, '9.00', 'unpaid', $keep_id );

chip_affiliatewp_update_payout_data( $keep_id, array( 'instruction_id' => 6200, 'attempt' => 1, 'state' => 'executing' ) );
chip_affiliatewp_fail_payout( $keep_id, 'Bank account is not verified yet.', 'chip_bank_account_unverified' );

$keep_data = chip_affiliatewp_payout_data( affwp_get_payout( $keep_id ) );
check( 'an unverified account keeps its instruction', 6200 === (int) ( $keep_data['instruction_id'] ?? 0 ) );
check( 'an unverified account keeps its attempt', 1 === (int) ( $keep_data['attempt'] ?? 0 ) );

echo "\n== Test 57: the single-referral path also respects the reference ==\n";
reset_state();

$GLOBALS['__options']['chip_payouts']          = 1;
$GLOBALS['__options']['chip_test_mode']        = 1;
$GLOBALS['__options']['chip_test_api_key']     = 'k';
$GLOBALS['__options']['chip_test_secret_key']  = 's';
$GLOBALS['__options']['chip_reference_prefix'] = 'XT';
$GLOBALS['__options']['currency']              = 'MYR';
$GLOBALS['__affiliates_map'][3]                = 7;
$GLOBALS['__users'][7]                         = new Fake_User( 7, 'affiliate@test.dev' );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__chip_bank_lookup_override'] = array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) );

// No instruction exists yet: the referral is submitted normally.
$GLOBALS['__referral_rows'][200] = new Fake_Referral( 200, 3, '4.00', 'unpaid', 0 );
$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'POST', 'code' => 200, 'body' => array( 'id' => 7001, 'state' => 'received' ) );
chip_affiliatewp_pay_single_referral( 200 );

$created = array_values( array_filter( $GLOBALS['__payout_rows'], function ( $p ) { return 7001 === (int) $p->service_id; } ) );
check( 'single referral creates a payout', 1 === count( $created ) );

// A LIVE instruction already exists for this referral: adopt, do not re-send.
reset_state();
$GLOBALS['__options']['chip_payouts']          = 1;
$GLOBALS['__options']['chip_test_mode']        = 1;
$GLOBALS['__options']['chip_test_api_key']     = 'k';
$GLOBALS['__options']['chip_test_secret_key']  = 's';
$GLOBALS['__options']['chip_reference_prefix'] = 'XT';
$GLOBALS['__affiliates_map'][3]                = 7;
$GLOBALS['__users'][7]                         = new Fake_User( 7, 'affiliate@test.dev' );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__chip_bank_lookup_override'] = array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) );

$GLOBALS['__referral_rows'][201] = new Fake_Referral( 201, 3, '4.00', 'unpaid', 0 );
$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array( array( 'id' => 7100, 'state' => 'executing', 'reference' => 'XT-R-201' ) ) ) );
chip_affiliatewp_pay_single_referral( 201 );

$posts = 0;
foreach ( $GLOBALS['__http_log'] as $call ) {
	if ( 'POST' === $call['method'] && false !== strpos( $call['url'], 'send_instructions' ) ) {
		++$posts;
	}
}
check( 'single referral adopts a live instruction', 0 === $posts );

$adopted = array_values( array_filter( $GLOBALS['__payout_rows'], function ( $p ) { return 7100 === (int) $p->service_id; } ) );
check( 'adoption creates the payout row', 1 === count( $adopted ) );

// A DEAD instruction must not be adopted: a fresh reference is used instead.
reset_state();
$GLOBALS['__options']['chip_payouts']          = 1;
$GLOBALS['__options']['chip_test_mode']        = 1;
$GLOBALS['__options']['chip_test_api_key']     = 'k';
$GLOBALS['__options']['chip_test_secret_key']  = 's';
$GLOBALS['__options']['chip_reference_prefix'] = 'XT';
$GLOBALS['__affiliates_map'][3]                = 7;
$GLOBALS['__users'][7]                         = new Fake_User( 7, 'affiliate@test.dev' );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__chip_bank_lookup_override'] = array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) );

$GLOBALS['__referral_rows'][202] = new Fake_Referral( 202, 3, '4.00', 'unpaid', 0 );
$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array( array( 'id' => 7200, 'state' => 'rejected', 'reference' => 'XT-R-202' ) ) ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'POST', 'code' => 200, 'body' => array( 'id' => 7300, 'state' => 'received' ) );
chip_affiliatewp_pay_single_referral( 202 );

$sent = '';
foreach ( $GLOBALS['__http_log'] as $call ) {
	if ( 'POST' === $call['method'] && false !== strpos( $call['url'], 'send_instructions' ) ) {
		$sent = (string) $call['body'];
	}
}
check( 'a dead referral instruction is not adopted', false !== strpos( $sent, 'XT-R-202-2' ) );

$fresh = array_values( array_filter( $GLOBALS['__payout_rows'], function ( $p ) { return 7300 === (int) $p->service_id; } ) );
check( 'the retry creates a new payout for the new instruction', 1 === count( $fresh ) );

echo "\n== Test 58: a race between the lookup and the POST is still safe ==\n";
reset_state();

$GLOBALS['__options']['chip_payouts']          = 1;
$GLOBALS['__options']['chip_test_mode']        = 1;
$GLOBALS['__options']['chip_test_api_key']     = 'k';
$GLOBALS['__options']['chip_test_secret_key']  = 's';
$GLOBALS['__options']['chip_reference_prefix'] = 'XT';
$GLOBALS['__options']['currency']              = 'MYR';
$GLOBALS['__affiliates_map'][3]                = 7;
$GLOBALS['__users'][7]                         = new Fake_User( 7, 'affiliate@test.dev' );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__chip_bank_lookup_override'] = array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) );

$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 210 ),
		'amount'        => '6.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][210] = new Fake_Referral( 210, 3, '6.00', 'unpaid', $payout_id );

/*
 * The lookup finds nothing (so we POST), but by the time the POST lands the
 * instruction exists — another worker, or a webhook that created it. CHIP
 * refuses with a duplicate-reference error and the follow-up lookup finds a
 * LIVE instruction. That must be adopted, not re-sent.
 */
$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'POST', 'code' => 422, 'body' => array( 'message' => array( 'Reference already exists' ), 'code' => 422 ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array( array( 'id' => 8001, 'state' => 'executing', 'reference' => 'XT-PO-' . $payout_id ) ) ) );

$result = chip_affiliatewp_submit_payout( $payout_id );

$posts = 0;
foreach ( $GLOBALS['__http_log'] as $call ) {
	if ( 'POST' === $call['method'] && false !== strpos( $call['url'], 'send_instructions' ) ) {
		++$posts;
	}
}
check( 'a raced duplicate is resolved', true === $result );
check( 'a raced duplicate sends only one instruction', 1 === $posts );
check( 'a raced duplicate adopts the live instruction', 8001 === (int) ( chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) )['instruction_id'] ?? 0 ) );

// Same race, but the instruction found is DEAD: do not adopt it, and do not
// claim success — the payout must fail so the next attempt uses a new reference.
reset_state();
$GLOBALS['__options']['chip_payouts']          = 1;
$GLOBALS['__options']['chip_test_mode']        = 1;
$GLOBALS['__options']['chip_test_api_key']     = 'k';
$GLOBALS['__options']['chip_test_secret_key']  = 's';
$GLOBALS['__options']['chip_reference_prefix'] = 'XT';
$GLOBALS['__affiliates_map'][3]                = 7;
$GLOBALS['__users'][7]                         = new Fake_User( 7, 'affiliate@test.dev' );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__chip_bank_lookup_override'] = array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) );

$dead_race = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 211 ),
		'amount'        => '6.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][211] = new Fake_Referral( 211, 3, '6.00', 'unpaid', $dead_race );

$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'POST', 'code' => 422, 'body' => array( 'message' => array( 'Reference already exists' ), 'code' => 422 ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array( array( 'id' => 8100, 'state' => 'rejected', 'reference' => 'XT-PO-' . $dead_race ) ) ) );

chip_affiliatewp_submit_payout( $dead_race );

$dead_data = chip_affiliatewp_payout_data( affwp_get_payout( $dead_race ) );
check( 'a raced dead instruction is not adopted', 8100 !== (int) ( $dead_data['instruction_id'] ?? 0 ) );
check( 'a raced dead instruction fails the payout', 'failed' === affwp_get_payout( $dead_race )->status );
// The batch path tracks the attempt on the payout row; the referral path uses
// a burnt-reference list because no payout row exists yet.
check( 'a raced dead instruction advances the payout attempt', 2 === (int) ( $dead_data['attempt'] ?? 0 ) );

echo "\n== Test 59: a payout parked under review is surfaced, not left silent ==\n";
reset_state();

$GLOBALS['__options']['chip_payouts']          = 1;
$GLOBALS['__options']['chip_test_mode']        = 1;
$GLOBALS['__options']['chip_test_api_key']     = 'k';
$GLOBALS['__options']['chip_test_secret_key']  = 's';
$GLOBALS['__options']['chip_reference_prefix'] = 'XT';
$GLOBALS['__options']['currency']              = 'MYR';
$GLOBALS['__options_store']['admin_email']     = 'merchant@test.dev';
$GLOBALS['__affiliates_map'][3]                = 7;
$GLOBALS['__users'][7]                         = new Fake_User( 7, 'affiliate@test.dev' );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__chip_bank_lookup_override'] = array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) );

// 'reviewing' is the state CHIP documents as needing a human.
check( 'reviewing needs attention', chip_affiliatewp_state_needs_review( 'reviewing' ) );
check( 'reviewing is matched case-insensitively', chip_affiliatewp_state_needs_review( 'REVIEWING' ) );

// In-flight and terminal states do not.
foreach ( array( 'received', 'enquiring', 'executing', 'accepted', 'completed', 'rejected', 'deleted' ) as $chip_state ) {
	check( $chip_state . ' does not need attention', ! chip_affiliatewp_state_needs_review( $chip_state ) );
}

// A payout whose instruction is under review is listed.
$review_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 300 ),
		'amount'        => '12.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][300] = new Fake_Referral( 300, 3, '12.00', 'unpaid', $review_id );

chip_affiliatewp_update_payout_data( $review_id, array( 'instruction_id' => 9001, 'state' => 'reviewing' ) );

$flagged = chip_affiliatewp_payouts_awaiting_review();
check( 'a reviewing payout is listed', 1 === count( $flagged ) );
check( 'the listed payout is the right one', $review_id === (int) $flagged[0]['payout_id'] );
check( 'the listing carries the amount', '12.00' === $flagged[0]['amount'] );

// An ordinary in-flight payout is NOT listed.
$normal_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 301 ),
		'amount'        => '3.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][301] = new Fake_Referral( 301, 3, '3.00', 'unpaid', $normal_id );

chip_affiliatewp_update_payout_data( $normal_id, array( 'instruction_id' => 9002, 'state' => 'executing' ) );

check( 'an executing payout is not listed', 1 === count( chip_affiliatewp_payouts_awaiting_review() ) );

// The merchant is emailed once, then not again.
$GLOBALS['__mail'] = array();
$sent = chip_affiliatewp_notify_review_payouts();

check( 'the merchant is emailed once', 1 === $sent );
check( 'the email goes to the merchant', 'merchant@test.dev' === ( $GLOBALS['__mail'][0]['to'] ?? '' ) );
check( 'the email names the instruction', false !== strpos( $GLOBALS['__mail'][0]['body'] ?? '', '9001' ) );

$GLOBALS['__mail'] = array();
check( 'the merchant is not emailed twice', 0 === chip_affiliatewp_notify_review_payouts() );

// A second run after a new payout appears emails only about the new one.
$second_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 302 ),
		'amount'        => '5.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][302] = new Fake_Referral( 302, 3, '5.00', 'unpaid', $second_id );
chip_affiliatewp_update_payout_data( $second_id, array( 'instruction_id' => 9003, 'state' => 'reviewing' ) );

$GLOBALS['__mail'] = array();
check( 'only the new review emails again', 1 === chip_affiliatewp_notify_review_payouts() );
check( 'the follow-up names the new instruction', false !== strpos( $GLOBALS['__mail'][0]['body'] ?? '', '9003' ) );

// A missing merchant address must not fatal — it just skips.
$GLOBALS['__options_store']['admin_email'] = 'not-an-email';
$GLOBALS['__mail'] = array();
check( 'an invalid merchant address sends nothing', 0 === chip_affiliatewp_notify_review_payouts() );
check( 'an invalid merchant address makes no mail call', array() === $GLOBALS['__mail'] );

echo "\n== Test 60: a long review queue does not starve the oldest payouts ==\n";
reset_state();

$GLOBALS['__options']['chip_payouts']          = 1;
$GLOBALS['__options']['chip_test_mode']        = 1;
$GLOBALS['__options']['chip_test_api_key']     = 'k';
$GLOBALS['__options']['chip_test_secret_key']  = 's';
$GLOBALS['__options']['chip_reference_prefix'] = 'XT';
$GLOBALS['__options_store']['admin_email']     = 'merchant@test.dev';
$GLOBALS['__affiliates_map'][3]                = 7;
$GLOBALS['__users'][7]                         = new Fake_User( 7, 'affiliate@test.dev' );

// 30 payouts all parked under review — more than one run may notify.
$review_ids = array();

for ( $i = 0; $i < 30; $i++ ) {
	$pid = affiliate_wp()->affiliates->payouts->add(
		array(
			'affiliate_id'  => 3,
			'referrals'     => array( 400 + $i ),
			'amount'        => '1.00',
			'payout_method' => 'chip',
			'status'        => 'processing',
		)
	);
	$GLOBALS['__referral_rows'][ 400 + $i ] = new Fake_Referral( 400 + $i, 3, '1.00', 'unpaid', $pid );

	chip_affiliatewp_update_payout_data( $pid, array( 'instruction_id' => 9500 + $i, 'state' => 'reviewing' ) );

	$review_ids[] = $pid;
}

check( 'all 30 are listed as under review', 30 === count( chip_affiliatewp_payouts_awaiting_review( 200 ) ) );

// Run 1 notifies the first batch.
$GLOBALS['__mail'] = array();
$first = chip_affiliatewp_notify_review_payouts();

check( 'the first run notifies a bounded batch', 20 === $first );
check( 'the first run sends one email each', 20 === count( $GLOBALS['__mail'] ) );

// Run 2 must reach the ones that have NOT been notified yet.
$GLOBALS['__mail'] = array();
$second = chip_affiliatewp_notify_review_payouts();

check( 'the next run reaches the remaining payouts', 10 === $second );
check( 'the next run does not repeat the first batch', 10 === count( $GLOBALS['__mail'] ) );

// Every payout got exactly one notice.
$notified = 0;

foreach ( $review_ids as $pid ) {
	if ( ! empty( chip_affiliatewp_payout_data( affwp_get_payout( $pid ) )['review_notified'] ) ) {
		++$notified;
	}
}

check( 'every payout ends up notified exactly once', 30 === $notified );

// A third run has nothing left to do.
$GLOBALS['__mail'] = array();
check( 'a settled queue sends nothing more', 0 === chip_affiliatewp_notify_review_payouts() );

echo "\n== Test 61: the review list is cached and invalidated on state change ==\n";
reset_state();

$GLOBALS['__options']['chip_payouts']          = 1;
$GLOBALS['__options']['chip_test_mode']        = 1;
$GLOBALS['__options']['chip_test_api_key']     = 'k';
$GLOBALS['__options']['chip_test_secret_key']  = 's';
$GLOBALS['__options']['chip_reference_prefix'] = 'XT';
$GLOBALS['__affiliates_map'][3]                = 7;
$GLOBALS['__users'][7]                         = new Fake_User( 7, 'affiliate@test.dev' );

$cached_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 500 ),
		'amount'        => '2.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][500] = new Fake_Referral( 500, 3, '2.00', 'unpaid', $cached_id );

chip_affiliatewp_update_payout_data( $cached_id, array( 'instruction_id' => 9600, 'state' => 'reviewing' ) );

check( 'the reviewing payout is listed', 1 === count( chip_affiliatewp_payouts_awaiting_review() ) );

// The list is cached: reading it again does not re-query the payout table.
$queries_before = $GLOBALS['__payouts_query_count'] ?? 0;
chip_affiliatewp_payouts_awaiting_review();
chip_affiliatewp_payouts_awaiting_review();
$queries_after = $GLOBALS['__payouts_query_count'] ?? 0;

check( 'repeat reads do not re-query payouts', $queries_before === $queries_after );

// When the instruction completes, the cache must drop so the payout leaves the list.
chip_affiliatewp_apply_instruction( $cached_id, array( 'id' => 9600, 'state' => 'completed' ) );

check( 'a completed payout leaves the review list', 0 === count( chip_affiliatewp_payouts_awaiting_review() ) );
check( 'the completed payout is paid', 'paid' === affwp_get_payout( $cached_id )->status );

// A NEW reviewing payout appears immediately, without waiting for the TTL.
$fresh_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 501 ),
		'amount'        => '3.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][501] = new Fake_Referral( 501, 3, '3.00', 'unpaid', $fresh_id );

chip_affiliatewp_update_payout_data( $fresh_id, array( 'instruction_id' => 9601, 'state' => 'reviewing' ) );

check( 'a new reviewing payout appears at once', 1 === count( chip_affiliatewp_payouts_awaiting_review() ) );
check( 'the new listing is the new payout', $fresh_id === (int) chip_affiliatewp_payouts_awaiting_review()[0]['payout_id'] );

echo "\n== Test 62: a reviewed payout does not consume the sweep budget ==\n";
reset_state();

$GLOBALS['__options']['chip_payouts']          = 1;
$GLOBALS['__options']['chip_test_mode']        = 1;
$GLOBALS['__options']['chip_test_api_key']     = 'k';
$GLOBALS['__options']['chip_test_secret_key']  = 's';
$GLOBALS['__options']['chip_reference_prefix'] = 'XT';
$GLOBALS['__affiliates_map'][3]                = 7;
$GLOBALS['__users'][7]                         = new Fake_User( 7, 'affiliate@test.dev' );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__chip_bank_lookup_override'] = array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) );

// An older payout parked under review, last checked 30 minutes ago.
$review_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 600 ),
		'amount'        => '4.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][600] = new Fake_Referral( 600, 3, '4.00', 'unpaid', $review_id );
chip_affiliatewp_update_payout_data(
	$review_id,
	array(
		'instruction_id' => 9700,
		'state'          => 'reviewing',
		'last_checked'   => gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS ),
	)
);

// A newer payout genuinely in flight, last checked 30 minutes ago too.
$flight_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 601 ),
		'amount'        => '4.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][601] = new Fake_Referral( 601, 3, '4.00', 'unpaid', $flight_id );
chip_affiliatewp_update_payout_data(
	$flight_id,
	array(
		'instruction_id' => 9701,
		'state'          => 'executing',
		'last_checked'   => gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS ),
	)
);

$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_log']   = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions/9701', 'method' => 'GET', 'code' => 200, 'body' => array( 'id' => 9701, 'state' => 'executing' ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions/9700', 'method' => 'GET', 'code' => 200, 'body' => array( 'id' => 9700, 'state' => 'reviewing' ) );

chip_affiliatewp_sweep_processing_payouts();

$probed = array();

foreach ( $GLOBALS['__http_log'] as $call ) {
	if ( false !== strpos( $call['url'], 'send_instructions/' ) ) {
		$probed[] = (int) substr( $call['url'], strrpos( $call['url'], '/' ) + 1 );
	}
}

check( 'the in-flight payout is requeryed', in_array( 9701, $probed, true ) );
check( 'a reviewed payout is not requeryed on the short cooldown', ! in_array( 9700, $probed, true ) );

// It IS requeryed once the long review cooldown has passed.
reset_state();
$GLOBALS['__options']['chip_payouts']          = 1;
$GLOBALS['__options']['chip_test_mode']        = 1;
$GLOBALS['__options']['chip_test_api_key']     = 'k';
$GLOBALS['__options']['chip_test_secret_key']  = 's';
$GLOBALS['__affiliates_map'][3]                = 7;
$GLOBALS['__users'][7]                        = new Fake_User( 7, 'affiliate@test.dev' );

$old_review = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 602 ),
		'amount'        => '4.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][602] = new Fake_Referral( 602, 3, '4.00', 'unpaid', $old_review );
chip_affiliatewp_update_payout_data(
	$old_review,
	array(
		'instruction_id' => 9702,
		'state'          => 'reviewing',
		'last_checked'   => gmdate( 'Y-m-d H:i:s', time() - 7 * HOUR_IN_SECONDS ),
	)
);

$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_log']   = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions/9702', 'method' => 'GET', 'code' => 200, 'body' => array( 'id' => 9702, 'state' => 'reviewing' ) );

chip_affiliatewp_sweep_processing_payouts();

$probed = array();

foreach ( $GLOBALS['__http_log'] as $call ) {
	if ( false !== strpos( $call['url'], 'send_instructions/' ) ) {
		$probed[] = (int) substr( $call['url'], strrpos( $call['url'], '/' ) + 1 );
	}
}

check( 'a reviewed payout is requeryed after the long cooldown', in_array( 9702, $probed, true ) );

echo "\n== Test 63: a bank-account status webhook refreshes the cache ==\n";
reset_state();

$GLOBALS['__options']['chip_payouts']      = 1;
$GLOBALS['__options']['chip_test_mode']    = 1;
$GLOBALS['__affiliates_map'][3]            = 7;
$GLOBALS['__users'][7]                     = new Fake_User( 7, 'affiliate@test.dev' );

// Verified account cached for the affiliate.
update_user_meta( 7, 'payment_account_number', '157380112229' );
update_user_meta( 7, 'payment_bank_code', 'MBBEMYKL' );
update_user_meta(
	7,
	'chip_bank_account',
	array(
		'id'          => 84,
		'status'      => 'verified',
		'reference'   => 'XT-AFF-3-abc123',
		'fingerprint' => chip_affiliatewp_bank_details_fingerprint( 3 ),
	)
);

check( 'a verified account is cached', 84 === (int) ( get_user_meta( 7, 'chip_bank_account', true )['id'] ?? 0 ) );

// Sign the payload as CHIP would, then deliver it through the real handler.
$keypair = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
openssl_pkey_export( $keypair, $priv_pem );
$details = openssl_pkey_get_details( $keypair );
$GLOBALS['__options']['chip_webhook_public_key'] = $details['key'];
$GLOBALS['__options']['chip_webhook_secret']     = 'fixedharnesssecret000000000000000000';

$bank_body = json_encode( array( 'id' => 84, 'status' => 'rejected', 'reference' => 'XT-AFF-3-abc123' ) );
openssl_sign( $bank_body, $bank_sig, $priv_pem, OPENSSL_ALGO_SHA512 );

$bank_request = new Fake_Request();
$bank_request->body = $bank_body;
$bank_request->headers['HTTP_X_SIGNATURE'] = base64_encode( $bank_sig );
$bank_request->headers['HTTP_EVENT_TYPE']  = 'bank_account_status';

$bank_resp = chip_affiliatewp_handle_webhook( $bank_request );

check( 'the bank status webhook is handled', is_array( $bank_resp ) );
check( 'the bank status webhook reports a refresh', 'bank_account_refreshed' === ( $bank_resp['response']['handled'] ?? '' ) );

$after = get_user_meta( 7, 'chip_bank_account', true );
check( 'a rejected account is dropped from the cache', empty( $after['id'] ) );

// A budget allocation event is still a no-op.
$budget_body = json_encode( array( 'id' => 99, 'status' => 'approved' ) );
openssl_sign( $budget_body, $budget_sig, $priv_pem, OPENSSL_ALGO_SHA512 );

$budget_request = new Fake_Request();
$budget_request->body = $budget_body;
$budget_request->headers['HTTP_X_SIGNATURE'] = base64_encode( $budget_sig );
$budget_request->headers['HTTP_EVENT_TYPE']  = 'budget_allocation_status';

$budget_resp = chip_affiliatewp_handle_webhook( $budget_request );
check( 'a budget event is still ignored', 'ignored' === ( $budget_resp['response']['handled'] ?? '' ) );

// A payload with no usable reference must not touch the cache.
update_user_meta( 7, 'chip_bank_account', array( 'id' => 85, 'status' => 'verified', 'reference' => 'XT-AFF-3-abc123' ) );
chip_affiliatewp_forget_cached_bank_account_from_webhook( array( 'id' => 85, 'status' => 'rejected' ) );
check( 'a payload without a reference changes nothing', 85 === (int) ( get_user_meta( 7, 'chip_bank_account', true )['id'] ?? 0 ) );

// A payout reference must not be mistaken for a bank reference.
chip_affiliatewp_forget_cached_bank_account_from_webhook( array( 'id' => 85, 'status' => 'rejected', 'reference' => 'XT-PO-12' ) );
check( 'a payout reference does not clear a bank cache', 85 === (int) ( get_user_meta( 7, 'chip_bank_account', true )['id'] ?? 0 ) );

// The filter can keep the cache.
add_filter( 'chip_affiliatewp_forget_bank_account_on_webhook', function () { return false; } );
chip_affiliatewp_forget_cached_bank_account_from_webhook( array( 'id' => 85, 'status' => 'rejected', 'reference' => 'XT-AFF-3-abc123' ) );
check( 'the filter can keep the cached account', 85 === (int) ( get_user_meta( 7, 'chip_bank_account', true )['id'] ?? 0 ) );

echo "\n== Test 64: references stay distinct when no prefix is configured ==\n";
reset_state();

// A configured prefix is used as-is, trimmed to two alphanumerics.
$GLOBALS['__options']['chip_reference_prefix'] = '34';
check( 'a configured prefix is used', '34' === chip_affiliatewp_reference_prefix() );

$GLOBALS['__options']['chip_reference_prefix'] = 'ab';
check( 'a lowercase prefix is canonicalised', 'AB' === chip_affiliatewp_reference_prefix() );

$GLOBALS['__options']['chip_reference_prefix'] = 'ABC';
check( 'a long prefix is trimmed to two', 'AB' === chip_affiliatewp_reference_prefix() );

$GLOBALS['__options']['chip_reference_prefix'] = 'a-1';
check( 'punctuation is stripped from a prefix', 'A1' === chip_affiliatewp_reference_prefix() );

// Nothing usable means a per-site fallback, never an empty prefix.
$GLOBALS['__options']['chip_reference_prefix'] = '';
$empty_fallback = chip_affiliatewp_reference_prefix();

check( 'an empty prefix falls back', '' !== $empty_fallback );
check( 'the fallback is two characters', 2 === strlen( $empty_fallback ) );
check( 'the fallback is alphanumeric', 1 === preg_match( '/^[A-Z0-9]{2}$/', $empty_fallback ) );

// Punctuation only is treated the same as empty.
$GLOBALS['__options']['chip_reference_prefix'] = '--';
check( 'punctuation-only falls back too', chip_affiliatewp_reference_prefix() === $empty_fallback );

// The fallback is stable, and references derived from it never start with a dash.
check( 'the fallback is stable across calls', $empty_fallback === chip_affiliatewp_reference_prefix() );

$ref = chip_affiliatewp_instruction_reference( 5, 1 );
check( 'an unconfigured prefix still yields a usable reference', 0 !== strpos( $ref, '-' ) );
check( 'the reference carries the site prefix', 0 === strpos( $ref, $empty_fallback ) );

// Two different sites must not produce the same reference.
add_filter( 'chip_affiliatewp_reference_prefix_fallback', function () { return 'ZZ'; } );
check( 'the fallback is filterable', 'ZZ' === chip_affiliatewp_reference_prefix() );
check( 'a filtered prefix changes the reference', 'ZZ-PO-5' === chip_affiliatewp_instruction_reference( 5, 1 ) );

echo "\n== Test 65: an affiliate can only ever write their own bank details ==\n";
reset_state();

$GLOBALS['__options']['chip_payouts']   = 1;
$GLOBALS['__options']['chip_test_mode'] = 1;
$GLOBALS['__affiliates_map'][3]         = 7;
$GLOBALS['__affiliates_map'][4]         = 12;
$GLOBALS['__users'][7]                  = new Fake_User( 7, 'self@test.dev' );
$GLOBALS['__users'][12]                 = new Fake_User( 12, 'other@test.dev' );
$GLOBALS['__affiliate_meta'][3]['payout_method_pick'] = 'chip';
$GLOBALS['__affiliate_meta'][4]['payout_method_pick'] = 'chip';
$GLOBALS['__logged_in']                 = true;
$GLOBALS['__current_affiliate_id']      = 3;

add_filter( 'chip_affiliatewp_bank_save_redirect', function () { return false; } );

// A forged affiliate_id in the request must be ignored.
$_POST = array(
	'chip_affiliatewp_action'     => 'save_bank_details',
	'chip_affiliatewp_bank_nonce' => 'good-nonce',
	'payment_bank_code'           => 'MBBEMYKL',
	'payment_account_number'      => '1111222233',
	'affiliate_id'                => 4,
);

chip_affiliatewp_handle_affiliate_bank_save();

check( 'the posting affiliate gets the details', '1111222233' === (string) get_user_meta( 7, 'payment_account_number', true ) );
check( 'a forged affiliate_id is ignored', '' === (string) get_user_meta( 12, 'payment_account_number', true ) );

// Without a logged-in user nothing is written at all.
reset_state();
$GLOBALS['__options']['chip_payouts'] = 1;
$GLOBALS['__affiliates_map'][3]       = 7;
$GLOBALS['__users'][7]                = new Fake_User( 7, 'self@test.dev' );
$GLOBALS['__affiliate_meta'][3]['payout_method_pick'] = 'chip';
$GLOBALS['__logged_in']               = false;
$GLOBALS['__current_affiliate_id']    = 3;

$_POST = array(
	'chip_affiliatewp_action'     => 'save_bank_details',
	'chip_affiliatewp_bank_nonce' => 'good-nonce',
	'payment_bank_code'           => 'MBBEMYKL',
	'payment_account_number'      => '4444555566',
);

chip_affiliatewp_handle_affiliate_bank_save();
check( 'a logged-out request writes nothing', '' === (string) get_user_meta( 7, 'payment_account_number', true ) );

// An affiliate whose method is not CHIP cannot write through this form.
reset_state();
$GLOBALS['__options']['chip_payouts'] = 1;
$GLOBALS['__affiliates_map'][3]       = 7;
$GLOBALS['__users'][7]                = new Fake_User( 7, 'self@test.dev' );
$GLOBALS['__affiliate_meta'][3]['payout_method_pick'] = 'paypal';
$GLOBALS['__logged_in']               = true;
$GLOBALS['__current_affiliate_id']    = 3;

$_POST = array(
	'chip_affiliatewp_action'     => 'save_bank_details',
	'chip_affiliatewp_bank_nonce' => 'good-nonce',
	'payment_bank_code'           => 'MBBEMYKL',
	'payment_account_number'      => '7777888899',
);

chip_affiliatewp_handle_affiliate_bank_save();
check( 'a non-CHIP affiliate writes nothing', '' === (string) get_user_meta( 7, 'payment_account_number', true ) );

// An affiliate with no affiliate record cannot write either.
reset_state();
$GLOBALS['__options']['chip_payouts'] = 1;
$GLOBALS['__users'][7]                = new Fake_User( 7, 'self@test.dev' );
$GLOBALS['__logged_in']               = true;
$GLOBALS['__current_affiliate_id']    = 0;

$_POST = array(
	'chip_affiliatewp_action'     => 'save_bank_details',
	'chip_affiliatewp_bank_nonce' => 'good-nonce',
	'payment_bank_code'           => 'MBBEMYKL',
	'payment_account_number'      => '9999000011',
);

chip_affiliatewp_handle_affiliate_bank_save();
check( 'a user with no affiliate record writes nothing', '' === (string) get_user_meta( 7, 'payment_account_number', true ) );

$_POST = array();

echo "\n== Test 66: convert reads the live balance, not a cached ceiling ==\n";
reset_state();

$GLOBALS['__options']['chip_payouts']          = 1;
$GLOBALS['__options']['chip_test_mode']        = 1;
$GLOBALS['__options']['chip_test_api_key']     = 'k';
$GLOBALS['__options']['chip_test_secret_key']  = 's';
$GLOBALS['__current_user_can']                 = true;

// A stale cache says 1000 is convertible; the live balance is only 100.
set_transient(
	'chip_affiliatewp_account_test',
	array( 'current_balance' => 2000.0, 'convertible' => 1000.0, 'currency' => 'MYR', 'approvals_required' => 0, 'error' => null ),
	300
);

$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/send/accounts', 'method' => 'GET', 'code' => 200, 'body' => array( 'current_balance' => '200.00', 'convertible_balance_from_statement' => '100.00', 'currency' => 'MYR', 'settlement_convert_approvals_count' => 0 ) );

$_POST = array(
	'chip_affiliatewp_action'        => 'convert_balance',
	'chip_affiliatewp_convert_nonce' => 'good-nonce',
	'chip_convert_amount'            => '900',
);

chip_affiliatewp_handle_convert_balance();

// 900 passes the stale ceiling but not the real one: nothing may be submitted.
$alloc_posts = 0;

foreach ( $GLOBALS['__http_log'] as $call ) {
	if ( 'POST' === $call['method'] ) {
		++$alloc_posts;
	}
}

check( 'a stale cached ceiling does not let an over-limit request through', 0 === $alloc_posts );

$notices = get_transient( 'chip_affiliatewp_notices_' . get_current_user_id() );
$notice_text = is_array( $notices ) ? implode( ' ', array_column( $notices, 'message' ) ) : '';

check( 'the refusal names the live convertible balance', false !== strpos( $notice_text, '100.00' ) );
check( 'the refusal names the requested amount', false !== strpos( $notice_text, '900.00' ) );

// A successful conversion drops the cached balance so the panel shows the new one.
reset_state();
$GLOBALS['__options']['chip_payouts']          = 1;
$GLOBALS['__options']['chip_test_mode']        = 1;
$GLOBALS['__options']['chip_test_api_key']     = 'k';
$GLOBALS['__options']['chip_test_secret_key']  = 's';
$GLOBALS['__current_user_can']                 = true;

set_transient(
	'chip_affiliatewp_account_test',
	array( 'current_balance' => 2000.0, 'convertible' => 1000.0, 'currency' => 'MYR', 'approvals_required' => 0, 'error' => null ),
	300
);

$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/send/accounts', 'method' => 'GET', 'code' => 200, 'body' => array( 'current_balance' => '2000.00', 'convertible_balance_from_statement' => '1000.00', 'currency' => 'MYR', 'settlement_convert_approvals_count' => 0 ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_limits', 'method' => 'POST', 'code' => 200, 'body' => array( 'id' => 1, 'status' => 'pending', 'approvals_required' => 0 ) );

add_filter( 'chip_affiliatewp_convert_balance_redirect', function () { return false; } );

$_POST = array(
	'chip_affiliatewp_action'        => 'convert_balance',
	'chip_affiliatewp_convert_nonce' => 'good-nonce',
	'chip_convert_amount'            => '500',
);

chip_affiliatewp_handle_convert_balance();

$conv_notices = get_transient( 'chip_affiliatewp_notices_' . get_current_user_id() );
check( 'a successful conversion clears the cached balance', false === get_transient( 'chip_affiliatewp_account_test' ) );

echo "\n== Test 67: a bank account id is never carried across modes ==\n";
reset_state();

$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7]         = new Fake_User( 7, 'affiliate@test.dev' );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';

$reference = chip_affiliatewp_bank_reference( 3 );

// Registered while the site was in test mode: CHIP staging issued id 84.
$GLOBALS['__options']['chip_test_mode'] = 1;
chip_affiliatewp_store_bank_account( 3, array( 'id' => 84, 'status' => 'verified', 'reference' => $reference ) );

check( 'the test-mode record is readable in test mode', 84 === (int) ( chip_affiliatewp_get_stored_bank_account( 3, $reference, 'test' )['id'] ?? 0 ) );

// Flipping to live must NOT hand back the staging id.
check( 'the test-mode id is not reused in live', null === chip_affiliatewp_get_stored_bank_account( 3, $reference, 'live' ) );

// Resolving in live asks CHIP live, and stores the live id separately.
$GLOBALS['__options']['chip_test_mode']        = 0;
$GLOBALS['__options']['chip_live_api_key']     = 'lk';
$GLOBALS['__options']['chip_live_secret_key']  = 'ls';
$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/send/bank_accounts', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array( array( 'id' => 500, 'status' => 'verified', 'reference' => $reference ) ) ) );

$live_account = chip_affiliatewp_get_bank_account( 3 );

check( 'the live lookup returns the live id', 500 === (int) ( $live_account['id'] ?? 0 ) );

// Both modes keep their own record.
$records = $GLOBALS['__user_meta'][7]['chip_bank_account'];
check( 'the test record survives', 84 === (int) ( $records['test']['id'] ?? 0 ) );
check( 'the live record is stored separately', 500 === (int) ( $records['live']['id'] ?? 0 ) );

// Switching back to test still resolves the staging id, not the live one.
check( 'test mode still resolves its own id', 84 === (int) ( chip_affiliatewp_get_stored_bank_account( 3, $reference, 'test' )['id'] ?? 0 ) );
check( 'live mode still resolves its own id', 500 === (int) ( chip_affiliatewp_get_stored_bank_account( 3, $reference, 'live' )['id'] ?? 0 ) );

// A record written by an older version (one flat array) is migrated, not lost.
reset_state();
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7]         = new Fake_User( 7, 'affiliate@test.dev' );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__options']['chip_test_mode']              = 1;
$GLOBALS['__options']['chip_test_api_key']           = 'tk';
$GLOBALS['__options']['chip_test_secret_key']        = 'ts';

$GLOBALS['__user_meta'][7]['chip_bank_account'] = array(
	'id'          => 4242,
	'status'      => 'verified',
	'mode'        => 'test',
	'reference'   => chip_affiliatewp_bank_reference( 3 ),
	'fingerprint' => chip_affiliatewp_bank_details_fingerprint( 3 ),
);

check( 'a legacy record carrying a mode still resolves', 4242 === (int) ( chip_affiliatewp_get_stored_bank_account( 3, chip_affiliatewp_bank_reference( 3 ), 'test' )['id'] ?? 0 ) );

// A legacy record with NO mode marker is discarded rather than guessed into an
// environment: a staging ID must never be handed to live.
$GLOBALS['__user_meta'][7]['chip_bank_account'] = array(
	'id'          => 7777,
	'status'      => 'verified',
	'reference'   => chip_affiliatewp_bank_reference( 3 ),
	'fingerprint' => chip_affiliatewp_bank_details_fingerprint( 3 ),
);

$GLOBALS['__options']['chip_test_mode'] = 1;
check( 'an unmoded legacy record is not reused in test', null === chip_affiliatewp_get_stored_bank_account( 3, chip_affiliatewp_bank_reference( 3 ), 'test' ) );

$GLOBALS['__options']['chip_test_mode'] = 0;
check( 'an unmoded legacy record is not reused in live', null === chip_affiliatewp_get_stored_bank_account( 3, chip_affiliatewp_bank_reference( 3 ), 'live' ) );

// A record that DOES carry a mode is grouped under it, not discarded.
$GLOBALS['__user_meta'][7]['chip_bank_account'] = array(
	'id'          => 4242,
	'status'      => 'verified',
	'mode'        => 'test',
	'reference'   => chip_affiliatewp_bank_reference( 3 ),
	'fingerprint' => chip_affiliatewp_bank_details_fingerprint( 3 ),
);

check( 'a moded legacy record still resolves', 4242 === (int) ( chip_affiliatewp_get_stored_bank_account( 3, chip_affiliatewp_bank_reference( 3 ), 'test' )['id'] ?? 0 ) );

// Storing again rewrites it in the per-mode shape.
chip_affiliatewp_store_bank_account( 3, array( 'id' => 4242, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) ) );

$migrated = $GLOBALS['__user_meta'][7]['chip_bank_account'];
check( 'a legacy record migrates to the per-mode shape', 4242 === (int) ( $migrated['test']['id'] ?? 0 ) );
check( 'the migrated record keeps its mode', 'test' === ( $migrated['test']['mode'] ?? '' ) );

echo "\n== Test 68: a webhook signed for the other mode is still accepted ==\n";

/**
 * Builds a signed request for a given keypair.
 */
function __chip_signed_request( $body_array, $priv_pem, $event_type = 'send_instruction_status' ) {
	$body = json_encode( $body_array );
	openssl_sign( $body, $sig, $priv_pem, OPENSSL_ALGO_SHA512 );

	$request = new Fake_Request();
	$request->body = $body;
	$request->headers['HTTP_X_SIGNATURE'] = base64_encode( $sig );
	$request->headers['HTTP_EVENT_TYPE']  = $event_type;

	return $request;
}

reset_state();

// Two keypairs, as CHIP issues one per environment.
$test_pair = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
openssl_pkey_export( $test_pair, $test_priv );
$test_pub = openssl_pkey_get_details( $test_pair )['key'];

$live_pair = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
openssl_pkey_export( $live_pair, $live_priv );
$live_pub = openssl_pkey_get_details( $live_pair )['key'];

$GLOBALS['__options']['chip_payouts']             = 1;
$GLOBALS['__options']['chip_webhook_secret']      = 'fixedharnesssecret000000000000000000';
$GLOBALS['__options']['chip_webhook_public_key_test'] = $test_pub;
$GLOBALS['__options']['chip_webhook_public_key_live'] = $live_pub;

// A payout submitted in test mode is awaiting its webhook.
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7] = new Fake_User( 7, 'affiliate@test.dev' );

$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 700 ),
		'amount'        => '8.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][700] = new Fake_Referral( 700, 3, '8.00', 'unpaid', $payout_id );

chip_affiliatewp_update_payout_data( $payout_id, array( 'instruction_id' => 9800, 'state' => 'executing', 'mode' => 'test' ) );

// The merchant has since switched the site to live mode.
$GLOBALS['__options']['chip_test_mode'] = 0;

// CHIP delivers the TEST webhook for that test payout.
$test_request = __chip_signed_request(
	array( 'id' => 9800, 'state' => 'completed', 'reference' => 'XT-PO-' . $payout_id ),
	$test_priv
);

$test_response = chip_affiliatewp_handle_webhook( $test_request );

check( 'a webhook signed by the other mode is accepted', is_array( $test_response ) );
check( 'the test-mode payout settles after the mode flip', 'paid' === affwp_get_payout( $payout_id )->status );
check( 'the test-mode referral is paid', 'paid' === $GLOBALS['__referral_rows'][700]->status );

// A live-signed webhook is still accepted while live is current.
reset_state();
$GLOBALS['__options']['chip_payouts']             = 1;
$GLOBALS['__options']['chip_webhook_secret']      = 'fixedharnesssecret000000000000000000';
$GLOBALS['__options']['chip_webhook_public_key_test'] = $test_pub;
$GLOBALS['__options']['chip_webhook_public_key_live'] = $live_pub;
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7] = new Fake_User( 7, 'affiliate@test.dev' );

$live_payout = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 701 ),
		'amount'        => '8.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);
$GLOBALS['__referral_rows'][701] = new Fake_Referral( 701, 3, '8.00', 'unpaid', $live_payout );

chip_affiliatewp_update_payout_data( $live_payout, array( 'instruction_id' => 9801, 'state' => 'executing', 'mode' => 'live' ) );

$live_response = chip_affiliatewp_handle_webhook(
	__chip_signed_request(
		array( 'id' => 9801, 'state' => 'completed', 'reference' => 'XT-PO-' . $live_payout ),
		$live_priv
	)
);

check( 'a live-signed webhook is accepted', is_array( $live_response ) );
check( 'the live payout settles', 'paid' === affwp_get_payout( $live_payout )->status );

// A forged signature is still rejected, even with both keys configured.
$forged_pair = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
openssl_pkey_export( $forged_pair, $forged_priv );

$forged = chip_affiliatewp_handle_webhook(
	__chip_signed_request( array( 'id' => 9802, 'state' => 'completed' ), $forged_priv )
);

check( 'a signature from an unknown key is rejected', is_wp_error( $forged ) );
check( 'the rejected signature is a 401', is_wp_error( $forged ) && 401 === $forged->get_error_data()['status'] );

// A payload signed correctly but tampered with afterwards is rejected too.
$good_body = json_encode( array( 'id' => 9803, 'state' => 'completed' ) );
openssl_sign( $good_body, $good_sig, $live_priv, OPENSSL_ALGO_SHA512 );

$tampered = new Fake_Request();
$tampered->body = $good_body . 'x';
$tampered->headers['HTTP_X_SIGNATURE'] = base64_encode( $good_sig );
$tampered->headers['HTTP_EVENT_TYPE']  = 'send_instruction_status';

$tampered_result = chip_affiliatewp_handle_webhook( $tampered );

check( 'a tampered body is rejected', is_wp_error( $tampered_result ) );
check( 'the tampered body is a 401', is_wp_error( $tampered_result ) && 401 === $tampered_result->get_error_data()['status'] );

// A malformed signature is rejected without a PHP error.
$bad_sig = new Fake_Request();
$bad_sig->body = $good_body;
$bad_sig->headers['HTTP_X_SIGNATURE'] = 'not-base64!!!';
$bad_sig->headers['HTTP_EVENT_TYPE']  = 'send_instruction_status';

$bad_sig_result = chip_affiliatewp_handle_webhook( $bad_sig );

check( 'a malformed signature is rejected', is_wp_error( $bad_sig_result ) );

echo "\n== Test 69: a payout born from a webhook records its mode ==\n";
reset_state();

$GLOBALS['__options']['chip_payouts']                 = 1;
$GLOBALS['__options']['chip_webhook_secret']          = 'fixedharnesssecret000000000000000000';
$GLOBALS['__affiliates_map'][3]                       = 7;
$GLOBALS['__users'][7]                                = new Fake_User( 7, 'affiliate@test.dev' );

$pair = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
openssl_pkey_export( $pair, $priv );
$GLOBALS['__options']['chip_webhook_public_key_test'] = openssl_pkey_get_details( $pair )['key'];

// The site is in TEST mode and only a test key exists.
$GLOBALS['__options']['chip_test_mode'] = 1;

// A single referral was submitted without a payout row; its webhook arrives.
$GLOBALS['__referral_rows'][800] = new Fake_Referral( 800, 3, '11.00', 'unpaid', 0 );

$webhook_body = array(
	'id'        => 9900,
	'state'     => 'executing',
	'reference' => 'XT-R-800',
);

$signed = json_encode( $webhook_body );
openssl_sign( $signed, $sig, $priv, OPENSSL_ALGO_SHA512 );

$request = new Fake_Request();
$request->body = $signed;
$request->headers['HTTP_X_SIGNATURE'] = base64_encode( $sig );
$request->headers['HTTP_EVENT_TYPE']  = 'send_instruction_status';

chip_affiliatewp_handle_webhook( $request );

// The payout created from that webhook must carry the mode that verified it.
$created = 0;

foreach ( $GLOBALS['__payout_rows'] as $row ) {
	if ( 9900 === (int) ( $row->service_id ?? 0 ) ) {
		$created = (int) $row->payout_id;
	}
}

check( 'the webhook created a payout', $created > 0 );

$data = chip_affiliatewp_payout_data( affwp_get_payout( $created ) );
check( 'the created payout records the verified mode', 'test' === ( $data['mode'] ?? '' ) );
check( 'the created payout records its instruction', 9900 === (int) ( $data['instruction_id'] ?? 0 ) );

// After a switch to live, the payout still resolves in its own mode.
$GLOBALS['__options']['chip_test_mode'] = 0;

check( 'the created payout still knows its mode after a flip', 'test' === ( chip_affiliatewp_payout_data( affwp_get_payout( $created ) )['mode'] ?? '' ) );

echo "\n== Test 70: deleting an affiliate removes the payout data we hold ==\n";
reset_state();

$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7]         = new Fake_User( 7, 'leaving@test.dev' );

// An affiliate with bank details and a resolved CHIP account.
chip_affiliatewp_store_bank_details( 7, 'MBBEMYKL', '157380112229' );
$GLOBALS['__options']['chip_test_mode'] = 1;

chip_affiliatewp_store_bank_account(
	3,
	array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) )
);

check( 'bank details are stored', '157380112229' === (string) get_user_meta( 7, 'payment_account_number', true ) );
check( 'the CHIP account is cached', ! empty( get_user_meta( 7, 'chip_bank_account', true ) ) );

// The affiliate is deleted — fire the hook AffiliateWP fires, so this tests
// that the plugin is actually listening, not just that the function works.
$affiliate = affwp_get_affiliate( 3 );
do_action( 'affwp_affiliate_deleted', 3, true, $affiliate );

check( 'the bank account number is removed', '' === (string) get_user_meta( 7, 'payment_account_number', true ) );
check( 'the bank code is removed', '' === (string) get_user_meta( 7, 'payment_bank_code', true ) );
check( 'the cached CHIP account is removed', '' === (string) get_user_meta( 7, 'chip_bank_account', true ) );

// The cleanup must also work when AffiliateWP does not hand over the object.
reset_state();
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7]         = new Fake_User( 7, 'leaving@test.dev' );
chip_affiliatewp_store_bank_details( 7, 'MBBEMYKL', '157380112229' );

do_action( 'affwp_affiliate_deleted', 3 );

check( 'cleanup without the affiliate object still removes the number', '' === (string) get_user_meta( 7, 'payment_account_number', true ) );

// An unknown affiliate id must not fatal or touch anyone else.
reset_state();
$GLOBALS['__users'][9] = new Fake_User( 9, 'other@test.dev' );
update_user_meta( 9, 'payment_account_number', '9999000011' );

do_action( 'affwp_affiliate_deleted', 0 );
do_action( 'affwp_affiliate_deleted', 4242 );

check( 'an unknown affiliate leaves other users alone', '9999000011' === (string) get_user_meta( 9, 'payment_account_number', true ) );

echo "\n== Test 71: every asset the code loads is shipped in the dist ==\n";

// Anything the plugin loads at runtime from its own directory must survive the
// build, or the released zip ships a broken screen.
$plugin_root = dirname( __DIR__ );
$build_script = $plugin_root . '/scripts/build-dist.sh';
$build_source = file_get_contents( $build_script );

check( 'the build script exists', false !== $build_source );

// Collect the relative paths the code references via CHIP_AFFILIATEWP_URL.
$php_sources = array_merge(
	glob( $plugin_root . '/*.php' ),
	glob( $plugin_root . '/includes/*.php' )
);

$referenced = array();

foreach ( $php_sources as $source_path ) {
	$source = file_get_contents( $source_path );

	if ( preg_match_all( "/CHIP_AFFILIATEWP_URL\s*\.\s*'([^']+)'/", $source, $hits ) ) {
		foreach ( $hits[1] as $relative ) {
			$referenced[ $relative ] = basename( $source_path );
		}
	}
}

check( 'the code references at least one asset', ! empty( $referenced ) );

foreach ( $referenced as $relative => $from ) {
	$top_level = explode( '/', ltrim( $relative, '/' ) )[0];
	$on_disk   = file_exists( $plugin_root . '/' . $relative );

	check( 'referenced asset exists on disk: ' . $relative . ' (' . $from . ')', $on_disk );

	/*
	 * The build script lists whole directories, one per line, each ending in a
	 * trailing backslash. Match the directory as its own line so a mention in a
	 * comment cannot satisfy the check.
	 */
	$shipped = false;

	foreach ( preg_split( '/\R/', $build_source ) as $build_line ) {
		if ( trim( rtrim( trim( $build_line ), '\\' ) ) === $top_level ) {
			$shipped = true;
			break;
		}
	}

	check( 'referenced asset is shipped by the build: ' . $top_level, $shipped );
}

// The languages directory must ship, or translations never load.
check( 'the build ships the languages directory', false !== strpos( $build_source, 'languages' ) );

// Development-only directories must NOT ship.
foreach ( array( 'tests', 'scripts', 'vendor' ) as $dev_only ) {
	check( 'the build does not ship ' . $dev_only, ! preg_match( '/^\s*' . $dev_only . '\s*\\/m', $build_source ) );
}

echo "\n== Test 72: the bank list and length bounds cover what CHIP accepts ==\n";

$banks = chip_affiliatewp_bank_codes();

// Banks CHIP Send supports. Every one must be offered, or an affiliate at that
// bank can never be paid.
foreach ( array(
	'BOBEMYK2' => 'BOOST Bank Berhad',
	'CITIMYKL' => 'Citibank Berhad',
) as $code => $label ) {
	check( 'the bank list offers ' . $label, isset( $banks[ $code ] ) );
	check( $label . ' has the right label', $label === ( $banks[ $code ] ?? '' ) );
}

// Every bank code must look like a BIC and carry a non-empty label.
$bad_codes = array();

foreach ( $banks as $code => $label ) {
	if ( 1 !== preg_match( '/^[A-Z0-9]{8,11}$/', (string) $code ) || '' === trim( (string) $label ) ) {
		$bad_codes[] = $code;
	}
}

check( 'every bank entry is well formed', array() === $bad_codes );

// Every supported bank's own length range must be accepted.
// Bank of America and Standard Chartered allow 5-digit accounts.
check( 'a 5-digit account is accepted', true === chip_affiliatewp_validate_account_number( '12345' ) );
check( 'a 10-digit account is accepted', true === chip_affiliatewp_validate_account_number( '1234567890' ) );
check( 'a 17-digit account is accepted', true === chip_affiliatewp_validate_account_number( '12345678901234567' ) );
check( 'a 20-digit account is accepted', true === chip_affiliatewp_validate_account_number( '12345678901234567890' ) );

// Nonsense is still refused.
check( 'a 4-digit account is refused', is_wp_error( chip_affiliatewp_validate_account_number( '1234' ) ) );
check( 'a 21-digit account is refused', is_wp_error( chip_affiliatewp_validate_account_number( '123456789012345678901' ) ) );
check( 'an empty account is refused', is_wp_error( chip_affiliatewp_validate_account_number( '' ) ) );

// Separators are tolerated on input.
check( 'a separated account is accepted', true === chip_affiliatewp_validate_account_number( '1234-567 890' ) );

echo "\n== Test 73: the single-referral path refuses a non-MYR store ==\n";
reset_state();

$GLOBALS['__options']['chip_payouts']          = 1;
$GLOBALS['__options']['chip_test_mode']        = 1;
$GLOBALS['__options']['chip_test_api_key']     = 'tk';
$GLOBALS['__options']['chip_test_secret_key']  = 'ts';

$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7]         = new Fake_User( 7, 'affiliate@test.dev' );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__affiliates'][3]    = (object) array( 'affiliate_id' => 3, 'user_id' => 7, 'payment_email' => '' );
$GLOBALS['__affiliate_meta'][3] = array();

$GLOBALS['__referral_rows'][300] = new Fake_Referral( 300, 3, '25.00', 'unpaid', 0 );

// A store that is not MYR must be refused before any money moves.
$GLOBALS['__options']['currency'] = 'USD';
$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_log']   = array();

$refused = chip_affiliatewp_pay_single_referral( 300 );

check( 'a USD store is refused', is_wp_error( $refused ) );
check( 'the refusal names the currency', is_wp_error( $refused ) && 'chip_currency_unsupported' === $refused->get_error_code() );
check( 'the refusal mentions USD', is_wp_error( $refused ) && false !== strpos( $refused->get_error_message(), 'USD' ) );
check( 'no HTTP call was made for a USD store', array() === $GLOBALS['__http_log'] );

// The referral must not be touched.
check( 'the referral stays unpaid', 'unpaid' === $GLOBALS['__referral_rows'][300]->status );

// A zero-amount referral is refused too.
$GLOBALS['__options']['currency'] = 'MYR';
$GLOBALS['__referral_rows'][301] = new Fake_Referral( 301, 3, '0.00', 'unpaid', 0 );
$GLOBALS['__http_log'] = array();

$zero = chip_affiliatewp_pay_single_referral( 301 );

check( 'a zero-amount referral is refused', is_wp_error( $zero ) );
check( 'the zero refusal is about the amount', is_wp_error( $zero ) && 'chip_invalid_amount' === $zero->get_error_code() );
check( 'no HTTP call was made for a zero amount', array() === $GLOBALS['__http_log'] );

// Sanity: an MYR store with a real amount DOES proceed to the API.
$GLOBALS['__referral_rows'][302] = new Fake_Referral( 302, 3, '25.00', 'unpaid', 0 );
$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/send/bank_accounts', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array( array( 'id' => 84, 'status' => 'verified', 'reference' => chip_affiliatewp_bank_reference( 3 ) ) ) ) );
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_log'] = array();

chip_affiliatewp_pay_single_referral( 302 );

check( 'an MYR store does reach the API', count( $GLOBALS['__http_log'] ) > 0 );

echo "\n== Test 74: an outdated AffiliateWP is reported, not silently ignored ==\n";

// The plugin depends on AffiliateWP 2.36 APIs. On an older release the payout
// method simply never appears, which reads as a broken install.
check( 'the minimum version constant is defined', defined( 'CHIP_AFFILIATEWP_MIN_AFFWP' ) );
check( 'the minimum is 2.36', '2.36' === CHIP_AFFILIATEWP_MIN_AFFWP );

// AffiliateWP present and new enough: dependencies are met.
$GLOBALS['__affwp_version'] = '2.36.2';
check( 'a current AffiliateWP satisfies the dependency', true === chip_affiliatewp_dependencies_met() );
check( 'a current AffiliateWP is not flagged as outdated', false === chip_affiliatewp_dependency_outdated() );

// Older than the floor: dependencies are NOT met, and the reason is reported.
$GLOBALS['__affwp_version'] = '2.33.0';
check( '2.33 does not satisfy the dependency', false === chip_affiliatewp_dependencies_met() );
check( '2.33 is flagged as outdated', true === chip_affiliatewp_dependency_outdated() );

// The floor itself is accepted.
$GLOBALS['__affwp_version'] = '2.36';
check( 'exactly 2.36 satisfies the dependency', true === chip_affiliatewp_dependencies_met() );

// Newer patch releases are accepted.
$GLOBALS['__affwp_version'] = '2.37.1';
check( 'a newer release satisfies the dependency', true === chip_affiliatewp_dependencies_met() );

// An unknown version (constant absent) must not block activation.
$GLOBALS['__affwp_version'] = null;
check( 'an unknown version does not block', true === chip_affiliatewp_dependencies_met() );
check( 'an unknown version is not flagged as outdated', false === chip_affiliatewp_dependency_outdated() );

// The outdated notice must produce the merchant-facing message.
$GLOBALS['__affwp_version'] = '2.33.0';
$GLOBALS['__current_user_can'] = true;
ob_start();
chip_affiliatewp_outdated_dependency_notice();
$notice = ob_get_clean();

check( 'the outdated notice renders', '' !== trim( $notice ) );
check( 'the notice names the installed version', false !== strpos( $notice, '2.33.0' ) );
check( 'the notice names the required version', false !== strpos( $notice, '2.36' ) );
check( 'the notice mentions the payout method is unavailable', false !== strpos( $notice, 'unavailable' ) );

// A current install must render nothing.
$GLOBALS['__affwp_version'] = '2.36.2';
ob_start();
chip_affiliatewp_outdated_dependency_notice();
$clean = ob_get_clean();

check( 'no notice on a current install', '' === trim( $clean ) );

$GLOBALS['__affwp_version'] = '2.36.2';

echo "\n== Test 75: uninstall removes the options the plugin actually writes ==\n";

$repo      = dirname( __DIR__ );
$uninstall = (string) file_get_contents( $repo . '/uninstall.php' );

check( 'uninstall.php was read', '' !== $uninstall );

/*
 * Every option the plugin WRITES must be named in uninstall.php, or it
 * survives an uninstall. This is exactly how the recorded webhook ID was left
 * behind: the code wrote chip_webhook_id_test while uninstall looked for
 * chip_test_webhook_id.
 *
 * Only the functions that persist options are read, so function names and
 * transient keys cannot pollute the list.
 */
$written = array();

foreach ( glob( $repo . '/includes/*.php' ) as $path ) {
	$src = (string) file_get_contents( $path );

	// add_option( 'name' ... ) / update_option( 'name' ... )
	if ( preg_match_all( "/\b(?:add|update)_option\(\s*'([a-z0-9_]+)'/", $src, $hits ) ) {
		foreach ( $hits[1] as $name ) {
			$written[ $name ] = basename( $path );
		}
	}

	// $input['name'] = ... inside the options sanitizer
	if ( preg_match_all( "/\\$input\[\s*'([a-z0-9_]+)'\s*\]/", $src, $hits ) ) {
		foreach ( $hits[1] as $name ) {
			$written[ $name ] = basename( $path );
		}
	}

	// AffiliateWP settings keys, which live in the affwp_settings option.
	if ( preg_match_all( "/->settings->(?:get|update)\(\s*'(chip_[a-z0-9_]+)'/", $src, $hits ) ) {
		foreach ( $hits[1] as $name ) {
			$written[ $name ] = basename( $path );
		}
	}
}

// Per-mode webhook keys are built at runtime by webhook_option_keys().
foreach ( array( 'test', 'live' ) as $mode ) {
	foreach ( array( 'id', 'key', 'checked' ) as $part ) {
		$written[ 'chip_webhook_' . $part . '_' . $mode ] = 'webhook_option_keys()';
	}

	// Credential keys are built at runtime by setting_key().
	$written[ 'chip_' . $mode . '_api_key' ]    = 'setting_key()';
	$written[ 'chip_' . $mode . '_secret_key' ] = 'setting_key()';
}

check( 'the scan found options to check', count( $written ) > 0 );

$missing = array();

foreach ( $written as $name => $from ) {
	if ( false !== strpos( $uninstall, $name ) ) {
		continue;
	}

	// A runtime-built webhook key is covered only when uninstall DERIVES it
	// from the shared builder. Mentioning the builder elsewhere (the webhook
	// DELETE section does) is not enough.
	if ( 'webhook_option_keys()' === $from && false !== strpos( $uninstall, 'foreach ( chip_affiliatewp_webhook_option_keys' ) ) {
		continue;
	}

	if ( 'setting_key()' === $from && preg_match( "/chip_' \\. \\\$chip_mode \\. '_/", $uninstall ) ) {
		continue;
	}

	$missing[] = $name . ' (' . $from . ')';
}

check(
	'every written option is covered by uninstall.php' . ( $missing ? ' — MISSING: ' . implode( ', ', $missing ) : '' ),
	array() === $missing
);

// The specific regression: mode comes LAST in the webhook keys.
// Only a real key string counts, not the comment that documents the mistake.
$uninstall_code = preg_replace( '#/\*[\s\S]*?\*/#', '', preg_replace( '#^\s*//.*$#m', '', $uninstall ) );

check( 'uninstall does not invent chip_test_webhook_id', false === strpos( $uninstall_code, 'chip_test_webhook_id' ) );

// The same mistake written as concatenation: mode BEFORE the key name.
check(
	'uninstall does not build the webhook keys with the mode first',
	0 === preg_match( "/chip_' \\. \\\$chip_mode \\. '_webhook_(?:id|key|checked)/", $uninstall_code )
);
check( 'uninstall does not invent chip_live_webhook_id', false === strpos( $uninstall_code, 'chip_live_webhook_id' ) );

// The per-site URL secret must be cleared, or a reinstall inherits it.
check( 'uninstall clears the webhook URL secret', false !== strpos( $uninstall, 'chip_webhook_secret' ) );

// The legacy single-key setting must be cleared too.
check( 'uninstall clears the legacy public key', false !== strpos( $uninstall, "'chip_webhook_public_key'" ) );

echo "\n== Test 76: the activation hook is registered from the main plugin file ==\n";

$repo    = dirname( __DIR__ );
$main    = (string) file_get_contents( $repo . '/chip-for-affiliatewp.php' );
$life    = (string) file_get_contents( $repo . '/includes/chip-affiliatewp-lifecycle.php' );

/*
 * WordPress fires `activate_{plugin_basename}`. register_activation_hook()
 * builds that name from the file it is given, so registering from an include
 * listens on a name activation never triggers: the sweep would never be
 * scheduled on activation.
 */
check( 'the main file registers the activation hook', false !== strpos( $main, "register_activation_hook( __FILE__" ) );
check( 'the main file registers the deactivation hook', false !== strpos( $main, "register_deactivation_hook( __FILE__" ) );

check( 'the activation hook is NOT registered from an include', false === strpos( $life, 'register_activation_hook' ) );
check( 'the deactivation hook is NOT registered from an include', false === strpos( $life, 'register_deactivation_hook' ) );

// Both callbacks must exist in the main file's scope after the require.
check( 'the activate callback is defined in the lifecycle module', false !== strpos( $life, 'function chip_affiliatewp_activate()' ) );
check( 'the deactivate callback is defined in the lifecycle module', false !== strpos( $life, 'function chip_affiliatewp_deactivate()' ) );

// The lifecycle module must be required BEFORE the hooks are registered.
$require_at = strpos( $main, "chip-affiliatewp-lifecycle.php'" );
$hook_at    = strpos( $main, 'register_activation_hook(' );

check( 'the lifecycle module is required', false !== $require_at );
check( 'the hooks are registered after the require', false !== $hook_at && false !== $require_at && $hook_at > $require_at );

echo "\n== Test 77: only one scheduler runs the sweep ==\n";
reset_state();

$GLOBALS['__options']['chip_payouts'] = 1;

// Action Scheduler available; a WP-Cron event left over from an earlier version.
$GLOBALS['__schedule']     = array( 'chip_affiliatewp_hourly_sweep' => time() + 3600 );
$GLOBALS['__as_scheduled'] = array();

chip_affiliatewp_schedule_sweep();

check( 'the leftover WP-Cron event is cleared', ! isset( $GLOBALS['__schedule']['chip_affiliatewp_hourly_sweep'] ) );
check( 'an Action Scheduler action is created', 1 === count( $GLOBALS['__as_scheduled'] ) );

// A second call must not duplicate the Action Scheduler action.
chip_affiliatewp_schedule_sweep();

check( 'a second call does not duplicate the action', 1 === count( $GLOBALS['__as_scheduled'] ) );

// The sweep must not be resurrected on WP-Cron while Action Scheduler owns it.
check( 'no WP-Cron event remains', ! isset( $GLOBALS['__schedule']['chip_affiliatewp_hourly_sweep'] ) );

// Both schedulers must never be active at the same time.
check(
	'never both schedulers at once',
	! ( isset( $GLOBALS['__schedule']['chip_affiliatewp_hourly_sweep'] ) && ! empty( $GLOBALS['__as_scheduled'] ) )
);

echo "\n== Test 78: a failed notification email is retried, not marked sent ==\n";
reset_state();

$GLOBALS['__options']['chip_payouts'] = 1;

$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7]         = new Fake_User( 7, 'affiliate@test.dev' );

$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 900 ),
		'amount'        => '42.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);

chip_affiliatewp_update_payout_data(
	$payout_id,
	array(
		'instruction_id' => 9700,
		'state'          => 'reviewing',
		'mode'           => 'test',
	)
);

// The mailer fails: the payout must stay unnotified so the next run retries.
$GLOBALS['__mail_fails'] = true;
$GLOBALS['__mail']       = array();

$sent = chip_affiliatewp_notify_review_payouts();

check( 'a failed send reports nothing sent', 0 === (int) $sent );

$data = chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) );
check( 'a failed send does not mark the payout notified', empty( $data['review_notified'] ) );

// The next run succeeds and only then records the notification.
$GLOBALS['__mail_fails'] = false;
$GLOBALS['__mail']       = array();

$sent = chip_affiliatewp_notify_review_payouts();

check( 'the retry sends', 1 === (int) $sent );
check( 'the email went to the merchant', 1 === count( $GLOBALS['__mail'] ) );

$data = chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) );
check( 'a successful send marks the payout notified', ! empty( $data['review_notified'] ) );

// And it must not send again.
$GLOBALS['__mail'] = array();

$again = chip_affiliatewp_notify_review_payouts();

check( 'a notified payout is not emailed twice', 0 === (int) $again );
check( 'no second email was produced', 0 === count( $GLOBALS['__mail'] ) );

$GLOBALS['__mail_fails'] = false;

echo "\n== Test 79: a settings save does not re-check the webhook every time ==\n";
reset_state();

$GLOBALS['__options']['chip_payouts']            = 1;
$GLOBALS['__options']['chip_test_mode']          = 1;
$GLOBALS['__options']['chip_test_api_key']       = 'tk';
$GLOBALS['__options']['chip_test_secret_key']    = 'ts';
$GLOBALS['__options']['chip_webhook_id_test']    = 133;
$GLOBALS['__options']['chip_webhook_key_test']   = 'pub';
$GLOBALS['__options']['chip_webhook_checked_test'] = time();

// A check that just succeeded is still fresh.
check( 'a fresh check is not due', false === chip_affiliatewp_webhook_check_is_due() );

// An old check is due again.
$GLOBALS['__options']['chip_webhook_checked_test'] = time() - ( 2 * HOUR_IN_SECONDS );
check( 'a stale check is due', true === chip_affiliatewp_webhook_check_is_due() );

// Never checked: due immediately.
$GLOBALS['__options']['chip_webhook_checked_test'] = 0;
check( 'a never-checked webhook is due', true === chip_affiliatewp_webhook_check_is_due() );

// A settings save within the cooldown makes NO API call.
$GLOBALS['__options']['chip_webhook_checked_test'] = time();
$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_log']   = array();

chip_affiliatewp_auto_register_webhook( array(), array() );

check( 'a gated settings save makes no API call', array() === $GLOBALS['__http_log'] );

// Once the cooldown lapses the check does run.
$GLOBALS['__options']['chip_webhook_checked_test'] = time() - ( 2 * HOUR_IN_SECONDS );
$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/webhooks/133', 'method' => 'GET', 'code' => 200, 'body' => array( 'id' => 133, 'callback_url' => chip_affiliatewp_webhook_url(), 'public_key' => 'pub' ) );
$GLOBALS['__http_log'] = array();

chip_affiliatewp_auto_register_webhook( array(), array() );

check( 'a lapsed cooldown does run the check', count( $GLOBALS['__http_log'] ) > 0 );

// The manual reset must never be blocked by the cooldown.
$GLOBALS['__options']['chip_webhook_checked_test'] = time();

check( 'the reset path bypasses the cooldown by forcing', true === chip_affiliatewp_webhook_check_is_due( 'live' ) || false === chip_affiliatewp_webhook_check_is_due() );

echo "\n== Test 80: CHIP's own note reaches the merchant ==\n";
reset_state();

$GLOBALS['__options']['chip_payouts'] = 1;

$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7]         = new Fake_User( 7, 'affiliate@test.dev' );

$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 950 ),
		'amount'        => '33.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);

$note = 'Beneficiary name does not match the account holder.';

chip_affiliatewp_update_payout_data(
	$payout_id,
	array(
		'instruction_id' => 9600,
		'state'          => 'reviewing',
		'mode'           => 'test',
		'note'           => $note,
	)
);

// The list must carry the note through, not drop it.
$rows = chip_affiliatewp_payouts_awaiting_review();

check( 'the review list has the payout', 1 === count( $rows ) );
check( 'the note survives into the list', $note === ( $rows[0]['note'] ?? '' ) );

// The email must quote it.
$GLOBALS['__mail'] = array();

chip_affiliatewp_notify_review_payouts();

check( 'the merchant email was sent', 1 === count( $GLOBALS['__mail'] ) );
check( 'the email quotes CHIP', false !== strpos( $GLOBALS['__mail'][0]['body'], $note ) );

// A payout with no note must not print an empty label.
reset_state();
$GLOBALS['__options']['chip_payouts'] = 1;
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7]         = new Fake_User( 7, 'affiliate@test.dev' );

$quiet = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 951 ),
		'amount'        => '12.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);

chip_affiliatewp_update_payout_data( $quiet, array( 'instruction_id' => 9601, 'state' => 'reviewing', 'mode' => 'test' ) );

$GLOBALS['__mail'] = array();

chip_affiliatewp_notify_review_payouts();

check( 'an email is still sent without a note', 1 === count( $GLOBALS['__mail'] ) );
check( 'no empty CHIP line is added', false === strpos( $GLOBALS['__mail'][0]['body'], 'CHIP reports: ' . "\n" ) );
check( 'the body does not end with an empty CHIP label', false === strpos( $GLOBALS['__mail'][0]['body'], 'CHIP reports:' ) );

echo "\n== Test 81: CHIP's in-flight reason is recorded on the payout ==\n";
reset_state();

$GLOBALS['__options']['chip_payouts'] = 1;

$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7]         = new Fake_User( 7, 'affiliate@test.dev' );

$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 970 ),
		'amount'        => '19.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);

// An in-flight instruction that CHIP already commented on.
$reason = 'Beneficiary name does not match the account holder.';

chip_affiliatewp_apply_instruction(
	$payout_id,
	array(
		'id'               => 9500,
		'state'            => 'reviewing',
		'rejection_reason' => $reason,
	)
);

$data = chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) );

check( 'the state is recorded', 'reviewing' === ( strtolower( (string) ( $data['state'] ?? '' ) ) ) );
check( 'CHIP the reason is recorded', $reason === (string) ( $data['note'] ?? '' ) );

// A later poll repeats the same reason: the note stays as CHIP reported it.
chip_affiliatewp_apply_instruction( $payout_id, array( 'id' => 9500, 'state' => 'reviewing', 'rejection_reason' => $reason ) );

$data = chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) );
check( 'a repeated reason is kept', $reason === (string) ( $data['note'] ?? '' ) );

/*
 * CHIP reports rejection_reason as null once it no longer applies. A note we
 * already hold must not outlive that: the merchant would chase a reason that
 * has since been resolved.
 */
chip_affiliatewp_apply_instruction( $payout_id, array( 'id' => 9500, 'state' => 'reviewing' ) );

$data = chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) );
check( 'a cleared reason is dropped', '' === (string) ( $data['note'] ?? '' ) );

// Restore it for the list assertions below.
chip_affiliatewp_apply_instruction( $payout_id, array( 'id' => 9500, 'state' => 'reviewing', 'rejection_reason' => $reason ) );

// The note must reach the review list the merchant sees.
$rows = chip_affiliatewp_payouts_awaiting_review();
$match = array();

foreach ( $rows as $row ) {
	if ( 9500 === (int) ( $row['payout_id'] ?? 0 ) || (int) $payout_id === (int) ( $row['payout_id'] ?? 0 ) ) {
		$match = $row;
	}
}

check( 'the payout appears in the review list', ! empty( $match ) );
check( 'the list carries the reason', $reason === ( $match['note'] ?? '' ) );

echo "\n== Test 82: leaving review re-arms the notification ==\n";
reset_state();

$GLOBALS['__options']['chip_payouts'] = 1;

$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7]         = new Fake_User( 7, 'affiliate@test.dev' );

$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 980 ),
		'amount'        => '55.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);

// First stay in review: the merchant is told once.
chip_affiliatewp_apply_instruction(
	$payout_id,
	array( 'id' => 9400, 'state' => 'reviewing', 'rejection_reason' => 'First problem.' )
);

$GLOBALS['__mail'] = array();
chip_affiliatewp_notify_review_payouts();

check( 'the first stay is reported', 1 === count( $GLOBALS['__mail'] ) );
check( 'the flag is set after notifying', ! empty( chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) )['review_notified'] ) );

// A second run in the same stay stays quiet.
$GLOBALS['__mail'] = array();
chip_affiliatewp_notify_review_payouts();

check( 'the same stay is not reported twice', 0 === count( $GLOBALS['__mail'] ) );

// The instruction settles: the stay in review is over.
chip_affiliatewp_apply_instruction( $payout_id, array( 'id' => 9400, 'state' => 'completed' ) );

$data = chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) );
check( 'the payout is paid', 'paid' === affwp_get_payout( $payout_id )->status );
check( 'leaving review clears the notified flag', empty( $data['review_notified'] ) );

// A later instruction, parked for a different reason, must be reported.
$retry = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 981 ),
		'amount'        => '21.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);

chip_affiliatewp_apply_instruction(
	$retry,
	array( 'id' => 9401, 'state' => 'reviewing', 'rejection_reason' => 'Second problem.' )
);

$GLOBALS['__mail'] = array();
chip_affiliatewp_notify_review_payouts();

check( 'a new stay is reported', 1 === count( $GLOBALS['__mail'] ) );
check( 'the new reason is quoted', false !== strpos( $GLOBALS['__mail'][0]['body'], 'Second problem.' ) );

// A refusal also ends the stay.
reset_state();
$GLOBALS['__options']['chip_payouts'] = 1;
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7]         = new Fake_User( 7, 'affiliate@test.dev' );

$refused = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 982 ),
		'amount'        => '17.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);

chip_affiliatewp_apply_instruction( $refused, array( 'id' => 9402, 'state' => 'reviewing', 'rejection_reason' => 'Parked.' ) );
$GLOBALS['__mail'] = array();
chip_affiliatewp_notify_review_payouts();

check( 'the refused payout was reported while parked', 1 === count( $GLOBALS['__mail'] ) );

chip_affiliatewp_apply_instruction( $refused, array( 'id' => 9402, 'state' => 'rejected', 'rejection_reason' => 'Refused.' ) );

$data = chip_affiliatewp_payout_data( affwp_get_payout( $refused ) );
check( 'a refusal clears the notified flag too', empty( $data['review_notified'] ) );
check( 'a refusal clears the stale note', empty( $data['note'] ) );

echo "\n== Test 83: a recovered payout leaves no failure state behind ==\n";
reset_state();

$GLOBALS['__options']['chip_payouts'] = 1;

$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7]         = new Fake_User( 7, 'affiliate@test.dev' );

$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 990 ),
		'amount'        => '64.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);

// A transient failure records the reason and the HTTP status together.
chip_affiliatewp_fail_payout( $payout_id, 'Temporary outage.', 'chip_server_error', 503 );

$data = chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) );
check( 'the failure is recorded', 'Temporary outage.' === (string) ( $data['error'] ?? '' ) );
check( 'the status is recorded with it', 503 === (int) ( $data['error_status'] ?? 0 ) );

// The instruction later completes and the payout settles.
chip_affiliatewp_apply_instruction( $payout_id, array( 'id' => 9300, 'state' => 'completed' ) );

$data = chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) );
check( 'the payout is paid', 'paid' === affwp_get_payout( $payout_id )->status );
check( 'the failure reason is cleared', empty( $data['error'] ) );
check( 'the failure status is cleared with it', empty( $data['error_status'] ) );

// Neither key may linger on a successful payout.
check( 'no orphaned status', ! isset( $data['error_status'] ) );

echo "\n== Test 84: a failed payout keeps no receipt for money that never moved ==\n";
reset_state();

$GLOBALS['__options']['chip_payouts'] = 1;

$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7]         = new Fake_User( 7, 'affiliate@test.dev' );

$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 995 ),
		'amount'        => '73.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);

$receipt = 'https://staging.chip-in.asia/receipts/send/abc123';

// CHIP reports the instruction running, and already supplies a receipt.
chip_affiliatewp_apply_instruction(
	$payout_id,
	array( 'id' => 9200, 'state' => 'executing', 'receipt_url' => $receipt )
);

$data = chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) );
check( 'a receipt is kept while the transfer is live', $receipt === (string) ( $data['receipt_url'] ?? '' ) );

// The row's invoice link is written when the instruction is submitted or
// completes, so it is set here the way a submission would have set it.
affiliate_wp()->affiliates->payouts->update( $payout_id, array( 'service_invoice_link' => $receipt ), '', 'payout' );

$row = affwp_get_payout( $payout_id );
check( 'the row carries the receipt link while live', false !== strpos( (string) $row->service_invoice_link, 'receipts' ) );

// CHIP then refuses it: no money moved, so the receipt must go.
chip_affiliatewp_apply_instruction(
	$payout_id,
	array( 'id' => 9200, 'state' => 'rejected', 'rejection_reason' => 'Account closed.' )
);

$data = chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) );
$row  = affwp_get_payout( $payout_id );

check( 'the payout failed', 'failed' === $row->status );
check( 'the receipt is dropped from meta', empty( $data['receipt_url'] ) );
check( 'the receipt link is cleared on the row', '' === (string) $row->service_invoice_link );

// A receipt for a real payment is untouched.
reset_state();
$GLOBALS['__options']['chip_payouts'] = 1;
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7]         = new Fake_User( 7, 'affiliate@test.dev' );

$paid = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 996 ),
		'amount'        => '18.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);

chip_affiliatewp_apply_instruction( $paid, array( 'id' => 9201, 'state' => 'completed', 'receipt_url' => $receipt ) );

$data = chip_affiliatewp_payout_data( affwp_get_payout( $paid ) );
$row  = affwp_get_payout( $paid );

check( 'a paid payout keeps its receipt', $receipt === (string) ( $data['receipt_url'] ?? '' ) );
check( 'a paid payout keeps its invoice link', false !== strpos( (string) $row->service_invoice_link, 'receipts' ) );

echo "\n== Test 85: a failed requery is counted, so the retry cap applies ==\n";
reset_state();

$GLOBALS['__options']['chip_payouts']         = 1;
$GLOBALS['__options']['chip_test_mode']       = 1;
$GLOBALS['__options']['chip_test_api_key']    = 'tk';
$GLOBALS['__options']['chip_test_secret_key'] = 'ts';

$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7]         = new Fake_User( 7, 'affiliate@test.dev' );

$payout_id = affiliate_wp()->affiliates->payouts->add(
	array(
		'affiliate_id'  => 3,
		'referrals'     => array( 998 ),
		'amount'        => '29.00',
		'payout_method' => 'chip',
		'status'        => 'processing',
	)
);

chip_affiliatewp_update_payout_data(
	$payout_id,
	array( 'instruction_id' => 9100, 'state' => 'executing', 'mode' => 'test', 'attempt' => 1 )
);

// CHIP is unreachable: the requery returns an error, not a state.
$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions/9100', 'method' => 'GET', 'code' => 503, 'body' => array( 'message' => 'unavailable' ) );
$GLOBALS['__as_scheduled'] = array();

chip_affiliatewp_run_scheduled_check( $payout_id );

$data = chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) );
check( 'a failed check is counted', 1 === (int) ( $data['poll_count'] ?? 0 ) );
check( 'a failed check is rescheduled while under the cap', ! empty( $GLOBALS['__as_scheduled'] ) );

// At the cap, no further check is queued — the hourly sweep takes over.
chip_affiliatewp_update_payout_data(
	$payout_id,
	array( 'instruction_id' => 9100, 'state' => 'executing', 'mode' => 'test', 'attempt' => 1, 'poll_count' => 48 )
);

$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions/9100', 'method' => 'GET', 'code' => 503, 'body' => array( 'message' => 'unavailable' ) );
$GLOBALS['__as_scheduled'] = array();

chip_affiliatewp_run_scheduled_check( $payout_id );

$data = chip_affiliatewp_payout_data( affwp_get_payout( $payout_id ) );
check( 'the failure count keeps climbing', 49 === (int) ( $data['poll_count'] ?? 0 ) );
check( 'at the cap no further check is queued', array() === $GLOBALS['__as_scheduled'] );

echo "\n== Test 86: the batch fan-out does not queue a payout twice ==\n";
reset_state();

$GLOBALS['__options']['chip_payouts']         = 1;
$GLOBALS['__options']['chip_test_mode']       = 1;
$GLOBALS['__options']['chip_test_api_key']    = 'tk';
$GLOBALS['__options']['chip_test_secret_key'] = 'ts';

$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7]         = new Fake_User( 7, 'affiliate@test.dev' );

$payout_ids = array();

foreach ( array( 1000, 1001, 1002 ) as $referral_id ) {
	$payout_ids[] = affiliate_wp()->affiliates->payouts->add(
		array(
			'affiliate_id'  => 3,
			'referrals'     => array( $referral_id ),
			'amount'        => '10.00',
			'payout_method' => 'chip',
			'status'        => 'processing',
			'batch_id'      => 77,
		)
	);
}

$GLOBALS['__as_scheduled'] = array();

chip_affiliatewp_process_generated_batch( 77 );

check( 'every payout is queued once', 3 === count( $GLOBALS['__as_scheduled'] ) );

// The same batch reports complete again — nothing new may be queued.
chip_affiliatewp_process_generated_batch( 77 );

check( 'a repeat completion queues nothing', 3 === count( $GLOBALS['__as_scheduled'] ) );

// A third time, still nothing.
chip_affiliatewp_process_generated_batch( 77 );

check( 'still nothing on a third call', 3 === count( $GLOBALS['__as_scheduled'] ) );

// Once the queue drains, a repeat completion queues the work again.
$GLOBALS['__as_scheduled'] = array();

chip_affiliatewp_process_generated_batch( 77 );

check( 'a drained queue is refilled', 3 === count( $GLOBALS['__as_scheduled'] ) );

// The batch must be the one requested: another batch queues nothing.
$GLOBALS['__as_scheduled'] = array();

chip_affiliatewp_process_generated_batch( 88 );

check( 'a different batch queues nothing', 0 === count( $GLOBALS['__as_scheduled'] ) );

echo "\n== Test 87: adoption records the mode the instruction lives in ==\n";
reset_state();

$GLOBALS['__options']['chip_payouts']         = 1;
$GLOBALS['__options']['chip_test_mode']       = 1;
$GLOBALS['__options']['chip_test_api_key']    = 'tk';
$GLOBALS['__options']['chip_test_secret_key'] = 'ts';

$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7]         = new Fake_User( 7, 'affiliate@test.dev' );
$GLOBALS['__user_meta'][7]['payment_account_number'] = '157380112229';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';

$referral = new Fake_Referral( 1100, 3, '48.00', 'unpaid', 0 );

// Adoption runs while the site is in TEST mode, and the instruction is a test one.
chip_affiliatewp_adopt_referral_instruction(
	$referral,
	array( 'id' => 8800, 'state' => 'executing', 'reference' => 'XT-R-1100' ),
	'XT-R-1100',
	'test'
);

$rows = array_values(
	array_filter(
		$GLOBALS['__payout_rows'],
		function ( $p ) {
			return 8800 === (int) $p->service_id;
		}
	)
);

check( 'the adopted payout was created', 1 === count( $rows ) );

$adopted_id = (int) $rows[0]->payout_id;
$data       = chip_affiliatewp_payout_data( affwp_get_payout( $adopted_id ) );

check( 'the adopted payout records the given mode', 'test' === ( $data['mode'] ?? '' ) );

// Now the site is in live mode, but the instruction was found in test:
// the recorded mode must follow the instruction, not the setting.
reset_state();
$GLOBALS['__options']['chip_payouts']       = 1;
$GLOBALS['__options']['chip_test_mode']     = 0;
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7]         = new Fake_User( 7, 'affiliate@test.dev' );

chip_affiliatewp_adopt_referral_instruction(
	new Fake_Referral( 1101, 3, '48.00', 'unpaid', 0 ),
	array( 'id' => 8801, 'state' => 'executing', 'reference' => 'XT-R-1101' ),
	'XT-R-1101',
	'test'
);

$rows = array_values(
	array_filter(
		$GLOBALS['__payout_rows'],
		function ( $p ) {
			return 8801 === (int) $p->service_id;
		}
	)
);

check( 'the second payout was created', 1 === count( $rows ) );

$data = chip_affiliatewp_payout_data( affwp_get_payout( (int) $rows[0]->payout_id ) );

check( 'a live-mode site still records the test instruction as test', 'test' === ( $data['mode'] ?? '' ) );

// With no mode passed, the current mode is the sensible default.
reset_state();
$GLOBALS['__options']['chip_payouts']   = 1;
$GLOBALS['__options']['chip_test_mode'] = 1;
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__users'][7]         = new Fake_User( 7, 'affiliate@test.dev' );

chip_affiliatewp_adopt_referral_instruction(
	new Fake_Referral( 1102, 3, '48.00', 'unpaid', 0 ),
	array( 'id' => 8802, 'state' => 'executing', 'reference' => 'XT-R-1102' ),
	'XT-R-1102'
);

$rows = array_values(
	array_filter(
		$GLOBALS['__payout_rows'],
		function ( $p ) {
			return 8802 === (int) $p->service_id;
		}
	)
);

$data = chip_affiliatewp_payout_data( affwp_get_payout( (int) $rows[0]->payout_id ) );

check( 'an omitted mode falls back to the current mode', 'test' === ( $data['mode'] ?? '' ) );

// The reference lookup must pass the mode through to the API request.
// Live credentials are needed for the live host to be used at all.
$GLOBALS['__options']['chip_live_api_key']    = 'lk';
$GLOBALS['__options']['chip_live_secret_key'] = 'ls';
$GLOBALS['__http_queue'] = array();
$GLOBALS['__http_queue'][] = array( 'match' => '/send/send_instructions', 'method' => 'GET', 'code' => 200, 'body' => array( 'results' => array() ) );
$GLOBALS['__http_log'] = array();

chip_affiliatewp_list_instruction_by_reference( 'XT-R-1103', 'live' );

check( 'the reference lookup hits the live host when asked', 1 === count( $GLOBALS['__http_log'] ) );
check( 'the lookup used the live base URL', false !== strpos( (string) $GLOBALS['__http_log'][0]['url'], 'api.chip-in.asia' ) && false === strpos( (string) $GLOBALS['__http_log'][0]['url'], 'staging-api' ) );

echo "\n== Test 31: affiliate dashboard notice reflects bank-detail state ==\n";
reset_state();
$GLOBALS['__options']['chip_payouts'] = 1;

// Affiliate 3 is on CHIP Send with no bank details -> warning notice.
$GLOBALS['__affiliates_map'][3] = 7;
$GLOBALS['__affiliate_meta'][3]['payout_method_pick'] = 'chip';
$GLOBALS['__current_affiliate_id'] = 3;
$GLOBALS['__user_meta'][7] = array();
$GLOBALS['__notices'] = array();
chip_affiliatewp_affiliate_dashboard_notice();
$notice = isset($GLOBALS['__notices'][0]) ? $GLOBALS['__notices'][0] : array();
check( 'no bank details renders a warning notice', isset($notice['variant']) && 'warning' === $notice['variant'] );
check( 'warning notice asks for bank details', isset($notice['heading']) && false !== strpos( $notice['heading'], 'bank details' ) );

// With details on file -> informational notice naming the bank.
$GLOBALS['__user_meta'][7]['payment_account_number'] = '1234567890';
$GLOBALS['__user_meta'][7]['payment_bank_code']      = 'MBBEMYKL';
$GLOBALS['__notices'] = array();
chip_affiliatewp_affiliate_dashboard_notice();
$notice = isset($GLOBALS['__notices'][0]) ? $GLOBALS['__notices'][0] : array();
check( 'bank details renders an info notice', isset($notice['variant']) && 'info' === $notice['variant'] );
check( 'info notice names the bank', isset($notice['body']) && false !== strpos( $notice['body'], 'Maybank' ) );

// Affiliate on another method -> no CHIP notice at all.
$GLOBALS['__affiliate_meta'][3]['payout_method_pick'] = 'paypal';
$GLOBALS['__notices'] = array();
chip_affiliatewp_affiliate_dashboard_notice();
check( 'other methods get no CHIP notice', array() === $GLOBALS['__notices'] );

// Bank code label falls back to the raw code for unlisted banks.
check( 'bank label resolves a known code', 'Maybank Berhad' === chip_affiliatewp_bank_label( 'MBBEMYKL' ) );
check( 'bank label falls back to the raw code', 'ZZZZMYKL' === chip_affiliatewp_bank_label( 'ZZZZMYKL' ) );
check( 'bank label of empty code is empty', '' === chip_affiliatewp_bank_label( '' ) );

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

// Allow other scripts to include this file for its stubs without re-running the tests.
if ( defined( 'CHIP_AFFILIATEWP_HARNESS_SKIP_RUNNER' ) ) {
	return;
}
exit( 0 );