<?php
namespace StokeChat;

defined( 'ABSPATH' ) || exit;

/**
 * Last-active tracking via user meta; powers the "away" heuristic for email alerts.
 */
class Presence {

	const META_KEY = 'stokechat_last_active';

	/** @var Settings */
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Record activity, writing at most once per minute to avoid meta churn.
	 */
	public function touch( $user_id ) {
		$last = (int) get_user_meta( $user_id, self::META_KEY, true );
		if ( time() - $last >= MINUTE_IN_SECONDS ) {
			update_user_meta( $user_id, self::META_KEY, time() );
		}
	}

	public function is_away( $user_id ) {
		$threshold = MINUTE_IN_SECONDS * (int) $this->settings->get( 'away_threshold_min' );
		$last      = (int) get_user_meta( $user_id, self::META_KEY, true );
		return ( time() - $last ) > $threshold;
	}
}
