<?php
namespace StokeChat;

defined( 'ABSPATH' ) || exit;

/**
 * Transient-backed sliding-window rate limiter.
 */
class Rate_Limiter {

	/**
	 * Record an attempt and report whether it is allowed.
	 *
	 * @param string $action  Short action slug (e.g. 'msg', 'room').
	 * @param int    $user_id Acting user.
	 * @param int    $max     Max attempts per window.
	 * @param int    $window  Window length in seconds.
	 * @return int 0 if allowed; otherwise seconds until the next attempt is allowed.
	 */
	public function hit( $action, $user_id, $max, $window ) {
		list( $max, $window ) = apply_filters( 'stokechat_rate_limit', array( $max, $window ), $action, $user_id );

		$key    = 'stokechat_rl_' . $action . '_' . (int) $user_id;
		$now    = time();
		$stamps = get_transient( $key );
		$stamps = is_array( $stamps ) ? $stamps : array();

		$stamps = array_values(
			array_filter(
				$stamps,
				function ( $t ) use ( $now, $window ) {
					return $t > $now - $window;
				}
			)
		);

		if ( count( $stamps ) >= $max ) {
			return max( 1, min( $stamps ) + $window - $now );
		}

		$stamps[] = $now;
		set_transient( $key, $stamps, $window );

		return 0;
	}
}
