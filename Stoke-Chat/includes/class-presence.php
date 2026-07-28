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

	/**
	 * "Away" means a chat user who has stepped out — NOT someone who has never
	 * opened the chat at all. Those accounts have no activity meta, so a naive
	 * `time() - 0 > threshold` made every user on the site permanently away and
	 * therefore permanently emailable, which is the amplifier behind the room
	 * invite → implicit DM mention → site-sent mail chain.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public function is_away( $user_id ) {
		$last = (int) get_user_meta( $user_id, self::META_KEY, true );

		if ( $last <= 0 ) {
			return false;
		}

		$threshold = MINUTE_IN_SECONDS * (int) $this->settings->get( 'away_threshold_min' );

		return ( time() - $last ) > $threshold;
	}
}
