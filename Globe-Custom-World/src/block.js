/**
 * Gutenberg block for World Builder Globe. Server-rendered; the sidebar
 * exposes the same attributes as the shortcode. Uses wp.* globals.
 */
( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, RangeControl, TextControl, SelectControl } = wp.components;
	const { __ } = wp.i18n;
	const el = wp.element.createElement;

	const SLIDERS = [
		{ key: 'sea', label: __( 'Sea level (ft)', 'world-builder-globe' ), min: -10000, max: 10000 },
		{ key: 'ice', label: __( 'Ice caps (%)', 'world-builder-globe' ), min: 0, max: 100 },
		{ key: 'vegetation', label: __( 'Vegetation (%)', 'world-builder-globe' ), min: 0, max: 100 },
		{ key: 'clouds', label: __( 'Cloud cover (%)', 'world-builder-globe' ), min: 0, max: 100 },
		{ key: 'atmosphere', label: __( 'Atmosphere (%)', 'world-builder-globe' ), min: 0, max: 100 },
		{ key: 'ocean', label: __( 'Ocean hue (deg)', 'world-builder-globe' ), min: 0, max: 360 },
		{ key: 'sun', label: __( 'Sun angle (deg)', 'world-builder-globe' ), min: 0, max: 360 },
		{ key: 'spin', label: __( 'Spin (deg/s)', 'world-builder-globe' ), min: -10, max: 10 },
	];

	registerBlockType( 'world-builder-globe/globe', {
		title: __( 'World Builder Globe', 'world-builder-globe' ),
		description: __(
			'Interactive planet builder with sliders for sea level, ice, vegetation, clouds, atmosphere, ocean color, sunlight, and rotation.',
			'world-builder-globe'
		),
		icon: 'admin-site-alt3',
		category: 'embed',
		attributes: {
			mode: { type: 'string', default: 'earth' },
			sea: { type: 'number', default: 0 },
			ice: { type: 'number', default: 15 },
			vegetation: { type: 'number', default: 50 },
			clouds: { type: 'number', default: 30 },
			atmosphere: { type: 'number', default: 40 },
			ocean: { type: 'number', default: 210 },
			sun: { type: 'number', default: 35 },
			spin: { type: 'number', default: 0 },
			height: { type: 'string', default: '640px' },
		},

		edit: function ( props ) {
			const { attributes: a, setAttributes } = props;
			const blockProps = useBlockProps( {
				style: {
					height: a.height,
					display: 'flex',
					alignItems: 'center',
					justifyContent: 'center',
					flexDirection: 'column',
					gap: '8px',
					background: 'radial-gradient(ellipse at center, #0b1026 0%, #05060f 100%)',
					color: '#9db2d9',
					borderRadius: '8px',
				},
			} );

			const sliderControls = SLIDERS.map( ( s ) =>
				el( RangeControl, {
					key: s.key,
					label: s.label,
					min: s.min,
					max: s.max,
					value: a[ s.key ],
					onChange: ( v ) => setAttributes( { [ s.key ]: v } ),
				} )
			);

			return el(
				'div',
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Initial world settings', 'world-builder-globe' ) },
						el( SelectControl, {
							label: __( 'Mode', 'world-builder-globe' ),
							value: a.mode,
							options: [
								{ label: __( 'Earth', 'world-builder-globe' ), value: 'earth' },
								{ label: __( 'Custom world', 'world-builder-globe' ), value: 'custom' },
							],
							onChange: ( v ) => setAttributes( { mode: v } ),
						} ),
						sliderControls,
						el( TextControl, {
							label: __( 'Height (CSS)', 'world-builder-globe' ),
							value: a.height,
							help: __( 'e.g. 640px or 80vh', 'world-builder-globe' ),
							onChange: ( v ) => setAttributes( { height: v } ),
						} )
					)
				),
				el(
					'div',
					blockProps,
					el(
						'strong',
						{ style: { fontSize: '16px' } },
						__( 'World Builder Globe', 'world-builder-globe' )
					),
					el(
						'span',
						{ style: { fontSize: '13px' } },
						( a.mode === 'custom'
							? __( 'Custom world', 'world-builder-globe' )
							: __( 'Earth', 'world-builder-globe' ) ) +
							' · ' +
							__( 'rendered on the live page', 'world-builder-globe' )
					)
				)
			);
		},

		save: function () {
			return null;
		},
	} );
} )( window.wp );
