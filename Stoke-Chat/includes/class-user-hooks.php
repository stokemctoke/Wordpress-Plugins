<?php
namespace StokeChat;

defined( 'ABSPATH' ) || exit;

/**
 * WP-user lifecycle: cleanup on user deletion, profile email opt-out field.
 */
class User_Hooks {

	/** @var Rooms */
	private $rooms;

	/** @var Members */
	private $members;

	public function __construct( Rooms $rooms, Members $members ) {
		$this->rooms   = $rooms;
		$this->members = $members;
	}

	public function register() {
		add_action( 'deleted_user', array( $this, 'cleanup_user' ) );
		add_action( 'show_user_profile', array( $this, 'profile_field' ) );
		add_action( 'edit_user_profile', array( $this, 'profile_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_field' ) );
	}

	/**
	 * When a WP user is deleted: hand their rooms to the next-in-line member
	 * (or delete empty rooms), then remove their memberships. Their messages
	 * stay and render as "Former member".
	 */
	public function cleanup_user( $user_id ) {
		foreach ( $this->rooms->ids_created_by( $user_id ) as $room_id ) {
			$successor = null;
			foreach ( $this->members->get_all( $room_id ) as $row ) {
				if ( (int) $row->user_id !== (int) $user_id ) {
					$successor = $row; // get_all() orders creator, moderators, members by join date.
					break;
				}
			}
			if ( $successor ) {
				$this->members->remove( $room_id, $user_id );
				$this->rooms->set_creator( $room_id, (int) $successor->user_id );
			} else {
				$this->rooms->delete( $room_id );
			}
		}

		$this->members->remove_all_for_user( $user_id );
	}

	public function profile_field( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Stoke Chat', 'stoke-chat' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Email alerts', 'stoke-chat' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="stokechat_email_optout" value="1"
							<?php checked( (bool) get_user_meta( $user->ID, Mailer::OPTOUT_META, true ) ); ?> />
						<?php esc_html_e( 'Do not email me when I am mentioned in chat while away', 'stoke-chat' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save_profile_field( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		// Nonce is verified by WP core before these profile-update hooks fire.
		if ( ! empty( $_POST['stokechat_email_optout'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_user_meta( $user_id, Mailer::OPTOUT_META, 1 );
		} else {
			delete_user_meta( $user_id, Mailer::OPTOUT_META );
		}
	}
}
