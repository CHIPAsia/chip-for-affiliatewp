<?php
/**
 * Resets this plugin's CHIP Send webhooks so they can be registered again.
 *
 * Only webhooks this plugin owns are touched: an entry must either be recorded
 * as ours for a mode, carry the plugin's webhook name, or point at this site's
 * webhook URL. A merchant's other CHIP Send webhooks (their own integrations)
 * are never removed.
 *
 * @package CHIPforAffiliateWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Cannot access directly.

/**
 * Returns the webhook name this plugin registers.
 *
 * @return string
 */
function chip_affiliatewp_webhook_name() {
	return 'AffiliateWP Payouts';
}

/**
 * Lists every CHIP Send webhook the plugin can attribute to itself.
 *
 * @param string|null $mode "test" or "live". Defaults to the current mode.
 * @return array {
 *     @type int[]  $ids     Webhook IDs considered ours.
 *     @type string $url     This site's webhook URL.
 *     @type array  $records Raw records for the IDs found.
 *     @type string $error   Error message when the list could not be read.
 * }
 */
function chip_affiliatewp_find_own_webhooks( $mode = null ) {
	$mode = in_array( $mode, array( 'test', 'live' ), true )
		? $mode
		: ( affiliate_wp()->settings->get( 'chip_test_mode' ) ? 'test' : 'live' );

	$keys = chip_affiliatewp_webhook_option_keys( $mode );
	$url  = chip_affiliatewp_webhook_url();
	$name = chip_affiliatewp_webhook_name();

	$ids  = array();
	$rows = array();

	/*
	 * The ID recorded for this mode is ours by definition. Kept as a string:
	 * CHIP currently issues numeric IDs, but coercing here would turn any
	 * other shape into 0 and silently skip deleting it.
	 */
	$stored_id = (string) affiliate_wp()->settings->get( $keys['id'], '' );

	if ( '' !== $stored_id ) {
		$ids[] = $stored_id;
	}

	$list = chip_affiliatewp_request( 'GET', '/webhooks', array(), array(), $mode );

	if ( is_wp_error( $list ) ) {
		return array(
			'ids'     => $ids,
			'url'     => $url,
			'records' => array(),
			'error'   => $list->get_error_message(),
		);
	}

	$items = array();

	if ( isset( $list['results'] ) && is_array( $list['results'] ) ) {
		$items = $list['results'];
	} elseif ( isset( $list[0] ) && is_array( $list[0] ) ) {
		$items = $list;
	}

	foreach ( $items as $item ) {
		if ( ! is_array( $item ) || empty( $item['id'] ) ) {
			continue;
		}

		$item_url  = (string) chip_affiliatewp_array_value( $item, 'callback_url' );
		$item_name = (string) chip_affiliatewp_array_value( $item, 'name' );

		// Ours when it points at this site, or carries the plugin's name.
		if ( $item_url !== $url && $item_name !== $name ) {
			continue;
		}

		$ids[]  = (string) $item['id'];
		$rows[] = $item;
	}

	return array(
		'ids'     => array_values( array_unique( array_filter( $ids, 'strlen' ) ) ),
		'url'     => $url,
		'records' => $rows,
		'error'   => '',
	);
}

/**
 * Deletes every webhook the plugin owns for a mode and clears its record.
 *
 * Leaves the reachability cache and the per-site URL secret alone: the secret
 * is what makes the URL unguessable, and keeping it means the re-registered
 * webhook lands on the same (already configured) endpoint.
 *
 * @param string|null $mode "test" or "live". Defaults to the current mode.
 * @return array|WP_Error {
 *     @type int      $deleted Number of webhooks removed.
 *     @type string[] $failed  Human-readable failures, if any.
 * } or WP_Error when nothing could be read.
 */
function chip_affiliatewp_reset_webhooks( $mode = null ) {
	$mode = in_array( $mode, array( 'test', 'live' ), true )
		? $mode
		: ( affiliate_wp()->settings->get( 'chip_test_mode' ) ? 'test' : 'live' );

	$found = chip_affiliatewp_find_own_webhooks( $mode );

	if ( '' !== $found['error'] ) {
		return new WP_Error( 'chip_webhook_list_failed', $found['error'] );
	}

	$deleted = 0;
	$failed  = array();

	foreach ( $found['ids'] as $webhook_id ) {
		$response = chip_affiliatewp_request( 'DELETE', '/webhooks/' . rawurlencode( (string) $webhook_id ), array(), array(), $mode );

		if ( is_wp_error( $response ) ) {
			// 404 means it is already gone; treat that as success.
			$status = chip_affiliatewp_error_http_status( $response );

			if ( 404 === $status ) {
				++$deleted;
				continue;
			}

			$failed[] = sprintf(
				/* translators: 1: webhook ID, 2: error message */
				__( 'Webhook %1$s could not be deleted: %2$s', 'chip-for-affiliatewp' ),
				(string) $webhook_id,
				$response->get_error_message()
			);

			continue;
		}

		++$deleted;
	}

	// Forget what we recorded, so the next save registers from scratch.
	$keys = chip_affiliatewp_webhook_option_keys( $mode );

	affiliate_wp()->settings->set(
		array(
			$keys['id']      => '',
			$keys['key']     => '',
			$keys['checked'] => '',
		),
		true
	);

	// A fresh registration should not reuse a stale reachability verdict.
	delete_transient( 'chip_affiliatewp_webhook_reachable' );

	return array(
		'deleted' => $deleted,
		'failed'  => $failed,
	);
}
