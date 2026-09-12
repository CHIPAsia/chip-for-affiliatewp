<?php
/**
 * Affiliate-facing bank details form.
 *
 * AffiliateWP gives each payout method a slot on the affiliate area's Settings
 * tab (`affwp_affiliate_dashboard_payments_section`), which is where Stripe and
 * PayPal let an affiliate enter their own payout details. CHIP Send fills the
 * same slot so an affiliate can supply the Malaysian bank account their
 * commissions are paid into without waiting on an administrator.
 *
 * The submitted values are written through the same helpers the Edit Affiliate
 * screen uses, so both entry points normalise and validate identically.
 *
 * @package CHIPforAffiliateWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Cannot access directly.

/**
 * Whether the affiliate area should show the CHIP Send payout section.
 *
 * @param int $affiliate_id Affiliate ID.
 * @return bool
 */
function chip_affiliatewp_affiliate_section_is_relevant( $affiliate_id ) {
	$affiliate_id = absint( $affiliate_id );

	if ( ! $affiliate_id ) {
		return false;
	}

	// Nothing to show while the method is switched off.
	if ( ! affiliate_wp()->settings->get( 'chip_payouts' ) ) {
		return false;
	}

	// The affiliate must actually be paid through CHIP Send.
	if ( function_exists( 'affwp_get_affiliate_usable_payout_method' )
		&& 'chip' !== affwp_get_affiliate_usable_payout_method( $affiliate_id ) ) {
		return false;
	}

	return true;
}

/**
 * Renders the bank details form on the affiliate area Settings tab.
 *
 * @param int $affiliate_id      Affiliate ID.
 * @param int $affiliate_user_id Affiliate's WordPress user ID.
 * @return void
 */
function chip_affiliatewp_affiliate_bank_form( $affiliate_id = 0, $affiliate_user_id = 0 ) {
	unset( $affiliate_user_id );

	$affiliate_id = $affiliate_id ? absint( $affiliate_id ) : absint( affwp_get_affiliate_id() );

	if ( ! chip_affiliatewp_affiliate_section_is_relevant( $affiliate_id ) ) {
		return;
	}

	$details = chip_affiliatewp_get_bank_details( $affiliate_id );
	$has     = '' !== $details['account_number'] && '' !== $details['bank_code'];

	// A one-time result notice from the save handler.
	$notice = get_transient( 'chip_affiliatewp_bank_notice_' . get_current_user_id() );

	if ( is_array( $notice ) ) {
		delete_transient( 'chip_affiliatewp_bank_notice_' . get_current_user_id() );

		if ( function_exists( 'affwp_callout' ) ) {
			affwp_callout(
				array(
					'tone'    => 'error' === ( $notice['type'] ?? '' ) ? 'warning' : 'info',
					'heading' => (string) ( $notice['heading'] ?? '' ),
					'content' => (string) ( $notice['message'] ?? '' ),
				)
			);
		}
	}

	?>
	<div class="affwp-chip-payouts mt-6">
		<h3 class="text-base font-medium text-gray-900">
			<?php esc_html_e( 'Payout bank account', 'chip-for-affiliatewp' ); ?>
		</h3>
		<p class="mt-1 text-sm text-gray-600">
			<?php esc_html_e( 'Your commissions are paid straight to a Malaysian bank account through CHIP Send.', 'chip-for-affiliatewp' ); ?>
		</p>

		<?php if ( $has ) : ?>
			<p class="mt-2 text-sm text-gray-600">
				<?php
				printf(
					/* translators: 1: bank name, 2: bank account number. */
					esc_html__( 'Currently paying to %1$s %2$s. Change it below if that is wrong.', 'chip-for-affiliatewp' ),
					esc_html( $details['bank_name'] ),
					esc_html( $details['account_number'] )
				);
				?>
			</p>
		<?php endif; ?>

		<form method="post" class="mt-4">
			<?php wp_nonce_field( 'chip_affiliatewp_save_bank_details', 'chip_affiliatewp_bank_nonce' ); ?>
			<input type="hidden" name="chip_affiliatewp_action" value="save_bank_details" />

			<p>
				<label for="chip-payment-bank-code" class="block text-sm font-medium text-gray-900">
					<?php esc_html_e( 'Bank', 'chip-for-affiliatewp' ); ?>
				</label>
				<select name="payment_bank_code" id="chip-payment-bank-code" class="mt-1 block w-full max-w-md rounded-md border border-gray-300">
					<option value=""><?php esc_html_e( '— Select bank —', 'chip-for-affiliatewp' ); ?></option>
					<?php foreach ( chip_affiliatewp_bank_codes() as $bank_code => $label ) : ?>
						<option value="<?php echo esc_attr( $bank_code ); ?>" <?php selected( $details['bank_code'], $bank_code ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<p class="mt-4">
				<label for="chip-payment-account-number" class="block text-sm font-medium text-gray-900">
					<?php esc_html_e( 'Bank account number', 'chip-for-affiliatewp' ); ?>
				</label>
				<input
					type="text"
					inputmode="numeric"
					autocomplete="off"
					name="payment_account_number"
					id="chip-payment-account-number"
					value="<?php echo esc_attr( $details['account_number'] ); ?>"
					class="mt-1 block w-full max-w-md rounded-md border border-gray-300"
				/>
				<span class="mt-1 block text-xs text-gray-500">
					<?php esc_html_e( 'Digits only. The account must be in your own name.', 'chip-for-affiliatewp' ); ?>
				</span>
			</p>

			<p class="mt-4">
				<?php
				if ( function_exists( 'affwp_button' ) ) {
					affwp_button(
						array(
							'type' => 'submit',
							'text' => $has
								? __( 'Update bank details', 'chip-for-affiliatewp' )
								: __( 'Save bank details', 'chip-for-affiliatewp' ),
						)
					);
				} else {
					submit_button(
						$has
							? __( 'Update bank details', 'chip-for-affiliatewp' )
							: __( 'Save bank details', 'chip-for-affiliatewp' ),
						'primary',
						'',
						false
					);
				}
				?>
			</p>
		</form>
	</div>
	<?php
}
add_action( 'affwp_affiliate_dashboard_payments_section', 'chip_affiliatewp_affiliate_bank_form', 10, 2 );

/**
 * Handles a bank details submission from the affiliate area.
 *
 * The affiliate area posts to itself, so this runs on `init` and only acts on
 * its own nonce-protected action. The affiliate can only ever write their own
 * details: the affiliate ID comes from the session, never from the request.
 *
 * @return void
 */
function chip_affiliatewp_handle_affiliate_bank_save() {
	if ( ! isset( $_POST['chip_affiliatewp_action'] ) || 'save_bank_details' !== sanitize_key( wp_unslash( $_POST['chip_affiliatewp_action'] ) ) ) {
		return;
	}

	if ( ! isset( $_POST['chip_affiliatewp_bank_nonce'] ) ) {
		return;
	}

	$nonce = sanitize_text_field( wp_unslash( $_POST['chip_affiliatewp_bank_nonce'] ) );

	if ( ! wp_verify_nonce( $nonce, 'chip_affiliatewp_save_bank_details' ) ) {
		return;
	}

	if ( ! is_user_logged_in() ) {
		return;
	}

	$affiliate_id = absint( affwp_get_affiliate_id() );

	if ( ! $affiliate_id || ! chip_affiliatewp_affiliate_section_is_relevant( $affiliate_id ) ) {
		return;
	}

	$bank_code = isset( $_POST['payment_bank_code'] )
		? strtoupper( sanitize_text_field( wp_unslash( $_POST['payment_bank_code'] ) ) )
		: '';
	$number    = isset( $_POST['payment_account_number'] )
		? preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['payment_account_number'] ) ) )
		: '';

	$notice = array();

	$code_check   = chip_affiliatewp_validate_bank_code( $bank_code );
	$number_check = chip_affiliatewp_validate_account_number( $number );

	if ( '' === $bank_code || '' === $number ) {
		$notice = array(
			'type'    => 'error',
			'heading' => __( 'Both fields are required', 'chip-for-affiliatewp' ),
			'message' => __( 'Choose your bank and enter the account number before saving.', 'chip-for-affiliatewp' ),
		);
	} elseif ( is_wp_error( $code_check ) ) {
		// The select only offers supported banks; anything else is a forged post.
		$notice = array(
			'type'    => 'error',
			'heading' => __( 'That bank is not supported', 'chip-for-affiliatewp' ),
			'message' => $code_check->get_error_message(),
		);
	} elseif ( is_wp_error( $number_check ) ) {
		$notice = array(
			'type'    => 'error',
			'heading' => __( 'That account number looks wrong', 'chip-for-affiliatewp' ),
			'message' => $number_check->get_error_message(),
		);
	} else {
		$user_id = affwp_get_affiliate_user_id( $affiliate_id );

		chip_affiliatewp_store_bank_details( $user_id, $bank_code, $number );

		$notice = array(
			'type'    => 'success',
			'heading' => __( 'Bank details saved', 'chip-for-affiliatewp' ),
			'message' => __( 'CHIP Send verifies the account before your next payout. You will be paid as soon as verification completes.', 'chip-for-affiliatewp' ),
		);
	}

	set_transient( 'chip_affiliatewp_bank_notice_' . get_current_user_id(), $notice, 60 );

	if ( apply_filters( 'chip_affiliatewp_bank_save_redirect', true ) ) {
		$redirect = wp_get_referer() ? wp_get_referer() : home_url();

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- returning to the affiliate area page the form was submitted from.
		wp_redirect( $redirect );
		exit;
	}
}
add_action( 'init', 'chip_affiliatewp_handle_affiliate_bank_save' );
