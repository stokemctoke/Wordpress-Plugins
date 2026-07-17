/**
 * Gallus QR — front-end hydration for the block and [gallus_qr] shortcode.
 *
 * PHP outputs .gallus-qr-embed placeholders carrying the encoded string and
 * the stored design as data attributes; this draws each one through the
 * shared designer so on-page codes match downloads exactly.
 */
( function () {
	'use strict';

	function hydrate() {
		if ( typeof GallusQRDesign === 'undefined' ) {
			return;
		}

		var embeds = document.querySelectorAll( '.gallus-qr-embed:not([data-gqr-done])' );

		for ( var i = 0; i < embeds.length; i++ ) {
			var embed   = embeds[ i ];
			var encodes = embed.getAttribute( 'data-encodes' );
			if ( ! encodes ) { continue; }

			var design = {};
			try {
				design = JSON.parse( embed.getAttribute( 'data-design' ) || '{}' );
			} catch ( e ) { /* fall back to defaults */ }

			var size = parseInt( embed.getAttribute( 'data-size' ), 10 ) || 256;

			embed.setAttribute( 'data-gqr-done', '1' );
			GallusQRDesign.renderInto( embed, design, encodes, size );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', hydrate );
	} else {
		hydrate();
	}
} )();
