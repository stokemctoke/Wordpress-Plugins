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

			$throttle_key = 'stokechat_emailed_' . $recipient_id . '_' . (int) $room->room_id;
			if ( get_transient( $throttle_key ) ) {
				continue;
			}
			set_transient( $throttle_key, 1, MINUTE_IN_SECONDS * (int) $this->settings->get( 'email_throttle_min' ) );

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

		/* translators: 1: author display name, 2: room name, 3: site name. */
		$subject = sprintf( __( '%1$s mentioned you in %2$s on %3$s', 'stoke-chat' ), $author_name, $room->name, $site_name );

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
