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
 * The URL carries a per-site secret path suffix (same pattern as
 * chip-for-givewp): every WordPress install gets a different webhook URL,
 * so the endpoint cannot be discovered by scanning for a fixed path. The
 * secret is generated once per install and reused. Prefers HTTPS when the
 * request context is SSL: CHIP Send deliveries must not hop through an
 * HTTP->HTTPS redirect, because some HTTP clients rewriting the redirect
 * change POST to GET and land on a 404.
 *
 * @return string
 */
function chip_affiliatewp_webhook_url() {
	$url = rest_url( 'chip-affiliatewp/v1/webhook/' . chip_affiliatewp_webhook_secret() );

	if ( is_ssl() ) {
		$url = preg_replace( '#^http:#', 'https:', $url );
	}

	return $url;
}

/**
 * Returns the per-site webhook URL secret, generating it on first use.
 *
 * @return string 32-char hex secret.
 */
function chip_affiliatewp_webhook_secret() {
	$secret = affiliate_wp()->settings->get( 'chip_webhook_secret', '' );

	if ( ! is_string( $secret ) || '' === $secret ) {
		$secret = bin2hex( random_bytes( 16 ) );
		affiliate_wp()->settings->set( array( 'chip_webhook_secret' => $secret ) );
	}

	return $secret;
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

	/*
	 * Manual key, checked for this mode first. The single-key fallback only
	 * counts when it was set for a webhook we cannot otherwise identify.
	 */
	if ( '' !== trim( (string) affiliate_wp()->settings->get( 'chip_webhook_public_key_' . $mode, '' ) ) ) {
		return true;
	}

	$has_id  = ! empty( affiliate_wp()->settings->get( $keys['id'], '' ) );
	$has_key = ! empty( affiliate_wp()->settings->get( $keys['key'], '' ) );

	// Auto-registered for this mode: both halves are present.
	if ( $has_id && $has_key ) {
		return true;
	}

	// A legacy single manual key with no per-mode webhook recorded.
	if ( '' !== trim( (string) affiliate_wp()->settings->get( 'chip_webhook_public_key', '' ) ) && ! $has_id ) {
		return true;
	}

	return false;
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
			} elseif ( (string) chip_affiliatewp_array_value( $details, 'callback_url' ) === $url ) {
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
		// Same-named webhook from an older site URL; repoint it here. The
		// CHIP Send API PATCH endpoint only applies the name field, so a
		// repoint requires deleting the stale webhook and creating a new
		// one with the current callback URL.
		$deleted = chip_affiliatewp_request( 'DELETE', '/webhooks/' . $stale_id );

		if ( is_wp_error( $deleted ) ) {
			$response = $deleted;
		} else {
			$response = chip_affiliatewp_request( 'POST', '/webhooks', $body );
		}
	} else {
		$response = chip_affiliatewp_request( 'POST', '/webhooks', $body );
	}

	if ( is_wp_error( $response ) ) {
		// A conflict during create means the webhook already exists; re-discover it.
		if ( ! $existing_id && ! $stale_id ) {
			$retry = chip_affiliatewp_request( 'GET', '/webhooks' );

			if ( ! is_wp_error( $retry ) && isset( $retry['results'] ) && is_array( $retry['results'] ) ) {
				foreach ( $retry['results'] as $row ) {
					if ( (string) chip_affiliatewp_array_value( $row, 'callback_url' ) === $url ) {
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

	$webhook_id = absint( chip_affiliatewp_array_value( $response, 'id', $existing_id ? $existing_id : $stale_id ) );

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
 * Resolves the webhook public key for a mode.
 *
 * Test and live are separate webhook objects at CHIP, each with its own public
 * key, so the key has to be resolved per mode. A single shared override would
 * verify test deliveries against the live key (and the reverse) and fail every
 * signature check. Manual key wins; otherwise the key captured by
 * auto-registration.
 *
 * @param string|null $mode "test" or "live". Defaults to the current mode.
 * @return string PEM public key, or empty string when unavailable.
 */
function chip_affiliatewp_webhook_public_key( $mode = null ) {
	$mode = in_array( $mode, array( 'test', 'live' ), true )
		? $mode
		: ( affiliate_wp()->settings->get( 'chip_test_mode' ) ? 'test' : 'live' );

	$manual = trim( (string) affiliate_wp()->settings->get( 'chip_webhook_public_key_' . $mode, '' ) );

	// Legacy single-key setting, read as a fallback so existing installs keep working.
	if ( '' === $manual ) {
		$manual = trim( (string) affiliate_wp()->settings->get( 'chip_webhook_public_key', '' ) );
	}

	if ( '' !== $manual ) {
		return $manual;
	}

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
	$raw        = (string) $request->get_body();
	$signature  = (string) $request->get_header( 'X-Signature' );
	$event_type = (string) $request->get_header( 'Event-Type' );

	/*
	 * Bound the body before doing any crypto. The URL carries a per-site
	 * secret, but if it ever leaks, an unbounded body would let a caller burn
	 * CPU on RSA verification of an arbitrarily large payload. CHIP's own
	 * deliveries are a few KB, so anything past the cap is not a real event.
	 */
	// 128 KiB: comfortably above CHIP's real deliveries, well below anything abusive.
	$max_body = (int) apply_filters( 'chip_affiliatewp_webhook_max_body_bytes', 131072 );

	if ( strlen( $raw ) > $max_body ) {
		return new WP_Error( 'chip_webhook_too_large', __( 'Payload too large.', 'chip-for-affiliatewp' ), array( 'status' => 413 ) );
	}

	/*
	 * Try every configured key, not just the current mode's.
	 *
	 * A webhook belongs to the mode that created it, and a merchant can flip
	 * modes while deliveries for the other one are still in flight — a test
	 * payout completing after the switch to live is the ordinary case. Checking
	 * only the current mode's key would reject those signed deliveries as
	 * forgeries, leaving the payout to be healed by the hourly sweep instead of
	 * settling immediately.
	 *
	 * Trying both keys does not widen the trust: each is a public key CHIP
	 * issued for this site's own webhook, and the signature must still verify
	 * against the exact raw body.
	 */
	$public_keys = array();

	foreach ( array( 'test', 'live' ) as $candidate_mode ) {
		$candidate = chip_affiliatewp_webhook_public_key( $candidate_mode );

		if ( '' !== $candidate ) {
			$public_keys[ $candidate_mode ] = $candidate;
		}
	}

	if ( empty( $public_keys ) ) {
		return new WP_Error( 'chip_webhook_unconfigured', __( 'Webhook signature verification is not configured yet.', 'chip-for-affiliatewp' ), array( 'status' => 503 ) );
	}

	if ( '' === $signature ) {
		return new WP_Error( 'chip_webhook_missing_signature', __( 'Missing signature.', 'chip-for-affiliatewp' ), array( 'status' => 401 ) );
	}

	$signature_bytes = (string) base64_decode( $signature, true );

	if ( '' === $signature_bytes ) {
		return new WP_Error( 'chip_webhook_invalid_signature', __( 'Signature verification failed.', 'chip-for-affiliatewp' ), array( 'status' => 401 ) );
	}

	$verified_mode = '';

	foreach ( $public_keys as $candidate_mode => $candidate_key ) {
		$key_object = openssl_pkey_get_public( $candidate_key );

		if ( false === $key_object ) {
			continue;
		}

		if ( 1 === openssl_verify( $raw, $signature_bytes, $key_object, OPENSSL_ALGO_SHA512 ) ) {
			$verified_mode = $candidate_mode;
			break;
		}
	}

	if ( '' === $verified_mode ) {
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

	/*
	 * A bank account changing status invalidates the record we cached for the
	 * affiliate. CHIP sends this when an account moves — notably verified to
	 * rejected, long after we stored it as good. Leaving the stale copy means
	 * the next payout resolves a dead account, is refused, and the affiliate
	 * only learns about it from a failed payout instead of straight away.
	 *
	 * The payload is used as a signal to re-read, not as the new record: the
	 * cached shape is what the read path expects, and re-reading keeps CHIP the
	 * source of truth. Nothing is written from the payload itself, so a payload
	 * we do not fully recognise cannot corrupt the cache.
	 */
	if ( 'bank_account_status' === $event_type ) {
		chip_affiliatewp_forget_cached_bank_account_from_webhook( $payload );

		return rest_ensure_response( array( 'handled' => 'bank_account_refreshed' ) );
	}

	// Budget allocation events carry no local state to update.
	if ( 'budget_allocation_status' === $event_type
		|| ( ! isset( $payload['state'] ) && isset( $payload['status'] ) ) ) {
		return rest_ensure_response( array( 'handled' => 'ignored' ) );
	}

	if ( 'send_instruction_status' !== $event_type && ! isset( $payload['state'] ) ) {
		return rest_ensure_response( array( 'handled' => 'ignored' ) );
	}

	if ( empty( $payload['id'] ) || ! isset( $payload['state'] ) ) {
		return new WP_Error( 'chip_webhook_incomplete', __( 'The payload is missing a send instruction ID or state.', 'chip-for-affiliatewp' ), array( 'status' => 400 ) );
	}

	chip_affiliatewp_process_instruction_webhook( $payload );

	return rest_ensure_response( array( 'handled' => true ) );
}

/**
 * Forgets a cached bank account when CHIP reports a status change.
 *
 * The payload is only trusted well enough to identify WHICH affiliate to
 * refresh — the account reference, which is derived from the affiliate's
 * details. The cached record is then dropped so the next payout re-reads the
 * current status from CHIP. Nothing is written from the payload, so a change in
 * its shape cannot leave the cache holding a record CHIP never sent.
 *
 * @param array $payload Webhook payload.
 * @return void
 */
function chip_affiliatewp_forget_cached_bank_account_from_webhook( $payload ) {
	$reference = (string) chip_affiliatewp_array_value( $payload, 'reference', '' );

	if ( '' === $reference ) {
		return;
	}

	/*
	 * Bank references look like "<prefix>-AFF-<affiliate_id>-<hash>". Parsing
	 * the affiliate out of it is what tells us whose cache to drop.
	 */
	if ( ! preg_match( '/-AFF-(\d+)/', $reference, $matches ) ) {
		return;
	}

	$affiliate_id = absint( $matches[1] );

	if ( ! $affiliate_id ) {
		return;
	}

	/**
	 * Filters whether a bank-account status webhook clears the cached record.
	 *
	 * Return false to keep the cache, e.g. when another integration owns the
	 * account and the local copy is deliberately not refreshed.
	 *
	 * @param bool  $forget       Whether to drop the cache. Default true.
	 * @param int   $affiliate_id Affiliate ID.
	 * @param array $payload      Webhook payload.
	 */
	if ( ! apply_filters( 'chip_affiliatewp_forget_bank_account_on_webhook', true, $affiliate_id, $payload ) ) {
		return;
	}

	chip_affiliatewp_forget_bank_account( $affiliate_id );
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

	/*
	 * Serialize processing per instruction so duplicate or racing deliveries
	 * cannot double-apply. GET_LOCK/RELEASE_LOCK are MySQL advisory locks, not
	 * data reads: there is nothing to cache, and they must hit the database to
	 * be atomic across concurrent workers.
	 */
	$lock_name = 'chip_affiliatewp_' . md5( 'instruction_' . ( $instruction_id ? $instruction_id : $payout_id ) );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- advisory lock, not a data read.
	$lock_value = 'mysql' === ( $GLOBALS['wpdb']->is_mysql ? 'mysql' : 'other' ) ? $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) ) : null;
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	if ( null !== $lock_value && '1' !== (string) $lock_value ) {
		// Another worker is already handling this exact delivery.
		return;
	}

	try {
		chip_affiliatewp_apply_instruction( $payout_id, $payload );
	} finally {
		if ( null !== $lock_value && '1' === (string) $lock_value ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releases the advisory lock above.
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
 * The route path carries a per-site secret suffix: each WordPress install
 * therefore has a different webhook URL, and requests to the bare path are
 * answered 404 so the endpoint cannot be discovered by scanning.
 *
 * @return void
 */
function chip_affiliatewp_register_rest_route() {
	$secret_suffix = chip_affiliatewp_webhook_secret();

	register_rest_route(
		'chip-affiliatewp/v1',
		'/webhook',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'chip_affiliatewp_handle_webhook_not_found',
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		'chip-affiliatewp/v1',
		'/webhook/' . $secret_suffix,
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'chip_affiliatewp_handle_webhook',
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * Answers requests to the undecorated webhook path with a 404.
 *
 * @return WP_Error
 */
function chip_affiliatewp_handle_webhook_not_found() {
	return new WP_Error( 'rest_no_route', __( 'No route was found matching the URL and request method.', 'chip-for-affiliatewp' ), array( 'status' => 404 ) );
}
add_action( 'rest_api_init', 'chip_affiliatewp_register_rest_route' );
