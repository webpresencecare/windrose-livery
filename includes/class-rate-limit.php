<?php
/**
 * A short-lived limit per visitor for Style Mermaid's public actions (downloads, fonts, previews).
 *
 * The visitor's address is never stored: the key is a keyed hash (HMAC with this site's secret salt) of the address
 * and the action, kept as a transient that expires one hour after the first request. Library managers are not
 * limited.
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

namespace Windrose\Livery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rate limit.
 */
final class Rate_Limit {

	public const WINDOW = HOUR_IN_SECONDS;

	/**
	 * Counts one request; false when the limit is reached.
	 *
	 * @param string $action Action name.
	 * @param int    $max    Requests per hour.
	 * @return bool
	 */
	public static function hit( string $action, int $max ): bool {
		$key  = self::key( $action );
		$now  = time();
		$data = get_transient( $key );
		if ( ! is_array( $data ) || ! is_int( $data['until'] ?? null ) || $data['until'] <= $now ) {
			$data = array(
				'n'     => 0,
				'until' => $now + self::WINDOW,
			);
		}
		if ( (int) $data['n'] >= $max ) {
			return false;
		}
		$data['n'] = (int) $data['n'] + 1;
		set_transient( $key, $data, (int) max( 1, $data['until'] - $now ) );
		return true;
	}

	/**
	 * Seconds until the window of an action ends (for Retry-After).
	 *
	 * @param string $action Action name.
	 * @return int
	 */
	public static function retry_after( string $action ): int {
		$data = get_transient( self::key( $action ) );
		return is_array( $data ) && is_int( $data['until'] ?? null ) ? max( 1, $data['until'] - time() ) : self::WINDOW;
	}

	/**
	 * Transient key: a keyed hash of the address and the action.
	 *
	 * @param string $action Action name.
	 * @return string
	 */
	private static function key( string $action ): string {
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$address = false === filter_var( $address, FILTER_VALIDATE_IP ) ? 'unknown' : $address;
		$packed  = (string) inet_pton( $address );
		// One IPv6 connection usually owns a whole /64: count the block. Not an IPv4 address in IPv6 form (::ffff:…).
		if ( 16 === strlen( $packed ) && ! str_starts_with( $packed, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
			$address = bin2hex( substr( $packed, 0, 8 ) ) . '::/64';
		}
		return 'windrose_livery_rl_' . substr( hash_hmac( 'sha256', $address . '|' . $action, wp_salt( 'nonce' ) ), 0, 32 );
	}
}
