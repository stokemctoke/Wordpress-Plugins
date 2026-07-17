/**
 * Gallus QR — live generator UI.
 *
 * Content types: URL (with optional UTM tagging) plus WiFi, vCard, email, SMS,
 * phone, calendar event, and plain text — each with its own field panel. The
 * encoded string is built by payloads.js (mirrored server-side).
 *
 * URL codes come in two explicit modes, surfaced in the UI so it's always
 * clear what the QR does:
 *   • Direct    — encodes the URL itself. Works forever, no tracking.
 *   • Trackable — saved via REST, encodes a short /qr/{slug} link that routes
 *                 through this site and counts every scan.
 * Other types are always Direct (a redirect is HTTP-only), but can still be
 * saved to the library for faithful re-downloads later.
 *
 * A badge + an "Encodes →" readout show the code's true nature, and downloads
 * are blocked in Trackable mode until the code is saved (so you can never grab
 * an untracked code while believing it's tracked).
 */
( function () {
	'use strict';

	if ( typeof QRCodeStyling === 'undefined'
		|| typeof GallusQRDesign === 'undefined'
		|| typeof GallusQRPayloads === 'undefined' ) {
		console.error( '[Gallus QR] rendering engine not found.' );
		return;
	}

	var cfg = window.GallusQR || {};

	// Localized string with an inline fallback (keeps the UI working even if
	// the localize call ever goes missing).
	var t = function ( key, fallback ) {
		return ( cfg.i18n && cfg.i18n[ key ] ) || fallback;
	};

	var $ = function ( id ) { return document.getElementById( id ); };

	var els = {
		payloadType:  $( 'gqr-payload-type' ),
		data:         $( 'gqr-data' ),
		dotStyle:     $( 'gqr-dot-style' ),
		cornerStyle:  $( 'gqr-corner-style' ),
		cornerDot:    $( 'gqr-corner-dot' ),
		fg:           $( 'gqr-fg' ),
		fg2:          $( 'gqr-fg2' ),
		fg2Field:     $( 'gqr-fg2-field' ),
		gradient:     $( 'gqr-gradient' ),
		bg:           $( 'gqr-bg' ),
		bgTransparent: $( 'gqr-bg-transparent' ),
		invert:       $( 'gqr-invert' ),
		logo:         $( 'gqr-logo' ),
		logoMedia:    $( 'gqr-logo-media' ),
		logoStatus:   $( 'gqr-logo-status' ),
		logoClear:    $( 'gqr-logo-clear' ),
		frameStyle:   $( 'gqr-frame-style' ),
		frameText:    $( 'gqr-frame-text' ),
		frameColors:  $( 'gqr-frame-colors' ),
		frameBand:    $( 'gqr-frame-band' ),
		frameTextColor: $( 'gqr-frame-textcolor' ),
		presetSelect: $( 'gqr-preset-select' ),
		presetDelete: $( 'gqr-preset-delete' ),
		presetName:   $( 'gqr-preset-name' ),
		presetSave:   $( 'gqr-preset-save' ),
		presetStatus: $( 'gqr-preset-status' ),
		size:         $( 'gqr-size' ),
		sizeValue:    $( 'gqr-size-value' ),
		modeWrap:     $( 'gqr-mode-wrap' ),
		modeDirect:   $( 'gqr-mode-direct' ),
		modeTrack:    $( 'gqr-mode-trackable' ),
		modeHelp:     $( 'gqr-mode-help' ),
		trackFields:  $( 'gqr-track-fields' ),
		title:        $( 'gqr-title' ),
		slugField:    $( 'gqr-slug-field' ),
		slug:         $( 'gqr-slug' ),
		slugStatus:   $( 'gqr-slug-status' ),
		advanced:     $( 'gqr-advanced' ),
		expires:      $( 'gqr-expires' ),
		maxScans:     $( 'gqr-max-scans' ),
		fallback:     $( 'gqr-fallback' ),
		destMode:     $( 'gqr-dest-mode' ),
		destExtra:    $( 'gqr-dest-extra' ),
		destB:        $( 'gqr-dest-b' ),
		destBLabel:   $( 'gqr-dest-b-label' ),
		switchField:  $( 'gqr-switch-field' ),
		switchAt:     $( 'gqr-switch-at' ),
		splitField:   $( 'gqr-split-field' ),
		abSplit:      $( 'gqr-ab-split' ),
		splitValue:   $( 'gqr-split-value' ),
		save:         $( 'gqr-save' ),
		saveResult:   $( 'gqr-save-result' ),
		canvas:       $( 'gqr-canvas' ),
		badge:        $( 'gqr-badge' ),
		encodes:      $( 'gqr-encodes' ),
		dlPng:        $( 'gqr-download-png' ),
		dlJpeg:       $( 'gqr-download-jpeg' ),
		dlSvg:        $( 'gqr-download-svg' ),
		downloadHint: $( 'gqr-download-hint' ),
		controls:     document.querySelector( '.gqr-controls' )
	};

	var panels = document.querySelectorAll( '.gqr-payload-panel' );

	// --- State ----------------------------------------------------------------

	var mode        = 'direct';   // 'direct' | 'trackable' (URL type only)
	var saved       = false;      // the current content has been saved
	var savedUrl    = '';         // its short /qr/{slug} link (URL type)
	var savedFor    = '';         // the encoded string it was saved for
	var logoDataUrl = null;       // uploaded-file logo (base64)
	var logoId      = 0;          // media-library logo attachment ID
	var logoUrl     = '';         // media-library logo URL

	var PREVIEW_SIZE = 320;

	// Host shown in the "tracked via …" badge (e.g. stokemctoke.com).
	var trackHost = ( function () {
		try { return new URL( cfg.qrBase ).host; } catch ( e ) { return 'this site'; }
	} )();

	function currentType() {
		return els.payloadType.value;
	}

	function isUrlType() {
		return currentType() === 'url';
	}

	function activePanel() {
		var type = currentType();
		for ( var i = 0; i < panels.length; i++ ) {
			if ( panels[ i ].getAttribute( 'data-type' ) === type ) {
				return panels[ i ];
			}
		}
		return null;
	}

	// Structured fields from the active panel's data-field inputs.
	function payloadFields() {
		var panel = activePanel();
		var out = {};
		if ( ! panel ) { return out; }
		var inputs = panel.querySelectorAll( '[data-field]' );
		for ( var i = 0; i < inputs.length; i++ ) {
			var input = inputs[ i ];
			out[ input.getAttribute( 'data-field' ) ] =
				input.type === 'checkbox' ? input.checked : input.value;
		}
		return out;
	}

	function utmValues() {
		var out = {};
		var inputs = document.querySelectorAll( '[data-utm]' );
		for ( var i = 0; i < inputs.length; i++ ) {
			out[ inputs[ i ].getAttribute( 'data-utm' ) ] = inputs[ i ].value.trim();
		}
		return out;
	}

	// The string this content encodes when Direct (before any short link).
	function rawValue() {
		if ( isUrlType() ) {
			return GallusQRPayloads.applyUtm( els.data.value, utmValues() );
		}
		return GallusQRPayloads.build( currentType(), payloadFields() );
	}

	// What the QR currently encodes, given the type/mode/saved state.
	function encodedValue() {
		if ( isUrlType() && mode === 'trackable' && saved ) {
			return savedUrl;
		}
		return rawValue();
	}

	// True when a download would NOT be the tracked code the user expects.
	function downloadBlocked() {
		return isUrlType() && mode === 'trackable' && ! saved;
	}

	// --- QR rendering ---------------------------------------------------------

	function exportSize() {
		return GallusQRDesign.clampSize( els.size.value );
	}

	function currentDesign() {
		var frame = null;
		if ( els.frameStyle.value !== 'none' && els.frameText.value.trim() ) {
			frame = {
				style:     els.frameStyle.value,
				text:      els.frameText.value.trim(),
				bandColor: els.frameBand.value,
				textColor: els.frameTextColor.value
			};
		}

		return {
			dotStyle:      els.dotStyle.value,
			cornerStyle:   els.cornerStyle.value,
			cornerDot:     els.cornerDot.value,
			fg:            els.fg.value,
			fg2:           els.gradient.value !== 'none' ? els.fg2.value : '',
			gradient:      els.gradient.value,
			bg:            els.bg.value,
			bgTransparent: els.bgTransparent.checked,
			size:          exportSize(),
			logo:          logoDataUrl || '',
			logoId:        logoId,
			logoUrl:       logoUrl,
			frame:         frame
		};
	}

	// The shared renderer draws the preview, so it's pixel-identical to stats
	// re-downloads and the front-end block (frames included).
	function render() {
		GallusQRDesign.renderInto( els.canvas, currentDesign(), encodedValue(), PREVIEW_SIZE );
	}

	// --- UI sync --------------------------------------------------------------

	// Shorten long payload strings for the "Encodes →" readout.
	function readout( value ) {
		if ( ! value ) { return '—'; }
		return value.length > 120 ? value.slice( 0, 117 ) + '…' : value;
	}

	// Reflect the whole state into badge / readout / buttons, then re-render.
	function updateUI() {
		var isUrl   = isUrlType();
		var isTrack = isUrl && mode === 'trackable';

		// Mode selector only applies to URL codes.
		els.modeWrap.hidden = ! isUrl;
		els.modeDirect.classList.toggle( 'is-active', ! isTrack );
		els.modeTrack.classList.toggle( 'is-active', isTrack );
		els.modeDirect.setAttribute( 'aria-checked', String( ! isTrack ) );
		els.modeTrack.setAttribute( 'aria-checked', String( isTrack ) );

		els.modeHelp.textContent = isTrack
			? t( 'modeHelpTrack', 'Routes through your site so every scan is counted. Save it to generate the tracked link.' )
			: t( 'modeHelpDirect', 'Encodes your URL directly. Works forever, but scans can’t be counted — best for permanent things like PCBs.' );

		// Save section: trackable URL codes must be saved; other types can be
		// saved to the library for faithful re-downloads later.
		els.trackFields.hidden = ! ( isTrack || ! isUrl );
		els.save.textContent = isTrack ? t( 'saveTrack', 'Save & make trackable' ) : t( 'saveLibrary', 'Save to library' );

		// Custom slugs + lifecycle options only matter when a short /qr/ link
		// will be printed (they act on the redirect, not the image).
		els.slugField.hidden = ! isTrack;
		els.advanced.hidden  = ! isTrack;

		// Badge.
		els.badge.classList.remove( 'gqr-badge--direct', 'gqr-badge--tracked', 'gqr-badge--pending' );
		if ( ! isUrl ) {
			els.badge.textContent = saved
				? t( 'badgeLibrary', '● Saved to library — not tracked' )
				: t( 'badgeDirect', '○ Direct — not tracked' );
			els.badge.classList.add( 'gqr-badge--direct' );
		} else if ( ! isTrack ) {
			els.badge.textContent = t( 'badgeDirect', '○ Direct — not tracked' );
			els.badge.classList.add( 'gqr-badge--direct' );
		} else if ( saved ) {
			els.badge.textContent = t( 'badgeTracked', '● Tracked · via' ) + ' ' + trackHost;
			els.badge.classList.add( 'gqr-badge--tracked' );
		} else {
			els.badge.textContent = t( 'badgePending', '○ Trackable — not saved yet' );
			els.badge.classList.add( 'gqr-badge--pending' );
		}

		// Encodes readout.
		if ( isTrack && saved ) {
			els.encodes.textContent = t( 'encodes', 'Encodes →' ) + ' ' + savedUrl + '\n↳ ' + readout( rawValue() );
		} else if ( isTrack ) {
			els.encodes.textContent = t( 'savePrompt', 'Save to generate your tracked link, then download.' );
		} else {
			els.encodes.textContent = t( 'encodes', 'Encodes →' ) + ' ' + readout( rawValue() );
		}

		// Downloads.
		var block = downloadBlocked();
		els.dlPng.disabled = block;
		els.dlJpeg.disabled = block;
		els.dlSvg.disabled = block;
		els.downloadHint.hidden = ! block;
		if ( block ) {
			els.downloadHint.textContent = t( 'downloadHint', 'Save first — otherwise you’d download an untracked code.' );
		}

		render();
	}

	function setMode( m ) {
		mode = m;
		updateUI();
	}

	function setType() {
		var type = currentType();
		for ( var i = 0; i < panels.length; i++ ) {
			panels[ i ].hidden = panels[ i ].getAttribute( 'data-type' ) !== type;
		}
		// Content changed wholesale — any previous save no longer applies.
		saved = false;
		savedUrl = '';
		clearSaveError();
		updateUI();
	}

	function clearSaveError() {
		els.saveResult.hidden = true;
		els.saveResult.textContent = '';
		els.saveResult.classList.remove( 'is-error' );
	}

	function showSaveMessage( msg, isError ) {
		els.saveResult.hidden = false;
		els.saveResult.classList.toggle( 'is-error', !! isError );
		els.saveResult.textContent = msg;
	}

	// --- Content + design listeners ---------------------------------------------

	// Any edit inside the controls column re-renders; editing the encoded
	// content also invalidates a previously-saved code.
	function onContentEdit() {
		if ( saved && rawValue() !== savedFor ) {
			saved = false;
			savedUrl = '';
		}
		clearSaveError();
		updateUI();
	}

	els.controls.addEventListener( 'input', function ( e ) {
		var t = e.target;
		if ( t.closest( '.gqr-payload-panel' ) || t.hasAttribute( 'data-utm' ) ) {
			onContentEdit();
		}
	} );
	els.controls.addEventListener( 'change', function ( e ) {
		var t = e.target;
		if ( t.closest( '.gqr-payload-panel' ) && ( t.tagName === 'SELECT' || t.type === 'checkbox' ) ) {
			onContentEdit();
		}
	} );

	els.payloadType.addEventListener( 'change', setType );

	// Show/hide the dependent design controls, then redraw.
	function syncDesignControls() {
		els.fg2Field.hidden    = els.gradient.value === 'none';
		els.frameColors.hidden = els.frameStyle.value === 'none';
	}

	function designChanged() {
		syncDesignControls();
		render();
	}

	els.dotStyle.addEventListener( 'change', render );
	els.cornerStyle.addEventListener( 'change', render );
	els.cornerDot.addEventListener( 'change', render );
	els.fg.addEventListener( 'input', render );
	els.fg2.addEventListener( 'input', render );
	els.gradient.addEventListener( 'change', designChanged );
	els.bg.addEventListener( 'input', render );
	els.bgTransparent.addEventListener( 'change', render );
	els.frameStyle.addEventListener( 'change', designChanged );
	els.frameText.addEventListener( 'input', render );
	els.frameBand.addEventListener( 'input', render );
	els.frameTextColor.addEventListener( 'input', render );

	els.invert.addEventListener( 'click', function () {
		var fg = els.fg.value;
		els.fg.value = els.bg.value;
		els.bg.value = fg;
		render();
	} );

	// --- Logo: media library or file upload ---------------------------------------

	function setLogoStatus() {
		if ( logoUrl ) {
			els.logoStatus.textContent = t( 'logoFromMedia', 'Logo: media library' );
		} else if ( logoDataUrl ) {
			els.logoStatus.textContent = t( 'logoFromUpload', 'Logo: uploaded file' );
		} else {
			els.logoStatus.textContent = '';
		}
	}

	var mediaFrame = null;

	els.logoMedia.addEventListener( 'click', function () {
		if ( typeof wp === 'undefined' || ! wp.media ) { return; }

		if ( ! mediaFrame ) {
			mediaFrame = wp.media( {
				title: t( 'logoMediaTitle', 'Choose a centre logo' ),
				button: { text: t( 'logoMediaButton', 'Use as logo' ) },
				library: { type: 'image' },
				multiple: false
			} );
			mediaFrame.on( 'select', function () {
				var attachment = mediaFrame.state().get( 'selection' ).first().toJSON();
				logoId      = attachment.id;
				logoUrl     = attachment.url;
				logoDataUrl = null;
				els.logo.value = '';
				setLogoStatus();
				render();
			} );
		}

		mediaFrame.open();
	} );

	els.logo.addEventListener( 'change', function ( e ) {
		var file = e.target.files && e.target.files[ 0 ];
		if ( ! file ) { return; }
		var reader = new FileReader();
		reader.onload = function ( ev ) {
			logoDataUrl = ev.target.result;
			logoId      = 0;
			logoUrl     = '';
			setLogoStatus();
			render();
		};
		reader.readAsDataURL( file );
	} );

	els.logoClear.addEventListener( 'click', function () {
		logoDataUrl = null;
		logoId      = 0;
		logoUrl     = '';
		els.logo.value = '';
		setLogoStatus();
		render();
	} );

	els.size.addEventListener( 'input', function () {
		els.sizeValue.textContent = exportSize();
	} );

	// --- Design presets ------------------------------------------------------------

	var presetDesigns = {}; // id => design object

	function setPresetStatus( msg ) {
		els.presetStatus.textContent = msg || '';
	}

	function loadPresets() {
		if ( ! cfg.presetsUrl ) { return; }
		fetch( cfg.presetsUrl, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce }
		} )
			.then( function ( res ) { return res.ok ? res.json() : []; } )
			.then( function ( presets ) {
				presetDesigns = {};
				// Rebuild the select, keeping the placeholder option.
				els.presetSelect.length = 1;
				( presets || [] ).forEach( function ( preset ) {
					presetDesigns[ preset.id ] = preset.design || {};
					var option = document.createElement( 'option' );
					option.value = String( preset.id );
					option.textContent = preset.name;
					els.presetSelect.appendChild( option );
				} );
			} )
			.catch( function () { /* presets are cosmetic — fail quietly */ } );
	}

	// Push a stored design into every design control.
	function applyDesign( raw ) {
		var d = GallusQRDesign.normalize( raw );

		els.dotStyle.value    = d.dotStyle;
		els.cornerStyle.value = d.cornerStyle;
		els.cornerDot.value   = d.cornerDot;
		els.fg.value          = d.fg;
		els.gradient.value    = d.gradient;
		if ( d.fg2 ) { els.fg2.value = d.fg2; }
		els.bg.value               = d.bg;
		els.bgTransparent.checked  = !! d.bgTransparent;
		els.size.value             = d.size;
		els.sizeValue.textContent  = d.size;

		if ( d.frame ) {
			els.frameStyle.value     = d.frame.style;
			els.frameText.value      = d.frame.text;
			els.frameBand.value      = d.frame.bandColor;
			els.frameTextColor.value = d.frame.textColor;
		} else {
			els.frameStyle.value = 'none';
		}

		logoDataUrl = d.logo || null;
		logoId      = d.logoId || 0;
		logoUrl     = d.logoUrl || '';
		els.logo.value = '';

		setLogoStatus();
		syncDesignControls();
		render();
	}

	els.presetSelect.addEventListener( 'change', function () {
		var id = els.presetSelect.value;
		els.presetDelete.hidden = ! id;
		if ( id && presetDesigns[ id ] ) {
			applyDesign( presetDesigns[ id ] );
			setPresetStatus( '' );
		}
	} );

	els.presetSave.addEventListener( 'click', function () {
		var name = els.presetName.value.trim();
		if ( ! name ) { return setPresetStatus( t( 'presetNeedsName', 'Give the preset a name first.' ) ); }
		if ( ! cfg.presetsUrl ) { return; }

		els.presetSave.disabled = true;
		fetch( cfg.presetsUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify( { name: name, design: currentDesign() } )
		} )
			.then( function ( res ) {
				if ( ! res.ok ) { throw new Error( t( 'presetSaveFailed', 'Could not save the preset.' ) ); }
				els.presetName.value = '';
				setPresetStatus( t( 'presetSaved', 'Preset saved.' ) );
				loadPresets();
			} )
			.catch( function ( err ) { setPresetStatus( err.message ); } )
			.finally( function () { els.presetSave.disabled = false; } );
	} );

	els.presetDelete.addEventListener( 'click', function () {
		var id = els.presetSelect.value;
		if ( ! id || ! cfg.presetsUrl ) { return; }

		fetch( cfg.presetsUrl + '/' + id, {
			method: 'DELETE',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce }
		} )
			.then( function () {
				els.presetSelect.value = '';
				els.presetDelete.hidden = true;
				setPresetStatus( t( 'presetDeleted', 'Preset deleted.' ) );
				loadPresets();
			} );
	} );

	// --- Custom slug (trackable URL codes) --------------------------------------

	var slugTimer = null;

	function slugValue() {
		return els.slug.value.trim().toLowerCase();
	}

	function setSlugStatus( msg, ok ) {
		els.slugStatus.textContent = msg;
		els.slugStatus.style.color = msg === '' ? '' : ( ok ? '#007a1f' : '#b32d2e' );
	}

	els.slug.addEventListener( 'input', function () {
		// Normalise as they type: lowercase, [a-z0-9-] only.
		var cleaned = els.slug.value.toLowerCase().replace( /[^a-z0-9-]/g, '' );
		if ( cleaned !== els.slug.value ) {
			els.slug.value = cleaned;
		}

		// A different slug means any saved short link no longer applies.
		if ( saved ) {
			saved = false;
			savedUrl = '';
			updateUI();
		}

		clearTimeout( slugTimer );
		if ( ! cleaned || ! cfg.slugCheckUrl ) {
			setSlugStatus( '', true );
			return;
		}

		slugTimer = setTimeout( function () {
			fetch( cfg.slugCheckUrl + '?slug=' + encodeURIComponent( cleaned ), {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': cfg.nonce }
			} )
				.then( function ( res ) { return res.json(); } )
				.then( function ( body ) {
					// Ignore stale responses if the field changed meanwhile.
					if ( body && body.slug === slugValue() ) {
						setSlugStatus( body.message || '', !! body.available );
					}
				} )
				.catch( function () { setSlugStatus( '', true ); } );
		}, 400 );
	} );

	// --- Advanced options (lifecycle + destination mode, trackable only) ---------

	function syncDestMode() {
		var m = els.destMode.value;
		els.destExtra.hidden   = m === 'single';
		els.switchField.hidden = m !== 'schedule';
		els.splitField.hidden  = m !== 'ab';
		els.destBLabel.textContent = m === 'schedule'
			? t( 'destBSchedule', 'Destination after the switch' )
			: t( 'destBAb', 'Second destination (B)' );
	}

	els.destMode.addEventListener( 'change', syncDestMode );
	els.abSplit.addEventListener( 'input', function () {
		els.splitValue.textContent = els.abSplit.value;
	} );
	syncDestMode();

	// Collect the advanced fields for the save request. Only non-defaults are
	// sent, so a plain save stays a plain save.
	function advancedValues() {
		var out = {};
		if ( els.expires.value ) { out.expires_at = els.expires.value; }
		var cap = parseInt( els.maxScans.value, 10 );
		if ( cap > 0 ) { out.max_scans = cap; }
		if ( els.fallback.value.trim() ) { out.fallback_url = els.fallback.value.trim(); }

		var m = els.destMode.value;
		if ( m !== 'single' && els.destB.value.trim() ) {
			out.dest_mode     = m;
			out.destination_b = els.destB.value.trim();
			if ( m === 'schedule' && els.switchAt.value ) {
				out.switch_at = els.switchAt.value;
			}
			if ( m === 'ab' ) {
				out.ab_split = parseInt( els.abSplit.value, 10 ) || 50;
			}
		}
		return out;
	}

	// --- Mode switch ----------------------------------------------------------

	els.modeDirect.addEventListener( 'click', function () { setMode( 'direct' ); } );
	els.modeTrack.addEventListener( 'click', function () { setMode( 'trackable' ); } );

	// --- Save (trackable URL, or library entry for other types) -----------------

	els.save.addEventListener( 'click', function () {
		var isUrl = isUrlType();

		if ( isUrl && ! els.data.value ) { return showSaveMessage( t( 'enterUrl', 'Enter a URL first.' ), true ); }
		if ( ! isUrl && ! rawValue() ) { return showSaveMessage( t( 'fillFields', 'Fill in the required fields first.' ), true ); }
		if ( ! cfg.restUrl ) { return showSaveMessage( t( 'restMissing', 'Saving is unavailable (REST config missing).' ), true ); }

		var body = {
			title:        els.title.value,
			payload_type: currentType(),
			design:       currentDesign()
		};
		if ( isUrl ) {
			body.destination = els.data.value;
			body.payload     = { utm: utmValues() };
			body.trackable   = true;
			if ( slugValue() ) {
				body.slug = slugValue();
			}
			var advanced = advancedValues();
			for ( var key in advanced ) {
				body[ key ] = advanced[ key ];
			}
		} else {
			body.payload   = payloadFields();
			body.trackable = false;
		}

		var encodedAtSave = rawValue();

		els.save.disabled = true;
		els.save.textContent = t( 'saving', 'Saving…' );

		fetch( cfg.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify( body )
		} )
			.then( function ( res ) {
				return res.json().then( function ( body ) { return { ok: res.ok, body: body }; } );
			} )
			.then( function ( result ) {
				if ( ! result.ok ) {
					throw new Error( ( result.body && result.body.message ) || t( 'saveFailed', 'Save failed.' ) );
				}
				saved = true;
				savedUrl = result.body.url;
				savedFor = encodedAtSave;
				clearSaveError();
				if ( ! isUrl ) {
					showSaveMessage( t( 'savedLibrary', 'Saved — find it under Scan Stats for re-downloads.' ), false );
				}
				updateUI();
			} )
			.catch( function ( err ) { showSaveMessage( err.message, true ); } )
			.finally( function () {
				els.save.disabled = false;
				els.save.textContent = isUrl ? t( 'saveTrack', 'Save & make trackable' ) : t( 'saveLibrary', 'Save to library' );
			} );
	} );

	// --- Downloads ------------------------------------------------------------

	function downloadAs( ext ) {
		if ( downloadBlocked() ) { return; }
		GallusQRDesign.download( currentDesign(), encodedValue(), ext, 'gallus-qr' );
	}

	els.dlPng.addEventListener( 'click', function () { downloadAs( 'png' ); } );
	els.dlJpeg.addEventListener( 'click', function () { downloadAs( 'jpeg' ); } );
	els.dlSvg.addEventListener( 'click', function () { downloadAs( 'svg' ); } );

	// --- Init -----------------------------------------------------------------

	// "QR for this page" deep links land here with ?url= and ?title= —
	// pre-fill the URL panel and jump straight into Trackable mode.
	( function () {
		try {
			var params  = new URLSearchParams( window.location.search );
			var prefill = params.get( 'url' );
			if ( prefill && /^https?:\/\//.test( prefill ) ) {
				els.data.value  = prefill;
				els.title.value = params.get( 'title' ) || '';
				mode = 'trackable';
			}
		} catch ( e ) { /* very old browsers: just skip the prefill */ }
	} )();

	syncDesignControls();
	loadPresets();
	updateUI();
} )();
