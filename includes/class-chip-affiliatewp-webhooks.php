<?php
/**
 * CHIP Send webhook auto-registration and inbound webhook handling.
 *
 * @package CHIPforAffiliateWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Cannot access directly.

/**
 * Builds the plugin webhook URL.
 *
 * Prefers HTTPS when the request that registered the URL was made over SSL
 * (or an HTTPS-aware proxy header is present): CHIP Send deliveries must not
 * hop through an HTTP->HTTPS redirect, because some HTTP clients rewriting
 * the redirect change POST to GET and land on a 404.
 *
 * @return string
 */
function chip_affiliatewp_webhook_url() {
	$url = rest_url( 'chip-affiliatewp/v1/webhook' );

	if ( is_ssl() ) {
		$url = preg_replace( '#^http:#', 'https:', $url );
	}

	return $url;
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
			'status'        => array( 'processing', 'paid', 'failed' ),
			'service_id'    => $instruction_id,
			'number'        => 1,
		)
	);

	if ( ! empty( $payouts ) ) {
		$found = is_array( $payouts ) ? array_shift( $payouts ) : $payouts;

		if ( is_object( $found ) ) {
			return absint( $found->payout_id );
		}

		return absint( $found );
	}

	return 0;
}

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
