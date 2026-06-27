/**
 * Gallus QR — live generator UI (Milestone 1).
 *
 * Reads the controls rendered by class-admin.php, draws the QR with the bundled
 * qr-code-styling engine, and re-renders on every change. No server calls yet —
 * everything here runs in the browser. Scan tracking arrives in Milestone 2.
 */
( function () {
	'use strict';

	// Bail loudly (to the console) if the engine didn't load.
	if ( typeof QRCodeStyling === 'undefined' ) {
		console.error( '[Gallus QR] qr-code-styling engine not found.' );
		return;
	}

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
		canvas:      $( 'gqr-canvas' ),
		dlPng:       $( 'gqr-download-png' ),
		dlSvg:       $( 'gqr-download-svg' )
	};

	// Data URL of the current centre logo, or null. Lives only in the browser.
	var logoDataUrl = null;

	// Build the option object the engine expects from the current control values.
	function buildOptions() {
		var opts = {
			width: 320,
			height: 320,
			type: 'svg',                 // svg preview scales crisply; PNG export still works
			data: els.data.value || ' ', // engine needs non-empty data
			margin: 8,
			qrOptions: {
				// High EC so a centre logo never breaks scannability.
				errorCorrectionLevel: logoDataUrl ? 'H' : 'M'
			},
			dotsOptions: {
				type: els.dotStyle.value,   // 'square' | 'rounded'
				color: els.fg.value
			},
			cornersSquareOptions: {
				type: els.cornerStyle.value // 'square' | 'extra-rounded'
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

	// Create the engine instance once, then update it on changes.
	var qrCode = new QRCodeStyling( buildOptions() );
	qrCode.append( els.canvas );

	function render() {
		qrCode.update( buildOptions() );
	}

	// --- Wire up the controls -------------------------------------------------

	[ 'input', 'change' ].forEach( function ( evt ) {
		els.data.addEventListener( evt, render );
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

	// --- Downloads ------------------------------------------------------------

	els.dlPng.addEventListener( 'click', function () {
		qrCode.download( { name: 'gallus-qr', extension: 'png' } );
	} );
	els.dlSvg.addEventListener( 'click', function () {
		qrCode.download( { name: 'gallus-qr', extension: 'svg' } );
	} );
} )();
