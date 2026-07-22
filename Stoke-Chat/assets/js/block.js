/**
 * Editor-side placeholder for the Stoke Chat block (frontend renders via PHP).
 */
( function ( blocks, element ) {
	'use strict';

	blocks.registerBlockType( 'stoke-chat/chat', {
		edit: function () {
			return element.createElement(
				'div',
				{
					style: {
						padding: '2em',
						textAlign: 'center',
						border: '1px dashed #949494',
						borderRadius: '4px',
						color: '#555'
					}
				},
				'💬 Stoke Chat — the chat app renders here for logged-in visitors.'
			);
		},
		save: function () {
			return null;
		}
	} );
}( window.wp.blocks, window.wp.element ) );
