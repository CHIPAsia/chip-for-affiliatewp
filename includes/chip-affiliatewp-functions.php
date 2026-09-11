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
 * Formats an amount for display with its currency code.
 *
 * Falls back to a plain number-plus-code string when AffiliateWP's currency
 * helper is unavailable, so the balance card never renders an empty value.
 *
 * @param float  $amount   Amount to format.
 * @param string $currency Currency code.
 * @return string
 */
function chip_affiliatewp_format_money( $amount, $currency = 'MYR' ) {
	$currency  = strtoupper( (string) $currency );
	$formatted = number_format( (float) $amount, 2, '.', ',' );

	if ( function_exists( 'affwp_currency_filter' ) ) {
		return html_entity_decode( affwp_currency_filter( $formatted, $currency ), ENT_QUOTES, 'UTF-8' );
	}

	return $currency . ' ' . $formatted;
}

/**
 * Parses a stored UTC timestamp into a Unix epoch.
 *
 * Timestamps are written with gmdate(), so they are UTC. strtotime() would
 * interpret them in the site's local timezone, which skews every comparison
 * that reads them back — on a UTC+8 site the ten-minute requery cooldown looked
 * like it had already elapsed, so the cooldown never applied at all. Anchor the
 * parse to UTC instead.
 *
 * @param mixed $value Stored timestamp (Y-m-d H:i:s).
 * @return int Epoch seconds, or 0 when unparseable.
 */
function chip_affiliatewp_parse_utc( $value ) {
	$value = trim( (string) $value );

	if ( '' === $value ) {
		return 0;
	}

	$date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );

	if ( false === $date ) {
		// Fall back to a UTC-anchored strtotime for any other stored shape.
		$epoch = strtotime( $value . ' UTC' );

		return false === $epoch ? 0 : $epoch;
	}

	return $date->getTimestamp();
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
 * Validates a receipt URL before it is stored or linked to.
 *
 * The URL arrives in a signed webhook, so a forged payload is already rejected
 * upstream — but the value ends up in an href, and a signature key is a single
 * point of failure. Accept only an absolute http(s) URL on a chip-in.asia host
 * and drop anything else, so a compromised or misconfigured sender cannot turn
 * the payout drawer into a javascript: link.
 *
 * @param mixed $url Candidate URL.
 * @return string Sanitized URL, or an empty string when it is not acceptable.
 */
function chip_affiliatewp_safe_receipt_url( $url ) {
	$url = trim( (string) $url );

	if ( '' === $url ) {
		return '';
	}

	$url = esc_url_raw( $url );

	if ( '' === $url ) {
		return '';
	}

	$host = wp_parse_url( $url, PHP_URL_HOST );

	if ( ! is_string( $host ) || '' === $host ) {
		return '';
	}

	$host = strtolower( $host );

	// CHIP serves receipts from its own domains (apex or any subdomain).
	$is_chip_host = ( 'chip-in.asia' === $host ) || ( '.chip-in.asia' === substr( $host, -13 ) );

	if ( ! $is_chip_host ) {
		return '';
	}

	$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

	if ( 'https' !== $scheme && 'http' !== $scheme ) {
		return '';
	}

	return $url;
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
