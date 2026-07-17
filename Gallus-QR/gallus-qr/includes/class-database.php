<?php
/**
 * Database layer for Gallus QR: owns the custom tables and every query
 * against them. Nothing else in the plugin touches $wpdb directly.
 *
 *   {prefix}gallus_qr_codes    — one row per saved code
 *   {prefix}gallus_qr_scans    — one row per scan/redirect hit
 *   {prefix}gallus_qr_presets  — one row per saved design preset
 *
 * All datetimes are stored in UTC and compared against UTC_TIMESTAMP();
 * convert to the site timezone only for display.
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

	/** @return string Fully-qualified presets table name. */
	public function presets_table() {
		global $wpdb;
		return $wpdb->prefix . 'gallus_qr_presets';
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
		$presets         = $this->presets_table();

		// Note: dbDelta is fussy — two spaces after PRIMARY KEY, lowercase types,
		// no backticks, and `text` columns cannot take a DEFAULT.
		$sql_codes = "CREATE TABLE {$codes} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(64) NOT NULL,
			title varchar(255) NOT NULL DEFAULT '',
			payload_type varchar(20) NOT NULL DEFAULT 'url',
			payload longtext NULL,
			destination text NOT NULL,
			trackable tinyint(1) NOT NULL DEFAULT 1,
			design longtext NULL,
			status varchar(10) NOT NULL DEFAULT 'active',
			expires_at datetime NULL DEFAULT NULL,
			max_scans bigint(20) unsigned NOT NULL DEFAULT 0,
			scan_count bigint(20) unsigned NOT NULL DEFAULT 0,
			fallback_url text NULL,
			dest_mode varchar(10) NOT NULL DEFAULT 'single',
			destination_b text NULL,
			switch_at datetime NULL DEFAULT NULL,
			ab_split tinyint(3) unsigned NOT NULL DEFAULT 50,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$charset_collate};";

		$sql_scans = "CREATE TABLE {$scans} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code_id bigint(20) unsigned NOT NULL,
			scanned_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			ip_hash char(64) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			country char(2) NOT NULL DEFAULT '',
			device varchar(10) NOT NULL DEFAULT '',
			os varchar(20) NOT NULL DEFAULT '',
			browser varchar(20) NOT NULL DEFAULT '',
			variant char(1) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY code_id (code_id),
			KEY scanned_at (scanned_at),
			KEY code_scanned (code_id,scanned_at)
		) {$charset_collate};";

		$sql_presets = "CREATE TABLE {$presets} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(100) NOT NULL,
			design longtext NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id)
		) {$charset_collate};";

		dbDelta( $sql_codes );
		dbDelta( $sql_scans );
		dbDelta( $sql_presets );

		// Record the schema version so upgrades can run without reactivation.
		update_option( 'gallus_qr_db_version', GALLUS_QR_DB_VERSION );
	}

	/**
	 * In-place upgrade, called on plugins_loaded when the stored schema version
	 * differs from GALLUS_QR_DB_VERSION. Runs dbDelta (additive) plus one-time
	 * data backfills. Also covers plugin *updates*, where activation hooks
	 * never fire.
	 */
	public function maybe_upgrade() {
		global $wpdb;

		$installed = (string) get_option( 'gallus_qr_db_version', '0' );
		if ( GALLUS_QR_DB_VERSION === $installed ) {
			return;
		}

		$this->create_tables();

		if ( (int) $installed < 3 ) {
			// Backfill the denormalised scan counter from the raw scan rows.
			$codes = $this->codes_table();
			$scans = $this->scans_table();
			$wpdb->query(
				"UPDATE {$codes} c
				 SET c.scan_count = ( SELECT COUNT(*) FROM {$scans} s WHERE s.code_id = c.id )"
			);

			// Admins get the plugin capability (activation hooks don't run on updates).
			$role = get_role( 'administrator' );
			if ( $role ) {
				$role->add_cap( 'manage_gallus_qr' );
			}
		}
	}

	/**
	 * Check a custom slug's *format*: lowercase letters, digits, hyphens, max
	 * 64 chars, and not on the reserved list. (Availability is a separate,
	 * per-install question — see is_slug_available().)
	 *
	 * @param string $slug
	 * @return true|WP_Error
	 */
	public static function validate_slug_format( $slug ) {
		if ( ! is_string( $slug ) || ! preg_match( '/^[a-z0-9-]{1,64}$/', $slug ) ) {
			return new WP_Error(
				'gallus_qr_bad_slug',
				__( 'Slugs may only use lowercase letters, numbers and hyphens (max 64 characters).', 'gallus-qr' ),
				array( 'status' => 400 )
			);
		}

		$reserved = apply_filters(
			'gallus_qr_reserved_slugs',
			array( 'qr', 'admin', 'api', 'login', 'new', 'edit', 'delete', 'stats', 'preview', 'wp-admin', 'wp-login' )
		);

		if ( in_array( $slug, $reserved, true ) ) {
			return new WP_Error(
				'gallus_qr_reserved_slug',
				__( 'That slug is reserved — pick another.', 'gallus-qr' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Is a slug free to use? The lookup runs through the table's collation,
	 * which is case-insensitive — matching the UNIQUE key, so a lowercase
	 * custom slug correctly reads as taken against an old mixed-case one.
	 *
	 * @param string $slug
	 * @return bool
	 */
	public function is_slug_available( $slug ) {
		return null === $this->get_code_by_slug( $slug );
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
	 * @param string $title        Human label.
	 * @param string $destination  Target URL, or the exact encoded string for
	 *                             non-URL payload types.
	 * @param bool   $trackable    Whether scans should be counted (URL only).
	 * @param string $design       Normalised design JSON (or '' for none).
	 * @param string $payload_type One of Gallus_QR_Payloads::TYPES.
	 * @param string $payload      Structured payload JSON (or '' for none).
	 * @param string $slug         Custom slug (validated by the caller), or ''
	 *                             to generate a random one. The UNIQUE key is
	 *                             the final referee on races.
	 * @return string|false
	 */
	public function insert_code( $title, $destination, $trackable = true, $design = '', $payload_type = 'url', $payload = '', $slug = '' ) {
		global $wpdb;

		if ( '' === $slug ) {
			$slug = $this->generate_unique_slug();
		}

		$ok = $wpdb->insert(
			$this->codes_table(),
			array(
				'slug'         => $slug,
				'title'        => $title,
				'payload_type' => $payload_type,
				'payload'      => $payload,
				'destination'  => $destination,
				'trackable'    => $trackable ? 1 : 0,
				'design'       => $design,
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
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
	 * Generic whitelisted field update, used by the REST PATCH route. Bumps
	 * updated_at. Returns false when nothing valid was passed or the query
	 * failed.
	 *
	 * @param int   $id
	 * @param array $fields Column => value. Unknown columns are dropped.
	 * @return bool
	 */
	public function update_code_fields( $id, array $fields ) {
		global $wpdb;

		$editable = array(
			'title'         => '%s',
			'destination'   => '%s',
			'status'        => '%s',
			'expires_at'    => '%s',
			'max_scans'     => '%d',
			'fallback_url'  => '%s',
			'dest_mode'     => '%s',
			'destination_b' => '%s',
			'switch_at'     => '%s',
			'ab_split'      => '%d',
		);

		$data    = array();
		$formats = array();
		foreach ( $fields as $column => $value ) {
			if ( isset( $editable[ $column ] ) ) {
				$data[ $column ] = $value;
				$formats[]       = null === $value ? null : $editable[ $column ];
			}
		}

		if ( empty( $data ) ) {
			return false;
		}

		$data['updated_at'] = current_time( 'mysql', true );
		$formats[]          = '%s';

		return false !== $wpdb->update(
			$this->codes_table(),
			$data,
			array( 'id' => (int) $id ),
			$formats,
			array( '%d' )
		);
	}

	/**
	 * Atomically claim one scan against a code's cap. The single UPDATE both
	 * increments the counter and enforces max_scans, so concurrent scans can
	 * never overshoot the cap (no read-then-write race).
	 *
	 * @param int $code_id
	 * @return bool True when the scan is allowed (counter incremented).
	 */
	public function try_count_scan( $code_id ) {
		global $wpdb;

		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->codes_table()}
				 SET scan_count = scan_count + 1
				 WHERE id = %d AND ( max_scans = 0 OR scan_count < max_scans )",
				$code_id
			)
		);

		return (bool) $rows;
	}

	/**
	 * Per-variant scan counts for an A/B code since a given datetime.
	 *
	 * @param int    $code_id
	 * @param string $since MySQL datetime (UTC).
	 * @return array<string,int> e.g. array( 'A' => 12, 'B' => 9 ).
	 */
	public function get_variant_counts( $code_id, $since ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT variant, COUNT(*) AS hits
				 FROM {$this->scans_table()}
				 WHERE code_id = %d AND scanned_at >= %s AND variant <> ''
				 GROUP BY variant",
				$code_id,
				$since
			)
		);

		$out = array();
		foreach ( $rows as $row ) {
			$out[ $row->variant ] = (int) $row->hits;
		}
		return $out;
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
	 * Look up a code by its ID.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public function get_code_by_id( $id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->codes_table()} WHERE id = %d",
				$id
			)
		);
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
	 * Record a single scan. Device/OS/browser are parsed from the UA at insert
	 * time (see Gallus_QR_Analytics) so breakdowns stay cheap GROUP BYs.
	 *
	 * @param int    $code_id
	 * @param string $ip_hash
	 * @param string $user_agent
	 * @param string $variant 'A'/'B' for A/B codes, '' otherwise.
	 * @param string $country Two-letter code, '' = unknown.
	 */
	public function insert_scan( $code_id, $ip_hash, $user_agent, $variant = '', $country = '' ) {
		global $wpdb;

		$parsed = Gallus_QR_Analytics::parse_user_agent( $user_agent );

		$wpdb->insert(
			$this->scans_table(),
			array(
				'code_id'    => $code_id,
				'scanned_at' => current_time( 'mysql', true ),
				'ip_hash'    => $ip_hash,
				'user_agent' => $user_agent,
				'variant'    => $variant,
				'country'    => $country,
				'device'     => $parsed['device'],
				'os'         => $parsed['os'],
				'browser'    => $parsed['browser'],
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	// --- Design presets -----------------------------------------------------------

	/** @return array All presets, newest first. */
	public function get_presets() {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT * FROM {$this->presets_table()} ORDER BY created_at DESC"
		);
	}

	/**
	 * Save a design preset.
	 *
	 * @param string $name
	 * @param string $design Normalised design JSON.
	 * @return int|false New preset ID, or false.
	 */
	public function insert_preset( $name, $design ) {
		global $wpdb;

		$ok = $wpdb->insert(
			$this->presets_table(),
			array(
				'name'       => $name,
				'design'     => $design,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Delete a preset.
	 *
	 * @param int $id
	 * @return bool
	 */
	public function delete_preset( $id ) {
		global $wpdb;
		return false !== $wpdb->delete( $this->presets_table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/** @return int Total number of saved codes. */
	public function count_codes() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->codes_table()}" );
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
	 * One page of codes (newest first, each with total_scans), plus the total
	 * row count for pagination headers.
	 *
	 * @param int    $page     1-based page number.
	 * @param int    $per_page Rows per page (1–100).
	 * @param string $search   Optional needle matched against title/slug/destination.
	 * @return array{items:array,total:int}
	 */
	public function get_codes_page( $page, $per_page, $search = '' ) {
		global $wpdb;
		$codes = $this->codes_table();
		$scans = $this->scans_table();

		$page     = max( 1, (int) $page );
		$per_page = max( 1, min( 100, (int) $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		if ( '' !== $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT c.*, COUNT( s.id ) AS total_scans
					 FROM {$codes} c
					 LEFT JOIN {$scans} s ON s.code_id = c.id
					 WHERE c.title LIKE %s OR c.slug LIKE %s OR c.destination LIKE %s
					 GROUP BY c.id
					 ORDER BY c.created_at DESC
					 LIMIT %d OFFSET %d",
					$like,
					$like,
					$like,
					$per_page,
					$offset
				)
			);
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$codes} c
					 WHERE c.title LIKE %s OR c.slug LIKE %s OR c.destination LIKE %s",
					$like,
					$like,
					$like
				)
			);
		} else {
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT c.*, COUNT( s.id ) AS total_scans
					 FROM {$codes} c
					 LEFT JOIN {$scans} s ON s.code_id = c.id
					 GROUP BY c.id
					 ORDER BY c.created_at DESC
					 LIMIT %d OFFSET %d",
					$per_page,
					$offset
				)
			);
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$codes}" );
		}

		return array(
			'items' => $items,
			'total' => $total,
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
				 WHERE code_id = %d AND scanned_at >= ( UTC_TIMESTAMP() - INTERVAL %d DAY )
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
	 * Breakdown of scans by a parsed UA column (device / os / browser) since a
	 * given datetime. Rows written before v2 have empty columns, so their raw
	 * user-agents are parsed in PHP as a fallback — new rows are a pure
	 * GROUP BY.
	 *
	 * @param int    $code_id 0 = all codes.
	 * @param string $since   MySQL datetime (UTC).
	 * @param string $column  One of device|os|browser.
	 * @return array<string,int> Bucket => count, biggest first.
	 */
	public function get_column_breakdown( $code_id, $since, $column ) {
		global $wpdb;
		$scans = $this->scans_table();

		if ( ! in_array( $column, array( 'device', 'os', 'browser' ), true ) ) {
			return array();
		}

		$code_where = $code_id ? $wpdb->prepare( 'AND code_id = %d', $code_id ) : '';

		$out = array();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$column} AS bucket, COUNT(*) AS hits
				 FROM {$scans}
				 WHERE scanned_at >= %s AND {$column} <> '' {$code_where}
				 GROUP BY {$column}",
				$since
			)
		);
		foreach ( $rows as $row ) {
			$out[ $row->bucket ] = (int) $row->hits;
		}

		// Legacy rows (pre-v2): parse the stored UA on the fly.
		$uas = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_agent FROM {$scans}
				 WHERE scanned_at >= %s AND {$column} = '' {$code_where}",
				$since
			)
		);
		foreach ( $uas as $ua ) {
			$parsed = Gallus_QR_Analytics::parse_user_agent( $ua );
			$bucket = $parsed[ $column ];
			$out[ $bucket ] = isset( $out[ $bucket ] ) ? $out[ $bucket ] + 1 : 1;
		}

		arsort( $out );
		return $out;
	}

	/**
	 * Device-type breakdown for a code since a given datetime.
	 *
	 * @param int    $code_id
	 * @param string $since
	 * @return array{Mobile:int,Tablet:int,Desktop:int}
	 */
	public function get_device_breakdown( $code_id, $since ) {
		$out = array(
			'Mobile'  => 0,
			'Tablet'  => 0,
			'Desktop' => 0,
		);
		foreach ( $this->get_column_breakdown( $code_id, $since, 'device' ) as $bucket => $hits ) {
			$out[ $bucket ] = $hits;
		}
		return $out;
	}

	/**
	 * Country breakdown since a given datetime ('' bucket = unknown).
	 *
	 * @param int    $code_id 0 = all codes.
	 * @param string $since
	 * @param int    $limit
	 * @return array<string,int> Country code => count, biggest first.
	 */
	public function get_country_breakdown( $code_id, $since, $limit = 10 ) {
		global $wpdb;
		$scans      = $this->scans_table();
		$code_where = $code_id ? $wpdb->prepare( 'AND code_id = %d', $code_id ) : '';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT country, COUNT(*) AS hits
				 FROM {$scans}
				 WHERE scanned_at >= %s {$code_where}
				 GROUP BY country
				 ORDER BY hits DESC
				 LIMIT %d",
				$since,
				max( 1, (int) $limit )
			)
		);

		$out = array();
		foreach ( $rows as $row ) {
			$out[ $row->country ] = (int) $row->hits;
		}
		return $out;
	}

	/**
	 * Scans per hour-of-day (0–23, UTC) since a given datetime.
	 *
	 * @param int    $code_id 0 = all codes.
	 * @param string $since
	 * @return array<int,int> 24 entries, hour => count.
	 */
	public function get_hourly_breakdown( $code_id, $since ) {
		global $wpdb;
		$scans      = $this->scans_table();
		$code_where = $code_id ? $wpdb->prepare( 'AND code_id = %d', $code_id ) : '';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT HOUR( scanned_at ) AS h, COUNT(*) AS hits
				 FROM {$scans}
				 WHERE scanned_at >= %s {$code_where}
				 GROUP BY HOUR( scanned_at )",
				$since
			)
		);

		$out = array_fill( 0, 24, 0 );
		foreach ( $rows as $row ) {
			$out[ (int) $row->h ] = (int) $row->hits;
		}
		return $out;
	}

	/**
	 * The most-scanned codes since a given datetime (dashboard widget).
	 *
	 * @param string $since MySQL datetime (UTC).
	 * @param int    $limit
	 * @return array Rows with slug/title plus a hits column.
	 */
	public function get_top_codes( $since, $limit = 5 ) {
		global $wpdb;
		$codes = $this->codes_table();
		$scans = $this->scans_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.slug, c.title, COUNT( s.id ) AS hits
				 FROM {$codes} c
				 INNER JOIN {$scans} s ON s.code_id = c.id
				 WHERE s.scanned_at >= %s
				 GROUP BY c.id
				 ORDER BY hits DESC
				 LIMIT %d",
				$since,
				max( 1, (int) $limit )
			)
		);
	}

	/**
	 * All scan rows for a code, oldest first (CSV export).
	 *
	 * @param int $code_id
	 * @return array
	 */
	public function get_scans_for_export( $code_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT scanned_at, country, device, os, browser, variant, ip_hash
				 FROM {$this->scans_table()}
				 WHERE code_id = %d
				 ORDER BY scanned_at ASC",
				$code_id
			),
			ARRAY_A
		);
	}

	/**
	 * Delete scan rows older than N days. The denormalised scan_count on the
	 * codes table is untouched, so lifetime totals and caps survive pruning.
	 *
	 * @param int $days
	 * @return int Rows deleted.
	 */
	public function prune_scans( $days ) {
		global $wpdb;
		$days = (int) $days;
		if ( $days < 1 ) {
			return 0;
		}

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->scans_table()}
				 WHERE scanned_at < ( UTC_TIMESTAMP() - INTERVAL %d DAY )",
				$days
			)
		);
	}
}
