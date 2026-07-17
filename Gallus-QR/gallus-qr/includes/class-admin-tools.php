<?php
/**
 * File-flow admin tools: per-code CSV export of scan data (a browser-native
 * download, so it stays on admin-post.php rather than REST) and — see the
 * Tools screen — bulk creation of trackable codes from a CSV upload.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gallus_QR_Admin_Tools {

	/** @var Gallus_QR_Database */
	private $db;

	public function __construct( Gallus_QR_Database $db ) {
		$this->db = $db;
	}

	/** Max rows a single CSV import may create. */
	const IMPORT_LIMIT = 500;

	/**
	 * Wire up WordPress hooks. Called from gallus-qr.php on plugins_loaded.
	 */
	public function init() {
		add_action( 'admin_post_gallus_qr_export_csv', array( $this, 'handle_export' ) );
		add_action( 'admin_post_gallus_qr_import_csv', array( $this, 'handle_import' ) );
	}

	/**
	 * The bulk-import screen (menu entry registered by Gallus_QR_Admin).
	 */
	public function render_import_page() {
		$results = get_transient( 'gallus_qr_import_results_' . get_current_user_id() );
		?>
		<div class="wrap gqr-wrap">
			<h1><?php esc_html_e( 'Gallus QR — Bulk import', 'gallus-qr' ); ?></h1>

			<p>
				<?php esc_html_e( 'Upload a CSV to create trackable URL codes in bulk. Columns:', 'gallus-qr' ); ?>
				<code>title,destination[,slug]</code>
				<?php
				/* translators: %d: maximum number of rows. */
				printf( esc_html__( '(max %d rows; a header row is skipped automatically).', 'gallus-qr' ), (int) self::IMPORT_LIMIT );
				?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="gallus_qr_import_csv">
				<?php wp_nonce_field( 'gallus_qr_import' ); ?>
				<input type="file" name="gqr_csv" accept=".csv,text/csv" required>
				<?php submit_button( __( 'Import', 'gallus-qr' ), 'primary', 'submit', false ); ?>
			</form>

			<?php if ( is_array( $results ) ) : ?>
				<?php delete_transient( 'gallus_qr_import_results_' . get_current_user_id() ); ?>
				<h2>
					<?php
					/* translators: 1: created count, 2: skipped count. */
					printf( esc_html__( 'Import finished — %1$d created, %2$d skipped', 'gallus-qr' ), (int) $results['created'], (int) $results['skipped'] );
					?>
				</h2>
				<?php if ( ! empty( $results['rows'] ) ) : ?>
					<table class="widefat striped" style="max-width:720px">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Row', 'gallus-qr' ); ?></th>
								<th><?php esc_html_e( 'Result', 'gallus-qr' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $results['rows'] as $line ) : ?>
								<tr>
									<td><?php echo (int) $line['row']; ?></td>
									<td><?php echo esc_html( $line['message'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Process an uploaded CSV: title,destination[,slug] per row, streamed with
	 * fgetcsv, capped at IMPORT_LIMIT. Results land in a short-lived transient
	 * shown back on the import screen.
	 */
	public function handle_import() {
		if ( ! current_user_can( Gallus_QR_Settings::capability() )
			|| ! check_admin_referer( 'gallus_qr_import' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'gallus-qr' ) );
		}

		$back = admin_url( 'admin.php?page=gallus-qr-import' );

		if ( empty( $_FILES['gqr_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['gqr_csv']['tmp_name'] ) ) {
			wp_safe_redirect( $back );
			exit;
		}

		$handle = fopen( $_FILES['gqr_csv']['tmp_name'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			wp_safe_redirect( $back );
			exit;
		}

		$results = array(
			'created' => 0,
			'skipped' => 0,
			'rows'    => array(),
		);
		$row_num = 0;

		while ( false !== ( $cols = fgetcsv( $handle ) ) ) {
			$row_num++;

			if ( $row_num > self::IMPORT_LIMIT + 1 ) {
				$results['rows'][] = array(
					'row'     => $row_num,
					/* translators: %d: maximum number of rows. */
					'message' => sprintf( __( 'Stopped — imports are capped at %d rows.', 'gallus-qr' ), (int) self::IMPORT_LIMIT ),
				);
				break;
			}

			$title       = isset( $cols[0] ) ? sanitize_text_field( $cols[0] ) : '';
			$destination = isset( $cols[1] ) ? trim( $cols[1] ) : '';
			$slug        = isset( $cols[2] ) ? strtolower( trim( $cols[2] ) ) : '';

			// Skip an obvious header row.
			if ( 1 === $row_num && ! wp_http_validate_url( $destination ) ) {
				continue;
			}

			if ( ! $destination || ! wp_http_validate_url( $destination ) ) {
				$results['skipped']++;
				$results['rows'][] = array(
					'row'     => $row_num,
					'message' => __( 'Skipped — invalid destination URL.', 'gallus-qr' ),
				);
				continue;
			}

			if ( '' !== $slug ) {
				$format = Gallus_QR_Database::validate_slug_format( $slug );
				if ( is_wp_error( $format ) || ! $this->db->is_slug_available( $slug ) ) {
					$results['skipped']++;
					$results['rows'][] = array(
						'row'     => $row_num,
						'message' => __( 'Skipped — slug invalid or already taken.', 'gallus-qr' ),
					);
					continue;
				}
			}

			$new_slug = $this->db->insert_code( $title, esc_url_raw( $destination ), true, '', 'url', '', $slug );

			if ( $new_slug ) {
				$results['created']++;
				$results['rows'][] = array(
					'row'     => $row_num,
					/* translators: %s: the new short link path. */
					'message' => sprintf( __( 'Created /qr/%s', 'gallus-qr' ), $new_slug ),
				);
			} else {
				$results['skipped']++;
				$results['rows'][] = array(
					'row'     => $row_num,
					'message' => __( 'Skipped — could not save.', 'gallus-qr' ),
				);
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		set_transient( 'gallus_qr_import_results_' . get_current_user_id(), $results, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Nonce-protected URL for one code's CSV export.
	 *
	 * @param int $code_id
	 * @return string
	 */
	public static function export_url( $code_id ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=gallus_qr_export_csv&code_id=' . (int) $code_id ),
			'gallus_qr_export_' . (int) $code_id
		);
	}

	/**
	 * Stream a code's scans as CSV. Timestamps are converted to site-local
	 * time for spreadsheet friendliness.
	 */
	public function handle_export() {
		$id = isset( $_GET['code_id'] ) ? absint( $_GET['code_id'] ) : 0;

		if ( ! current_user_can( Gallus_QR_Settings::capability() )
			|| ! check_admin_referer( 'gallus_qr_export_' . $id ) ) {
			wp_die( esc_html__( 'Permission denied.', 'gallus-qr' ) );
		}

		$code = $this->db->get_code_by_id( $id );
		if ( ! $code ) {
			wp_die( esc_html__( 'No such QR code.', 'gallus-qr' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="gallus-qr-scans-' . sanitize_file_name( $code->slug ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'scanned_at_local', 'country', 'device', 'os', 'browser', 'variant', 'visitor_hash' ) );

		foreach ( $this->db->get_scans_for_export( $id ) as $row ) {
			fputcsv(
				$out,
				array(
					get_date_from_gmt( $row['scanned_at'], 'Y-m-d H:i:s' ),
					$row['country'],
					$row['device'],
					$row['os'],
					$row['browser'],
					$row['variant'],
					// Truncated: enough to spot repeat visitors, never reversible.
					substr( $row['ip_hash'], 0, 12 ),
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
