/**
 * Gutenberg block for Sea Level Globe.
 *
 * Server-rendered: the front end reuses the shortcode render callback, so
 * the editor shows a placeholder with the settings summary and the sidebar
 * exposes the same attributes as the shortcode.
 *
 * Uses the wp.* globals provided by WordPress; no build-time deps needed.
 */
( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, RangeControl, TextControl, SelectControl } = wp.components;
	const { __ } = wp.i18n;
	const el = wp.element.createElement;

	registerBlockType( 'sea-level-globe/globe', {
		title: __( 'Sea Level Globe', 'sea-level-globe' ),
		description: __(
			'Interactive 3D globe with an adjustable sea level.',
			'sea-level-globe'
		),
		icon: 'admin-site-alt3',
		category: 'embed',
		attributes: {
			min: { type: 'number', default: -10000 },
			max: { type: 'number', default: 10000 },
			start: { type: 'number', default: 0 },
			step: { type: 'number', default: 10 },
			unit: { type: 'string', default: 'ft' },
			height: { type: 'string', default: '600px' },
		},

		edit: function ( props ) {
			const { attributes: a, setAttributes } = props;
			const blockProps = useBlockProps( {
				style: {
					height: a.height,
					display: 'flex',
					alignItems: 'center',
					justifyContent: 'center',
					background:
						'radial-gradient(ellipse at center, #0b1026 0%, #05060f 100%)',
					color: '#9db2d9',
					borderRadius: '8px',
					flexDirection: 'column',
					gap: '8px',
				},
			} );

			return el(
				'div',
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Sea Level Settings', 'sea-level-globe' ) },
						el( TextControl, {
							label: __( 'Minimum', 'sea-level-globe' ),
							type: 'number',
							value: a.min,
							onChange: ( v ) => setAttributes( { min: parseFloat( v ) || 0 } ),
						} ),
						el( TextControl, {
							label: __( 'Maximum', 'sea-level-globe' ),
							type: 'number',
							value: a.max,
							onChange: ( v ) => setAttributes( { max: parseFloat( v ) || 0 } ),
						} ),
						el( RangeControl, {
							label: __( 'Initial sea level', 'sea-level-globe' ),
							min: a.min,
							max: a.max,
							value: a.start,
							onChange: ( v ) => setAttributes( { start: v } ),
						} ),
						el( TextControl, {
							label: __( 'Slider step', 'sea-level-globe' ),
							type: 'number',
							value: a.step,
							onChange: ( v ) => setAttributes( { step: parseFloat( v ) || 10 } ),
						} ),
						el( SelectControl, {
							label: __( 'Unit', 'sea-level-globe' ),
							value: a.unit,
							options: [
								{ label: __( 'Feet', 'sea-level-globe' ), value: 'ft' },
								{ label: __( 'Metres', 'sea-level-globe' ), value: 'm' },
							],
							onChange: ( v ) => setAttributes( { unit: v } ),
						} ),
						el( TextControl, {
							label: __( 'Height (CSS)', 'sea-level-globe' ),
							value: a.height,
							help: __( 'e.g. 600px or 80vh', 'sea-level-globe' ),
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
						__( 'Sea Level Globe', 'sea-level-globe' )
					),
					el(
						'span',
						{ style: { fontSize: '13px' } },
						`${ a.min } ${ a.unit } to +${ a.max } ${ a.unit } · ` +
							__( 'rendered on the live page', 'sea-level-globe' )
					)
				)
			);
		},

		// Server-rendered; nothing saved to post content.
		save: function () {
			return null;
		},
	} );
} )( window.wp );
