/**
 * Gallus QR — Scan Stats re-download helper.
 *
 * Each row has PNG/SVG buttons carrying the code's short /qr/{slug} link. On
 * click we render that link with the bundled engine (plain black-on-white) and
 * trigger a download. Custom generator styling isn't stored, so this is the
 * canonical scannable code, not the decorated one.
 */
( function () {
	'use strict';

	if ( typeof QRCodeStyling === 'undefined' ) {
		return;
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.gqr-dl' );
		if ( ! btn ) {
			return;
		}

		var url  = btn.getAttribute( 'data-url' );
		var slug = btn.getAttribute( 'data-slug' ) || 'code';
		var ext  = btn.getAttribute( 'data-ext' ) === 'svg' ? 'svg' : 'png';

		var qr = new QRCodeStyling( {
			width: 512,
			height: 512,
			type: 'svg',
			data: url,
			margin: 8,
			dotsOptions: { type: 'square', color: '#000000' },
			backgroundOptions: { color: '#ffffff' }
		} );

		qr.download( { name: 'gallus-qr-' + slug, extension: ext } );
	} );
} )();
