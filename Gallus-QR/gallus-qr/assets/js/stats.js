/**
 * Gallus QR — Scan Stats page behaviour.
 *
 * Re-downloads: each row's PNG/SVG buttons carry the string the code encodes
 * (short /qr/ link for tracked codes, raw payload for library codes); on click
 * we render it through the shared designer with the code's *stored* design so
 * a re-download matches the original.
 *
 * Edits: rename / retarget / pause / delete all go through the REST API
 * (PATCH/DELETE /gallus-qr/v1/codes/{id}). Status changes and deletes reload
 * the page so badges, counters and charts stay truthful.
 */
( function () {
	'use strict';

	if ( typeof QRCodeStyling === 'undefined' || typeof GallusQRDesign === 'undefined' ) {
		return;
	}

	var store   = window.GallusQRStats || {};
	var designs = store.designs || {};

	var t = function ( key, fallback ) {
		return ( store.i18n && store.i18n[ key ] ) || fallback;
	};

	// --- Notices ----------------------------------------------------------------

	function notice( message, isError ) {
		var box = document.getElementById( 'gqr-stats-notice' );
		if ( ! box ) { return; }
		box.innerHTML = '';
		var div = document.createElement( 'div' );
		div.className = 'notice ' + ( isError ? 'notice-error' : 'notice-success' );
		var p = document.createElement( 'p' );
		p.textContent = message;
		div.appendChild( p );
		box.appendChild( div );
	}

	// --- REST helpers -------------------------------------------------------------

	function request( id, method, body ) {
		return fetch( store.restBase + '/' + id, {
			method: method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': store.nonce },
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				if ( ! res.ok ) {
					throw new Error( ( data && data.message ) || t( 'requestFailed', 'Request failed.' ) );
				}
				return data;
			} );
		} );
	}

	function rowId( el ) {
		var row = el.closest( 'tr[data-id]' );
		return row ? row.getAttribute( 'data-id' ) : null;
	}

	// --- Click handling -------------------------------------------------------------

	document.addEventListener( 'click', function ( e ) {
		var btn;

		// Re-download with the stored design.
		btn = e.target.closest( '.gqr-dl' );
		if ( btn ) {
			var slug = btn.getAttribute( 'data-slug' ) || 'code';
			var ext  = btn.getAttribute( 'data-ext' ) === 'svg' ? 'svg' : 'png';
			GallusQRDesign.download( designs[ slug ], btn.getAttribute( 'data-url' ), ext, 'gallus-qr-' + slug );
			return;
		}

		// Rename.
		btn = e.target.closest( '.gqr-save-title' );
		if ( btn ) {
			var id    = rowId( btn );
			var input = btn.parentNode.querySelector( '.gqr-field-title' );
			if ( ! id || ! input ) { return; }
			request( id, 'PATCH', { title: input.value } )
				.then( function () { notice( t( 'renamed', 'Code renamed.' ), false ); } )
				.catch( function ( err ) { notice( err.message, true ); } );
			return;
		}

		// Retarget (URL codes only — the button doesn't render otherwise).
		btn = e.target.closest( '.gqr-save-dest' );
		if ( btn ) {
			var destId = rowId( btn );
			var dest   = btn.parentNode.querySelector( '.gqr-field-dest' );
			if ( ! destId || ! dest ) { return; }
			request( destId, 'PATCH', { destination: dest.value } )
				.then( function () {
					notice( t( 'retargeted', 'Destination updated — the printed code now points to the new URL.' ), false );
				} )
				.catch( function ( err ) { notice( err.message, true ); } );
			return;
		}

		// Pause / resume.
		btn = e.target.closest( '.gqr-toggle-status' );
		if ( btn ) {
			var statusId = rowId( btn );
			if ( ! statusId ) { return; }
			btn.disabled = true;
			request( statusId, 'PATCH', { status: btn.getAttribute( 'data-next' ) } )
				.then( function () { window.location.reload(); } )
				.catch( function ( err ) {
					btn.disabled = false;
					notice( err.message, true );
				} );
			return;
		}

		// Delete.
		btn = e.target.closest( '.gqr-delete-code' );
		if ( btn ) {
			var deleteId = rowId( btn );
			if ( ! deleteId ) { return; }
			if ( ! window.confirm( t( 'deleteConfirm', 'Delete this code and all its scan data? This cannot be undone.' ) ) ) {
				return;
			}
			btn.disabled = true;
			request( deleteId, 'DELETE' )
				.then( function () { window.location.reload(); } )
				.catch( function ( err ) {
					btn.disabled = false;
					notice( err.message, true );
				} );
		}
	} );
} )();
