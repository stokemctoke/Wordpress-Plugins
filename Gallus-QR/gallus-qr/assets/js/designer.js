/**
 * Gallus QR — shared design renderer.
 *
 * The single place that turns a stored design (the JSON kept in the codes
 * table) into qr-code-styling options, live previews, and downloads. The
 * generator, the stats re-download buttons, and the front-end block all render
 * through here so a code always looks the same everywhere.
 *
 * Designs are normalized on the way in: v1 designs (base64 `logo`, the
 * original two dot/corner styles) pass through unchanged semantics — stored
 * rows are never rewritten. v2 adds more shapes, two-stop gradients,
 * transparent backgrounds, media-library logos, a frame/CTA wrapper, and
 * JPEG export.
 */
( function () {
	'use strict';

	var DOT_STYLES        = [ 'square', 'rounded', 'dots', 'classy', 'classy-rounded', 'extra-rounded' ];
	var CORNER_STYLES     = [ 'square', 'extra-rounded', 'dot' ];
	var CORNER_DOT_STYLES = [ 'auto', 'square', 'dot' ];
	var GRADIENT_TYPES    = [ 'none', 'linear', 'radial' ];
	var FRAME_STYLES      = [ 'none', 'label-bottom', 'label-top' ];

	var DEFAULTS = {
		dotStyle:      'square',   // qr-code-styling dot type
		cornerStyle:   'square',   // corner square type
		cornerDot:     'auto',     // corner dot type ('auto' = derived from cornerStyle)
		fg:            '#000000',
		fg2:           '',         // second gradient stop ('' = solid)
		gradient:      'none',     // none | linear | radial
		bg:            '#ffffff',
		bgTransparent: false,
		size:          512,        // export size in px (SVG stays vector)
		logo:          '',         // v1: base64 data URL
		logoId:        0,          // v2: media-library attachment ID
		logoUrl:       '',         // v2: resolved attachment URL
		frame:         null        // { style, text, bandColor, textColor } or null
	};

	function oneOf( value, allowed, fallback ) {
		return allowed.indexOf( value ) !== -1 ? value : fallback;
	}

	// Clamp any size-ish value into the supported export range.
	function clampSize( n ) {
		n = parseInt( n, 10 );
		if ( isNaN( n ) ) { n = DEFAULTS.size; }
		return Math.max( 128, Math.min( 1024, n ) );
	}

	/**
	 * Fill a raw stored design (v1 or v2, possibly undefined) into a complete
	 * design object. Unknown keys are dropped; missing keys get defaults;
	 * enum-ish keys are validated so a hand-edited row can't break rendering.
	 */
	function normalize( raw ) {
		raw = raw && typeof raw === 'object' ? raw : {};
		var d = {};
		for ( var key in DEFAULTS ) {
			d[ key ] = raw.hasOwnProperty( key ) && raw[ key ] !== null && raw[ key ] !== undefined
				? raw[ key ]
				: DEFAULTS[ key ];
		}

		d.size        = clampSize( d.size );
		d.dotStyle    = oneOf( d.dotStyle, DOT_STYLES, DEFAULTS.dotStyle );
		d.cornerStyle = oneOf( d.cornerStyle, CORNER_STYLES, DEFAULTS.cornerStyle );
		d.cornerDot   = oneOf( d.cornerDot, CORNER_DOT_STYLES, DEFAULTS.cornerDot );
		d.gradient    = oneOf( d.gradient, GRADIENT_TYPES, DEFAULTS.gradient );

		if ( d.frame && typeof d.frame === 'object' && d.frame.style
			&& oneOf( d.frame.style, FRAME_STYLES, 'none' ) !== 'none' && d.frame.text ) {
			d.frame = {
				style:     d.frame.style,
				text:      String( d.frame.text ).slice( 0, 40 ),
				bandColor: d.frame.bandColor || '#000000',
				textColor: d.frame.textColor || '#ffffff'
			};
		} else {
			d.frame = null;
		}

		return d;
	}

	// The image drawn in the centre, if any: media-library URL wins, then the
	// legacy base64 data URL.
	function logoSrc( design ) {
		return design.logoUrl || design.logo || '';
	}

	/**
	 * Map a (normalized) design + encoded data to qr-code-styling options.
	 *
	 * @param {Object} design       Normalized design.
	 * @param {string} data         The string the QR encodes.
	 * @param {number} sizeOverride Optional pixel size (e.g. preview size);
	 *                              falls back to the design's export size.
	 */
	function buildOptions( design, data, sizeOverride ) {
		design = normalize( design );
		var size = sizeOverride ? clampSize( sizeOverride ) : design.size;
		var logo = logoSrc( design );

		var dots = { type: design.dotStyle };
		if ( design.gradient !== 'none' && design.fg2 ) {
			dots.gradient = {
				type: design.gradient,
				rotation: 0,
				colorStops: [
					{ offset: 0, color: design.fg },
					{ offset: 1, color: design.fg2 }
				]
			};
		} else {
			dots.color = design.fg;
		}

		var cornerDot = design.cornerDot === 'auto'
			? ( design.cornerStyle === 'extra-rounded' || design.cornerStyle === 'dot' ? 'dot' : 'square' )
			: design.cornerDot;

		var opts = {
			width: size,
			height: size,
			type: 'svg',
			data: data || ' ',
			margin: 8,
			qrOptions: { errorCorrectionLevel: logo ? 'H' : 'M' },
			dotsOptions: dots,
			cornersSquareOptions: { type: design.cornerStyle },
			cornersDotOptions: { type: cornerDot },
			backgroundOptions: { color: design.bgTransparent ? 'rgba(0,0,0,0)' : design.bg }
		};

		if ( logo ) {
			opts.image = logo;
			opts.imageOptions = { crossOrigin: 'anonymous', margin: 6, imageSize: 0.4, hideBackgroundDots: true };
		}

		return opts;
	}

	/**
	 * qr-code-styling's SVG has fixed width/height but no viewBox, so CSS
	 * scaling clips the bottom/right. Add a viewBox and let it scale to fit.
	 * Call after (re-)rendering into a container.
	 */
	function fitSvg( container ) {
		var svg = container.querySelector( 'svg' );
		if ( ! svg ) { return; }
		if ( ! svg.getAttribute( 'viewBox' ) ) {
			var w = parseInt( svg.getAttribute( 'width' ), 10 );
			var h = parseInt( svg.getAttribute( 'height' ), 10 );
			if ( w && h ) {
				svg.setAttribute( 'viewBox', '0 0 ' + w + ' ' + h );
			}
		}
		svg.setAttribute( 'width', '100%' );
		svg.removeAttribute( 'height' );
		svg.style.display = 'block';
		svg.style.height = 'auto';
	}

	// --- Frame / CTA wrapper ------------------------------------------------------

	/**
	 * Frame metrics for a given QR pixel size: label band height, padding and
	 * border radius all scale with the code.
	 */
	function frameMetrics( size ) {
		var band = Math.round( size * 0.18 );
		var pad  = Math.round( size * 0.05 );
		return {
			band:   band,
			pad:    pad,
			width:  size + pad * 2,
			height: size + pad * 2 + band,
			radius: Math.round( size * 0.04 )
		};
	}

	/**
	 * Wrap a rendered QR <svg> string in an outer SVG with a rounded border
	 * and a label band ("SCAN ME"). Returns the wrapper SVG as a string.
	 *
	 * @param {string} qrSvg  The QR's own <svg …>…</svg> markup.
	 * @param {Object} design Normalized design with a non-null frame.
	 * @param {number} size   QR pixel size the svg was rendered at.
	 */
	function wrapWithFrame( qrSvg, design, size ) {
		var f     = design.frame;
		var m     = frameMetrics( size );
		var onTop = f.style === 'label-top';
		var qrY   = m.pad + ( onTop ? m.band : 0 );
		var bandY = onTop ? 0 : m.height - m.band;
		var textY = bandY + m.band / 2;
		var bgFill = design.bgTransparent ? '#ffffff' : design.bg;

		// Give the inner svg an explicit position/size inside the wrapper.
		var inner = qrSvg.replace(
			/<svg([^>]*)>/,
			function ( match, attrs ) {
				attrs = attrs
					.replace( /\s(x|y|width|height)="[^"]*"/g, '' );
				return '<svg' + attrs + ' x="' + m.pad + '" y="' + qrY + '" width="' + size + '" height="' + size + '">';
			}
		);

		var esc = function ( s ) {
			return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
		};

		return '<svg xmlns="http://www.w3.org/2000/svg" width="' + m.width + '" height="' + m.height + '"'
			+ ' viewBox="0 0 ' + m.width + ' ' + m.height + '">'
			+ '<rect x="0" y="0" width="' + m.width + '" height="' + m.height + '" rx="' + m.radius + '" fill="' + esc( f.bandColor ) + '"/>'
			+ '<rect x="' + ( m.pad / 2 ) + '" y="' + ( onTop ? m.band : m.pad / 2 ) + '" width="' + ( m.width - m.pad ) + '"'
			+ ' height="' + ( m.height - m.band - m.pad / 2 - ( onTop ? m.pad / 2 : 0 ) ) + '" rx="' + Math.round( m.radius * 0.6 ) + '" fill="' + esc( bgFill ) + '"/>'
			+ inner
			+ '<text x="' + ( m.width / 2 ) + '" y="' + textY + '" fill="' + esc( f.textColor ) + '"'
			+ ' font-family="Arial, Helvetica, sans-serif" font-weight="bold" font-size="' + Math.round( m.band * 0.5 ) + '"'
			+ ' text-anchor="middle" dominant-baseline="central">' + esc( f.text ) + '</text>'
			+ '</svg>';
	}

	/**
	 * Get the raw <svg> markup for a design + data at a given size.
	 * Resolves with a string.
	 */
	function qrSvgString( design, data, size ) {
		var qr = new QRCodeStyling( buildOptions( design, data, size ) );
		return qr.getRawData( 'svg' ).then( function ( blob ) {
			return blob.text ? blob.text() : new Promise( function ( resolve ) {
				var reader = new FileReader();
				reader.onload = function () { resolve( reader.result ); };
				reader.readAsText( blob );
			} );
		} );
	}

	// Trigger a browser download of a Blob.
	function saveBlob( blob, filename ) {
		var url = URL.createObjectURL( blob );
		var a   = document.createElement( 'a' );
		a.href = url;
		a.download = filename;
		document.body.appendChild( a );
		a.click();
		document.body.removeChild( a );
		setTimeout( function () { URL.revokeObjectURL( url ); }, 5000 );
	}

	/**
	 * Rasterise an SVG string onto a canvas and save it as PNG or JPEG.
	 * JPEG has no alpha, so the canvas is filled white first.
	 */
	function rasterizeAndSave( svgString, width, height, ext, filename ) {
		var blob = new Blob( [ svgString ], { type: 'image/svg+xml;charset=utf-8' } );
		var url  = URL.createObjectURL( blob );
		var img  = new Image();

		img.onload = function () {
			var canvas    = document.createElement( 'canvas' );
			canvas.width  = width;
			canvas.height = height;
			var ctx = canvas.getContext( '2d' );
			if ( ext === 'jpeg' ) {
				ctx.fillStyle = '#ffffff';
				ctx.fillRect( 0, 0, width, height );
			}
			ctx.drawImage( img, 0, 0, width, height );
			URL.revokeObjectURL( url );
			canvas.toBlob( function ( out ) {
				if ( out ) { saveBlob( out, filename ); }
			}, ext === 'jpeg' ? 'image/jpeg' : 'image/png', 0.92 );
		};
		img.onerror = function () { URL.revokeObjectURL( url ); };
		img.src = url;
	}

	// --- Public API -----------------------------------------------------------------

	/**
	 * Render a design into a container element (used by the front-end block;
	 * the generator keeps its own live instance for cheap re-renders of the
	 * un-framed code and draws the frame around the container in CSS-land).
	 */
	function renderInto( container, design, data, sizeOverride ) {
		if ( typeof QRCodeStyling === 'undefined' ) { return null; }
		design = normalize( design );
		var size = sizeOverride ? clampSize( sizeOverride ) : design.size;

		if ( design.frame ) {
			qrSvgString( design, data, size ).then( function ( svg ) {
				container.innerHTML = wrapWithFrame( svg, design, size );
				fitSvg( container );
			} );
			return null;
		}

		var qr = new QRCodeStyling( buildOptions( design, data, size ) );
		container.innerHTML = '';
		qr.append( container );
		requestAnimationFrame( function () { fitSvg( container ); } );
		return qr;
	}

	/**
	 * Download a design at its full export size.
	 *
	 * @param {Object} design Raw or normalized design.
	 * @param {string} data   Encoded string.
	 * @param {string} ext    'png' | 'jpeg' | 'svg'.
	 * @param {string} name   Filename without extension.
	 */
	function download( design, data, ext, name ) {
		if ( typeof QRCodeStyling === 'undefined' ) { return; }
		design = normalize( design );
		ext    = ext === 'svg' ? 'svg' : ( ext === 'jpeg' ? 'jpeg' : 'png' );
		name   = name || 'gallus-qr';

		// No frame: let the library handle every format natively…
		if ( ! design.frame && ext !== 'jpeg' ) {
			var qr = new QRCodeStyling( buildOptions( design, data ) );
			qr.download( { name: name, extension: ext } );
			return;
		}

		// …framed (or JPEG, which needs a white underlay): compose ourselves.
		var size = design.size;
		qrSvgString( design, data, size ).then( function ( svg ) {
			var outSvg = design.frame ? wrapWithFrame( svg, design, size ) : svg;
			var m      = design.frame ? frameMetrics( size ) : { width: size, height: size };

			if ( ext === 'svg' ) {
				saveBlob(
					new Blob( [ outSvg ], { type: 'image/svg+xml;charset=utf-8' } ),
					name + '.svg'
				);
				return;
			}

			rasterizeAndSave( outSvg, m.width, m.height, ext, name + '.' + ext );
		} );
	}

	window.GallusQRDesign = {
		DEFAULTS: DEFAULTS,
		DOT_STYLES: DOT_STYLES,
		CORNER_STYLES: CORNER_STYLES,
		clampSize: clampSize,
		normalize: normalize,
		buildOptions: buildOptions,
		fitSvg: fitSvg,
		renderInto: renderInto,
		download: download
	};
} )();
