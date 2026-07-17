<?php
/**
 * Daily maintenance: prune scan rows older than the retention setting.
 * The event is always scheduled; prune() no-ops when retention is 0 (keep
 * forever), so changing the setting needs no cron reshuffling. Lifetime
 * totals and scan caps live in codes.scan_count and survive pruning.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gallus_QR_Cron {

	const HOOK = 'gallus_qr_daily_prune';

	/** @var Gallus_QR_Database */
	private $db;

	public function __construct( Gallus_QR_Database $db ) {
		$this->db = $db;
	}

	/**
	 * Wire up WordPress hooks. Called from gallus-qr.php on plugins_loaded.
	 */
	public function init() {
		add_action( self::HOOK, array( $this, 'prune' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
	}

	/**
	 * Make sure the daily event exists (also self-heals if it was lost).
	 */
	public function maybe_schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK );
		}
	}

	/**
	 * The daily prune, honouring the retention setting (0 = keep forever).
	 */
	public function prune() {
		$days = (int) Gallus_QR_Settings::get( 'retention_days' );
		if ( $days > 0 ) {
			$this->db->prune_scans( $days );
		}
	}

	/**
	 * Remove the scheduled event (deactivation / uninstall).
	 */
	public static function clear() {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
