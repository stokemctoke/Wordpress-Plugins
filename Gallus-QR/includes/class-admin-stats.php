<?php
/**
 * The Scan Stats screen: per-code stats table with inline editing
 * (rename / retarget / pause / delete via the REST API — stats.js wires the
 * buttons) and server-rendered sparklines.
 *
 * The menu entry itself is registered by Gallus_QR_Admin (it owns the parent
 * menu); it hands the page hook back here via set_hook().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gallus_QR_Admin_Stats {

	/** @var Gallus_QR_Database */
	private $db;

	/** Hook suffix of this page, set by Gallus_QR_Admin. @var string */
	private $hook = '';

	public function __construct( Gallus_QR_Database $db ) {
		$this->db = $db;
	}

	/** @param string $hook Hook suffix from add_submenu_page(). */
	public function set_hook( $hook ) {
		$this->hook = (string) $hook;
	}

	/** @return string Hook suffix of the stats page. */
	public function hook() {
		return $this->hook;
	}

	/** Allowed date ranges for the dashboard: query value => [label, days]. */
	private function ranges() {
		return array(
			'7'   => array( __( 'Last 7 days', 'gallus-qr' ), 7 ),
			'30'  => array( __( 'Last 30 days', 'gallus-qr' ), 30 ),
			'90'  => array( __( 'Last 90 days', 'gallus-qr' ), 90 ),
			'all' => array( __( 'All time', 'gallus-qr' ), 3650 ),
		);
	}

	/**
	 * A code's lifecycle state for the Status column.
	 *
	 * @param object $code
	 * @param string $now UTC MySQL datetime.
	 * @return array{key:string,label:string} key: active|paused|expired|capped.
	 */
	private function lifecycle_state( $code, $now ) {
		if ( isset( $code->status ) && 'paused' === $code->status ) {
			return array(
				'key'   => 'paused',
				'label' => __( 'Paused', 'gallus-qr' ),
			);
		}
		if ( ! empty( $code->expires_at ) && $code->expires_at <= $now ) {
			return array(
				'key'   => 'expired',
				'label' => __( 'Expired', 'gallus-qr' ),
			);
		}
		if ( isset( $code->max_scans ) && (int) $code->max_scans > 0
			&& (int) $code->scan_count >= (int) $code->max_scans ) {
			return array(
				'key'   => 'capped',
				'label' => __( 'Limit reached', 'gallus-qr' ),
			);
		}
		return array(
			'key'   => 'active',
			'label' => __( 'Active', 'gallus-qr' ),
		);
	}

	/**
	 * The scan-stats dashboard: a date-range selector plus, per code, its
	 * editable label/destination, lifecycle status, totals (total + unique),
	 * device split, a bar chart, and re-download/delete actions. Charts are
	 * server-rendered; edits go through the REST API (stats.js).
	 *
	 * Admins also get an owner filter (all / mine / specific user).
	 */
	/**
	 * The slice of codes the Scan Stats screen is currently showing.
	 *
	 * Shared with the asset enqueue, which builds the slug => design map for
	 * re-downloads: that map is inlined into the page with wp_localize_script,
	 * so it has to cover this page's rows and no more. Rendering every code's
	 * design regardless of pagination would put the whole library — including
	 * every embedded logo — into every stats page load.
	 *
	 * Each row also costs four further queries (range summary, two breakdowns,
	 * daily counts), so the list is bounded rather than growing with the number
	 * of codes on the site. An administrator's default view spans every user's
	 * codes, and any one of those users can create more.
	 *
	 * @return array{codes:array,total:int,pages:int,paged:int,per_page:int}
	 */
	public function current_page() {
		$owner = Gallus_QR_Settings::request_owner_scope();

		$per_page = (int) apply_filters( 'gallus_qr_stats_per_page', 20 );
		$per_page = max( 1, min( 200, $per_page ) );

		$total = (int) $this->db->count_codes( $owner );
		$pages = max( 1, (int) ceil( $total / $per_page ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = isset( $_GET['gqr_paged'] ) ? absint( wp_unslash( $_GET['gqr_paged'] ) ) : 1;
		$paged = max( 1, min( $pages, $paged ) );

		return array(
			'codes'    => $this->db->get_codes_owned( $owner, $per_page, ( $paged - 1 ) * $per_page ),
			'total'    => $total,
			'pages'    => $pages,
			'paged'    => $paged,
			'per_page' => $per_page,
		);
	}

	public function render_page() {
		$can_filter = Gallus_QR_Settings::can_manage_all();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$owner_raw = isset( $_GET['gqr_owner'] ) ? sanitize_text_field( wp_unslash( $_GET['gqr_owner'] ) ) : ( $can_filter ? 'all' : 'me' );
		$owner     = Gallus_QR_Settings::resolve_owner_filter( $owner_raw );
		$ranges    = $this->ranges();

		$page  = $this->current_page();
		$codes = $page['codes'];
		$total = $page['total'];
		$pages = $page['pages'];
		$paged = $page['paged'];

		// Selected range (default 30 days).
		$range_key = isset( $_GET['gqr_range'] ) ? sanitize_key( $_GET['gqr_range'] ) : '30'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $ranges[ $range_key ] ) ) {
			$range_key = '30';
		}
		$days = $ranges[ $range_key ][1];

		// Scan timestamps are stored in UTC, so range boundaries are UTC too.
		$now     = current_time( 'mysql', true );
		$since   = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$owners  = $can_filter ? $this->owner_filter_choices() : array();
		$show_owner_col = $can_filter && 'me' !== $owner_raw;
		?>
		<div class="wrap gqr-wrap">
			<h1><?php esc_html_e( 'Gallus QR — Scan Stats', 'gallus-qr' ); ?></h1>

			<div id="gqr-stats-notice"></div>

			<form method="get" class="gqr-range-form">
				<input type="hidden" name="page" value="gallus-qr-stats">
				<label>
					<?php esc_html_e( 'Date range:', 'gallus-qr' ); ?>
					<select name="gqr_range" onchange="this.form.submit()">
						<?php foreach ( $ranges as $key => $info ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $range_key ); ?>>
								<?php echo esc_html( $info[0] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<?php if ( $can_filter ) : ?>
					<label>
						<?php esc_html_e( 'Owner:', 'gallus-qr' ); ?>
						<select name="gqr_owner" onchange="this.form.submit()">
							<option value="all" <?php selected( $owner_raw, 'all' ); ?>><?php esc_html_e( 'All codes', 'gallus-qr' ); ?></option>
							<option value="me" <?php selected( $owner_raw, 'me' ); ?>><?php esc_html_e( 'My codes', 'gallus-qr' ); ?></option>
							<?php if ( ! empty( $owners ) ) : ?>
								<optgroup label="<?php esc_attr_e( 'By user', 'gallus-qr' ); ?>">
									<?php foreach ( $owners as $uid => $label ) : ?>
										<option value="<?php echo esc_attr( (string) $uid ); ?>" <?php selected( $owner_raw, (string) $uid ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</optgroup>
							<?php endif; ?>
						</select>
					</label>
				<?php endif; ?>
			</form>

			<?php if ( empty( $codes ) ) : ?>
				<p>
					<?php
					if ( $can_filter && 'all' !== $owner_raw && $this->db->count_codes( null ) > 0 ) {
						esc_html_e( 'No codes match this owner filter. Try “All codes” or another user.', 'gallus-qr' );
					} else {
						esc_html_e( 'No saved codes yet. Create one on the Generator screen.', 'gallus-qr' );
					}
					?>
				</p>
			<?php else : ?>
				<?php $this->render_overview( $since, $owner ); ?>

				<table class="widefat gqr-stats-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Label', 'gallus-qr' ); ?></th>
							<?php if ( $show_owner_col ) : ?>
								<th><?php esc_html_e( 'Owner', 'gallus-qr' ); ?></th>
							<?php endif; ?>
							<th><?php esc_html_e( 'Short link', 'gallus-qr' ); ?></th>
							<th><?php esc_html_e( 'Destination', 'gallus-qr' ); ?></th>
							<th><?php esc_html_e( 'Status', 'gallus-qr' ); ?></th>
							<th><?php esc_html_e( 'Scans (range)', 'gallus-qr' ); ?></th>
							<th><?php esc_html_e( 'Devices', 'gallus-qr' ); ?></th>
							<th><?php echo esc_html( $ranges[ $range_key ][0] ); ?></th>
							<th><?php esc_html_e( 'Actions', 'gallus-qr' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $codes as $code ) :
							$short      = home_url( '/qr/' . $code->slug );
							$type       = ! empty( $code->payload_type ) ? $code->payload_type : 'url';
							$is_url     = ( 'url' === $type );
							$is_tracked = ( (int) $code->trackable === 1 );
							$state      = $this->lifecycle_state( $code, $now );
							// What a re-download encodes: tracked codes encode their
							// short link; library codes encode the payload itself.
							$encodes = $is_tracked ? $short : $code->destination;
							$summary = $this->db->get_range_summary( (int) $code->id, $since );
							$devices = $this->db->get_device_breakdown( (int) $code->id, $since );
							$is_ab   = $is_tracked && ! empty( $code->dest_mode ) && 'ab' === $code->dest_mode;
							$splits  = $is_ab ? $this->db->get_variant_counts( (int) $code->id, $since ) : array();
							?>
							<tr data-id="<?php echo (int) $code->id; ?>">
								<td>
									<span class="gqr-inline">
										<input type="text" class="gqr-field-title" value="<?php echo esc_attr( $code->title ); ?>" placeholder="<?php esc_attr_e( 'Label', 'gallus-qr' ); ?>">
										<button type="button" class="button button-small gqr-save-title"><?php esc_html_e( 'Save', 'gallus-qr' ); ?></button>
									</span>
								</td>
								<?php if ( $show_owner_col ) : ?>
									<td class="gqr-owner">
										<?php echo esc_html( $this->owner_label( isset( $code->user_id ) ? (int) $code->user_id : 0 ) ); ?>
									</td>
								<?php endif; ?>
								<td>
									<?php if ( $is_tracked ) : ?>
										<a href="<?php echo esc_url( $short ); ?>" target="_blank" rel="noopener">
											/qr/<?php echo esc_html( $code->slug ); ?>
										</a>
									<?php else : ?>
										<span class="gqr-type-tag"><?php echo esc_html( $type ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $is_url ) : ?>
										<span class="gqr-inline">
											<input type="url" class="gqr-field-dest gqr-dest-input" value="<?php echo esc_attr( $code->destination ); ?>">
											<button type="button" class="button button-small gqr-save-dest"><?php esc_html_e( 'Update', 'gallus-qr' ); ?></button>
										</span>
										<?php if ( ! empty( $code->dest_mode ) && 'single' !== $code->dest_mode && ! empty( $code->destination_b ) ) : ?>
											<span class="gqr-sub gqr-dest-b-note">
												<?php
												if ( 'schedule' === $code->dest_mode ) {
													printf(
														/* translators: 1: URL, 2: site-local date/time. */
														esc_html__( '→ %1$s after %2$s', 'gallus-qr' ),
														esc_html( $code->destination_b ),
														esc_html( get_date_from_gmt( (string) $code->switch_at, 'Y-m-d H:i' ) )
													);
												} else {
													printf(
														/* translators: 1: URL, 2: percentage. */
														esc_html__( 'B: %1$s (%2$d%%)', 'gallus-qr' ),
														esc_html( $code->destination_b ),
														(int) $code->ab_split
													);
												}
												?>
											</span>
										<?php endif; ?>
									<?php else : ?>
										<code class="gqr-payload-preview"><?php
											$preview = (string) $code->destination;
											echo esc_html( mb_strimwidth( $preview, 0, 60, '…' ) );
										?></code>
									<?php endif; ?>
								</td>
								<td class="gqr-status">
									<?php if ( $is_tracked ) : ?>
										<span class="gqr-state gqr-state--<?php echo esc_attr( $state['key'] ); ?>">
											<?php echo esc_html( $state['label'] ); ?>
										</span>
										<?php if ( ! empty( $code->expires_at ) && 'expired' !== $state['key'] ) : ?>
											<span class="gqr-sub">
												<?php
												/* translators: %s: site-local date/time. */
												printf( esc_html__( 'until %s', 'gallus-qr' ), esc_html( get_date_from_gmt( $code->expires_at, 'Y-m-d H:i' ) ) );
												?>
											</span>
										<?php endif; ?>
										<?php if ( (int) $code->max_scans > 0 ) : ?>
											<span class="gqr-sub">
												<?php echo esc_html( (int) $code->scan_count . ' / ' . (int) $code->max_scans ); ?>
											</span>
										<?php endif; ?>
										<button type="button" class="button-link gqr-toggle-status" data-next="<?php echo 'paused' === $code->status ? 'active' : 'paused'; ?>">
											<?php 'paused' === $code->status ? esc_html_e( 'Resume', 'gallus-qr' ) : esc_html_e( 'Pause', 'gallus-qr' ); ?>
										</button>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
								<td class="gqr-total">
									<?php echo (int) $summary['total']; ?>
									<span class="gqr-sub"><?php
										/* translators: %d: number of unique visitors. */
										printf( esc_html__( '%d unique', 'gallus-qr' ), (int) $summary['unique'] );
									?></span>
									<?php if ( $is_ab ) : ?>
										<span class="gqr-sub">
											A: <?php echo isset( $splits['A'] ) ? (int) $splits['A'] : 0; ?>
											· B: <?php echo isset( $splits['B'] ) ? (int) $splits['B'] : 0; ?>
										</span>
									<?php endif; ?>
								</td>
								<td class="gqr-devices">
									<?php
									$parts = array();
									foreach ( $devices as $name => $count ) {
										if ( $count > 0 ) {
											$parts[] = esc_html( $name . ': ' . $count );
										}
									}
									echo $parts ? implode( '<br>', $parts ) : '—'; // phpcs:ignore WordPress.Security.EscapeOutput
									?>
								</td>
								<td><?php $this->render_sparkline( (int) $code->id, (int) min( $days, 90 ) ); ?></td>
								<td class="gqr-actions">
									<button type="button" class="button button-small gqr-dl" data-url="<?php echo esc_attr( $encodes ); ?>" data-slug="<?php echo esc_attr( $code->slug ); ?>" data-ext="png">PNG</button>
									<button type="button" class="button button-small gqr-dl" data-url="<?php echo esc_attr( $encodes ); ?>" data-slug="<?php echo esc_attr( $code->slug ); ?>" data-ext="svg">SVG</button>
									<?php if ( $is_tracked ) : ?>
										<a class="button button-small" href="<?php echo esc_url( Gallus_QR_Admin_Tools::export_url( (int) $code->id ) ); ?>">CSV</a>
									<?php endif; ?>
									<button type="button" class="button-link gqr-delete gqr-delete-code"><?php esc_html_e( 'Delete', 'gallus-qr' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $pages > 1 ) : ?>
					<div class="tablenav gqr-pagination">
						<div class="tablenav-pages">
							<span class="displaying-num">
								<?php
								printf(
									/* translators: %s: number of codes. */
									esc_html( _n( '%s code', '%s codes', $total, 'gallus-qr' ) ),
									esc_html( number_format_i18n( $total ) )
								);
								?>
							</span>
							<?php
							echo wp_kses_post(
								paginate_links(
									array(
										// %#% is paginate_links' page-number placeholder.
										// add_query_arg() does not URL-encode values,
										// so it survives; esc_url_raw() here would
										// mangle it. This is core's own pattern in
										// WP_List_Table::pagination(), and
										// paginate_links() esc_url()s each href it emits.
										'base'      => add_query_arg( 'gqr_paged', '%#%' ),
										'format'    => '',
										'prev_text' => '&laquo;',
										'next_text' => '&raquo;',
										'total'     => $pages,
										'current'   => $paged,
									)
								)
							);
							?>
						</div>
					</div>
				<?php endif; ?>

				<p class="gqr-help">
					<?php esc_html_e( 'Re-download (PNG/SVG) regenerates the code using the design you saved it with. Codes saved before v0.5.0 come out plain black-on-white.', 'gallus-qr' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * User IDs that own codes → display labels for the admin Owner filter.
	 *
	 * @return array<int,string> user_id => label
	 */
	private function owner_filter_choices() {
		$out = array();
		foreach ( $this->db->get_code_owner_ids() as $uid ) {
			$out[ $uid ] = $this->owner_label( $uid );
		}
		asort( $out, SORT_NATURAL | SORT_FLAG_CASE );
		return $out;
	}

	/**
	 * Display name for a code owner.
	 *
	 * @param int $user_id
	 * @return string
	 */
	private function owner_label( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return __( 'Unknown', 'gallus-qr' );
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return __( 'Former member', 'gallus-qr' );
		}
		$label = $user->display_name ? $user->display_name : $user->user_login;
		if ( (int) get_current_user_id() === $user_id ) {
			/* translators: %s: the current user's display name. */
			return sprintf( __( '%s (you)', 'gallus-qr' ), $label );
		}
		return $label;
	}

	/**
	 * Overview panels for the selected range: hour-of-day heatmap, top
	 * countries, and OS/browser splits (scoped to the owner filter).
	 *
	 * @param string   $since MySQL datetime (UTC).
	 * @param int|null $owner null = all codes; int = that owner's codes.
	 */
	private function render_overview( $since, $owner = null ) {
		$hourly    = $this->db->get_hourly_breakdown( 0, $since, $owner );
		$countries = $this->db->get_country_breakdown( 0, $since, 8, $owner );
		$os        = array_slice( $this->db->get_column_breakdown( 0, $since, 'os', $owner ), 0, 6, true );
		$browsers  = array_slice( $this->db->get_column_breakdown( 0, $since, 'browser', $owner ), 0, 6, true );

		// Shift the UTC hour buckets into site-local hours for display.
		$offset = (int) round( wp_timezone()->getOffset( new DateTime( 'now', wp_timezone() ) ) / HOUR_IN_SECONDS );
		$local  = array();
		for ( $h = 0; $h < 24; $h++ ) {
			$local[ $h ] = $hourly[ ( ( $h - $offset ) % 24 + 24 ) % 24 ];
		}
		$max = max( 1, max( $local ) );
		?>
		<div class="gqr-overview">
			<div class="gqr-panel">
				<h3><?php esc_html_e( 'Scans by hour of day', 'gallus-qr' ); ?></h3>
				<div class="gqr-heatmap" role="img" aria-label="<?php esc_attr_e( 'Scans per hour of day', 'gallus-qr' ); ?>">
					<?php for ( $h = 0; $h < 24; $h++ ) : ?>
						<span class="gqr-heatcell"
							style="opacity:<?php echo esc_attr( (string) max( 0.08, $local[ $h ] / $max ) ); ?>"
							title="<?php echo esc_attr( sprintf( '%02d:00 — %d', $h, $local[ $h ] ) ); ?>"></span>
					<?php endfor; ?>
				</div>
				<span class="gqr-help"><?php esc_html_e( '00:00 → 23:00, site time', 'gallus-qr' ); ?></span>
			</div>

			<div class="gqr-panel">
				<h3><?php esc_html_e( 'Top countries', 'gallus-qr' ); ?></h3>
				<?php if ( empty( $countries ) ) : ?>
					<p class="gqr-help"><?php esc_html_e( 'No scans in this range.', 'gallus-qr' ); ?></p>
				<?php else : ?>
					<ul class="gqr-mini-list">
						<?php foreach ( $countries as $country => $hits ) : ?>
							<li>
								<span><?php echo esc_html( '' === $country ? __( 'Unknown', 'gallus-qr' ) : $country ); ?></span>
								<strong><?php echo (int) $hits; ?></strong>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

			<div class="gqr-panel">
				<h3><?php esc_html_e( 'Operating systems', 'gallus-qr' ); ?></h3>
				<ul class="gqr-mini-list">
					<?php foreach ( $os as $bucket => $hits ) : ?>
						<li><span><?php echo esc_html( $bucket ); ?></span> <strong><?php echo (int) $hits; ?></strong></li>
					<?php endforeach; ?>
				</ul>
			</div>

			<div class="gqr-panel">
				<h3><?php esc_html_e( 'Browsers', 'gallus-qr' ); ?></h3>
				<ul class="gqr-mini-list">
					<?php foreach ( $browsers as $bucket => $hits ) : ?>
						<li><span><?php echo esc_html( $bucket ); ?></span> <strong><?php echo (int) $hits; ?></strong></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a per-day bar chart (up to 90 days) for one code as CSS bars.
	 * Days are UTC buckets, matching how scan timestamps are stored.
	 *
	 * @param int $code_id
	 * @param int $days
	 */
	private function render_sparkline( $code_id, $days = 30 ) {
		$daily = $this->db->get_daily_scans( $code_id, $days );

		// Build an ordered list of the last N days, filling gaps with 0.
		$counts = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day            = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$counts[ $day ] = isset( $daily[ $day ] ) ? $daily[ $day ] : 0;
		}

		$max   = max( 1, max( $counts ) );
		$label = sprintf(
			/* translators: %d: number of days. */
			esc_attr__( 'Scans per day, last %d days', 'gallus-qr' ),
			$days
		);
		echo '<div class="gqr-spark" role="img" aria-label="' . esc_attr( $label ) . '">';
		foreach ( $counts as $day => $hits ) {
			$pct   = (int) round( ( $hits / $max ) * 100 );
			$title = sprintf( '%s: %d', $day, $hits );
			printf(
				'<span class="gqr-spark-bar" style="height:%d%%" title="%s"></span>',
				max( 4, $pct ), // floor so empty days are still visible
				esc_attr( $title )
			);
		}
		echo '</div>';
	}
}
