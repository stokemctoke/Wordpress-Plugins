<?php
/**
 * Plugin Name:       World Builder Globe
 * Description:       Interactive 3D planet builder. Start from Earth or generate a random world, then shape it with sliders: sea level, ice caps, vegetation, clouds, atmosphere, ocean color, sunlight, and rotation.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Stoke
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       world-builder-globe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WBG_VERSION', '1.1.0' );
define( 'WBG_URL', plugin_dir_url( __FILE__ ) );

function wbg_register_assets() {
	wp_register_script( 'world-builder-globe', WBG_URL . 'build/globe.js', array(), WBG_VERSION, true );
	wp_register_style( 'world-builder-globe', WBG_URL . 'build/globe.css', array(), WBG_VERSION );
}
add_action( 'wp_enqueue_scripts', 'wbg_register_assets' );

/**
 * Render a globe container. Shared by the shortcode and the block.
 */
function wbg_render_globe( $atts ) {
	$atts = shortcode_atts(
		array(
			'mode'       => 'earth',   // earth | custom
			'sea'        => '0',       // ft, -10000..10000
			'ice'        => '15',      // percent
			'vegetation' => '50',      // percent (50 = natural Earth)
			'clouds'     => '30',      // percent
			'atmosphere' => '40',      // percent
			'ocean'      => '210',     // hue degrees 0..360
			'sun'        => '35',      // degrees 0..360
			'spin'       => '0',       // degrees per second, -10..10
			'height'     => '640px',
		),
		$atts,
		'world_builder_globe'
	);

	$mode = 'custom' === $atts['mode'] ? 'custom' : 'earth';

	$clamp = function ( $v, $lo, $hi, $fallback ) {
		if ( ! is_numeric( $v ) ) {
			return $fallback;
		}
		return max( $lo, min( $hi, floatval( $v ) ) );
	};

	$sea    = $clamp( $atts['sea'], -10000, 10000, 0 );
	$ice    = $clamp( $atts['ice'], 0, 100, 15 );
	$veg    = $clamp( $atts['vegetation'], 0, 100, 50 );
	$cloud  = $clamp( $atts['clouds'], 0, 100, 30 );
	$atmo   = $clamp( $atts['atmosphere'], 0, 100, 40 );
	$ocean  = $clamp( $atts['ocean'], 0, 360, 210 );
	$sun    = $clamp( $atts['sun'], 0, 360, 35 );
	$spin   = $clamp( $atts['spin'], -10, 10, 0 );
	$height = preg_match( '/^\d+(\.\d+)?(px|vh|em|rem|%)$/', $atts['height'] ) ? $atts['height'] : '640px';

	wp_enqueue_script( 'world-builder-globe' );
	wp_enqueue_style( 'world-builder-globe' );

	return sprintf(
		'<div class="wbg-globe" style="height:%s" data-mode="%s" data-sea="%s" data-ice="%s" data-vegetation="%s" data-clouds="%s" data-atmosphere="%s" data-ocean="%s" data-sun="%s" data-spin="%s" data-color-src="%s" data-elev-src="%s"><div class="wbg-loading">%s</div></div>',
		esc_attr( $height ),
		esc_attr( $mode ),
		esc_attr( $sea ),
		esc_attr( $ice ),
		esc_attr( $veg ),
		esc_attr( $cloud ),
		esc_attr( $atmo ),
		esc_attr( $ocean ),
		esc_attr( $sun ),
		esc_attr( $spin ),
		esc_url( WBG_URL . 'assets/earth-color.jpg' ),
		esc_url( WBG_URL . 'assets/earth-elevation.png' ),
		esc_html__( 'Loading planet…', 'world-builder-globe' )
	);
}
add_shortcode( 'world_builder_globe', 'wbg_render_globe' );

function wbg_register_block() {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}

	wp_register_script(
		'world-builder-globe-block',
		WBG_URL . 'build/block.js',
		array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
		WBG_VERSION,
		true
	);

	register_block_type(
		'world-builder-globe/globe',
		array(
			'editor_script'   => 'world-builder-globe-block',
			'render_callback' => 'wbg_block_render',
			'attributes'      => array(
				'mode'       => array( 'type' => 'string', 'default' => 'earth' ),
				'sea'        => array( 'type' => 'number', 'default' => 0 ),
				'ice'        => array( 'type' => 'number', 'default' => 15 ),
				'vegetation' => array( 'type' => 'number', 'default' => 50 ),
				'clouds'     => array( 'type' => 'number', 'default' => 30 ),
				'atmosphere' => array( 'type' => 'number', 'default' => 40 ),
				'ocean'      => array( 'type' => 'number', 'default' => 210 ),
				'sun'        => array( 'type' => 'number', 'default' => 35 ),
				'spin'       => array( 'type' => 'number', 'default' => 0 ),
				'height'     => array( 'type' => 'string', 'default' => '640px' ),
			),
		)
	);
}
add_action( 'init', 'wbg_register_block' );

/**
 * Admin menu entry with a custom globe-and-sliders icon.
 * WordPress recolors black SVG data-URI icons to match the admin scheme.
 */
function wbg_admin_menu() {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">'
		. '<path fill="black" fill-rule="evenodd" d="M10 1a9 9 0 1 0 0 18a9 9 0 1 0 0-18Z'
		. 'M4 7h12v1.2H4Z M4 11.8h12V13H4Z'
		. 'M7 6a1.6 1.6 0 1 0 0 3.2a1.6 1.6 0 1 0 0-3.2Z'
		. 'M13 10.8a1.6 1.6 0 1 0 0 3.2a1.6 1.6 0 1 0 0-3.2Z"/></svg>';

	add_menu_page(
		__( 'World Builder Globe', 'world-builder-globe' ),
		__( 'World Builder', 'world-builder-globe' ),
		'manage_options',
		'world-builder-globe',
		'wbg_admin_page',
		'data:image/svg+xml;base64,' . base64_encode( $svg )
	);
}
add_action( 'admin_menu', 'wbg_admin_menu' );

/**
 * Front-end assets are registered on wp_enqueue_scripts only, so register
 * and enqueue them here for the admin preview page.
 */
function wbg_admin_assets( $hook ) {
	if ( 'toplevel_page_world-builder-globe' !== $hook ) {
		return;
	}
	wbg_register_assets();
	wp_enqueue_script( 'world-builder-globe' );
	wp_enqueue_style( 'world-builder-globe' );
}
add_action( 'admin_enqueue_scripts', 'wbg_admin_assets' );

/**
 * Admin page: live preview plus shortcode reference.
 */
function wbg_admin_page() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'World Builder Globe', 'world-builder-globe' ); ?></h1>
		<p><?php esc_html_e( 'Interactive 3D planet builder. This is a live preview with the default settings — try Custom world mode and Randomize.', 'world-builder-globe' ); ?></p>
		<div style="max-width: 960px;">
			<?php echo wbg_render_globe( array() ); // phpcs:ignore WordPress.Security.EscapeOutput -- builds its own escaped markup. ?>
		</div>
		<h2><?php esc_html_e( 'Usage', 'world-builder-globe' ); ?></h2>
		<p><?php esc_html_e( 'Add the shortcode to any page or post, or insert the "World Builder Globe" block:', 'world-builder-globe' ); ?></p>
		<p><code>[world_builder_globe]</code></p>
		<p><code>[world_builder_globe mode="custom" ocean="140" spin="2" height="500px"]</code></p>
		<table class="widefat striped" style="max-width: 700px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Attribute', 'world-builder-globe' ); ?></th>
					<th><?php esc_html_e( 'Default', 'world-builder-globe' ); ?></th>
					<th><?php esc_html_e( 'Description', 'world-builder-globe' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>mode</code></td><td>earth</td><td><?php esc_html_e( 'earth or custom (procedural world)', 'world-builder-globe' ); ?></td></tr>
				<tr><td><code>sea</code></td><td>0</td><td><?php esc_html_e( 'Initial sea level in ft (-10000 to 10000)', 'world-builder-globe' ); ?></td></tr>
				<tr><td><code>ice</code></td><td>15</td><td><?php esc_html_e( 'Ice caps percent', 'world-builder-globe' ); ?></td></tr>
				<tr><td><code>vegetation</code></td><td>50</td><td><?php esc_html_e( 'Vegetation percent (50 = natural Earth)', 'world-builder-globe' ); ?></td></tr>
				<tr><td><code>clouds</code></td><td>30</td><td><?php esc_html_e( 'Cloud cover percent', 'world-builder-globe' ); ?></td></tr>
				<tr><td><code>atmosphere</code></td><td>40</td><td><?php esc_html_e( 'Atmosphere glow percent', 'world-builder-globe' ); ?></td></tr>
				<tr><td><code>ocean</code></td><td>210</td><td><?php esc_html_e( 'Ocean hue in degrees (0-360)', 'world-builder-globe' ); ?></td></tr>
				<tr><td><code>sun</code></td><td>35</td><td><?php esc_html_e( 'Sun angle in degrees (0-360)', 'world-builder-globe' ); ?></td></tr>
				<tr><td><code>spin</code></td><td>0</td><td><?php esc_html_e( 'Rotation in degrees per second (-10 to 10)', 'world-builder-globe' ); ?></td></tr>
				<tr><td><code>height</code></td><td>640px</td><td><?php esc_html_e( 'Widget height (px, vh, em, rem, %)', 'world-builder-globe' ); ?></td></tr>
			</tbody>
		</table>
	</div>
	<?php
}

function wbg_block_render( $attributes ) {
	return wbg_render_globe( array_map( 'strval', $attributes ) );
}
