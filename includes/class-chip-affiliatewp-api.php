<?php
/**
 * CHIP Send API client: signed requests and credentials.
 *
 * @package CHIPforAffiliateWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Cannot access directly.

/**
 * Returns the CHIP Send API base URL for a mode.
 *
 * @param string|null $mode 'test', 'live', or null for the current mode.
 * @return string Base URL without trailing slash.
 */
function chip_affiliatewp_api_base_url( $mode = null ) {
	$test_mode = null === $mode
		? (bool) affiliate_wp()->settings->get( 'chip_test_mode' )
		: ( 'test' === $mode );

	if ( $test_mode ) {
		return 'https://staging-api.chip-in.asia/api';
	}

	return 'https://api.chip-in.asia/api';
}

/**
 * Returns the settings key matching a mode.
 *
 * @param string      $suffix Settings key suffix, e.g. "api_key".
 * @param string|null $mode   'test' or 'live'; null uses the current mode.
 * @return string Settings key such as "chip_test_api_key".
 */
function chip_affiliatewp_setting_key( $suffix, $mode = null ) {
	if ( null === $mode ) {
		$mode = affiliate_wp()->settings->get( 'chip_test_mode' ) ? 'test' : 'live';
	}

	return 'chip_' . $mode . '_' . $suffix;
}

/**
 * Returns the configured API credentials for a mode.
 *
 * @param string|null $mode 'test' or 'live'; null uses the current mode.
 * @return array { api_key: string, secret_key: string }
 */
function chip_affiliatewp_credentials( $mode = null ) {
	return array(
		'api_key'    => (string) affiliate_wp()->settings->get( chip_affiliatewp_setting_key( 'api_key', $mode ) ),
		'secret_key' => (string) affiliate_wp()->settings->get( chip_affiliatewp_setting_key( 'secret_key', $mode ) ),
	);
}

/**
 * Whether valid-looking CHIP Send credentials are configured.
 *
 * @return bool
 */
function chip_affiliatewp_has_credentials() {
	$credentials = chip_affiliatewp_credentials();

	return '' !== $credentials['api_key'] && '' !== $credentials['secret_key'];
}

/**
 * Sends a signed request to the CHIP Send API.
 *
 * Every request carries a fresh epoch and an HMAC-SHA512 checksum of
 * "<epoch><api_key>" signed with the API secret.
 *
 * @param string      $method HTTP method, "GET" or "POST".
 * @param string      $path   API path beginning with a slash, e.g. "/send/send_instructions".
 * @param array       $body   Optional JSON payload for POST requests.
 * @param array       $query  Optional query parameters.
 * @param string|null $mode   'test' or 'live'; null uses the current mode.
 * @return array|WP_Error Decoded JSON response, or WP_Error on failure.
 */
function chip_affiliatewp_request( $method, $path, $body = array(), $query = array(), $mode = null ) {
	$credentials = chip_affiliatewp_credentials( $mode );

	if ( '' === $credentials['api_key'] || '' === $credentials['secret_key'] ) {
		return new WP_Error( 'chip_missing_credentials', __( 'CHIP Send API credentials are not configured.', 'chip-for-affiliatewp' ) );
	}

	$url = chip_affiliatewp_api_base_url( $mode ) . $path;

	if ( ! empty( $query ) ) {
		$url = add_query_arg( $query, $url );
	}

	$epoch    = (string) time();
	$checksum = hash_hmac( 'sha512', $epoch . $credentials['api_key'], $credentials['secret_key'] );

	$args = array(
		'method'  => $method,
		'timeout' => 30,
		'headers' => array(
			'Authorization' => 'Bearer ' . $credentials['api_key'],
			'epoch'         => $epoch,
			'checksum'      => $checksum,
			'Content-Type'  => 'application/json',
		),
	);

	if ( ! empty( $body ) ) {
		$args['body'] = wp_json_encode( $body );
	}

	$response = wp_remote_request( $url, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$raw  = wp_remote_retrieve_body( $response );
	$data = json_decode( $raw, true );

	if ( $code < 200 || $code >= 300 ) {
		$detail = '';

		if ( is_array( $data ) ) {
			foreach ( array( 'message', 'error', 'errors' ) as $key ) {
				if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
					$detail = $data[ $key ];
					break;
				}
			}
		}

		return new WP_Error(
			'chip_api_error',
			sprintf(
				/* translators: 1: HTTP status code, 2: API error detail */
				__( 'CHIP Send API error (HTTP %1$s): %2$s', 'chip-for-affiliatewp' ),
				$code,
				'' !== $detail ? $detail : __( 'request failed', 'chip-for-affiliatewp' )
			)
		);
	}

	if ( ! is_array( $data ) ) {
		return new WP_Error( 'chip_api_invalid_response', __( 'CHIP Send API returned an unexpected response.', 'chip-for-affiliatewp' ) );
	}

	return $data;
}

/**
 * Lists CHIP Send instructions filtered by reference.
 *
 * Returns the first matching instruction, or null when none exists. Used to
 * recover an instruction that was already created when a submission retry
 * hits a duplicate-reference rejection.
 *
 * @param string $reference Reference value.
 * @return array|null
 */
function chip_affiliatewp_list_instruction_by_reference( $reference ) {
	$response = chip_affiliatewp_request(
		'GET',
		'/send/send_instructions',
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

	foreach ( $response['results'] as $instruction ) {
		if ( isset( $instruction['reference'] ) && $reference === (string) $instruction['reference'] ) {
			return $instruction;
		}
	}

	return null;
}

/**
 * Formats a payout amount for the CHIP Send API.
 *
 * @param string|int|float $amount Amount.
 * @return string Amount with up to two decimal places, e.g. "100.00".
 */
function chip_affiliatewp_format_amount( $amount ) {
	return sprintf( '%0.2f', (float) $amount );
}
