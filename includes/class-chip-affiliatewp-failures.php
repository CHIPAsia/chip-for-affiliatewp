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
 * Classifies a CHIP Send payout failure into an AffiliateWP failure class.
 *
 * @param string $error_code    WP_Error code or provider error code.
 * @param string $error_message Human-readable error message.
 * @return string One of the AffiliateWP failure-class constants.
 */
function chip_affiliatewp_classify_failure( $error_code, $error_message = '' ) {
	$code    = strtolower( (string) $error_code );
	$message = strtolower( (string) $error_message );

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
	 * CHIP rejections are terminal and usually mean the recipient's bank
	 * details are wrong, so treat them as needing affiliate action. The
	 * remaining API failures (timeouts, 5xx, rate limits) are worth retrying
	 * unchanged, which is what the transient class means.
	 */
	if ( false !== strpos( $message, 'instruction rejected' ) || false !== strpos( $message, 'rejection' ) ) {
		return 'affiliate_action_required';
	}

	if ( false !== strpos( $code, 'chip_api_error' ) || false !== strpos( $message, 'http 5' ) || false !== strpos( $message, 'timed out' ) ) {
		return 'transient';
	}

	return 'unknown';
}

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
