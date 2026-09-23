<?php
/**
 * Simple fixed-window rate limiting on transients.
 *
 * @package FavrCore
 */

declare(strict_types=1);

namespace FavrMembers\Vendor\FavrCore\Support;

/**
 * Counts actions per key (e.g. "listing_save_{$user_id}") in a time window. Good enough to stop
 * accidental floods and casual abuse on member forms; not a security boundary on its own.
 */
final class RateLimit {

	/**
	 * Record one attempt. Returns false once the limit for the window is exceeded.
	 *
	 * @param string $key    Bucket key (without prefix).
	 * @param int    $max    Allowed attempts per window.
	 * @param int    $window Window length in seconds.
	 */
	public static function hit( string $key, int $max, int $window = HOUR_IN_SECONDS ): bool {
		$name  = 'favr_rl_' . md5( $key );
		$state = get_transient( $name );
		$now   = time();
		if ( ! is_array( $state ) || (int) ( $state['until'] ?? 0 ) <= $now ) {
			$state = array(
				'count' => 0,
				'until' => $now + $window,
			);
		}
		++$state['count'];
		set_transient( $name, $state, max( 1, (int) $state['until'] - $now ) );
		return $state['count'] <= $max;
	}
}
