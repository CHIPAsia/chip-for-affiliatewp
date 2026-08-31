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
function chip_affiliatewp_array_value( $data, $key, $default = '' ) {
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
