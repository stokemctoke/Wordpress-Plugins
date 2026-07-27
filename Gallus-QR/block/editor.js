/**
 * Gallus QR Code block — editor UI (vanilla JS, no build step).
 *
 * Pick one of your saved codes; the preview renders live through the shared
 * designer (the same renderer the front end uses), so the editor shows the
 * real thing.
 */
( function ( wp ) {
	'use strict';

	var el              = wp.element.createElement;
	var useState        = wp.element.useState;
	var useEffect       = wp.element.useEffect;
	var useRef          = wp.element.useRef;
	var __              = wp.i18n.__;
	var registerBlock   = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps   = wp.blockEditor.useBlockProps;
	var ComboboxControl = wp.components.ComboboxControl;
	var RangeControl    = wp.components.RangeControl;
	var PanelBody       = wp.components.PanelBody;
	var Placeholder     = wp.components.Placeholder;
	var Spinner         = wp.components.Spinner;

	// One fetch of the code list per editor session, shared across instances.
	var codesPromise = null;
	function fetchCodes() {
		if ( ! codesPromise ) {
			codesPromise = wp.apiFetch( { path: '/gallus-qr/v1/codes?per_page=100' } )
				.catch( function () { return []; } );
		}
		return codesPromise;
	}

	function Preview( props ) {
		var ref = useRef( null );

		useEffect( function () {
			if ( ! ref.current || ! props.code || typeof GallusQRDesign === 'undefined' ) {
				return;
			}
			var encodes = props.code.trackable ? props.code.url : props.code.destination;
			GallusQRDesign.renderInto( ref.current, props.code.design || {}, encodes, props.size );
		}, [ props.code, props.size ] );

		return el( 'div', {
			ref: ref,
			className: 'gallus-qr-block-preview',
			style: { maxWidth: props.size + 'px' }
		} );
	}

	registerBlock( 'gallus-qr/code', {
		edit: function ( props ) {
			var attributes    = props.attributes;
			var setAttributes = props.setAttributes;

			var state    = useState( null );
			var codes    = state[ 0 ];
			var setCodes = state[ 1 ];

			useEffect( function () {
				var alive = true;
				fetchCodes().then( function ( list ) {
					if ( alive ) { setCodes( list ); }
				} );
				return function () { alive = false; };
			}, [] );

			var selected = null;
			if ( codes && attributes.codeId ) {
				for ( var i = 0; i < codes.length; i++ ) {
					if ( codes[ i ].id === attributes.codeId ) {
						selected = codes[ i ];
						break;
					}
				}
			}

			var options = ( codes || [] ).map( function ( code ) {
				return {
					value: String( code.id ),
					label: ( code.title || code.slug ) + ' (/qr/' + code.slug + ')'
				};
			} );

			var controls = el(
				InspectorControls,
				{},
				el(
					PanelBody,
					{ title: __( 'QR code', 'gallus-qr' ) },
					el( ComboboxControl, {
						label: __( 'Saved code', 'gallus-qr' ),
						value: attributes.codeId ? String( attributes.codeId ) : '',
						options: options,
						onChange: function ( value ) {
							setAttributes( { codeId: parseInt( value, 10 ) || 0 } );
						}
					} ),
					el( RangeControl, {
						label: __( 'Display size (px)', 'gallus-qr' ),
						min: 128,
						max: 1024,
						step: 16,
						value: attributes.size,
						onChange: function ( value ) {
							setAttributes( { size: value || 256 } );
						}
					} )
				)
			);

			var body;
			if ( codes === null ) {
				body = el( Placeholder, { label: __( 'Gallus QR Code', 'gallus-qr' ) }, el( Spinner, {} ) );
			} else if ( ! codes.length ) {
				body = el(
					Placeholder,
					{ label: __( 'Gallus QR Code', 'gallus-qr' ) },
					__( 'No saved codes yet — create one under Gallus QR in the admin menu.', 'gallus-qr' )
				);
			} else if ( ! selected ) {
				body = el(
					Placeholder,
					{ label: __( 'Gallus QR Code', 'gallus-qr' ) },
					el( ComboboxControl, {
						label: __( 'Pick a saved code', 'gallus-qr' ),
						value: '',
						options: options,
						onChange: function ( value ) {
							setAttributes( { codeId: parseInt( value, 10 ) || 0 } );
						}
					} )
				);
			} else {
				body = el( Preview, { code: selected, size: attributes.size } );
			}

			return el( 'div', useBlockProps(), controls, body );
		},

		// Dynamic block: PHP renders the placeholder, frontend.js hydrates it.
		save: function () {
			return null;
		}
	} );
} )( window.wp );
