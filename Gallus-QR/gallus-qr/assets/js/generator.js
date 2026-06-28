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

	// The persistable design — everything needed to faithfully re-draw this code.
	function currentDesign() {
		return {
			dotStyle:    els.dotStyle.value,
			cornerStyle: els.cornerStyle.value,
			fg:          els.fg.value,
			bg:          els.bg.value,
			size:        exportSize(),
			logo:        logoDataUrl || ''
		};
	}

	// The on-screen preview is always this many px; the slider only affects the
	// downloaded file. This keeps the preview a constant size in its window.
	var PREVIEW_SIZE = 320;

	function buildOptions( forExport ) {
		var size = forExport ? exportSize() : PREVIEW_SIZE;
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

	var qrCode = new QRCodeStyling( buildOptions( false ) );

	// qr-code-styling's SVG has fixed width/height but no viewBox, so CSS
	// scaling clips the bottom/right. Add a viewBox and let it scale to fit.
	function fixSvgScaling() {
		var svg = els.canvas.querySelector( 'svg' );
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

	// Re-apply whenever the engine replaces the SVG (every update()).
	new MutationObserver( fixSvgScaling ).observe( els.canvas, { childList: true } );
	qrCode.append( els.canvas );
	requestAnimationFrame( fixSvgScaling );

	function render() {
		qrCode.update( buildOptions( false ) );
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

	// Size only affects the export, so just update the readout — no re-render.
	els.size.addEventListener( 'input', function () {
		els.sizeValue.textContent = exportSize();
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
				destination: destination,
				design: currentDesign()
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

	// Export from a fresh instance at the chosen resolution, leaving the
	// fixed-size preview untouched.
	function downloadAs( ext ) {
		var exporter = new QRCodeStyling( buildOptions( true ) );
		exporter.download( { name: 'gallus-qr', extension: ext } );
	}

	els.dlPng.addEventListener( 'click', function () { downloadAs( 'png' ); } );
	els.dlSvg.addEventListener( 'click', function () { downloadAs( 'svg' ); } );
} )();
