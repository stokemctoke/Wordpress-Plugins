<?php
namespace StokeChat;

defined( 'ABSPATH' ) || exit;

/**
 * Away email alerts for @mentions (and the other party in 2-person private rooms).
 * Throttled per user+room; sent off-request via a single cron event.
 */
class Mailer {

	const CRON_HOOK    = 'stokechat_send_alert';
	const OPTOUT_META  = 'stokechat_email_optout';

	/** @var Settings */
	private $settings;

	/** @var Presence */
	private $presence;

	/** @var Members */
	private $members;

	/** @var Rooms */
	private $rooms;

	/** @var Mentions */
	private $mentions;

	public function __construct( Settings $settings, Presence $presence, Members $members, Rooms $rooms, Mentions $mentions ) {
		$this->settings = $settings;
		$this->presence = $presence;
		$this->members  = $members;
		$this->rooms    = $rooms;
		$this->mentions = $mentions;
	}

	public function register() {
		add_action( 'stokechat_message_created', array( $this, 'queue_alerts' ) );
		add_action( self::CRON_HOOK, array( $this, 'send_alert' ), 10, 3 );
	}

	/**
	 * @param object $message Raw message row.
	 */
	public function queue_alerts( $message ) {
		if ( ! $this->settings->get( 'emails_enabled' ) ) {
			return;
		}

		$room = $this->rooms->get( $message->room_id );
		if ( ! $room ) {
			return;
		}

		$author_id  = (int) $message->user_id;
		$recipients = $this->mentions->parse( $message->content, $room->room_id, $author_id );

		// In a private 2-person room (a DM), the other member is implicitly mentioned.
		if ( (int) $room->is_private ) {
			$member_ids = $this->members->user_ids( $room->room_id );
			if ( 2 === count( $member_ids ) ) {
				foreach ( $member_ids as $member_id ) {
					if ( $member_id !== $author_id ) {
						$recipients[] = $member_id;
					}
				}
			}
		}

		foreach ( array_unique( $recipients ) as $recipient_id ) {
			if ( get_user_meta( $recipient_id, self::OPTOUT_META, true ) ) {
				continue;
			}
			if ( ! $this->presence->is_away( $recipient_id ) ) {
				continue;
			}

			$throttle_min = (int) $this->settings->get( 'email_throttle_min' );

			// Per-room throttle: one alert per conversation per window.
			$throttle_key = 'stokechat_emailed_' . $recipient_id . '_' . (int) $room->room_id;
			if ( get_transient( $throttle_key ) ) {
				continue;
			}

			// Per-RECIPIENT ceiling as well. Rooms are cheap to create, so a
			// per-room key alone lets one sender fan out across fresh rooms and
			// keep mailing the same person indefinitely.
			if ( ! $this->within_recipient_budget( $recipient_id ) ) {
				continue;
			}

			set_transient( $throttle_key, 1, MINUTE_IN_SECONDS * $throttle_min );

			$args = array( $recipient_id, (int) $room->room_id, $author_id );

			/**
			 * Return true to send synchronously (for hosts with broken WP-Cron).
			 */
			if ( apply_filters( 'stokechat_send_immediately', false ) ) {
				$this->send_alert( ...$args );
			} else {
				wp_schedule_single_event( time(), self::CRON_HOOK, $args );
			}
		}
	}

	/**
	 * How many chat alerts one person may receive per hour, across all rooms.
	 *
	 * @param int $recipient_id
	 * @return bool True when there is budget left.
	 */
	private function within_recipient_budget( $recipient_id ) {
		/**
		 * Filter the per-recipient hourly cap on chat alert emails.
		 *
		 * @param int $limit
		 * @param int $recipient_id
		 */
		$limit = (int) apply_filters( 'stokechat_alerts_per_recipient_hourly', 4, $recipient_id );

		if ( $limit < 1 ) {
			return true;
		}

		$key   = 'stokechat_alertcount_' . (int) $recipient_id;
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}

	public function send_alert( $recipient_id, $room_id, $author_id ) {
		$recipient = get_userdata( $recipient_id );
		$room      = $this->rooms->get( $room_id );
		if ( ! $recipient || ! $room ) {
			return;
		}

		// Re-check opt-out at send time (cron may fire later).
		if ( get_user_meta( $recipient_id, self::OPTOUT_META, true ) ) {
			return;
		}

		$author      = get_userdata( $author_id );
		$author_name = $author ? $author->display_name : __( 'Someone', 'stoke-chat' );
		$site_name   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$chat_url = $this->settings->get( 'chat_page_url' );
		if ( ! $chat_url ) {
			$chat_url = home_url( '/' );
		}

		// The room name is chosen by whoever created the room, and anyone who
		// can create one can conscript a stranger into it — so it must not
		// reach the subject line, where it would render as though the SITE were
		// saying it ("URGENT: reset your password at …"). The name still
		// appears in the body, quoted and in context.
		/* translators: %s: site name. */
		$subject = sprintf( __( 'You were mentioned in chat on %s', 'stoke-chat' ), $site_name );

		$body = sprintf(
			/* translators: 1: recipient display name, 2: author display name, 3: room name, 4: chat URL. */
			__(
				"Hi %1\$s,\n\n%2\$s mentioned you in the room \"%3\$s\" while you were away.\n\nCatch up here: %4\$s\n\nYou can turn these emails off from your profile page.",
				'stoke-chat'
			),
			$recipient->display_name,
			$author_name,
			$room->name,
			$chat_url
		);

		$subject = apply_filters( 'stokechat_alert_subject', $subject, $recipient_id, $room, $author_id );
		$body    = apply_filters( 'stokechat_alert_body', $body, $recipient_id, $room, $author_id );

		wp_mail( $recipient->user_email, $subject, $body );
	}
}
