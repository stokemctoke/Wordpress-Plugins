/**
 * Gallus QR — Scan Stats re-download helper.
 *
 * Each row's PNG/SVG buttons carry the code's short /qr/{slug} link. On click we
 * render that link with the code's *stored* design (shapes/colours/size/logo)
 * and trigger a download — so a re-download matches the original. Codes saved
 * before designs were persisted fall back to plain black-on-white.
 */
( function () {
	'use strict';

	if ( typeof QRCodeStyling === 'undefined' ) {
		return;
	}

	var store   = window.GallusQRStats || {};
	var designs = store.designs || {};

	// Map a stored design (or undefined) to qr-code-styling options.
	function optionsFor( url, slug ) {
		var d = designs[ slug ] || {};

		var size        = parseInt( d.size, 10 );
		if ( isNaN( size ) ) { size = 512; }
		var dotStyle    = d.dotStyle === 'rounded' ? 'rounded' : 'square';
		var cornerStyle = d.cornerStyle === 'extra-rounded' ? 'extra-rounded' : 'square';
		var fg          = d.fg || '#000000';
		var bg          = d.bg || '#ffffff';

		var opts = {
			width: size,
			height: size,
			type: 'svg',
			data: url,
			margin: 8,
			qrOptions: { errorCorrectionLevel: d.logo ? 'H' : 'M' },
			dotsOptions: { type: dotStyle, color: fg },
			cornersSquareOptions: { type: cornerStyle },
			cornersDotOptions: { type: cornerStyle === 'extra-rounded' ? 'dot' : 'square' },
			backgroundOptions: { color: bg }
		};

		if ( d.logo ) {
			opts.image = d.logo;
			opts.imageOptions = {
				crossOrigin: 'anonymous',
				margin: 6,
				imageSize: 0.4,
				hideBackgroundDots: true
			};
		}

		return opts;
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.gqr-dl' );
		if ( ! btn ) {
			return;
		}

		var url  = btn.getAttribute( 'data-url' );
		var slug = btn.getAttribute( 'data-slug' ) || 'code';
		var ext  = btn.getAttribute( 'data-ext' ) === 'svg' ? 'svg' : 'png';

		var qr = new QRCodeStyling( optionsFor( url, slug ) );
		qr.download( { name: 'gallus-qr-' + slug, extension: ext } );
	} );
} )();
