<?php
/**
 * Plugin Name:       Sea Level Globe
 * Description:       Interactive 3D globe of Earth with an adjustable sea level. Zoom, rotate, and raise or lower the oceans by up to 10,000 ft via a slider or exact text entry.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Stoke
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sea-level-globe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SLG_VERSION', '1.1.0' );
define( 'SLG_URL', plugin_dir_url( __FILE__ ) );
define( 'SLG_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Register (but do not enqueue) front-end assets.
 * They are enqueued only when a globe is actually rendered.
 */
function slg_register_assets() {
	wp_register_script(
		'sea-level-globe',
		SLG_URL . 'build/globe.js',
		array(),
		SLG_VERSION,
		true
	);
	wp_register_style(
		'sea-level-globe',
		SLG_URL . 'build/globe.css',
		array(),
		SLG_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'slg_register_assets' );

/**
 * Render the globe container. Shared by the shortcode and the block.
 *
 * @param array $atts Shortcode/block attributes.
 * @return string HTML markup.
 */
function slg_render_globe( $atts ) {
	$atts = shortcode_atts(
		array(
			'min'    => '-10000',
			'max'    => '10000',
			'start'  => '0',
			'step'   => '10',
			'unit'   => 'ft',
			'height' => '600px',
		),
		$atts,
		'sea_level_globe'
	);

	$min   = floatval( $atts['min'] );
	$max   = floatval( $atts['max'] );
	$start = floatval( $atts['start'] );
	$step  = abs( floatval( $atts['step'] ) );
	$unit  = in_array( $atts['unit'], array( 'ft', 'm' ), true ) ? $atts['unit'] : 'ft';

	if ( $max <= $min ) {
		$min = -10000;
		$max = 10000;
	}
	$start = max( $min, min( $max, $start ) );
	if ( $step <= 0 ) {
		$step = 10;
	}

	// Allow only simple CSS lengths for the height attribute.
	$height = preg_match( '/^\d+(\.\d+)?(px|vh|em|rem|%)$/', $atts['height'] ) ? $atts['height'] : '600px';

	wp_enqueue_script( 'sea-level-globe' );
	wp_enqueue_style( 'sea-level-globe' );

	return sprintf(
		'<div class="slg-globe" style="height:%s" data-min="%s" data-max="%s" data-start="%s" data-step="%s" data-unit="%s" data-color-src="%s" data-elev-src="%s"><div class="slg-loading">%s</div></div>',
		esc_attr( $height ),
		esc_attr( $min ),
		esc_attr( $max ),
		esc_attr( $start ),
		esc_attr( $step ),
		esc_attr( $unit ),
		esc_url( SLG_URL . 'assets/earth-color.jpg' ),
		esc_url( SLG_URL . 'assets/earth-elevation.png' ),
		esc_html__( 'Loading globe…', 'sea-level-globe' )
	);
}
add_shortcode( 'sea_level_globe', 'slg_render_globe' );

/**
 * Register the Gutenberg block (server-rendered, reuses the shortcode renderer).
 */
function slg_register_block() {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}

	wp_register_script(
		'sea-level-globe-block',
		SLG_URL . 'build/block.js',
		array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
		SLG_VERSION,
		true
	);

	register_block_type(
		'sea-level-globe/globe',
		array(
			'editor_script'   => 'sea-level-globe-block',
			'render_callback' => 'slg_block_render',
			'attributes'      => array(
				'min'    => array( 'type' => 'number', 'default' => -10000 ),
				'max'    => array( 'type' => 'number', 'default' => 10000 ),
				'start'  => array( 'type' => 'number', 'default' => 0 ),
				'step'   => array( 'type' => 'number', 'default' => 10 ),
				'unit'   => array( 'type' => 'string', 'default' => 'ft' ),
				'height' => array( 'type' => 'string', 'default' => '600px' ),
			),
		)
	);
}
add_action( 'init', 'slg_register_block' );

/**
 * Block render callback.
 *
 * @param array $attributes Block attributes.
 * @return string HTML markup.
 */
/**
 * Admin menu entry with a custom globe-and-waves icon.
 * WordPress recolors black SVG data-URI icons to match the admin scheme.
 */
function slg_admin_menu() {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">'
		. '<path fill="black" fill-rule="evenodd" d="M10 1a9 9 0 1 0 0 18a9 9 0 1 0 0-18Z'
		. 'M3 10.2h14v1.3H3Z M4.5 13.4h11v1.3h-11Z"/></svg>';

	add_menu_page(
		__( 'Sea Level Globe', 'sea-level-globe' ),
		__( 'Sea Level Globe', 'sea-level-globe' ),
		'manage_options',
		'sea-level-globe',
		'slg_admin_page',
		'data:image/svg+xml;base64,' . base64_encode( $svg )
	);
}
add_action( 'admin_menu', 'slg_admin_menu' );

/**
 * Front-end assets are registered on wp_enqueue_scripts only, so register
 * and enqueue them here for the admin preview page.
 */
function slg_admin_assets( $hook ) {
	if ( 'toplevel_page_sea-level-globe' !== $hook ) {
		return;
	}
	slg_register_assets();
	wp_enqueue_script( 'sea-level-globe' );
	wp_enqueue_style( 'sea-level-globe' );
}
add_action( 'admin_enqueue_scripts', 'slg_admin_assets' );

/**
 * Admin page: live preview plus shortcode reference.
 */
function slg_admin_page() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Sea Level Globe', 'sea-level-globe' ); ?></h1>
		<p><?php esc_html_e( 'Interactive 3D globe with an adjustable sea level. This is a live preview with the default settings.', 'sea-level-globe' ); ?></p>
		<div style="max-width: 900px;">
			<?php echo slg_render_globe( array() ); // phpcs:ignore WordPress.Security.EscapeOutput -- builds its own escaped markup. ?>
		</div>
		<h2><?php esc_html_e( 'Usage', 'sea-level-globe' ); ?></h2>
		<p><?php esc_html_e( 'Add the shortcode to any page or post, or insert the "Sea Level Globe" block:', 'sea-level-globe' ); ?></p>
		<p><code>[sea_level_globe]</code></p>
		<p><code>[sea_level_globe min="-400" max="400" step="5" unit="m" height="500px"]</code></p>
		<table class="widefat striped" style="max-width: 700px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Attribute', 'sea-level-globe' ); ?></th>
					<th><?php esc_html_e( 'Default', 'sea-level-globe' ); ?></th>
					<th><?php esc_html_e( 'Description', 'sea-level-globe' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>min</code></td><td>-10000</td><td><?php esc_html_e( 'Lowest slider value', 'sea-level-globe' ); ?></td></tr>
				<tr><td><code>max</code></td><td>10000</td><td><?php esc_html_e( 'Highest slider value', 'sea-level-globe' ); ?></td></tr>
				<tr><td><code>start</code></td><td>0</td><td><?php esc_html_e( 'Initial sea level (0 = today)', 'sea-level-globe' ); ?></td></tr>
				<tr><td><code>step</code></td><td>10</td><td><?php esc_html_e( 'Slider increment', 'sea-level-globe' ); ?></td></tr>
				<tr><td><code>unit</code></td><td>ft</td><td><?php esc_html_e( 'Initial unit: ft or m', 'sea-level-globe' ); ?></td></tr>
				<tr><td><code>height</code></td><td>600px</td><td><?php esc_html_e( 'Widget height (px, vh, em, rem, %)', 'sea-level-globe' ); ?></td></tr>
			</tbody>
		</table>
	</div>
	<?php
}

function slg_block_render( $attributes ) {
	return slg_render_globe(
		array(
			'min'    => isset( $attributes['min'] ) ? $attributes['min'] : -10000,
			'max'    => isset( $attributes['max'] ) ? $attributes['max'] : 10000,
			'start'  => isset( $attributes['start'] ) ? $attributes['start'] : 0,
			'step'   => isset( $attributes['step'] ) ? $attributes['step'] : 10,
			'unit'   => isset( $attributes['unit'] ) ? $attributes['unit'] : 'ft',
			'height' => isset( $attributes['height'] ) ? $attributes['height'] : '600px',
		)
	);
}
