<?php
/**
 * Shared helpers used across plugin modules.
 *
 * @package CHIPforAffiliateWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Cannot access directly.

/**
 * Reads a value from an array without notices.
 *
 * @param array      $data    Source array.
 * @param string|int $key     Key to read.
 * @param mixed      $default Default value.
 * @return mixed
 */
function chip_affiliatewp_array_value( $data, $key, $default = '' ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- '$default' is the clearest name for a fallback value.
	return isset( $data[ $key ] ) ? $data[ $key ] : $default;
}

/**
 * Multibyte-safe substring.
 *
 * @param string $text  Input text.
 * @param int    $limit Maximum length.
 * @return string
 */
function chip_affiliatewp_substr( $text, $limit ) {
	if ( function_exists( 'mb_substr' ) ) {
		return mb_substr( $text, 0, $limit );
	}

	return substr( $text, 0, $limit );
}

/**
 * Sanitizes a description for the CHIP Send API.
 *
 * CHIP Send rejects any character outside a fixed allow-list:
 *
 *     alphanumeric, period, space, underscore, hyphen, forward slash,
 *     at symbol, parentheses
 *
 * Referral and payout descriptions routinely contain characters outside that
 * set (notably "#", as in "Commission for referral #42"), which makes the API
 * answer 422 and the payout fail. Normalize to the allowed set before sending,
 * then trim to the API's length budget.
 *
 * @param string $text  Raw description.
 * @param int    $limit Maximum length after sanitizing.
 * @return string Safe description, never empty.
 */
function chip_affiliatewp_sanitize_description( $text, $limit = 140 ) {
	$text = (string) $text;

	// Map common typography to its ASCII equivalent first so meaning survives.
	$text = str_replace(
		array( '’', '‘', '“', '”', '–', '—', '…' ),
		array( "'", "'", '"', '"', '-', '-', '...' ),
		$text
	);

	// "#" reads as "number" in these descriptions; CHIP rejects the character.
	$text = str_replace( '#', 'No.', $text );

	// Drop anything outside the CHIP allow-list.
	$text = preg_replace( '/[^A-Za-z0-9 ._\-\/@()]/', ' ', $text );

	// Collapse the whitespace the replacement above can introduce.
	$text = trim( preg_replace( '/\s+/', ' ', $text ) );

	if ( '' === $text ) {
		$text = 'Affiliate commission payout';
	}

	return chip_affiliatewp_substr( $text, $limit );
}
