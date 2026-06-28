/**
 * Gallus QR — live generator UI.
 *
 * Two explicit modes, surfaced in the UI so it's always clear what the QR does:
 *   • Direct    — encodes the URL itself. Works forever, no tracking.
 *   • Trackable — saved via REST, encodes a short /qr/{slug} link that routes
 *                 through this site and counts every scan.
 *
 * A badge + an "Encodes →" readout show the code's true nature, and downloads
 * are blocked in Trackable mode until the code is saved (so you can never grab
 * an untracked code while believing it's tracked).
 */
( function () {
	'use strict';

	if ( typeof QRCodeStyling === 'undefined' ) {
		console.error( '[Gallus QR] qr-code-styling engine not found.' );
		return;
	}

	var cfg = window.GallusQR || {};

	var $ = function ( id ) { return document.getElementById( id ); };

	var els = {
		data:         $( 'gqr-data' ),
		dotStyle:     $( 'gqr-dot-style' ),
		cornerStyle:  $( 'gqr-corner-style' ),
		fg:           $( 'gqr-fg' ),
		bg:           $( 'gqr-bg' ),
		invert:       $( 'gqr-invert' ),
		logo:         $( 'gqr-logo' ),
		logoClear:    $( 'gqr-logo-clear' ),
		size:         $( 'gqr-size' ),
		sizeValue:    $( 'gqr-size-value' ),
		modeDirect:   $( 'gqr-mode-direct' ),
		modeTrack:    $( 'gqr-mode-trackable' ),
		modeHelp:     $( 'gqr-mode-help' ),
		trackFields:  $( 'gqr-track-fields' ),
		title:        $( 'gqr-title' ),
		save:         $( 'gqr-save' ),
		saveResult:   $( 'gqr-save-result' ),
		canvas:       $( 'gqr-canvas' ),
		badge:        $( 'gqr-badge' ),
		encodes:      $( 'gqr-encodes' ),
		dlPng:        $( 'gqr-download-png' ),
		dlSvg:        $( 'gqr-download-svg' ),
		downloadHint: $( 'gqr-download-hint' )
	};

	// --- State ----------------------------------------------------------------

	var mode        = 'direct';   // 'direct' | 'trackable'
	var saved       = false;      // a trackable code has been saved
	var savedUrl    = '';         // its short /qr/{slug} link
	var savedFor    = '';         // the destination it was saved for
	var logoDataUrl = null;

	var PREVIEW_SIZE = 320;

	// Host shown in the "tracked via …" badge (e.g. stokemctoke.com).
	var trackHost = ( function () {
		try { return new URL( cfg.qrBase ).host; } catch ( e ) { return 'this site'; }
	} )();

	// What the QR currently encodes, given the mode/saved state.
	function encodedValue() {
		if ( mode === 'trackable' && saved ) {
			return savedUrl;
		}
		return els.data.value;
	}

	// True when a download would NOT be the tracked code the user expects.
	function downloadBlocked() {
		return mode === 'trackable' && ! saved;
	}

	// --- QR rendering ---------------------------------------------------------

	function exportSize() {
		var n = parseInt( els.size.value, 10 );
		if ( isNaN( n ) ) { n = 512; }
		return Math.max( 128, Math.min( 1024, n ) );
	}

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

	function buildOptions( forExport ) {
		var size = forExport ? exportSize() : PREVIEW_SIZE;
		var opts = {
			width: size,
			height: size,
			type: 'svg',
			data: encodedValue() || ' ',
			margin: 8,
			qrOptions: { errorCorrectionLevel: logoDataUrl ? 'H' : 'M' },
			dotsOptions: { type: els.dotStyle.value, color: els.fg.value },
			cornersSquareOptions: { type: els.cornerStyle.value },
			cornersDotOptions: { type: els.cornerStyle.value === 'extra-rounded' ? 'dot' : 'square' },
			backgroundOptions: { color: els.bg.value }
		};
		if ( logoDataUrl ) {
			opts.image = logoDataUrl;
			opts.imageOptions = { crossOrigin: 'anonymous', margin: 6, imageSize: 0.4, hideBackgroundDots: true };
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

	new MutationObserver( fixSvgScaling ).observe( els.canvas, { childList: true } );
	qrCode.append( els.canvas );
	requestAnimationFrame( fixSvgScaling );

	function render() {
		qrCode.update( buildOptions( false ) );
	}

	// --- UI sync --------------------------------------------------------------

	// Reflect the whole state into badge / readout / buttons, then re-render.
	function updateUI() {
		var isTrack = mode === 'trackable';

		// Mode buttons.
		els.modeDirect.classList.toggle( 'is-active', ! isTrack );
		els.modeTrack.classList.toggle( 'is-active', isTrack );
		els.modeDirect.setAttribute( 'aria-checked', String( ! isTrack ) );
		els.modeTrack.setAttribute( 'aria-checked', String( isTrack ) );
		els.trackFields.hidden = ! isTrack;

		els.modeHelp.textContent = isTrack
			? 'Routes through your site so every scan is counted. Save it to generate the tracked link.'
			: 'Encodes your URL directly. Works forever, but scans can’t be counted — best for permanent things like PCBs.';

		// Badge.
		els.badge.classList.remove( 'gqr-badge--direct', 'gqr-badge--tracked', 'gqr-badge--pending' );
		if ( ! isTrack ) {
			els.badge.textContent = '○ Direct — not tracked';
			els.badge.classList.add( 'gqr-badge--direct' );
		} else if ( saved ) {
			els.badge.textContent = '● Tracked · via ' + trackHost;
			els.badge.classList.add( 'gqr-badge--tracked' );
		} else {
			els.badge.textContent = '○ Trackable — not saved yet';
			els.badge.classList.add( 'gqr-badge--pending' );
		}

		// Encodes readout.
		if ( ! isTrack ) {
			els.encodes.textContent = 'Encodes → ' + ( els.data.value || '—' );
		} else if ( saved ) {
			els.encodes.textContent = 'Encodes → ' + savedUrl + '\n↳ ' + els.data.value;
		} else {
			els.encodes.textContent = 'Save to generate your tracked link, then download.';
		}

		// Downloads.
		var block = downloadBlocked();
		els.dlPng.disabled = block;
		els.dlSvg.disabled = block;
		els.downloadHint.hidden = ! block;
		if ( block ) {
			els.downloadHint.textContent = 'Save first — otherwise you’d download an untracked code.';
		}

		render();
	}

	function setMode( m ) {
		mode = m;
		updateUI();
	}

	function clearSaveError() {
		els.saveResult.hidden = true;
		els.saveResult.textContent = '';
		els.saveResult.classList.remove( 'is-error' );
	}

	// --- Design controls ------------------------------------------------------

	els.data.addEventListener( 'input', function () {
		// Editing the URL invalidates a previously-saved trackable link.
		if ( saved && els.data.value !== savedFor ) {
			saved = false;
			savedUrl = '';
		}
		clearSaveError();
		updateUI();
	} );

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
		if ( ! file ) { return; }
		var reader = new FileReader();
		reader.onload = function ( ev ) { logoDataUrl = ev.target.result; render(); };
		reader.readAsDataURL( file );
	} );

	els.logoClear.addEventListener( 'click', function () {
		logoDataUrl = null;
		els.logo.value = '';
		render();
	} );

	els.size.addEventListener( 'input', function () {
		els.sizeValue.textContent = exportSize();
	} );

	// --- Mode switch ----------------------------------------------------------

	els.modeDirect.addEventListener( 'click', function () { setMode( 'direct' ); } );
	els.modeTrack.addEventListener( 'click', function () { setMode( 'trackable' ); } );

	// --- Save (trackable) -----------------------------------------------------

	els.save.addEventListener( 'click', function () {
		var destination = els.data.value;
		if ( ! destination ) { return showSaveError( 'Enter a URL first.' ); }
		if ( ! cfg.restUrl ) { return showSaveError( 'Tracking is unavailable (REST config missing).' ); }

		els.save.disabled = true;
		els.save.textContent = 'Saving…';

		fetch( cfg.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify( { title: els.title.value, destination: destination, design: currentDesign() } )
		} )
			.then( function ( res ) {
				return res.json().then( function ( body ) { return { ok: res.ok, body: body }; } );
			} )
			.then( function ( result ) {
				if ( ! result.ok ) {
					throw new Error( ( result.body && result.body.message ) || 'Save failed.' );
				}
				saved = true;
				savedUrl = result.body.url;
				savedFor = destination;
				clearSaveError();
				updateUI();
			} )
			.catch( function ( err ) { showSaveError( err.message ); } )
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

	// --- Downloads ------------------------------------------------------------

	function downloadAs( ext ) {
		if ( downloadBlocked() ) { return; }
		var exporter = new QRCodeStyling( buildOptions( true ) );
		exporter.download( { name: 'gallus-qr', extension: ext } );
	}

	els.dlPng.addEventListener( 'click', function () { downloadAs( 'png' ); } );
	els.dlSvg.addEventListener( 'click', function () { downloadAs( 'svg' ); } );

	// --- Init -----------------------------------------------------------------

	updateUI();
} )();
