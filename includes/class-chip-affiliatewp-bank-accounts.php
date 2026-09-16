<?php
/**
 * Affiliate bank details storage and CHIP Send bank-account sync.
 *
 * @package CHIPforAffiliateWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Cannot access directly.

/**
 * Reads the affiliate's saved payout bank details.
 *
 * @param int $affiliate_id Affiliate ID.
 * @return array { account_number: string, bank_code: string }
 */
function chip_affiliatewp_get_bank_details( $affiliate_id ) {
	$user_id = affwp_get_affiliate_user_id( $affiliate_id );
	$code    = (string) get_user_meta( $user_id, 'payment_bank_code', true );

	return array(
		'account_number' => (string) get_user_meta( $user_id, 'payment_account_number', true ),
		'bank_code'      => $code,
		'bank_name'      => chip_affiliatewp_bank_label( $code ),
	);
}

/**
 * Returns a human-readable label for a bank code.
 *
 * Falls back to the raw code so a bank we do not list still renders something
 * meaningful rather than an empty string.
 *
 * @param string $code Bank code.
 * @return string
 */
function chip_affiliatewp_bank_label( $code ) {
	$code = strtoupper( trim( (string) $code ) );

	if ( '' === $code ) {
		return '';
	}

	$banks = chip_affiliatewp_bank_codes();

	return isset( $banks[ $code ] ) ? $banks[ $code ] : $code;
}

/**
 * Returns the reference prefix used for every CHIP Send reference.
 *
 * CHIP stores a reference permanently and refuses a repeat, so the prefix is
 * what keeps two installations apart when they share one CHIP account: without
 * it, payout 5 on site A and payout 5 on site B would both be "-PO-5", and the
 * second site's submission would be refused as a duplicate of the first's
 * instruction.
 *
 * The stored setting is trimmed to two alphanumerics. When nothing usable is
 * left — a merchant cleared the field, or typed punctuation — a stable
 * per-site value derived from the site URL is used instead, so references
 * remain distinct without the merchant having to notice.
 *
 * @return string
 */
function chip_affiliatewp_reference_prefix() {
	// One canonical form: references must not differ by case, or the same
	// payout could produce two references and two instructions.
	$prefix = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) affiliate_wp()->settings->get( 'chip_reference_prefix' ) ) );
	$prefix = substr( $prefix, 0, 2 );

	if ( '' !== $prefix ) {
		return $prefix;
	}

	/*
	 * Derive from the site URL: stable across requests, distinct between
	 * installations, and not something the merchant has to choose.
	 */
	$fallback = strtoupper( substr( md5( (string) home_url() ), 0, 2 ) );

	/**
	 * Filters the fallback reference prefix used when none is configured.
	 *
	 * The prefix is capped: a CHIP reference is 40 characters, and the attempt
	 * number sits at the end of it. A prefix long enough to push the attempt
	 * past the cut makes every retry reuse the reference CHIP has already
	 * refused — the retry is rejected, adopts the dead instruction, and the
	 * payout can never be paid.
	 *
	 * @param string $fallback Two-character prefix.
	 */
	$fallback = (string) apply_filters( 'chip_affiliatewp_reference_prefix_fallback', $fallback );

	return substr( strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $fallback ) ), 0, 4 );
}

/**
 * Returns a stable per-affiliate bank account reference, derived from the
 * account details themselves.
 *
 * A reference derived from the account number and bank code means CHIP can
 * reject duplicate submissions for identical recipients, while corrected
 * details produce a fresh reference instead of colliding with a rejected
 * registration.
 *
 * Both this reference and the stored-account fingerprint normalize the same
 * way (upper-cased bank code, digits-only account number) so that cosmetic
 * differences — "1234-567 890" versus "1234567890" — resolve to one CHIP
 * account rather than registering a second one for the same recipient.
 *
 * @param int $affiliate_id Affiliate ID.
 * @return string
 */
function chip_affiliatewp_bank_reference( $affiliate_id ) {
	$hash = substr( chip_affiliatewp_bank_details_fingerprint( $affiliate_id ), 0, 6 );

	return substr( chip_affiliatewp_reference_prefix() . '-AFF-' . $affiliate_id . '-' . $hash, 0, 40 );
}

/**
 * Returns the send instruction reference for a payout attempt.
 *
 * CHIP treats the reference as the idempotency key: it is stored permanently
 * and a repeated value is refused with `422 Reference already exists`. That
 * gives the reference two jobs that pull in opposite directions, so it carries
 * an attempt number.
 *
 * - Within one attempt the value is stable, which is what makes a retry after
 *   an unclear response (a timeout where CHIP may or may not have accepted the
 *   instruction) safe: the repeat is refused, the existing instruction is
 *   adopted, and no second payment is created.
 * - Once CHIP has definitively refused an instruction, the next attempt needs a
 *   NEW reference. Reusing the old one would be refused too, and adoption would
 *   find the dead instruction and park the payout on it forever — the retry
 *   would look successful while nothing moved.
 *
 * The first attempt keeps the bare `{prefix}-PO-{id}` form so references
 * written by earlier versions still resolve.
 *
 * @param int $payout_id Payout ID.
 * @param int $attempt   Attempt number, 1-based.
 * @return string
 */
function chip_affiliatewp_instruction_reference( $payout_id, $attempt = 1 ) {
	$payout_id = absint( $payout_id );
	$attempt   = max( 1, absint( $attempt ) );
	$base      = chip_affiliatewp_reference_prefix() . '-PO-' . $payout_id;

	if ( $attempt > 1 ) {
		$base .= '-' . $attempt;
	}

	return substr( $base, 0, 40 );
}

/**
 * Returns the attempt number for a payout's next submission.
 *
 * Stored in payout meta and incremented only when an attempt ends with CHIP
 * definitively refusing the instruction.
 *
 * @param array $data Payout meta.
 * @return int Attempt number, 1-based.
 */
function chip_affiliatewp_payout_attempt( $data ) {
	$attempt = isset( $data['attempt'] ) ? absint( $data['attempt'] ) : 1;

	return max( 1, $attempt );
}

/**
 * Retrieves the affiliate's CHIP Send bank account registered under its stable reference.
 *
 * Reads the stored account first: the id is stable for a given set of bank
 * details, so the common path costs one user-meta read instead of an API call.
 * The CHIP API is only consulted when nothing is stored, or when the stored
 * record no longer matches the current details (a changed account number or
 * bank invalidates the id — reusing it would pay the old account).
 *
 * @param int         $affiliate_id Affiliate ID.
 * @param string|null $mode         'test' or 'live'; null uses the current mode.
 * @return array|null Bank account record, or null when none exists.
 */
function chip_affiliatewp_get_bank_account( $affiliate_id, $mode = null ) {
	$reference = chip_affiliatewp_bank_reference( $affiliate_id );
	$mode      = in_array( $mode, array( 'test', 'live' ), true ) ? $mode : chip_affiliatewp_current_mode();

	$stored = chip_affiliatewp_get_stored_bank_account( $affiliate_id, $reference, $mode );

	if ( null !== $stored ) {
		return $stored;
	}

	$response = chip_affiliatewp_request(
		'GET',
		'/send/bank_accounts',
		array(),
		array(
			'page'      => 1,
			'limit'     => 25,
			'reference' => $reference,
		),
		$mode
	);

	if ( is_wp_error( $response ) || empty( $response['results'] ) || ! is_array( $response['results'] ) ) {
		return null;
	}

	foreach ( $response['results'] as $account ) {
		if ( isset( $account['reference'] ) && $reference === (string) $account['reference'] ) {
			chip_affiliatewp_store_bank_account( $affiliate_id, $account, $mode );

			return $account;
		}
	}

	return null;
}

/**
 * Returns the stored bank account record for an affiliate, when still valid.
 *
 * A stored record is only reused while its fingerprint matches the affiliate's
 * current bank details. Anything else — changed details, a deleted account, a
 * record written before fingerprints existed — falls through to a fresh lookup.
 *
 * @param int         $affiliate_id Affiliate ID.
 * @param string      $reference    Expected CHIP reference.
 * @param string|null $mode         Optional. Mode the record belongs to.
 * @return array|null Stored record, or null when it must be re-fetched.
 */
function chip_affiliatewp_get_stored_bank_account( $affiliate_id, $reference, $mode = null ) {
	$user_id = affwp_get_affiliate_user_id( $affiliate_id );
	$mode    = null === $mode ? chip_affiliatewp_current_mode() : ( 'test' === $mode ? 'test' : 'live' );

	$records = get_user_meta( $user_id, 'chip_bank_account', true );

	/*
	 * Records written before the cache became mode-aware are a single flat
	 * array with no reliable mode marker. Guessing which environment issued
	 * them is unsafe: defaulting a test-mode ID into live would pay whoever
	 * owns that ID live, and the reverse is merely wrong. Treat such a record
	 * as unusable and let the next payout re-register the account in the mode
	 * actually in use — one extra API call, never a wrong payment.
	 *
	 * A record that does carry a mode was written by the mode-aware version and
	 * is grouped under that mode.
	 */
	if ( is_array( $records ) && isset( $records['id'] ) ) {
		$legacy_mode = (string) ( $records['mode'] ?? '' );

		$records = in_array( $legacy_mode, array( 'test', 'live' ), true )
			? array( $legacy_mode => $records )
			: array();
	}

	$record = is_array( $records ) && isset( $records[ $mode ] ) ? $records[ $mode ] : null;

	/*
	 * A CHIP account ID is scoped to the environment that issued it: the same
	 * number means a different account in test and in live. Reusing a test ID
	 * against live would at best fail and at worst name somebody else's
	 * account, so the cache is kept per mode and an ID is only ever used in the
	 * mode it came from.
	 */
	if ( ! is_array( $record ) || empty( $record['id'] ) ) {
		return null;
	}

	// A deleted account must never be reused.
	if ( ! empty( $record['deleted_at'] ) ) {
		return null;
	}

	/*
	 * A rejected account is equally unusable: CHIP will not accept a payout to
	 * it, so the caller must go through a fresh registration (and surface the
	 * rejection) rather than silently reusing the id. The reference is derived
	 * from the details, so unchanged details reuse the same reference — the
	 * lookup then returns the rejected record and the payout fails with the
	 * rejection reason, which is what the affiliate needs to see.
	 */
	if ( 'rejected' === (string) chip_affiliatewp_array_value( $record, 'status' ) ) {
		return null;
	}

	if ( (string) chip_affiliatewp_array_value( $record, 'reference' ) !== (string) $reference ) {
		return null;
	}

	if ( (string) chip_affiliatewp_array_value( $record, 'fingerprint' ) !== chip_affiliatewp_bank_details_fingerprint( $affiliate_id ) ) {
		return null;
	}

	return $record;
}

/**
 * Returns the mode the site is currently configured for.
 *
 * @return string 'test' or 'live'.
 */
function chip_affiliatewp_current_mode() {
	return affiliate_wp()->settings->get( 'chip_test_mode' ) ? 'test' : 'live';
}

/**
 * Stores a CHIP Send bank account record against the affiliate.
 *
 * Kept per mode: the same CHIP account ID means different things in test and
 * live, so each environment's record is stored separately.
 *
 * @param int         $affiliate_id Affiliate ID.
 * @param array       $account      Bank account record returned by CHIP.
 * @param string|null $mode         Optional. Mode the record came from.
 * @return void
 */
function chip_affiliatewp_store_bank_account( $affiliate_id, $account, $mode = null ) {
	$user_id = affwp_get_affiliate_user_id( $affiliate_id );
	$mode    = null === $mode ? chip_affiliatewp_current_mode() : ( 'test' === $mode ? 'test' : 'live' );

	$account['fingerprint'] = chip_affiliatewp_bank_details_fingerprint( $affiliate_id );
	$account['mode']        = $mode;

	$records = get_user_meta( $user_id, 'chip_bank_account', true );
	$records = is_array( $records ) ? $records : array();

	// Migrate a record written by an older version, which stored one flat array.
	if ( isset( $records['id'] ) ) {
		$legacy_mode = (string) ( $records['mode'] ?? '' );

		// No reliable mode marker: drop it rather than guess an environment.
		$records = in_array( $legacy_mode, array( 'test', 'live' ), true )
			? array( $legacy_mode => $records )
			: array();
	}

	$records[ $mode ] = $account;

	update_user_meta( $user_id, 'chip_bank_account', $records );
}

/**
 * Forgets the stored CHIP Send bank account for an affiliate.
 *
 * Called when the affiliate's details change so the next payout re-resolves
 * against CHIP instead of paying a stale account.
 *
 * @param int $affiliate_id Affiliate ID.
 * @return void
 */
function chip_affiliatewp_forget_bank_account( $affiliate_id ) {
	$user_id = affwp_get_affiliate_user_id( $affiliate_id );

	if ( $user_id ) {
		delete_user_meta( $user_id, 'chip_bank_account' );
	}
}

/**
 * Removes the payout data this plugin keeps when an affiliate is deleted.
 *
 * AffiliateWP deletes its own meta on delete and leaves everything else, and
 * WordPress keeps user meta unless the user row goes too — an affiliate can be
 * deleted while the WordPress user stays. That would strand a bank account
 * number and a cached CHIP account ID on a user who is no longer an affiliate,
 * and a later affiliate reusing that user would inherit both.
 *
 * @param int              $affiliate_id Affiliate ID.
 * @param bool             $delete_data  Whether AffiliateWP was asked to delete data.
 * @param \AffWP\Affiliate $affiliate   Affiliate object.
 * @return void
 */
function chip_affiliatewp_cleanup_deleted_affiliate( $affiliate_id, $delete_data = false, $affiliate = null ) {
	unset( $delete_data );

	$affiliate_id = absint( $affiliate_id );

	if ( ! $affiliate_id ) {
		return;
	}

	$user_id = $affiliate && ! empty( $affiliate->user_id )
		? absint( $affiliate->user_id )
		: affwp_get_affiliate_user_id( $affiliate_id );

	if ( ! $user_id ) {
		return;
	}

	// The affiliate's own bank details and the CHIP account resolved from them.
	chip_affiliatewp_forget_bank_account( $affiliate_id );

	delete_user_meta( $user_id, 'payment_account_number' );
	delete_user_meta( $user_id, 'payment_bank_code' );
	delete_user_meta( $user_id, 'chip_bank_account_superseded' );
}
add_action( 'affwp_affiliate_deleted', 'chip_affiliatewp_cleanup_deleted_affiliate', 10, 3 );

/**
 * Returns a stable fingerprint of the affiliate's current bank details.
 *
 * The account id is only valid for the details it was created from, so the
 * fingerprint is what makes a stored id safe to reuse across payouts.
 *
 * @param int $affiliate_id Affiliate ID.
 * @return string
 */
function chip_affiliatewp_bank_details_fingerprint( $affiliate_id ) {
	$details = chip_affiliatewp_get_bank_details( $affiliate_id );

	return md5( strtoupper( $details['bank_code'] ) . '|' . preg_replace( '/\D/', '', $details['account_number'] ) );
}

/**
 * Deletes a superseded CHIP Send bank account.
 *
 * Correcting a mistyped account number, or changing banks, produces a new
 * reference and therefore a new CHIP record, because a reference derived from
 * the details is what makes registration idempotent. Without this the previous
 * record stays in the merchant's CHIP account for good — one stale recipient per
 * correction, still payable by anything holding its id.
 *
 * The delete is refused while a payout is still in flight against the account:
 * CHIP's delete prevents future payments, and a transfer already executing must
 * not be interfered with.
 *
 * @param int         $affiliate_id Affiliate ID.
 * @param int         $account_id   CHIP bank account ID to delete.
 * @param string|null $mode         Optional. Mode the record belongs to.
 * @return bool
 */
function chip_affiliatewp_delete_superseded_bank_account( $affiliate_id, $account_id, $mode = null ) {
	$account_id = absint( $account_id );

	if ( ! $account_id ) {
		return false;
	}

	$mode = in_array( $mode, array( 'test', 'live' ), true ) ? $mode : chip_affiliatewp_current_mode();

	if ( chip_affiliatewp_bank_account_is_in_use( $affiliate_id, $account_id, $mode ) ) {
		return false;
	}

	$response = chip_affiliatewp_request( 'DELETE', '/send/bank_accounts/' . $account_id, array(), array(), $mode );

	if ( is_wp_error( $response ) ) {
		/*
		 * Already gone, or CHIP refused. CHIP answers a delete of an unknown id
		 * with an error, and a record the merchant removed themselves should not
		 * be retried forever, so the marker is cleared either way.
		 */
		chip_affiliatewp_forget_superseded_bank_account( $affiliate_id, $mode );

		return false;
	}

	chip_affiliatewp_forget_superseded_bank_account( $affiliate_id, $mode );

	return true;
}

/**
 * Whether a payout is still working against a bank account.
 *
 * The account id is read from the payout's own meta. The payouts table has no
 * column for it: its service_id holds the instruction id, so comparing against
 * that would never match and the guard would always answer "not in use".
 *
 * The payout's mode is compared too. A bank account id is only unique within
 * one CHIP account, so a live payout holding id 77 would otherwise make this
 * refuse while a TEST account with the same number is being cleaned up: the
 * superseded record stays marked, the delete is refused, and it is retried on
 * every later registration without ever succeeding.
 *
 * @param int         $affiliate_id Affiliate ID.
 * @param int         $account_id   CHIP bank account ID.
 * @param string|null $mode         Optional. Mode whose account is being checked.
 * @return bool
 */
function chip_affiliatewp_bank_account_is_in_use( $affiliate_id, $account_id, $mode = null ) {
	$account_id = absint( $account_id );

	if ( ! $account_id ) {
		return false;
	}

	$mode = in_array( $mode, array( 'test', 'live' ), true ) ? $mode : null;

	$payouts = affiliate_wp()->affiliates->payouts->get_payouts(
		array(
			'affiliate_id'  => absint( $affiliate_id ),
			'payout_method' => 'chip',
			'status'        => 'processing',
			'number'        => 50,
		)
	);

	foreach ( (array) $payouts as $payout ) {
		$data = chip_affiliatewp_payout_data( $payout );

		if ( (int) chip_affiliatewp_array_value( $data, 'bank_account_id', 0 ) !== $account_id ) {
			continue;
		}

		/*
		 * A payout with no recorded mode (written before modes were stored)
		 * cannot be attributed either way, so it protects the id everywhere.
		 */
		if ( null !== $mode ) {
			$payout_mode = (string) chip_affiliatewp_array_value( $data, 'mode', '' );

			if ( in_array( $payout_mode, array( 'test', 'live' ), true ) && $payout_mode !== $mode ) {
				continue;
			}
		}

		return true;
	}

	return false;
}

/**
 * The name to register a recipient bank account under.
 *
 * `affwp_get_affiliate_name()` returns an empty string when the user has no
 * first or last name set, which is the ordinary state of an account created
 * with an email address and a password. CHIP requires a name of at least one
 * character, so passing it through unchanged makes account registration fail
 * and the affiliate unpayable. Fall back to the login, then the email, and
 * finally to something explicitly usable.
 *
 * @param int $affiliate_id Affiliate ID.
 * @return string
 */
function chip_affiliatewp_bank_account_name( $affiliate_id ) {
	$affiliate_id = absint( $affiliate_id );
	$name         = trim( (string) affwp_get_affiliate_name( $affiliate_id ) );

	if ( '' !== $name ) {
		return chip_affiliatewp_substr( $name, 128 );
	}

	$user_id = function_exists( 'affwp_get_affiliate_user_id' ) ? absint( affwp_get_affiliate_user_id( $affiliate_id ) ) : 0;
	$user    = $user_id ? get_userdata( $user_id ) : false;

	if ( $user ) {
		$login = trim( (string) $user->user_login );

		if ( '' !== $login ) {
			return chip_affiliatewp_substr( $login, 128 );
		}

		$email = trim( (string) $user->user_email );

		if ( '' !== $email ) {
			return chip_affiliatewp_substr( $email, 128 );
		}
	}

	// Nothing identifying on file. CHIP needs a non-empty name; name it so the
	// merchant can see which affiliate the account belongs to.
	return sprintf( 'Affiliate %d', $affiliate_id );
}

/**
 * Adds the affiliate's bank account on CHIP Send, or returns the existing one.
 *
 * The unique per-details reference makes the submission idempotent: CHIP
 * rejects a duplicate registration of the same recipient, and this plugin
 * looks the account up first so repeat payouts reuse the existing record.
 *
 * @param int         $affiliate_id Affiliate ID.
 * @param string|null $mode         'test' or 'live'; null uses the current mode.
 * @return array|WP_Error Bank account record with at least "id" and "status".
 */
function chip_affiliatewp_ensure_bank_account( $affiliate_id, $mode = null ) {
	/*
	 * The mode is a parameter so callers that have already resolved one - the
	 * submission path, which resolves it once for the instruction and the
	 * record - cannot have the bank account registered in the other
	 * environment by a setting change in between. Resolving here as well means
	 * the function deciding where the money goes and the one deciding which CHIP
	 * account the recipient is registered in can disagree.
	 */
	$mode     = in_array( $mode, array( 'test', 'live' ), true ) ? $mode : chip_affiliatewp_current_mode();
	$existing = chip_affiliatewp_get_bank_account( $affiliate_id, $mode );

	if ( is_array( $existing ) && ! empty( $existing['id'] ) ) {
		$deleted_at = chip_affiliatewp_array_value( $existing, 'deleted_at' );

		if ( empty( $deleted_at ) ) {
			return $existing;
		}
	}

	$details = chip_affiliatewp_get_bank_details( $affiliate_id );

	if ( '' === $details['account_number'] || '' === $details['bank_code'] ) {
		return new WP_Error( 'chip_missing_bank_details', __( 'This affiliate has no bank account details on file.', 'chip-for-affiliatewp' ) );
	}

	/*
	 * Corrected details leave the record registered under the old reference
	 * behind in the CHIP account. Clear it once the replacement is confirmed,
	 * never before: deleting first would leave the affiliate without a payable
	 * account if the new registration were to fail.
	 */
	$superseded = chip_affiliatewp_superseded_bank_account_id( $affiliate_id, $mode );

	$response = chip_affiliatewp_request(
		'POST',
		'/send/bank_accounts',
		array(
			'account_number' => $details['account_number'],
			'bank_code'      => $details['bank_code'],
			'name'           => chip_affiliatewp_bank_account_name( $affiliate_id ),
			'reference'      => chip_affiliatewp_bank_reference( $affiliate_id ),
		),
		array(),
		$mode
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( $superseded && ! empty( $response['id'] ) ) {
		chip_affiliatewp_delete_superseded_bank_account( $affiliate_id, $superseded, $mode );
	}

	if ( empty( $response['id'] ) ) {
		return new WP_Error( 'chip_invalid_bank_account', __( 'CHIP Send did not return a bank account ID.', 'chip-for-affiliatewp' ) );
	}

	// Cache the id so repeat payouts skip the lookup entirely.
	chip_affiliatewp_store_bank_account( $affiliate_id, $response, $mode );

	return $response;
}

/**
 * Adds the affiliate bank details fields on the Edit Affiliate screen.
 *
 * @param AffWP\Affiliate $affiliate Affiliate object.
 * @return void
 */
function chip_affiliatewp_affiliate_bank_fields( $affiliate ) {
	if ( ! affiliate_wp()->settings->get( 'chip_payouts' ) ) {
		return;
	}

	$details = chip_affiliatewp_get_bank_details( $affiliate->affiliate_id );
	?>
	<tr class="form-row form-required">
		<th scope="row">
			<label for="payment_bank_code"><?php esc_html_e( 'Bank Code', 'chip-for-affiliatewp' ); ?></label>
		</th>
		<td>
			<select name="payment_bank_code" id="payment_bank_code">
				<option value="" <?php selected( $details['bank_code'], '' ); ?>><?php esc_html_e( '— Select bank —', 'chip-for-affiliatewp' ); ?></option>
				<?php foreach ( chip_affiliatewp_bank_codes() as $bank_code => $label ) : ?>
					<option value="<?php echo esc_attr( $bank_code ); ?>" <?php selected( $details['bank_code'], $bank_code ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<p class="description"><?php esc_html_e( 'Bank code for the affiliate payout sent via CHIP Send.', 'chip-for-affiliatewp' ); ?></p>
		</td>
	</tr>
	<tr class="form-row form-required">
		<th scope="row">
			<label for="payment_account_number"><?php esc_html_e( 'Bank Account Number', 'chip-for-affiliatewp' ); ?></label>
		</th>
		<td>
			<input class="regular-text" type="text" name="payment_account_number" id="payment_account_number" value="<?php echo esc_attr( $details['account_number'] ); ?>" />
			<p class="description"><?php esc_html_e( 'Account number for the affiliate payout sent via CHIP Send.', 'chip-for-affiliatewp' ); ?></p>
		</td>
	</tr>
	<?php
}
add_action( 'affwp_edit_affiliate_end', 'chip_affiliatewp_affiliate_bank_fields' );

/**
 * Validates a Malaysian bank account number.
 *
 * Lives here rather than in the form handler so the affiliate area and the Edit
 * Affiliate screen accept exactly the same numbers. A number that reaches CHIP
 * with the wrong shape comes back as a rejection the affiliate cannot act on,
 * so the check belongs with the storage it guards.
 *
 * @param string $number Account number, digits only.
 * @return true|WP_Error True when usable, WP_Error describing the problem.
 */
function chip_affiliatewp_validate_account_number( $number ) {
	$number = preg_replace( '/\D/', '', (string) $number );

	if ( '' === $number ) {
		return new WP_Error( 'chip_account_number_missing', __( 'Enter your bank account number.', 'chip-for-affiliatewp' ) );
	}

	/*
	 * Length depends on the recipient bank: CHIP's own schema puts the general
	 * range at 10-17 digits, while individual banks extend as low as 5
	 * (Bank of America, Standard Chartered) and as high as 17.
	 *
	 * This is a sanity bound, not the bank's own rule — CHIP verifies the
	 * account against the bank and reports anything it will not accept. Being
	 * wider than CHIP's schema means a number CHIP rejects is reported with
	 * CHIP's reason rather than a misleading "wrong length"; being narrower
	 * would refuse accounts that are genuinely valid.
	 */
	if ( strlen( $number ) < 5 || strlen( $number ) > 20 ) {
		return new WP_Error( 'chip_account_number_length', __( 'A Malaysian bank account number is between 5 and 20 digits. Check the number and try again.', 'chip-for-affiliatewp' ) );
	}

	return true;
}

/**
 * Validates a bank code against the banks CHIP Send can pay to.
 *
 * @param string $bank_code Bank code.
 * @return true|WP_Error True when supported, WP_Error otherwise.
 */
function chip_affiliatewp_validate_bank_code( $bank_code ) {
	$bank_code = strtoupper( trim( (string) $bank_code ) );

	if ( '' === $bank_code ) {
		return new WP_Error( 'chip_bank_code_missing', __( 'Choose your bank.', 'chip-for-affiliatewp' ) );
	}

	if ( ! array_key_exists( $bank_code, chip_affiliatewp_bank_codes() ) ) {
		return new WP_Error( 'chip_bank_code_unsupported', __( 'Pick a bank from the list. CHIP Send can only pay to the banks shown.', 'chip-for-affiliatewp' ) );
	}

	return true;
}

/**
 * Stores an affiliate's bank details after validation.
 *
 * Shared by the Edit Affiliate screen and the affiliate area form, so both
 * entry points normalise identically: digits only, an uppercase bank code that
 * must be one CHIP Send supports, and a dropped CHIP account cache when
 * anything changed. A value that fails validation is stored as empty, which
 * leaves the affiliate "not ready" rather than registered with a bad account.
 *
 * @param int    $user_id   Affiliate's WordPress user ID.
 * @param string $bank_code Bank code.
 * @param string $number    Account number.
 * @return bool Whether the stored details changed.
 */
function chip_affiliatewp_store_bank_details( $user_id, $bank_code, $number ) {
	$user_id = absint( $user_id );

	if ( ! $user_id ) {
		return false;
	}

	$changed = false;

	/*
	 * Keep digits only. The field accepts the separators people write on paper
	 * ("1234-567 890"), but storing them verbatim would make the same account
	 * produce two different CHIP references. An empty result is stored as empty
	 * so the affiliate is treated as not-ready rather than registered with a
	 * blank account.
	 */
	$new_number = preg_replace( '/\D/', '', (string) $number );
	$new_code   = strtoupper( trim( (string) $bank_code ) );

	// A code or number CHIP cannot use is stored empty, never as-is.
	if ( is_wp_error( chip_affiliatewp_validate_bank_code( $new_code ) ) ) {
		$new_code = '';
	}

	if ( is_wp_error( chip_affiliatewp_validate_account_number( $new_number ) ) ) {
		$new_number = '';
	}

	if ( (string) get_user_meta( $user_id, 'payment_account_number', true ) !== $new_number ) {
		$changed = true;
	}

	if ( (string) get_user_meta( $user_id, 'payment_bank_code', true ) !== $new_code ) {
		$changed = true;
	}

	update_user_meta( $user_id, 'payment_account_number', $new_number );
	update_user_meta( $user_id, 'payment_bank_code', $new_code );

	/*
	 * New details mean the cached CHIP Send account id no longer describes this
	 * affiliate's account. Record the record this change supersedes, per mode,
	 * then drop the cache so the next payout registers the new details rather
	 * than paying the previous account.
	 *
	 * The superseded account cannot be deleted here: the new details have not
	 * been registered with CHIP yet, and deleting first would leave the
	 * affiliate with nothing payable if that registration were to fail.
	 */
	if ( $changed ) {
		$previous = get_user_meta( $user_id, 'chip_bank_account', true );

		if ( is_array( $previous ) ) {
			$superseded = get_user_meta( $user_id, 'chip_bank_account_superseded', true );
			$superseded = is_array( $superseded ) ? $superseded : array();

			foreach ( array( 'test', 'live' ) as $record_mode ) {
				$record = $previous[ $record_mode ] ?? null;

				if ( is_array( $record ) && ! empty( $record['id'] ) ) {
					$superseded[ $record_mode ] = absint( $record['id'] );
				}
			}

			if ( $superseded ) {
				update_user_meta( $user_id, 'chip_bank_account_superseded', $superseded );
			}
		}

		delete_user_meta( $user_id, 'chip_bank_account' );
	}

	return $changed;
}

/**
 * Returns the CHIP bank account a details change superseded, for a mode.
 *
 * @param int    $affiliate_id Affiliate ID.
 * @param string $mode         Mode the record belongs to.
 * @return int Account ID, or 0 when there is nothing to clear.
 */
function chip_affiliatewp_superseded_bank_account_id( $affiliate_id, $mode ) {
	$user_id = affwp_get_affiliate_user_id( $affiliate_id );

	if ( ! $user_id ) {
		return 0;
	}

	$superseded = get_user_meta( $user_id, 'chip_bank_account_superseded', true );

	if ( ! is_array( $superseded ) ) {
		return 0;
	}

	return absint( $superseded[ $mode ] ?? 0 );
}

/**
 * Forgets a superseded bank account once it has been deleted.
 *
 * @param int    $affiliate_id Affiliate ID.
 * @param string $mode         Mode the record belonged to.
 * @return void
 */
function chip_affiliatewp_forget_superseded_bank_account( $affiliate_id, $mode ) {
	$user_id = affwp_get_affiliate_user_id( $affiliate_id );

	if ( ! $user_id ) {
		return;
	}

	$superseded = get_user_meta( $user_id, 'chip_bank_account_superseded', true );

	if ( ! is_array( $superseded ) || ! isset( $superseded[ $mode ] ) ) {
		return;
	}

	unset( $superseded[ $mode ] );

	if ( $superseded ) {
		update_user_meta( $user_id, 'chip_bank_account_superseded', $superseded );
	} else {
		delete_user_meta( $user_id, 'chip_bank_account_superseded' );
	}
}

/**
 * Saves the affiliate bank details from the Edit Affiliate screen.
 *
 * @param AffWP\Affiliate $affiliate Affiliate object.
 * @param array           $args      Update arguments.
 * @param array           $data      Raw submitted data.
 * @return void
 */
function chip_affiliatewp_save_bank_details( $affiliate, $args, $data ) {
	if ( empty( $data['payment_bank_code'] ) && empty( $data['payment_account_number'] ) ) {
		return;
	}

	$bank_code = isset( $data['payment_bank_code'] )
		? sanitize_text_field( $data['payment_bank_code'] )
		: (string) get_user_meta( $affiliate->user_id, 'payment_bank_code', true );
	$number    = isset( $data['payment_account_number'] )
		? sanitize_text_field( $data['payment_account_number'] )
		: (string) get_user_meta( $affiliate->user_id, 'payment_account_number', true );

	chip_affiliatewp_store_bank_details( $affiliate->user_id, $bank_code, $number );
}
add_action( 'affwp_pre_update_affiliate', 'chip_affiliatewp_save_bank_details', 10, 3 );

/**
 * Returns the Malaysian bank codes supported by CHIP Send.
 *
 * @return array Map of bank code => label.
 */
function chip_affiliatewp_bank_codes() {
	return array(
		'ACDBMYK2' => 'AEON Bank (M) Berhad',
		'PHBMMYKL' => 'Affin Bank Berhad',
		'AGOBMYKL' => 'Agrobank',
		'RJHIMYKL' => 'Al-Rajhi',
		'MFBBMYKL' => 'Alliance Bank Malaysia Berhad',
		'ARBKMYKL' => 'Ambank Malaysia Berhad',
		'BIMBMYKL' => 'Bank Islam Malaysia Berhad',
		'BKRMMYKL' => 'Bank Kerjasama Rakyat Malaysia Berhad',
		'BMMBMYKL' => 'Bank Muamalat Malaysia Bhd',
		'BOFAMY2X' => 'Bank of America (M) Berhad',
		'BKCHMYKL' => 'Bank of China (M) Berhad',
		'BOTKMYKX' => 'Bank of Tokyo-Mitsubishi UFJ (M) Berhad',
		'BSNAMYK1' => 'Bank Simpanan Nasional Berhad',
		'BNPAMYKL' => 'BNP Paribas Malaysia Berhad',
		'BOBEMYK2' => 'BOOST Bank Berhad',
		'PCBCMYKL' => 'China Construction Bank (M) Berhad',
		'CIBBMYKL' => 'CIMB Bank Berhad',
		'CITIMYKL' => 'Citibank Berhad',
		'DEUTMYKL' => 'Deutsche Bank (Malaysia) Berhad',
		'FNXSMYNB' => 'Finexus Cards Sdn. Bhd.',
		'GXSPMYKL' => 'GX Bank Berhad',
		'HLBBMYKL' => 'Hong Leong Bank Berhad',
		'HBMBMYKL' => 'HSBC Bank Malaysia Berhad',
		'ICBKMYKL' => 'Industrial and Commercial Bank of China (M) Berhad',
		'CHASMYKX' => 'JP Morgan Chase Bank Berhad',
		'KFHOMYKL' => 'Kuwait Finance House',
		'MBBEMYKL' => 'Maybank Berhad',
		'AFBQMYKL' => 'MBSB Bank Berhad',
		'MHCBMYKA' => 'Mizuho Bank (Malaysia) Berhad',
		'OCBCMYKL' => 'OCBC Bank Berhad',
		'PBBEMYKL' => 'Public Bank Berhad',
		'RHBBMYKL' => 'RHB Bank Berhad',
		'SCBLMYKX' => 'Standard Chartered Bank Malaysia Berhad',
		'SMBCMYKL' => 'Sumitomo Mitsui Banking Corporation (M) Berhad',
		'TNGDMYNB' => 'Touch \'n Go eWallet',
		'UOVBMYKL' => 'United Overseas Bank Berhad (UOB)',
	);
}
