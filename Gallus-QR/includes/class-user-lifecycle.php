<?php
/**
 * What happens to a member's codes when the member goes away.
 *
 * Ownership is a bare user_id integer, and nothing used to react to a user
 * being deleted — the rows simply kept pointing at an ID that no longer
 * resolved. WordPress reissues user IDs (InnoDB resets AUTO_INCREMENT to
 * MAX(ID)+1 on restart before MySQL 8.0, and WP-CLI imports, staging syncs and
 * multisite user moves all reassign them), so the next person to land on that
 * number silently inherited a stranger's codes — including the ability to
 * repoint a QR that is already printed on a product.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gallus_QR_User_Lifecycle {

	/** @var Gallus_QR_Database */
	private $db;

	public function __construct( Gallus_QR_Database $db ) {
		$this->db = $db;
	}

	/**
	 * Hook into WordPress. Called from gallus-qr.php.
	 */
	public function init() {
		add_action( 'deleted_user', array( $this, 'on_deleted_user' ), 10, 2 );
		add_action( 'remove_user_from_blog', array( $this, 'on_removed_from_blog' ), 10, 2 );
	}

	/**
	 * A user was deleted outright.
	 *
	 * @param int      $user_id  The departing user.
	 * @param int|null $reassign User chosen on WordPress's own "what should be
	 *                           done with their content?" prompt, or null.
	 */
	public function on_deleted_user( $user_id, $reassign = null ) {
		$this->rehome( (int) $user_id, $reassign );
	}

	/**
	 * A user was removed from one site of a network. Their codes live in that
	 * site's tables, so the same rehoming applies — but only there.
	 *
	 * @param int $user_id
	 * @param int $blog_id
	 */
	public function on_removed_from_blog( $user_id, $blog_id ) {
		$switched = false;

		if ( is_multisite() && (int) $blog_id && (int) $blog_id !== (int) get_current_blog_id() ) {
			switch_to_blog( (int) $blog_id );
			$switched = true;
		}

		// WordPress passes no reassignment target on this hook, so fall through
		// to the site operator rather than destroying anything.
		$this->rehome( (int) $user_id, null );

		if ( $switched ) {
			restore_current_blog();
		}
	}

	/**
	 * Move a departing user's codes and presets somewhere valid, so no row is
	 * left pointing at an ID that could later be reissued.
	 *
	 * @param int      $user_id
	 * @param int|null $reassign
	 */
	private function rehome( $user_id, $reassign ) {
		if ( $user_id < 1 ) {
			return;
		}

		$successor = ( $reassign && (int) $reassign > 0 )
			? (int) $reassign
			: $this->db->first_admin_id();

		/**
		 * Filter who inherits a departing member's codes and presets.
		 *
		 * Default is WordPress's own reassignment choice when the admin made
		 * one, otherwise the site's first administrator. Deleting is NOT the
		 * default: a trackable code may already be printed on packaging or
		 * etched into a PCB, and dropping the row breaks it in the field with
		 * no way back. Return 0 to delete the rows instead.
		 *
		 * @param int      $successor User ID to inherit, or 0 to delete.
		 * @param int      $user_id   The departing user.
		 * @param int|null $reassign  WordPress's reassignment target, if any.
		 */
		$successor = (int) apply_filters( 'gallus_qr_inherit_owner', $successor, $user_id, $reassign );

		if ( $successor > 0 && $successor !== $user_id ) {
			$this->db->reassign_owner( $user_id, $successor );
			return;
		}

		if ( $successor < 1 ) {
			$this->db->delete_owner_rows( $user_id );
		}
	}
}
