/**
 * Gallus QR — payload builders (JS mirror of includes/class-payloads.php).
 *
 * Builds the exact string a QR encodes from structured fields, for the live
 * preview. The server rebuilds the string itself when a code is saved, so
 * these only have to match what PHP produces — if you change an escape rule
 * here, change it there too.
 */
( function () {
	'use strict';

	// Backslash-escape \ ; , : " for WIFI: strings.
	function escapeWifi( value ) {
		return String( value ).replace( /([\\;,:"])/g, '\\$1' );
	}

	// vCard/iCal text escaping: backslash, semicolon, comma, newline.
	function escapeVText( value ) {
		return String( value )
			.replace( /\\/g, '\\\\' )
			.replace( /;/g, '\\;' )
			.replace( /,/g, '\\,' )
			.replace( /\r?\n/g, '\\n' );
	}

	function digitsAndPlus( value ) {
		return String( value ).replace( /[^0-9+]/g, '' );
	}

	// datetime-local value (local tz) → iCal UTC basic format.
	function icalDatetime( value ) {
		if ( ! value ) { return ''; }
		var d = new Date( value );
		if ( isNaN( d.getTime() ) ) { return ''; }
		return d.toISOString().replace( /[-:]/g, '' ).replace( /\.\d{3}/, '' );
	}

	var builders = {
		text: function ( f ) {
			return f.text || '';
		},

		wifi: function ( f ) {
			var enc = f.encryption === 'WEP' || f.encryption === 'nopass' ? f.encryption : 'WPA';
			if ( ! f.ssid ) { return ''; }
			var out = 'WIFI:T:' + enc + ';S:' + escapeWifi( f.ssid ) + ';';
			if ( enc !== 'nopass' ) {
				if ( ! f.password ) { return ''; }
				out += 'P:' + escapeWifi( f.password ) + ';';
			}
			if ( f.hidden ) { out += 'H:true;'; }
			return out + ';';
		},

		vcard: function ( f ) {
			var first = ( f.first || '' ).trim();
			var last  = ( f.last || '' ).trim();
			if ( ! first && ! last ) { return ''; }

			var lines = [ 'BEGIN:VCARD', 'VERSION:3.0' ];
			lines.push( 'N:' + escapeVText( last ) + ';' + escapeVText( first ) + ';;;' );
			lines.push( 'FN:' + escapeVText( ( first + ' ' + last ).trim() ) );
			if ( f.org ) { lines.push( 'ORG:' + escapeVText( f.org ) ); }
			if ( f.job ) { lines.push( 'TITLE:' + escapeVText( f.job ) ); }
			var phone = digitsAndPlus( f.phone || '' );
			if ( phone ) { lines.push( 'TEL;TYPE=CELL:' + phone ); }
			if ( f.email ) { lines.push( 'EMAIL:' + f.email.trim() ); }
			if ( f.url ) { lines.push( 'URL:' + f.url.trim() ); }
			if ( f.street || f.city || f.zip || f.country ) {
				lines.push(
					'ADR;TYPE=WORK:;;' + escapeVText( f.street || '' ) + ';' + escapeVText( f.city || '' )
					+ ';;' + escapeVText( f.zip || '' ) + ';' + escapeVText( f.country || '' )
				);
			}
			lines.push( 'END:VCARD' );
			return lines.join( '\r\n' );
		},

		email: function ( f ) {
			if ( ! f.to ) { return ''; }
			var out = 'mailto:' + f.to.trim();
			var query = [];
			if ( f.subject ) { query.push( 'subject=' + encodeURIComponent( f.subject ) ); }
			if ( f.body ) { query.push( 'body=' + encodeURIComponent( f.body ) ); }
			return query.length ? out + '?' + query.join( '&' ) : out;
		},

		sms: function ( f ) {
			var number = digitsAndPlus( f.number || '' );
			if ( ! number ) { return ''; }
			return 'SMSTO:' + number + ':' + ( f.message || '' );
		},

		tel: function ( f ) {
			var number = digitsAndPlus( f.number || '' );
			return number ? 'tel:' + number : '';
		},

		event: function ( f ) {
			var start = icalDatetime( f.start );
			if ( ! f.summary || ! start ) { return ''; }
			var lines = [ 'BEGIN:VCALENDAR', 'VERSION:2.0', 'BEGIN:VEVENT' ];
			lines.push( 'SUMMARY:' + escapeVText( f.summary ) );
			lines.push( 'DTSTART:' + start );
			var end = icalDatetime( f.end );
			if ( end ) { lines.push( 'DTEND:' + end ); }
			if ( f.location ) { lines.push( 'LOCATION:' + escapeVText( f.location ) ); }
			if ( f.description ) { lines.push( 'DESCRIPTION:' + escapeVText( f.description ) ); }
			lines.push( 'END:VEVENT' );
			lines.push( 'END:VCALENDAR' );
			return lines.join( '\r\n' );
		}
	};

	window.GallusQRPayloads = {
		/**
		 * Build the encoded string for a type from its fields. Returns '' when
		 * required fields are missing (the preview shows a placeholder).
		 */
		build: function ( type, fields ) {
			return builders[ type ] ? builders[ type ]( fields || {} ) : '';
		},

		/** Append non-empty UTM params to a URL. */
		applyUtm: function ( url, utm ) {
			utm = utm || {};
			var pairs = [];
			if ( utm.source ) { pairs.push( 'utm_source=' + encodeURIComponent( utm.source ) ); }
			if ( utm.medium ) { pairs.push( 'utm_medium=' + encodeURIComponent( utm.medium ) ); }
			if ( utm.campaign ) { pairs.push( 'utm_campaign=' + encodeURIComponent( utm.campaign ) ); }
			if ( ! pairs.length || ! url ) { return url; }
			return url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + pairs.join( '&' );
		},

		isTrackableType: function ( type ) {
			return type === 'url';
		}
	};
} )();
