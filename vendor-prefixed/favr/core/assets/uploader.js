/* Favr Core — front-end image uploader. Posts to a plugin REST route; never touches wp-admin. */
( function () {
	'use strict';

	function sync( root ) {
		var ids = Array.prototype.map.call( root.querySelectorAll( '.favr-upload__item' ), function ( li ) {
			return li.getAttribute( 'data-id' );
		} );
		root.querySelector( 'input[type=hidden]' ).value = ids.join( ',' );
	}

	function item( root, id, src ) {
		var li = document.createElement( 'li' );
		li.className = 'favr-upload__item';
		li.setAttribute( 'data-id', id );
		li.draggable = root.getAttribute( 'data-multiple' ) === '1';
		var img = document.createElement( 'img' );
		img.src = src;
		img.alt = '';
		var remove = document.createElement( 'button' );
		remove.type = 'button';
		remove.className = 'favr-upload__remove';
		remove.setAttribute( 'aria-label', ( window.favrCoreFields && favrCoreFields.i18n && favrCoreFields.i18n.removeImage ) || 'Remove image' );
		remove.textContent = '×';
		li.appendChild( img );
		li.appendChild( remove );
		return li;
	}

	function upload( root, files ) {
		var multiple = root.getAttribute( 'data-multiple' ) === '1';
		var list = root.querySelector( '.favr-upload__list' );
		var status = root.querySelector( '.favr-upload__status' );
		var queue = Array.prototype.slice.call( files, 0, multiple ? files.length : 1 );
		var form = root.closest( 'form' );
		var submit = form ? form.querySelectorAll( '[type=submit]' ) : [];

		function busy( on ) {
			root.classList.toggle( 'is-busy', on );
			Array.prototype.forEach.call( submit, function ( b ) { b.disabled = on; } );
		}

		function next() {
			var file = queue.shift();
			if ( ! file ) {
				busy( false );
				return;
			}
			status.textContent = file.name + '…';
			var body = new FormData();
			body.append( 'file', file );
			body.append( 'parent', root.getAttribute( 'data-parent' ) || '0' );
			fetch( root.getAttribute( 'data-endpoint' ), {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': root.getAttribute( 'data-nonce' ) },
				body: body
			} ).then( function ( res ) {
				return res.json().then( function ( json ) {
					if ( ! res.ok ) {
						throw new Error( json && json.message ? json.message : root.getAttribute( 'data-error' ) );
					}
					return json;
				} );
			} ).then( function ( json ) {
				if ( ! multiple ) {
					list.innerHTML = '';
				}
				list.appendChild( item( root, json.id, json.thumb ) );
				sync( root );
				status.textContent = '';
				next();
			} ).catch( function ( err ) {
				status.textContent = err && err.message ? err.message : root.getAttribute( 'data-error' );
				busy( false );
			} );
		}

		busy( true );
		next();
	}

	function init( root ) {
		var input = root.querySelector( 'input[type=file]' );
		var list = root.querySelector( '.favr-upload__list' );
		var dragging = null;

		input.addEventListener( 'change', function () {
			if ( input.files && input.files.length ) {
				upload( root, input.files );
			}
			input.value = '';
		} );

		list.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.favr-upload__remove' );
			if ( btn ) {
				btn.closest( '.favr-upload__item' ).remove();
				sync( root );
			}
		} );

		list.addEventListener( 'dragstart', function ( e ) {
			dragging = e.target.closest( '.favr-upload__item' );
			if ( dragging ) {
				dragging.classList.add( 'is-dragging' );
				e.dataTransfer.effectAllowed = 'move';
			}
		} );
		list.addEventListener( 'dragover', function ( e ) {
			var over = e.target.closest( '.favr-upload__item' );
			if ( ! dragging || ! over || over === dragging ) {
				return;
			}
			e.preventDefault();
			var rect = over.getBoundingClientRect();
			list.insertBefore( dragging, ( e.clientX - rect.left ) > rect.width / 2 ? over.nextSibling : over );
		} );
		list.addEventListener( 'dragend', function () {
			if ( dragging ) {
				dragging.classList.remove( 'is-dragging' );
				dragging = null;
				sync( root );
			}
		} );

		/* Dropping files anywhere on the widget uploads them. */
		root.addEventListener( 'dragover', function ( e ) {
			if ( ! dragging && e.dataTransfer && Array.prototype.indexOf.call( e.dataTransfer.types, 'Files' ) !== -1 ) {
				e.preventDefault();
				root.classList.add( 'is-over' );
			}
		} );
		root.addEventListener( 'dragleave', function () {
			root.classList.remove( 'is-over' );
		} );
		root.addEventListener( 'drop', function ( e ) {
			if ( ! dragging && e.dataTransfer && e.dataTransfer.files.length ) {
				e.preventDefault();
				root.classList.remove( 'is-over' );
				upload( root, e.dataTransfer.files );
			}
		} );
	}

	function boot() {
		Array.prototype.forEach.call( document.querySelectorAll( '.favr-upload' ), init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
