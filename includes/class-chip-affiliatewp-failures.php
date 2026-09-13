<?php
/**
 * CHIP Send payout failure classification.
 *
 * AffiliateWP groups payout failures into classes (transient, affiliate action
 * required, admin action required, data error, unknown). The class drives the
 * Retry button, the automatic retry sweep, and the "Payout Failed — Action
 * Required" email. A method that never classifies its failures leaves every
 * failure as UNKNOWN, so a merchant whose bank details are simply missing gets
 * no email and no retry guidance.
 *
 * @package CHIPforAffiliateWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Cannot access directly.

/**
 * Classifies a payout failure for AffiliateWP's retry machinery.
 *
 * @param string   $error_code Plugin error code.
 * @param string   $error_message Human-readable failure reason.
 * @param int|null $http_status  HTTP status from the API response, when known.
 * @return string Failure class.
 */
function chip_affiliatewp_classify_failure( $error_code, $error_message = '', $http_status = null ) {
	$code    = strtolower( (string) $error_code );
	$message = strtolower( (string) $error_message );
	$status  = is_numeric( $http_status ) ? (int) $http_status : 0;

	/*
	 * The HTTP status is the reliable signal for an API failure, so it is
	 * checked before anything derived from message text. A 4xx is a settled
	 * answer — bad credentials, a rejected payload, a missing resource — and
	 * retrying it unchanged just burns attempts. A 5xx or a transport timeout
	 * is worth retrying.
	 */
	if ( $status >= 500 ) {
		return 'transient';
	}

	if ( $status >= 400 ) {
		// 429 is the one 4xx that clears on its own.
		if ( 429 === $status ) {
			return 'transient';
		}

		// Credentials, permissions and payload problems are the merchant's to fix.
		return 'admin_action_required';
	}

	// Bank details the affiliate (or the admin on their behalf) must supply or fix.
	$affiliate_codes = array(
		'chip_missing_bank_details',
		'chip_invalid_bank_account',
		'chip_bank_account_unverified',
		'chip_no_email',
		'chip_no_affiliate',
	);

	if ( in_array( $code, $affiliate_codes, true ) ) {
		return 'affiliate_action_required';
	}

	// Merchant-side problems: credentials, disabled method, balance.
	$admin_codes = array(
		'chip_missing_credentials',
		'chip_payouts_disabled',
		'chip_webhook_unconfigured',
	);

	if ( in_array( $code, $admin_codes, true ) ) {
		return 'admin_action_required';
	}

	/*
	 * An amount the API cannot accept, or work that is no longer there to do.
	 * The figures and the referral states are the store's own, so these are data
	 * problems rather than provider ones: retrying unchanged would fail
	 * identically.
	 */
	if ( in_array( $code, array( 'chip_invalid_amount', 'chip_currency_unsupported', 'chip_reference_conflict', 'chip_referrals_no_longer_payable' ), true ) ) {
		return 'data_error';
	}

	/*
	 * Instruction states. `rejected` means the recipient's bank details were
	 * refused, which the affiliate or the admin acting for them must fix.
	 *
	 * `deleted` and `not_found` mean the instruction is gone from CHIP: nothing
	 * will ever settle it, and only the merchant can find out why. Classified
	 * here rather than left to the message text, which happened to carry the
	 * words for some of these and not others.
	 */
	if ( in_array( $code, array( 'chip_instruction_rejected' ), true ) ) {
		return 'affiliate_action_required';
	}

	if ( in_array( $code, array( 'chip_instruction_deleted', 'chip_instruction_not_found' ), true ) ) {
		return 'admin_action_required';
	}

	/*
	 * A response the plugin could not make sense of, or one missing the fields
	 * it needs. The API answered, so this is the provider's side: retrying the
	 * same request later is reasonable.
	 */
	if ( in_array( $code, array( 'chip_instruction_failed', 'chip_api_invalid_response', 'chip_payout_not_created' ), true ) ) {
		return 'transient';
	}

	/*
	 * CHIP rejections are terminal and usually mean the recipient's bank
	 * details are wrong, so treat them as needing affiliate action. Anything
	 * left is an API or transport problem with no status attached, which is
	 * worth retrying unchanged.
	 */
	if ( false !== strpos( $message, 'instruction rejected' ) || false !== strpos( $message, 'rejection' ) ) {
		return 'affiliate_action_required';
	}

	if ( false !== strpos( $code, 'chip_api_error' ) || false !== strpos( $code, 'chip_http' ) ) {
		return 'transient';
	}

	// A transport failure never reached the API, so nothing was applied.
	if ( false !== strpos( $message, 'timed out' ) || false !== strpos( $message, 'could not resolve' ) || false !== strpos( $message, 'connection' ) ) {
		return 'transient';
	}

	return 'unknown';
}

/**
 * Returns an actionable "where to fix this" line for a failure.
 *
 * AffiliateWP renders a failed payout's description verbatim as the error
 * message in the admin drawer. For merchant-side problems the provider text
 * alone does not say where to act, and CHIP has no dashboard URL core can link
 * to — core hard-codes Stripe and PayPal, and the next-steps builder has no
 * filter — so the drawer's button renders without a target and does nothing
 * when clicked. Naming the exact screen here is what makes the failure
 * actionable.
 *
 * @param string   $error_code  Plugin error code.
 * @param int|null $http_status HTTP status, when known.
 * @return string Hint sentence, or empty string when none applies.
 */
function chip_affiliatewp_failure_hint( $error_code, $http_status = null ) {
	$code   = strtolower( (string) $error_code );
	$status = is_numeric( $http_status ) ? (int) $http_status : 0;

	if ( 'chip_missing_credentials' === $code ) {
		return __( 'Add your CHIP Send API key and secret under Settings → Payouts → CHIP Send.', 'chip-for-affiliatewp' );
	}

	if ( 'chip_payouts_disabled' === $code ) {
		return __( 'Turn CHIP Send back on under Settings → Payouts.', 'chip-for-affiliatewp' );
	}

	if ( 'chip_webhook_unconfigured' === $code ) {
		return __( 'Save your credentials under Settings → Payouts → CHIP Send so the webhook can register.', 'chip-for-affiliatewp' );
	}

	// CHIP refused our credentials: the fix is on our own settings screen.
	if ( 401 === $status || 403 === $status ) {
		return __( 'Check the API key and secret under Settings → Payouts → CHIP Send, and confirm the key is still active in your CHIP account.', 'chip-for-affiliatewp' );
	}

	/*
	 * A validation rejection. After the description is sanitised before sending,
	 * the usual remaining cause is the recipient's bank details — but a payload
	 * problem is possible too, so name both rather than guess.
	 */
	if ( 422 === $status ) {
		return __( "Check the affiliate's bank account details, and contact CHIP support with this message if they look correct.", 'chip-for-affiliatewp' );
	}

	return '';
}

/**
 * Supplies the affiliate-facing body for CHIP Send payout failures.
 *
 * The registered `payout_failure_chip_body` setting is the merchant's own copy
 * and stays authoritative for anything generic. A rejection that names a bank
 * problem, though, is actionable in a specific way: the affiliate has to fix
 * their account details, and telling them so beats a generic "your payout
 * failed". Only the actionable classes get replaced copy.
 *
 * @param string $body   Current body.
 * @param object $payout Payout being emailed about.
 * @param string $method Payout method slug.
 * @param string $state  Failure state.
 * @return string
 */
function chip_affiliatewp_failure_email_body( $body, $payout, $method, $state ) {
	if ( 'chip' !== $method || 'failed' !== $state ) {
		return $body;
	}

	$data  = chip_affiliatewp_payout_data( $payout );
	$class = isset( $data['failure_class'] ) ? (string) $data['failure_class'] : '';

	if ( ! class_exists( '\AffWP\Payouts\Failure_Class' ) || \AffWP\Payouts\Failure_Class::AFFILIATE_ACTION_REQUIRED !== $class ) {
		return $body;
	}

	return __( "Hi {name},\n\nWe couldn't send your {amount} commission from {site_name} to your bank account.\n\nThis usually means the bank account details on your affiliate account need attention — a wrong or incomplete account number, or a bank account that hasn't finished verification.\n\nPlease open your settings and check your bank details:\n{affiliate_payout_settings_url}\n\nOnce they're corrected, we'll automatically try sending your commission again. Questions? Just reply to this email.\n\nThanks,\n{site_name}", 'chip-for-affiliatewp' );
}
add_filter( 'affwp_payout_failure_email_body', 'chip_affiliatewp_failure_email_body', 10, 4 );

/**
 * Registers the classifier with AffiliateWP.
 *
 * @return void
 */
function chip_affiliatewp_register_failure_classifier() {
	if ( ! class_exists( '\AffWP\Payouts\Failure_Classifier' ) ) {
		return;
	}

	\AffWP\Payouts\Failure_Classifier::register( 'chip', 'chip_affiliatewp_classify_failure' );
}
add_action( 'init', 'chip_affiliatewp_register_failure_classifier', 20 );

/**
 * Registers the CHIP copy for the "Payout Failed — Action Required" email.
 *
 * Registering a body gives the method its own editable template under
 * Settings → Emails instead of falling back to the generic copy, so a
 * merchant can word the "fix your bank details" instruction for CHIP Send.
 *
 * @return void
 */
function chip_affiliatewp_register_failure_email_template() {
	if ( ! class_exists( '\AffWP\Payouts\Failure_Email_Registry' ) ) {
		return;
	}

	\AffWP\Payouts\Failure_Email_Registry::register(
		'chip',
		array(
			'label' => __( 'CHIP Send', 'chip-for-affiliatewp' ),
			'body'  => __( "Hi {name},\n\nWe tried to send your {amount} commission from {site_name} to your bank account, but the payment could not go through.\n\nThis usually means the bank account details on your affiliate profile are missing or incorrect. Please check your bank name and account number and update them here:\n{affiliate_payout_settings_url}\n\nOnce your details are correct, we'll include this commission in your next payout.\n\nQuestions? Just reply to this email.\n\nThanks,\n{site_name}", 'chip-for-affiliatewp' ),
		)
	);
}
add_action( 'init', 'chip_affiliatewp_register_failure_email_template', 20 );
