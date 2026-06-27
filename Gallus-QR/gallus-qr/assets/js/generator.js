/**
 * Gallus QR — live generator UI.
 *
 * Draws the QR with the bundled qr-code-styling engine and re-renders on every
 * change. Static codes encode the URL directly (M1). Trackable codes are first
 * saved via the REST endpoint, which returns a short /qr/{slug} link; the QR
 * then encodes that link so each scan can be counted (M2).
 */
( function () {
	'use strict';

	if ( typeof QRCodeStyling === 'undefined' ) {
		console.error( '[Gallus QR] qr-code-styling engine not found.' );
		return;
	}

	// Settings injected by wp_localize_script (REST url, nonce, /qr/ base).
	var cfg = window.GallusQR || {};

	var $ = function ( id ) { return document.getElementById( id ); };

	var els = {
		data:        $( 'gqr-data' ),
		dotStyle:    $( 'gqr-dot-style' ),
		cornerStyle: $( 'gqr-corner-style' ),
		fg:          $( 'gqr-fg' ),
		bg:          $( 'gqr-bg' ),
		invert:      $( 'gqr-invert' ),
		logo:        $( 'gqr-logo' ),
		logoClear:   $( 'gqr-logo-clear' ),
		size:        $( 'gqr-size' ),
		sizeValue:   $( 'gqr-size-value' ),
		canvas:      $( 'gqr-canvas' ),
		dlPng:       $( 'gqr-download-png' ),
		dlSvg:       $( 'gqr-download-svg' ),
		trackable:   $( 'gqr-trackable' ),
		trackFields: $( 'gqr-track-fields' ),
		title:       $( 'gqr-title' ),
		save:        $( 'gqr-save' ),
		saveResult:  $( 'gqr-save-result' )
	};

	// Logo data URL (browser-only). And the encoded value: either the typed URL
	// or, once a trackable code is saved, the short /qr/{slug} link.
	var logoDataUrl = null;
	var encodedValue = els.data.value;

	// Clamp the size slider to the supported 128–1024 range.
	function exportSize() {
		var n = parseInt( els.size.value, 10 );
		if ( isNaN( n ) ) { n = 512; }
		return Math.max( 128, Math.min( 1024, n ) );
	}

	function buildOptions() {
		var size = exportSize();
		var opts = {
			width: size,
			height: size,
			type: 'svg',
			data: encodedValue || ' ',
			margin: 8,
			qrOptions: {
				errorCorrectionLevel: logoDataUrl ? 'H' : 'M'
			},
			dotsOptions: {
				type: els.dotStyle.value,
				color: els.fg.value
			},
			cornersSquareOptions: {
				type: els.cornerStyle.value
			},
			cornersDotOptions: {
				type: els.cornerStyle.value === 'extra-rounded' ? 'dot' : 'square'
			},
			backgroundOptions: {
				color: els.bg.value
			}
		};

		if ( logoDataUrl ) {
			opts.image = logoDataUrl;
			opts.imageOptions = {
				crossOrigin: 'anonymous',
				margin: 6,
				imageSize: 0.4,
				hideBackgroundDots: true
			};
		}

		return opts;
	}

	var qrCode = new QRCodeStyling( buildOptions() );
	qrCode.append( els.canvas );

	function render() {
		qrCode.update( buildOptions() );
	}

	// Typing in the URL field clears any previously-saved short link, because
	// the code now points somewhere else.
	function onDataChange() {
		encodedValue = els.data.value;
		clearSaveResult();
		render();
	}

	function clearSaveResult() {
		els.saveResult.hidden = true;
		els.saveResult.textContent = '';
		els.saveResult.classList.remove( 'is-error' );
	}

	// --- Wire up the design controls -----------------------------------------

	els.data.addEventListener( 'input', onDataChange );
	els.dotStyle.addEventListener( 'change', render );
	els.cornerStyle.addEventListener( 'change', render );
	els.fg.addEventListener( 'input', render );
	els.bg.addEventListener( 'input', render );

	els.invert.addEventListener( 'click', function () {
		var fg = els.fg.value;
		els.fg.value = els.bg.value;
		els.bg.value = fg;
		render();
	} );

	els.logo.addEventListener( 'change', function ( e ) {
		var file = e.target.files && e.target.files[ 0 ];
		if ( ! file ) {
			return;
		}
		var reader = new FileReader();
		reader.onload = function ( ev ) {
			logoDataUrl = ev.target.result;
			render();
		};
		reader.readAsDataURL( file );
	} );

	els.logoClear.addEventListener( 'click', function () {
		logoDataUrl = null;
		els.logo.value = '';
		render();
	} );

	els.size.addEventListener( 'input', function () {
		els.sizeValue.textContent = exportSize();
		render();
	} );

	// --- Tracking ------------------------------------------------------------

	els.trackable.addEventListener( 'change', function () {
		els.trackFields.hidden = ! els.trackable.checked;
		// Switching off restores the directly-encoded URL.
		if ( ! els.trackable.checked ) {
			encodedValue = els.data.value;
			clearSaveResult();
			render();
		}
	} );

	els.save.addEventListener( 'click', function () {
		var destination = els.data.value;
		if ( ! destination ) {
			showSaveError( 'Enter a URL first.' );
			return;
		}
		if ( ! cfg.restUrl ) {
			showSaveError( 'Tracking is unavailable (REST config missing).' );
			return;
		}

		els.save.disabled = true;
		els.save.textContent = 'Saving…';

		fetch( cfg.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce
			},
			body: JSON.stringify( {
				title: els.title.value,
				destination: destination
			} )
		} )
			.then( function ( res ) {
				return res.json().then( function ( body ) {
					return { ok: res.ok, body: body };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok ) {
					throw new Error( ( result.body && result.body.message ) || 'Save failed.' );
				}
				// Encode the short link from now on, and re-render.
				encodedValue = result.body.url;
				render();

				els.saveResult.hidden = false;
				els.saveResult.classList.remove( 'is-error' );
				els.saveResult.textContent =
					'Trackable! Scans will count. This QR now points to ' + result.body.url;
			} )
			.catch( function ( err ) {
				showSaveError( err.message );
			} )
			.finally( function () {
				els.save.disabled = false;
				els.save.textContent = 'Save & make trackable';
			} );
	} );

	function showSaveError( msg ) {
		els.saveResult.hidden = false;
		els.saveResult.classList.add( 'is-error' );
		els.saveResult.textContent = msg;
	}

	// --- Downloads -----------------------------------------------------------

	els.dlPng.addEventListener( 'click', function () {
		qrCode.download( { name: 'gallus-qr', extension: 'png' } );
	} );
	els.dlSvg.addEventListener( 'click', function () {
		qrCode.download( { name: 'gallus-qr', extension: 'svg' } );
	} );
} )();
