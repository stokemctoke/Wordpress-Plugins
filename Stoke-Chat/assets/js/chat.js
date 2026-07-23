/**
 * Stoke Chat frontend. Vanilla JS, no build step.
 * All user-supplied strings are rendered via textContent — never innerHTML.
 */
( function () {
	'use strict';

	var cfg  = window.StokeChatCfg;
	var root = document.getElementById( 'stokechat-app' );
	if ( ! cfg || ! root ) {
		return;
	}

	var MENTION_RE = /(^|\s)(@[a-zA-Z0-9_.\-]{2,60})/g;
	var IDLE_MS    = 5 * 60 * 1000;
	var SMILEY_RE  = buildSmileyRe();

	var state = {
		rooms: [],
		activeRoomId: 0,
		lastMessageId: 0,
		myRole: null,
		pollTimer: 0,
		tick: 0,
		lastActivity: Date.now(),
		membersOpen: false,
		smileyPickerOpen: false
	};

	var ui = {}; // Populated by buildLayout().

	/* ------------------------------------------------------------------ */
	/* Helpers                                                            */
	/* ------------------------------------------------------------------ */

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined && text !== null ) {
			node.textContent = text;
		}
		return node;
	}

	/**
	 * Longest-first regex of smiley shortcodes so :-)/:smile: beat shorter overlaps.
	 */
	function buildSmileyRe() {
		var map = cfg.smileyMap || {};
		var codes = Object.keys( map ).sort( function ( a, b ) { return b.length - a.length; } );
		if ( ! codes.length ) {
			return null;
		}
		var escaped = codes.map( function ( c ) {
			return c.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
		} );
		return new RegExp( '(' + escaped.join( '|' ) + ')', 'g' );
	}

	async function api( path, opts ) {
		opts = opts || {};
		var headers = { 'X-WP-Nonce': cfg.nonce };
		if ( opts.body ) {
			headers['Content-Type'] = 'application/json';
		}
		var res = await fetch( cfg.restUrl + path, {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: headers,
			body: opts.body ? JSON.stringify( opts.body ) : undefined
		} );
		var data = null;
		try {
			data = await res.json();
		} catch ( e ) { /* Non-JSON response. */ }
		if ( ! res.ok ) {
			var err = new Error( ( data && data.message ) || 'Request failed (' + res.status + ')' );
			err.status = res.status;
			err.code   = data && data.code;
			throw err;
		}
		return data;
	}

	function toast( text ) {
		var t = el( 'div', 'stokechat-toast', text );
		ui.toasts.appendChild( t );
		setTimeout( function () {
			t.classList.add( 'is-out' );
			setTimeout( function () { t.remove(); }, 400 );
		}, 3500 );
	}

	function handleError( err, silent ) {
		if ( err.status === 429 ) {
			toast( 'You are sending too fast — slow down a little.' );
			return;
		}
		if ( err.code === 'rest_cookie_invalid_nonce' ) {
			toast( 'Your session expired. Please reload the page.' );
			return;
		}
		if ( ! silent ) {
			toast( err.message || 'Something went wrong.' );
		}
	}

	function fmtTime( mysqlUtc ) {
		if ( ! mysqlUtc ) {
			return '';
		}
		var d = new Date( mysqlUtc.replace( ' ', 'T' ) + 'Z' );
		if ( isNaN( d.getTime() ) ) {
			return '';
		}
		var today = new Date();
		var sameDay = d.toDateString() === today.toDateString();
		return sameDay
			? d.toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } )
			: d.toLocaleString( [], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' } );
	}

	function activeRoom() {
		return state.rooms.find( function ( r ) { return r.room_id === state.activeRoomId; } ) || null;
	}

	function canModerate() {
		return state.myRole === 'creator' || state.myRole === 'moderator' || cfg.isAdmin;
	}

	/**
	 * Two-step inline confirmation: first click arms the button, second executes.
	 */
	function confirmButton( button, action ) {
		button.addEventListener( 'click', function () {
			if ( button.dataset.armed ) {
				delete button.dataset.armed;
				button.textContent = button.dataset.label;
				action();
			} else {
				button.dataset.label = button.textContent;
				button.dataset.armed = '1';
				button.textContent = 'Confirm?';
				setTimeout( function () {
					if ( button.dataset.armed ) {
						delete button.dataset.armed;
						button.textContent = button.dataset.label;
					}
				}, 4000 );
			}
		} );
	}

	/**
	 * Message text -> DOM nodes with @mention spans and smileys (all via textContent / safe img).
	 */
	function contentNodes( text ) {
		var frag = document.createDocumentFragment();
		var last = 0;
		var match;
		MENTION_RE.lastIndex = 0;
		while ( ( match = MENTION_RE.exec( text ) ) !== null ) {
			var start = match.index + match[1].length;
			if ( start > last ) {
				appendWithSmileys( frag, text.slice( last, start ) );
			}
			var isMe = match[2].slice( 1 ).toLowerCase() === String( cfg.me.username ).toLowerCase();
			frag.appendChild( el( 'span', 'stokechat-mention' + ( isMe ? ' is-me' : '' ), match[2] ) );
			last = start + match[2].length;
		}
		if ( last < text.length ) {
			appendWithSmileys( frag, text.slice( last ) );
		}
		return frag;
	}

	function appendWithSmileys( frag, text ) {
		var map = cfg.smileyMap || {};
		if ( ! SMILEY_RE || ! text ) {
			if ( text ) {
				frag.appendChild( document.createTextNode( text ) );
			}
			return;
		}
		var last = 0;
		var match;
		SMILEY_RE.lastIndex = 0;
		while ( ( match = SMILEY_RE.exec( text ) ) !== null ) {
			if ( match.index > last ) {
				frag.appendChild( document.createTextNode( text.slice( last, match.index ) ) );
			}
			var info = map[ match[0] ];
			if ( info && info.type === 'image' && info.value ) {
				var img = el( 'img', 'stokechat-smiley' );
				img.src = info.value;
				img.alt = match[0];
				img.title = match[0];
				img.loading = 'lazy';
				frag.appendChild( img );
			} else if ( info && info.value ) {
				frag.appendChild( el( 'span', 'stokechat-smiley-emoji', info.value ) );
			} else {
				frag.appendChild( document.createTextNode( match[0] ) );
			}
			last = match.index + match[0].length;
		}
		if ( last < text.length ) {
			frag.appendChild( document.createTextNode( text.slice( last ) ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Layout                                                             */
	/* ------------------------------------------------------------------ */

	function buildLayout() {
		root.textContent = '';
		root.removeAttribute( 'data-loading' );

		var sidebar = el( 'aside', 'stokechat-sidebar' );
		var sideHead = el( 'div', 'stokechat-sidebar-head' );
		sideHead.appendChild( el( 'h2', 'stokechat-title', 'Rooms' ) );

		if ( cfg.canCreateRooms ) {
			var newBtn = el( 'button', 'stokechat-btn stokechat-new-room', '+ New' );
			newBtn.type = 'button';
			newBtn.addEventListener( 'click', toggleCreateForm );
			sideHead.appendChild( newBtn );
		}
		sidebar.appendChild( sideHead );

		ui.createForm = buildCreateForm();
		sidebar.appendChild( ui.createForm );

		ui.roomList = el( 'ul', 'stokechat-room-list' );
		sidebar.appendChild( ui.roomList );

		var main = el( 'section', 'stokechat-main' );

		ui.roomHead  = el( 'header', 'stokechat-room-head' );
		ui.roomTitle = el( 'h2', 'stokechat-room-title', 'Select a room' );
		ui.roomMeta  = el( 'span', 'stokechat-room-meta', '' );
		ui.roomActions = el( 'div', 'stokechat-room-actions' );
		ui.roomHead.appendChild( ui.roomTitle );
		ui.roomHead.appendChild( ui.roomMeta );
		ui.roomHead.appendChild( ui.roomActions );

		ui.messages = el( 'div', 'stokechat-messages' );
		ui.messages.setAttribute( 'role', 'log' );
		ui.messages.setAttribute( 'aria-live', 'polite' );

		ui.joinBar = el( 'div', 'stokechat-join-bar' );
		ui.joinBar.hidden = true;

		ui.membersPanel = el( 'div', 'stokechat-members-panel' );
		ui.membersPanel.hidden = true;

		ui.composer = buildComposer();

		main.appendChild( ui.roomHead );
		main.appendChild( ui.membersPanel );
		main.appendChild( ui.messages );
		main.appendChild( ui.joinBar );
		main.appendChild( ui.composer );

		ui.toasts = el( 'div', 'stokechat-toasts' );

		root.appendChild( sidebar );
		root.appendChild( main );
		root.appendChild( ui.toasts );
	}

	function buildCreateForm() {
		var form = el( 'form', 'stokechat-create-form' );
		form.hidden = true;

		var nameInput = el( 'input', 'stokechat-input' );
		nameInput.type = 'text';
		nameInput.placeholder = 'Room name';
		nameInput.maxLength = 190;
		nameInput.required = true;

		var privLabel = el( 'label', 'stokechat-checkbox' );
		var privInput = el( 'input' );
		privInput.type = 'checkbox';
		privLabel.appendChild( privInput );
		privLabel.appendChild( document.createTextNode( ' Private (invite only)' ) );

		var submit = el( 'button', 'stokechat-btn is-primary', 'Create room' );
		submit.type = 'submit';

		form.appendChild( nameInput );
		form.appendChild( privLabel );
		form.appendChild( submit );

		form.addEventListener( 'submit', async function ( e ) {
			e.preventDefault();
			var name = nameInput.value.trim();
			if ( ! name ) {
				return;
			}
			submit.disabled = true;
			try {
				var room = await api( '/rooms', { method: 'POST', body: { name: name, is_private: privInput.checked } } );
				nameInput.value = '';
				privInput.checked = false;
				form.hidden = true;
				await refreshRooms();
				selectRoom( room.room_id );
			} catch ( err ) {
				handleError( err );
			}
			submit.disabled = false;
		} );

		return form;
	}

	function toggleCreateForm() {
		ui.createForm.hidden = ! ui.createForm.hidden;
		if ( ! ui.createForm.hidden ) {
			ui.createForm.querySelector( 'input[type=text]' ).focus();
		}
	}

	function buildComposer() {
		var form = el( 'form', 'stokechat-composer' );
		form.hidden = true;

		ui.textarea = el( 'textarea', 'stokechat-textarea' );
		ui.textarea.placeholder = 'Write a message… use @username or :smile:';
		ui.textarea.rows = 2;
		ui.textarea.maxLength = cfg.maxLength;

		ui.counter = el( 'span', 'stokechat-counter', '' );

		ui.smileyBtn = el( 'button', 'stokechat-btn stokechat-smiley-toggle', '☺' );
		ui.smileyBtn.type = 'button';
		ui.smileyBtn.title = 'Insert smiley';
		ui.smileyBtn.setAttribute( 'aria-expanded', 'false' );
		ui.smileyBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			toggleSmileyPicker();
		} );

		ui.smileyPicker = buildSmileyPicker();

		ui.sendBtn = el( 'button', 'stokechat-btn is-send', 'Send' );
		ui.sendBtn.type = 'submit';
		ui.sendBtn.disabled = true;

		var row = el( 'div', 'stokechat-composer-row' );
		row.appendChild( ui.smileyBtn );
		row.appendChild( ui.counter );
		row.appendChild( ui.sendBtn );

		var wrap = el( 'div', 'stokechat-composer-tools' );
		wrap.appendChild( ui.smileyPicker );
		wrap.appendChild( row );

		form.appendChild( ui.textarea );
		form.appendChild( wrap );

		ui.textarea.addEventListener( 'input', updateComposer );
		ui.textarea.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' && ! e.shiftKey ) {
				e.preventDefault();
				form.requestSubmit ? form.requestSubmit() : form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
			}
			if ( e.key === 'Escape' && state.smileyPickerOpen ) {
				closeSmileyPicker();
			}
		} );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			closeSmileyPicker();
			var text = ui.textarea.value.trim();
			if ( ! text || text.length > cfg.maxLength ) {
				return;
			}
			ui.textarea.value = '';
			updateComposer();
			sendMessage( text );
			ui.textarea.focus();
		} );

		document.addEventListener( 'click', function ( e ) {
			if ( ! state.smileyPickerOpen ) {
				return;
			}
			if ( ! form.contains( e.target ) ) {
				closeSmileyPicker();
			}
		} );

		return form;
	}

	function buildSmileyPicker() {
		var panel = el( 'div', 'stokechat-smiley-picker' );
		panel.hidden = true;
		panel.setAttribute( 'role', 'listbox' );
		panel.setAttribute( 'aria-label', 'Smileys' );

		var list = cfg.smileys || [];
		if ( ! list.length ) {
			panel.appendChild( el( 'p', 'stokechat-empty', 'No smileys available.' ) );
			return panel;
		}

		list.forEach( function ( s ) {
			var btn = el( 'button', 'stokechat-smiley-option' );
			btn.type = 'button';
			btn.title = s.label || s.code;
			btn.setAttribute( 'role', 'option' );
			if ( s.type === 'image' ) {
				var img = el( 'img', 'stokechat-smiley' );
				img.src = s.value;
				img.alt = s.code;
				btn.appendChild( img );
			} else {
				btn.textContent = s.value;
			}
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				insertSmiley( s );
			} );
			panel.appendChild( btn );
		} );

		return panel;
	}

	function toggleSmileyPicker() {
		if ( state.smileyPickerOpen ) {
			closeSmileyPicker();
		} else {
			state.smileyPickerOpen = true;
			ui.smileyPicker.hidden = false;
			ui.smileyBtn.setAttribute( 'aria-expanded', 'true' );
		}
	}

	function closeSmileyPicker() {
		state.smileyPickerOpen = false;
		if ( ui.smileyPicker ) {
			ui.smileyPicker.hidden = true;
		}
		if ( ui.smileyBtn ) {
			ui.smileyBtn.setAttribute( 'aria-expanded', 'false' );
		}
	}

	function insertSmiley( s ) {
		var insert = ( s.type === 'image' ) ? s.code : s.value;
		var ta = ui.textarea;
		var start = ta.selectionStart || 0;
		var end = ta.selectionEnd || 0;
		var before = ta.value.slice( 0, start );
		var after = ta.value.slice( end );
		var needsSpace = before.length && ! /\s$/.test( before );
		var chunk = ( needsSpace ? ' ' : '' ) + insert;
		if ( ta.value.length + chunk.length > cfg.maxLength ) {
			toast( 'Message is too long for that smiley.' );
			return;
		}
		ta.value = before + chunk + after;
		var caret = before.length + chunk.length;
		ta.focus();
		ta.setSelectionRange( caret, caret );
		updateComposer();
		closeSmileyPicker();
	}

	function updateComposer() {
		var len = ui.textarea.value.length;
		ui.counter.textContent = len > cfg.maxLength * 0.8 ? len + ' / ' + cfg.maxLength : '';
		ui.sendBtn.disabled = ! ui.textarea.value.trim() || len > cfg.maxLength;
	}

	/* ------------------------------------------------------------------ */
	/* Rooms                                                              */
	/* ------------------------------------------------------------------ */

	async function refreshRooms() {
		var data = await api( '/rooms' );
		state.rooms = data.rooms;
		renderRoomList();

		var current = activeRoom();
		if ( current ) {
			state.myRole = current.room_role;
		} else if ( state.activeRoomId ) {
			// Active room disappeared (deleted or kicked).
			state.activeRoomId = 0;
			state.myRole = null;
			renderRoomHead();
			ui.messages.textContent = '';
			ui.composer.hidden = true;
			toast( 'That room is no longer available.' );
		}
	}

	function renderRoomList() {
		ui.roomList.textContent = '';
		if ( ! state.rooms.length ) {
			ui.roomList.appendChild( el( 'li', 'stokechat-empty', 'No rooms yet.' ) );
			return;
		}
		state.rooms.forEach( function ( room ) {
			var li = el( 'li', 'stokechat-room-item' + ( room.room_id === state.activeRoomId ? ' is-active' : '' ) );
			li.dataset.roomId = String( room.room_id );

			var handle = el( 'button', 'stokechat-drag-handle', '⋮⋮' );
			handle.type = 'button';
			handle.title = 'Drag to reorder';
			handle.setAttribute( 'aria-label', 'Drag to reorder ' + room.name );
			handle.draggable = true;

			var btn = el( 'button', 'stokechat-room-btn' );
			btn.type = 'button';

			if ( room.is_private ) {
				btn.appendChild( el( 'span', 'stokechat-lock', '🔒' ) );
			}
			btn.appendChild( el( 'span', 'stokechat-room-name', room.name ) );
			if ( room.unread_count > 0 && room.room_id !== state.activeRoomId ) {
				btn.appendChild( el( 'span', 'stokechat-badge', String( room.unread_count ) ) );
			}
			if ( ! room.is_member ) {
				btn.appendChild( el( 'span', 'stokechat-room-hint', 'not joined' ) );
			}

			btn.addEventListener( 'click', function () { selectRoom( room.room_id ); } );

			li.appendChild( handle );
			li.appendChild( btn );
			bindRoomDrag( li, handle );
			ui.roomList.appendChild( li );
		} );
	}

	var dragRoomId = 0;

	function bindRoomDrag( li, handle ) {
		handle.addEventListener( 'dragstart', function ( e ) {
			dragRoomId = parseInt( li.dataset.roomId, 10 ) || 0;
			li.classList.add( 'is-dragging' );
			if ( e.dataTransfer ) {
				e.dataTransfer.effectAllowed = 'move';
				e.dataTransfer.setData( 'text/plain', String( dragRoomId ) );
				try {
					e.dataTransfer.setDragImage( li, 12, 12 );
				} catch ( err ) { /* Older browsers. */ }
			}
		} );
		handle.addEventListener( 'dragend', function () {
			li.classList.remove( 'is-dragging' );
			clearDropIndicators();
			dragRoomId = 0;
		} );
		// Prevent the handle click from also selecting the room.
		handle.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
		} );
		li.addEventListener( 'dragover', function ( e ) {
			e.preventDefault();
			if ( ! dragRoomId || String( dragRoomId ) === li.dataset.roomId ) {
				return;
			}
			if ( e.dataTransfer ) {
				e.dataTransfer.dropEffect = 'move';
			}
			var rect = li.getBoundingClientRect();
			var before = ( e.clientY - rect.top ) < rect.height / 2;
			clearDropIndicators();
			li.classList.add( before ? 'drop-before' : 'drop-after' );
		} );
		li.addEventListener( 'dragleave', function ( e ) {
			if ( ! li.contains( e.relatedTarget ) ) {
				li.classList.remove( 'drop-before', 'drop-after' );
			}
		} );
		li.addEventListener( 'drop', function ( e ) {
			e.preventDefault();
			var targetId = parseInt( li.dataset.roomId, 10 );
			var before = li.classList.contains( 'drop-before' );
			clearDropIndicators();
			if ( ! dragRoomId || ! targetId || dragRoomId === targetId ) {
				return;
			}
			reorderRoomsLocal( dragRoomId, targetId, before );
			persistRoomOrder();
		} );
	}

	function clearDropIndicators() {
		ui.roomList.querySelectorAll( '.drop-before, .drop-after' ).forEach( function ( node ) {
			node.classList.remove( 'drop-before', 'drop-after' );
		} );
	}

	function reorderRoomsLocal( fromId, toId, before ) {
		var fromIdx = state.rooms.findIndex( function ( r ) { return r.room_id === fromId; } );
		var toIdx   = state.rooms.findIndex( function ( r ) { return r.room_id === toId; } );
		if ( fromIdx < 0 || toIdx < 0 ) {
			return;
		}
		var moved = state.rooms.splice( fromIdx, 1 )[0];
		toIdx = state.rooms.findIndex( function ( r ) { return r.room_id === toId; } );
		var insertAt = before ? toIdx : toIdx + 1;
		state.rooms.splice( insertAt, 0, moved );
		renderRoomList();
	}

	function persistRoomOrder() {
		var ids = state.rooms.map( function ( r ) { return r.room_id; } );
		api( '/rooms/order', { method: 'POST', body: { room_ids: ids } } ).catch( function ( err ) {
			handleError( err );
		} );
	}

	function selectRoom( roomId ) {
		state.activeRoomId  = roomId;
		state.lastMessageId = 0;
		state.membersOpen   = false;
		ui.membersPanel.hidden = true;
		ui.messages.textContent = '';

		var room = activeRoom();
		state.myRole = room ? room.room_role : null;

		renderRoomList();
		renderRoomHead();

		if ( room && room.is_member ) {
			ui.joinBar.hidden = true;
			ui.composer.hidden = false;
			loadMessages();
		} else if ( room ) {
			renderJoinBar( room );
			ui.composer.hidden = true;
		}
	}

	function renderRoomHead() {
		var room = activeRoom();
		ui.roomActions.textContent = '';

		if ( ! room ) {
			ui.roomTitle.textContent = 'Select a room';
			ui.roomMeta.textContent = '';
			return;
		}

		ui.roomTitle.textContent = ( room.is_private ? '🔒 ' : '' ) + room.name;
		ui.roomMeta.textContent  = room.member_count + ( room.member_count === 1 ? ' member' : ' members' );

		if ( room.is_member ) {
			var membersBtn = el( 'button', 'stokechat-btn', 'Members' );
			membersBtn.type = 'button';
			membersBtn.addEventListener( 'click', toggleMembers );
			ui.roomActions.appendChild( membersBtn );

			if ( room.room_role === 'creator' || cfg.isAdmin ) {
				var renameBtn = el( 'button', 'stokechat-btn', 'Rename' );
				renameBtn.type = 'button';
				renameBtn.addEventListener( 'click', function () { beginRename( room ); } );
				ui.roomActions.appendChild( renameBtn );
			}

			if ( room.room_role !== 'creator' ) {
				var leaveBtn = el( 'button', 'stokechat-btn', 'Leave' );
				leaveBtn.type = 'button';
				confirmButton( leaveBtn, async function () {
					try {
						await api( '/rooms/' + room.room_id + '/leave', { method: 'POST' } );
						state.activeRoomId = 0;
						await refreshRooms();
						renderRoomHead();
						ui.messages.textContent = '';
						ui.composer.hidden = true;
					} catch ( err ) {
						handleError( err );
					}
				} );
				ui.roomActions.appendChild( leaveBtn );
			}

			if ( room.room_role === 'creator' || cfg.isAdmin ) {
				var delBtn = el( 'button', 'stokechat-btn is-danger', 'Delete room' );
				delBtn.type = 'button';
				confirmButton( delBtn, async function () {
					try {
						await api( '/rooms/' + room.room_id, { method: 'DELETE' } );
						state.activeRoomId = 0;
						await refreshRooms();
						renderRoomHead();
						ui.messages.textContent = '';
						ui.composer.hidden = true;
						toast( 'Room deleted.' );
					} catch ( err ) {
						handleError( err );
					}
				} );
				ui.roomActions.appendChild( delBtn );
			}
		}
	}

	function beginRename( room ) {
		var input = el( 'input', 'stokechat-input stokechat-rename-input' );
		input.type = 'text';
		input.value = room.name;
		input.maxLength = 190;
		input.setAttribute( 'aria-label', 'Room name' );

		ui.roomTitle.textContent = '';
		ui.roomTitle.appendChild( input );
		input.focus();
		input.select();

		var saving = false;
		var cancelled = false;

		async function save() {
			if ( saving || cancelled ) {
				return;
			}
			var name = input.value.trim();
			if ( ! name || name === room.name ) {
				renderRoomHead();
				return;
			}
			saving = true;
			input.disabled = true;
			try {
				var updated = await api( '/rooms/' + room.room_id, {
					method: 'POST',
					body: { name: name }
				} );
				room.name = updated.name;
				renderRoomList();
				renderRoomHead();
				toast( 'Room renamed.' );
			} catch ( err ) {
				handleError( err );
				renderRoomHead();
			}
		}

		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				save();
			} else if ( e.key === 'Escape' ) {
				e.preventDefault();
				cancelled = true;
				renderRoomHead();
			}
		} );
		input.addEventListener( 'blur', function () {
			setTimeout( save, 100 );
		} );
	}

	function renderJoinBar( room ) {
		ui.joinBar.textContent = '';
		ui.joinBar.hidden = false;
		ui.joinBar.appendChild( el( 'p', null, 'Join this room to read and send messages.' ) );
		var joinBtn = el( 'button', 'stokechat-btn is-primary', 'Join room' );
		joinBtn.type = 'button';
		joinBtn.addEventListener( 'click', async function () {
			try {
				await api( '/rooms/' + room.room_id + '/join', { method: 'POST' } );
				await refreshRooms();
				selectRoom( room.room_id );
			} catch ( err ) {
				handleError( err );
			}
		} );
		ui.joinBar.appendChild( joinBtn );
	}

	/* ------------------------------------------------------------------ */
	/* Messages                                                           */
	/* ------------------------------------------------------------------ */

	async function loadMessages() {
		try {
			var data = await api( '/rooms/' + state.activeRoomId + '/messages' );
			appendMessages( data.messages );
			ui.joinBar.hidden = true;
		} catch ( err ) {
			handleError( err );
		}
	}

	function isPinnedToBottom() {
		return ui.messages.scrollHeight - ui.messages.scrollTop - ui.messages.clientHeight < 60;
	}

	function scrollToBottom() {
		ui.messages.scrollTop = ui.messages.scrollHeight;
	}

	function appendMessages( list ) {
		if ( ! list || ! list.length ) {
			return;
		}
		var pinned = isPinnedToBottom();
		list.forEach( function ( m ) {
			if ( m.message_id <= state.lastMessageId ) {
				return;
			}
			ui.messages.appendChild( messageNode( m ) );
			state.lastMessageId = Math.max( state.lastMessageId, m.message_id );
		} );
		if ( pinned ) {
			scrollToBottom();
		}
	}

	function messageNode( m, pending ) {
		var item = el( 'article', 'stokechat-msg' + ( m.user_id === cfg.me.id ? ' is-mine' : '' ) );
		if ( m.message_id ) {
			item.dataset.id = String( m.message_id );
		}

		if ( m.avatar_url ) {
			var img = el( 'img', 'stokechat-avatar' );
			img.src = m.avatar_url;
			img.alt = '';
			img.loading = 'lazy';
			item.appendChild( img );
		} else {
			item.appendChild( el( 'span', 'stokechat-avatar stokechat-avatar-blank', '•' ) );
		}

		var body = el( 'div', 'stokechat-msg-body' );
		var meta = el( 'div', 'stokechat-msg-meta' );
		meta.appendChild( el( 'strong', 'stokechat-msg-author', m.display_name ) );
		meta.appendChild( el( 'time', 'stokechat-msg-time', pending ? 'sending…' : fmtTime( m.created_at ) ) );

		if ( ! pending && m.message_id && ( m.user_id === cfg.me.id || canModerate() ) ) {
			var del = el( 'button', 'stokechat-msg-delete', '×' );
			del.type = 'button';
			del.title = 'Delete message';
			confirmButton( del, async function () {
				try {
					await api( '/messages/' + m.message_id, { method: 'DELETE' } );
					item.remove();
				} catch ( err ) {
					handleError( err );
				}
			} );
			meta.appendChild( del );
		}

		var content = el( 'div', 'stokechat-msg-content' );
		content.appendChild( contentNodes( m.content ) );

		body.appendChild( meta );
		body.appendChild( content );
		item.appendChild( body );
		return item;
	}

	function sendMessage( text ) {
		var roomId  = state.activeRoomId;
		var pending = messageNode(
			{
				message_id: 0,
				user_id: cfg.me.id,
				display_name: cfg.me.display_name,
				avatar_url: cfg.me.avatar_url,
				content: text,
				created_at: null
			},
			true
		);
		pending.classList.add( 'is-pending' );
		ui.messages.appendChild( pending );
		scrollToBottom();

		api( '/rooms/' + roomId + '/messages', { method: 'POST', body: { content: text } } )
			.then( function ( m ) {
				pending.remove();
				if ( roomId === state.activeRoomId ) {
					appendMessages( [ m ] );
				}
			} )
			.catch( function ( err ) {
				pending.classList.remove( 'is-pending' );
				pending.classList.add( 'is-failed' );
				var retry = el( 'button', 'stokechat-btn stokechat-retry', 'Retry' );
				retry.type = 'button';
				retry.addEventListener( 'click', function () {
					pending.remove();
					if ( roomId === state.activeRoomId ) {
						sendMessage( text );
					}
				} );
				pending.querySelector( '.stokechat-msg-body' ).appendChild( retry );
				handleError( err );
			} );
	}

	/* ------------------------------------------------------------------ */
	/* Members panel                                                      */
	/* ------------------------------------------------------------------ */

	async function toggleMembers() {
		state.membersOpen = ! state.membersOpen;
		ui.membersPanel.hidden = ! state.membersOpen;
		if ( state.membersOpen ) {
			await renderMembersPanel();
		}
	}

	async function renderMembersPanel() {
		var room = activeRoom();
		if ( ! room ) {
			return;
		}
		ui.membersPanel.textContent = '';
		ui.membersPanel.appendChild( el( 'h3', 'stokechat-panel-title', 'Members' ) );

		var list = el( 'ul', 'stokechat-member-list' );
		ui.membersPanel.appendChild( list );

		try {
			var data = await api( '/rooms/' + room.room_id + '/members' );
			data.members.forEach( function ( member ) {
				list.appendChild( memberRow( room, member ) );
			} );
		} catch ( err ) {
			handleError( err );
			return;
		}

		if ( canModerate() && state.myRole ) {
			ui.membersPanel.appendChild( buildInviteForm( room ) );
		}
	}

	function memberRow( room, member ) {
		var li = el( 'li', 'stokechat-member' );
		li.appendChild( el( 'span', 'stokechat-member-name', member.display_name ) );
		li.appendChild( el( 'span', 'stokechat-role-tag is-' + member.room_role, member.room_role ) );

		var isSelf = member.user_id === cfg.me.id;

		if ( state.myRole === 'creator' && member.room_role !== 'creator' ) {
			var select = el( 'select', 'stokechat-role-select' );
			[ 'member', 'moderator' ].forEach( function ( role ) {
				var opt = el( 'option', null, role );
				opt.value = role;
				opt.selected = role === member.room_role;
				select.appendChild( opt );
			} );
			select.addEventListener( 'change', async function () {
				try {
					await api( '/rooms/' + room.room_id + '/members/' + member.user_id, {
						method: 'PUT',
						body: { room_role: select.value }
					} );
					renderMembersPanel();
				} catch ( err ) {
					handleError( err );
				}
			} );
			li.appendChild( select );
		}

		if ( canModerate() && ! isSelf && member.room_role !== 'creator' ) {
			var kick = el( 'button', 'stokechat-btn is-danger', 'Kick' );
			kick.type = 'button';
			confirmButton( kick, async function () {
				try {
					await api( '/rooms/' + room.room_id + '/members/' + member.user_id, { method: 'DELETE' } );
					renderMembersPanel();
					refreshRooms();
				} catch ( err ) {
					handleError( err );
				}
			} );
			li.appendChild( kick );
		}

		return li;
	}

	function buildInviteForm( room ) {
		var wrap = el( 'div', 'stokechat-invite' );
		wrap.appendChild( el( 'h4', 'stokechat-panel-title', 'Invite someone' ) );

		var input = el( 'input', 'stokechat-input' );
		input.type = 'text';
		input.placeholder = 'Search users…';

		var results = el( 'ul', 'stokechat-invite-results' );
		var timer = 0;

		input.addEventListener( 'input', function () {
			clearTimeout( timer );
			var term = input.value.trim();
			if ( term.length < 2 ) {
				results.textContent = '';
				return;
			}
			timer = setTimeout( async function () {
				try {
					var data = await api( '/users?search=' + encodeURIComponent( term ) );
					results.textContent = '';
					data.users.forEach( function ( user ) {
						var li  = el( 'li' );
						var btn = el( 'button', 'stokechat-invite-btn', user.display_name + ' (@' + user.username + ')' );
						btn.type = 'button';
						btn.addEventListener( 'click', async function () {
							try {
								await api( '/rooms/' + room.room_id + '/members', {
									method: 'POST',
									body: { user_id: user.user_id }
								} );
								input.value = '';
								results.textContent = '';
								toast( 'Invited ' + user.display_name + '.' );
								renderMembersPanel();
								refreshRooms();
							} catch ( err ) {
								handleError( err );
							}
						} );
						li.appendChild( btn );
						results.appendChild( li );
					} );
				} catch ( err ) {
					handleError( err, true );
				}
			}, 300 );
		} );

		wrap.appendChild( input );
		wrap.appendChild( results );
		return wrap;
	}

	/* ------------------------------------------------------------------ */
	/* Polling                                                            */
	/* ------------------------------------------------------------------ */

	function currentIntervalMs() {
		var idle = Date.now() - state.lastActivity > IDLE_MS;
		var slow = document.hidden || idle;
		return ( slow ? cfg.pollIntervalHidden : cfg.pollInterval ) * 1000;
	}

	function schedulePoll() {
		clearTimeout( state.pollTimer );
		state.pollTimer = setTimeout( poll, currentIntervalMs() );
	}

	async function poll() {
		state.tick++;
		try {
			var room = activeRoom();
			if ( room && room.is_member ) {
				var data = await api( '/rooms/' + room.room_id + '/messages?after=' + state.lastMessageId );
				appendMessages( data.messages );
			}
			if ( state.tick % 3 === 0 || ! room ) {
				await refreshRooms();
			}
		} catch ( err ) {
			handleError( err, true );
		}
		schedulePoll();
	}

	/* ------------------------------------------------------------------ */
	/* Boot                                                               */
	/* ------------------------------------------------------------------ */

	function trackActivity() {
		state.lastActivity = Date.now();
	}

	async function boot() {
		buildLayout();

		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden ) {
				trackActivity();
				clearTimeout( state.pollTimer );
				poll();
			}
		} );
		[ 'mousemove', 'keydown', 'click', 'touchstart' ].forEach( function ( evt ) {
			document.addEventListener( evt, trackActivity, { passive: true } );
		} );

		try {
			await refreshRooms();
			var first = state.rooms.find( function ( r ) { return r.is_member; } ) || state.rooms[0];
			if ( first ) {
				selectRoom( first.room_id );
			}
		} catch ( err ) {
			handleError( err );
		}

		schedulePoll();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
