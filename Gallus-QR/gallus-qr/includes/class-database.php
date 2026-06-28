<?php
/**
 * Database layer for Gallus QR: owns the two custom tables and every query
 * against them. Nothing else in the plugin touches $wpdb directly.
 *
 *   {prefix}gallus_qr_codes  — one row per saved (trackable) code
 *   {prefix}gallus_qr_scans  — one row per scan/redirect hit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gallus_QR_Database {

	/** @return string Fully-qualified codes table name. */
	public function codes_table() {
		global $wpdb;
		return $wpdb->prefix . 'gallus_qr_codes';
	}

	/** @return string Fully-qualified scans table name. */
	public function scans_table() {
		global $wpdb;
		return $wpdb->prefix . 'gallus_qr_scans';
	}

	/**
	 * Create/upgrade the tables. Called from the activation hook. dbDelta is
	 * idempotent — safe to run on every activation.
	 */
	public function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$codes           = $this->codes_table();
		$scans           = $this->scans_table();

		// Note: dbDelta is fussy — two spaces after PRIMARY KEY, lowercase types.
		$sql_codes = "CREATE TABLE {$codes} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(16) NOT NULL,
			title varchar(255) NOT NULL DEFAULT '',
			destination text NOT NULL,
			trackable tinyint(1) NOT NULL DEFAULT 1,
			design longtext NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$charset_collate};";

		$sql_scans = "CREATE TABLE {$scans} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code_id bigint(20) unsigned NOT NULL,
			scanned_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			ip_hash char(64) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY code_id (code_id),
			KEY scanned_at (scanned_at)
		) {$charset_collate};";

		dbDelta( $sql_codes );
		dbDelta( $sql_scans );

		// Record the schema version so upgrades can run without reactivation.
		update_option( 'gallus_qr_db_version', GALLUS_QR_DB_VERSION );
	}

	/**
	 * Generate a short slug that isn't already taken (base62, 6 chars).
	 *
	 * @return string
	 */
	public function generate_unique_slug() {
		$alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		do {
			$slug = '';
			for ( $i = 0; $i < 6; $i++ ) {
				$slug .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
			}
		} while ( $this->get_code_by_slug( $slug ) );

		return $slug;
	}

	/**
	 * Insert a new code. Returns the slug on success, or false on failure.
	 *
	 * @param string $title       Human label.
	 * @param string $destination Real target URL.
	 * @param bool   $trackable   Whether scans should be counted.
	 * @param string $design      Normalised design JSON (or '' for none).
	 * @return string|false
	 */
	public function insert_code( $title, $destination, $trackable = true, $design = '' ) {
		global $wpdb;

		$slug = $this->generate_unique_slug();

		$ok = $wpdb->insert(
			$this->codes_table(),
			array(
				'slug'        => $slug,
				'title'       => $title,
				'destination' => $destination,
				'trackable'   => $trackable ? 1 : 0,
				'design'      => $design,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return $ok ? $slug : false;
	}

	/**
	 * Rename a code (update its label).
	 *
	 * @param int    $id
	 * @param string $title
	 * @return bool
	 */
	public function update_code_title( $id, $title ) {
		global $wpdb;
		return false !== $wpdb->update(
			$this->codes_table(),
			array( 'title' => $title ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Change a code's destination. The slug/short-link is unchanged, so an
	 * already-printed QR instantly re-points — the dynamic-code superpower.
	 *
	 * @param int    $id
	 * @param string $destination
	 * @return bool
	 */
	public function update_code_destination( $id, $destination ) {
		global $wpdb;
		return false !== $wpdb->update(
			$this->codes_table(),
			array( 'destination' => $destination ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Delete a code and all of its scan rows.
	 *
	 * @param int $id
	 * @return bool
	 */
	public function delete_code( $id ) {
		global $wpdb;
		$wpdb->delete( $this->scans_table(), array( 'code_id' => $id ), array( '%d' ) );
		return false !== $wpdb->delete( $this->codes_table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Look up a code by its slug.
	 *
	 * @param string $slug
	 * @return object|null
	 */
	public function get_code_by_slug( $slug ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->codes_table()} WHERE slug = %s",
				$slug
			)
		);
	}

	/**
	 * Record a single scan.
	 *
	 * @param int    $code_id
	 * @param string $ip_hash
	 * @param string $user_agent
	 */
	public function insert_scan( $code_id, $ip_hash, $user_agent ) {
		global $wpdb;
		$wpdb->insert(
			$this->scans_table(),
			array(
				'code_id'    => $code_id,
				'scanned_at' => current_time( 'mysql' ),
				'ip_hash'    => $ip_hash,
				'user_agent' => $user_agent,
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * All saved codes, newest first, each with a total_scans column.
	 *
	 * @return array
	 */
	public function get_codes_with_counts() {
		global $wpdb;
		$codes = $this->codes_table();
		$scans = $this->scans_table();

		return $wpdb->get_results(
			"SELECT c.*, COUNT( s.id ) AS total_scans
			 FROM {$codes} c
			 LEFT JOIN {$scans} s ON s.code_id = c.id
			 GROUP BY c.id
			 ORDER BY c.created_at DESC"
		);
	}

	/**
	 * Daily scan counts for one code over the last N days, as a slug => count
	 * map keyed by 'Y-m-d'. Missing days are filled with 0 by the caller.
	 *
	 * @param int $code_id
	 * @param int $days
	 * @return array<string,int>
	 */
	public function get_daily_scans( $code_id, $days = 30 ) {
		global $wpdb;
		$scans = $this->scans_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE( scanned_at ) AS day, COUNT(*) AS hits
				 FROM {$scans}
				 WHERE code_id = %d AND scanned_at >= ( NOW() - INTERVAL %d DAY )
				 GROUP BY DATE( scanned_at )",
				$code_id,
				$days
			)
		);

		$map = array();
		foreach ( $rows as $row ) {
			$map[ $row->day ] = (int) $row->hits;
		}
		return $map;
	}

	/**
	 * Total + unique scan counts for a code since a given datetime.
	 * Unique is by distinct (non-empty) IP hash.
	 *
	 * @param int    $code_id
	 * @param string $since MySQL datetime ('Y-m-d H:i:s').
	 * @return array{total:int,unique:int}
	 */
	public function get_range_summary( $code_id, $since ) {
		global $wpdb;
		$scans = $this->scans_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total,
				        COUNT( DISTINCT NULLIF( ip_hash, '' ) ) AS uniques
				 FROM {$scans}
				 WHERE code_id = %d AND scanned_at >= %s",
				$code_id,
				$since
			)
		);

		return array(
			'total'  => $row ? (int) $row->total : 0,
			'unique' => $row ? (int) $row->uniques : 0,
		);
	}

	/**
	 * Device-type breakdown for a code since a given datetime. User-agents are
	 * fetched and bucketed in PHP (SQL can't parse them).
	 *
	 * @param int    $code_id
	 * @param string $since
	 * @return array{Mobile:int,Tablet:int,Desktop:int}
	 */
	public function get_device_breakdown( $code_id, $since ) {
		global $wpdb;
		$scans = $this->scans_table();

		$uas = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_agent FROM {$scans}
				 WHERE code_id = %d AND scanned_at >= %s",
				$code_id,
				$since
			)
		);

		$out = array(
			'Mobile'  => 0,
			'Tablet'  => 0,
			'Desktop' => 0,
		);
		foreach ( $uas as $ua ) {
			$out[ self::categorize_user_agent( $ua ) ]++;
		}
		return $out;
	}

	/**
	 * Crude device bucket from a user-agent string.
	 *
	 * @param string $ua
	 * @return string One of Mobile|Tablet|Desktop.
	 */
	public static function categorize_user_agent( $ua ) {
		$ua = strtolower( (string) $ua );

		if ( false !== strpos( $ua, 'ipad' ) || false !== strpos( $ua, 'tablet' ) ) {
			return 'Tablet';
		}
		if ( false !== strpos( $ua, 'mobi' )
			|| false !== strpos( $ua, 'android' )
			|| false !== strpos( $ua, 'iphone' ) ) {
			return 'Mobile';
		}
		return 'Desktop';
	}
}
