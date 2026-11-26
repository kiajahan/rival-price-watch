/* Rival Price Watch: add/remove competitor rows in the product box, and test a link. */
( function () {
	'use strict';
	var box = document.getElementById( 'rpw-links' );
	if ( ! box ) {
		return;
	}
	var cfg = window.rpwAdmin || {};
	var tbody = box.querySelector( 'table.rpw-links tbody' );
	var tpl = document.getElementById( 'rpw-row-template' );
	var preview = box.querySelector( '.rpw-preview' );
	var max = cfg.max || 10;

	function field( row, name ) {
		var el = row.querySelector( '[name="' + name + '[]"]' );
		return el ? el.value : '';
	}

	function show( title, lines, warnings, bad ) {
		if ( ! preview ) {
			return;
		}
		preview.hidden = false;
		preview.className = 'rpw-preview' + ( bad ? ' rpw-preview-bad' : '' );
		preview.textContent = '';
		if ( title ) {
			var head = document.createElement( 'strong' );
			head.textContent = title;
			preview.appendChild( head );
		}
		var list = document.createElement( 'ul' );
		( lines || [] ).forEach( function ( text ) {
			var li = document.createElement( 'li' );
			li.textContent = text;
			list.appendChild( li );
		} );
		( warnings || [] ).forEach( function ( text ) {
			var li = document.createElement( 'li' );
			li.className = 'rpw-preview-warn';
			li.textContent = text;
			list.appendChild( li );
		} );
		preview.appendChild( list );
	}

	function test( button ) {
		var row = button.closest( 'tr' );
		var url = field( row, 'rpw_url' ).trim();
		if ( ! url ) {
			show( '', [], [ cfg.nourl ], true );
			return;
		}
		var post = document.getElementById( 'post_ID' );
		var data = new URLSearchParams();
		data.append( 'action', 'rpw_preview' );
		data.append( 'nonce', cfg.nonce || '' );
		data.append( 'product', post ? post.value : '0' );
		data.append( 'url', url );
		data.append( 'currency', field( row, 'rpw_currency' ) );
		data.append( 'factor', field( row, 'rpw_factor' ) );
		data.append( 'match', field( row, 'rpw_match' ) );
		data.append( 'mode', field( row, 'rpw_mode' ) || 'words' );

		button.disabled = true;
		show( url, [ cfg.testing ], [], false );
		fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: data } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				var d = ( json && json.data ) || {};
				if ( json && json.success ) {
					show( url, d.lines, d.warn, ! d.ok );
				} else {
					show( url, [], [ d.message || cfg.failed ], true );
				}
			} )
			.catch( function () {
				show( url, [], [ cfg.failed ], true );
			} )
			.then( function () {
				button.disabled = false;
			} );
	}

	box.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '.rpw-add' ) ) {
			e.preventDefault();
			if ( tbody.rows.length >= max ) {
				return;
			}
			tbody.appendChild( tpl.content.cloneNode( true ) );
			tbody.rows[ tbody.rows.length - 1 ].querySelector( 'input' ).focus();
		}
		var tester = e.target.closest( '.rpw-test' );
		if ( tester ) {
			e.preventDefault();
			test( tester );
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
