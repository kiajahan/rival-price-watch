/* Rival Price Watch: add/remove competitor rows in the product box. */
( function () {
	'use strict';
	var box = document.getElementById( 'rpw-links' );
	if ( ! box ) {
		return;
	}
	var tbody = box.querySelector( 'table.rpw-links tbody' );
	var tpl = document.getElementById( 'rpw-row-template' );
	var max = ( window.rpwAdmin && window.rpwAdmin.max ) || 10;

	box.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '.rpw-add' ) ) {
			e.preventDefault();
			if ( tbody.rows.length >= max ) {
				return;
			}
			tbody.appendChild( tpl.content.cloneNode( true ) );
			tbody.rows[ tbody.rows.length - 1 ].querySelector( 'input' ).focus();
		}
		var rm = e.target.closest( '.rpw-remove' );
		if ( rm ) {
			e.preventDefault();
			var row = rm.closest( 'tr' );
			if ( tbody.rows.length > 1 ) {
				row.remove();
			} else {
				row.querySelectorAll( 'input, select' ).forEach( function ( i ) {
					i.value = 'number' === i.type ? '1' : '';
				} );
			}
		}
	} );
} )();
