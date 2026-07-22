<?php
namespace StokeChat;

defined( 'ABSPATH' ) || exit;

/**
 * @username mention parsing. Only resolves to actual members of the room.
 */
class Mentions {

	const PATTERN = '/(?<=^|\s)@([a-zA-Z0-9_.\-]{2,60})/u';

	/** @var Members */
	private $members;

	public function __construct( Members $members ) {
		$this->members = $members;
	}

	/**
	 * @param string $content   Message text (already sanitized).
	 * @param int    $room_id   Room the message was posted in.
	 * @param int    $author_id Author (excluded from results).
	 * @return int[] Mentioned member user IDs, deduped.
	 */
	public function parse( $content, $room_id, $author_id ) {
		if ( false === strpos( $content, '@' ) ) {
			return array();
		}

		preg_match_all( self::PATTERN, $content, $matches );

		$ids = array();
		foreach ( array_unique( $matches[1] ) as $login ) {
			$user = get_user_by( 'login', $login );
			if ( ! $user || (int) $user->ID === (int) $author_id ) {
				continue;
			}
			if ( $this->members->is_member( $room_id, $user->ID ) ) {
				$ids[] = (int) $user->ID;
			}
		}

		return array_values( array_unique( $ids ) );
	}
}
