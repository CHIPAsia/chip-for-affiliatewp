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

	return array(
		'account_number' => (string) get_user_meta( $user_id, 'payment_account_number', true ),
		'bank_code'      => (string) get_user_meta( $user_id, 'payment_bank_code', true ),
	);
}

/**
 * Returns the two-character reference prefix setting, sanitized.
 *
 * @return string
 */
function chip_affiliatewp_reference_prefix() {
	$prefix = (string) affiliate_wp()->settings->get( 'chip_reference_prefix' );

	return substr( preg_replace( '/[^A-Za-z0-9.-]/', '', $prefix ), 0, 2 );
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
 * @param int $affiliate_id Affiliate ID.
 * @return string
 */
function chip_affiliatewp_bank_reference( $affiliate_id ) {
	$details = chip_affiliatewp_get_bank_details( $affiliate_id );
	$hash    = substr( md5( $details['bank_code'] . '|' . $details['account_number'] ), 0, 6 );

	return substr( chip_affiliatewp_reference_prefix() . '-AFF-' . $affiliate_id . '-' . $hash, 0, 40 );
}

/**
 * Returns a stable send instruction reference for a payout.
 *
 * @param int $payout_id Payout ID.
 * @return string
 */
function chip_affiliatewp_instruction_reference( $payout_id ) {
	return substr( chip_affiliatewp_reference_prefix() . '-PO-' . $payout_id, 0, 40 );
}

/**
 * Retrieves the affiliate's CHIP Send bank account registered under its stable reference.
 *
 * @param int $affiliate_id Affiliate ID.
 * @return array|null Bank account record, or null when none exists.
 */
function chip_affiliatewp_get_bank_account( $affiliate_id ) {
	$reference = chip_affiliatewp_bank_reference( $affiliate_id );

	$response = chip_affiliatewp_request(
		'GET',
		'/send/bank_accounts',
		array(),
		array(
			'page'      => 1,
			'limit'     => 25,
			'reference' => $reference,
		)
	);

	if ( is_wp_error( $response ) || empty( $response['results'] ) || ! is_array( $response['results'] ) ) {
		return null;
	}

	foreach ( $response['results'] as $account ) {
		if ( isset( $account['reference'] ) && $reference === (string) $account['reference'] ) {
			return $account;
		}
	}

	return null;
}

/**
 * Adds the affiliate's bank account on CHIP Send, or returns the existing one.
 *
 * The unique per-details reference makes the submission idempotent: CHIP
 * rejects a duplicate registration of the same recipient, and this plugin
 * looks the account up first so repeat payouts reuse the existing record.
 *
 * @param int $affiliate_id Affiliate ID.
 * @return array|WP_Error Bank account record with at least "id" and "status".
 */
function chip_affiliatewp_ensure_bank_account( $affiliate_id ) {
	$existing = chip_affiliatewp_get_bank_account( $affiliate_id );

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

	$response = chip_affiliatewp_request(
		'POST',
		'/send/bank_accounts',
		array(
			'account_number' => $details['account_number'],
			'bank_code'      => $details['bank_code'],
			'name'           => chip_affiliatewp_substr( (string) affwp_get_affiliate_name( $affiliate_id ), 128 ),
			'reference'      => chip_affiliatewp_bank_reference( $affiliate_id ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( empty( $response['id'] ) ) {
		return new WP_Error( 'chip_invalid_bank_account', __( 'CHIP Send did not return a bank account ID.', 'chip-for-affiliatewp' ) );
	}

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

	if ( isset( $data['payment_account_number'] ) ) {
		update_user_meta(
			$affiliate->user_id,
			'payment_account_number',
			sanitize_text_field( $data['payment_account_number'] )
		);
	}

	if ( isset( $data['payment_bank_code'] ) ) {
		update_user_meta(
			$affiliate->user_id,
			'payment_bank_code',
			sanitize_text_field( $data['payment_bank_code'] )
		);
	}
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
		'PCBCMYKL' => 'China Construction Bank (M) Berhad',
		'CIBBMYKL' => 'CIMB Bank Berhad',
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
