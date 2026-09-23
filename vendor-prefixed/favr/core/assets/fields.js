/**
 * Favr Core — shared field UI (tabs, conditions, media, gallery, hours, repeater).
 * No build step; depends on jQuery, and on wp.media / jquery-ui-sortable when those
 * field types are present. Strings come from window.favrCoreFields.i18n.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.favrCoreFields || {};
	var i18n = cfg.i18n || {};

	/* ------------------------------------------------------------------ Tabs */

	function storageKey( $panel ) {
		return 'favrTab:' + ( $panel.attr( 'id' ) || 'panel' );
	}

	function activateTab( $panel, tabId, focus ) {
		var $tab = $panel.find( '.favr-tab[data-tab="' + tabId + '"]' );
		if ( ! $tab.length ) {
			return;
		}
		$panel.find( '.favr-tab' ).removeClass( 'is-active' ).attr( { 'aria-selected': 'false', tabindex: '-1' } );
		$tab.addClass( 'is-active' ).attr( { 'aria-selected': 'true', tabindex: '0' } );
		$panel.find( '.favr-pane' ).removeClass( 'is-active' ).attr( 'hidden', true );
		$panel.find( '#' + $tab.attr( 'aria-controls' ) ).addClass( 'is-active' ).removeAttr( 'hidden' );
		if ( focus ) {
			$tab.trigger( 'focus' );
		}
		try {
			window.sessionStorage.setItem( storageKey( $panel ), tabId );
		} catch ( e ) {}
	}

	function initPanel( $panel ) {
		$panel.on( 'click', '.favr-tab', function () {
			activateTab( $panel, $( this ).data( 'tab' ) );
		} );

		// Arrow-key navigation between tabs (WAI-ARIA tabs pattern).
		$panel.on( 'keydown', '.favr-tab', function ( e ) {
			var $tabs = $panel.find( '.favr-tab' );
			var index = $tabs.index( this );
			var next = null;
			if ( 'ArrowDown' === e.key || 'ArrowRight' === e.key ) {
				next = ( index + 1 ) % $tabs.length;
			} else if ( 'ArrowUp' === e.key || 'ArrowLeft' === e.key ) {
				next = ( index - 1 + $tabs.length ) % $tabs.length;
			} else if ( 'Home' === e.key ) {
				next = 0;
			} else if ( 'End' === e.key ) {
				next = $tabs.length - 1;
			}
			if ( null !== next ) {
				e.preventDefault();
				activateTab( $panel, $tabs.eq( next ).data( 'tab' ), true );
			}
		} );
		$panel.find( '.favr-tab:not(.is-active)' ).attr( 'tabindex', '-1' );

		var initial = '';
		try {
			initial = window.sessionStorage.getItem( storageKey( $panel ) ) || '';
		} catch ( e ) {}
		if ( initial ) {
			activateTab( $panel, initial );
		}

		// A native validation error on a hidden tab would fail silently: reveal it.
		// "invalid" does not bubble, so listen in the capture phase.
		var form = $panel.closest( 'form' ).get( 0 );
		if ( form ) {
			form.addEventListener(
				'invalid',
				function ( e ) {
					var $pane = $( e.target ).closest( '.favr-pane' );
					if ( $pane.length && ! $pane.hasClass( 'is-active' ) ) {
						var tabId = $panel.find( '.favr-tab[aria-controls="' + $pane.attr( 'id' ) + '"]' ).data( 'tab' );
						activateTab( $panel, tabId );
					}
					$( e.target ).closest( '.favr-field' ).addClass( 'has-error' );
				},
				true
			);
		}
		$panel.on( 'input change', '.has-error input, .has-error textarea', function () {
			$( this ).closest( '.favr-field' ).removeClass( 'has-error' );
		} );
	}

	/** Public API for plugins (e.g. "go to field" links). */
	window.favrCore = {
		activateTab: function ( panel, tabId ) {
			activateTab( $( panel ), tabId );
		},
		applyConditions: function () {
			applyConditions( false );
		}
	};

	/* ---------------------------------------------------------- Conditions */

	function fieldValue( fieldId ) {
		var $wrap = $( '.favr-field[data-field="' + fieldId + '"]' );
		var $radios = $wrap.find( 'input[type=radio]' );
		if ( $radios.length ) {
			return String( $radios.filter( ':checked' ).val() || '' );
		}
		var $check = $wrap.find( 'input[type=checkbox]' );
		if ( $check.length === 1 ) {
			return $check.is( ':checked' ) ? '1' : '';
		}
		var $input = $wrap.find( 'input:not([type=hidden]), select, textarea' ).first();
		return $input.length ? String( $input.val() || '' ) : '';
	}

	function applyConditions( animate ) {
		$( '.favr-field[data-cond-field]' ).each( function () {
			var $field = $( this );
			var allowed = String( $field.data( 'cond-value' ) ).split( '|' );
			var show = allowed.indexOf( fieldValue( $field.data( 'cond-field' ) ) ) !== -1;
			if ( animate && $field.hasClass( 'is-cond-hidden' ) === ! show ) {
				return; // Already in the right state.
			}
			$field.toggleClass( 'is-cond-hidden', ! show );
			if ( animate ) {
				$field.stop( true, true )[ show ? 'slideDown' : 'slideUp' ]( 150 );
			} else {
				$field.toggle( show );
			}
		} );
	}

	/* --------------------------------------------------- Counters & URLs */

	function updateCounter( el ) {
		var max = parseInt( el.getAttribute( 'maxlength' ), 10 );
		var $counter = $( el ).siblings( '.favr-counter' );
		if ( ! max || ! $counter.length ) {
			return;
		}
		var left = max - el.value.length;
		$counter.text( ( i18n.charsLeft || '%d' ).replace( '%d', left ) ).toggleClass( 'is-low', left < 20 );
	}

	function normalizeUrl( el ) {
		var value = $.trim( el.value );
		if ( value && ! /^[a-z][a-z0-9+.-]*:/i.test( value ) ) {
			el.value = 'https://' + value.replace( /^\/+/, '' );
		}
	}

	/* -------------------------------------------------------------- Media */

	function initImagePickers() {
		$( document ).on( 'click', '.favr-image .favr-media-pick', function ( e ) {
			e.preventDefault();
			var $wrap = $( this ).closest( '.favr-image' );
			var frame = wp.media( {
				title: i18n.chooseLogo,
				button: { text: i18n.useImage },
				library: { type: 'image' },
				multiple: false
			} );
			frame.on( 'open', function () {
				var id = parseInt( $wrap.find( 'input[type=hidden]' ).val(), 10 );
				if ( id ) {
					var attachment = wp.media.attachment( id );
					attachment.fetch();
					frame.state().get( 'selection' ).add( attachment );
				}
			} );
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				var url = ( att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url );
				$wrap.find( 'input[type=hidden]' ).val( att.id ).trigger( 'change' );
				$wrap.find( '.favr-image__preview img' ).remove();
				$wrap.find( '.favr-image__preview' ).prepend( $( '<img alt="">' ).attr( 'src', url ) );
				$wrap.addClass( 'has-image' );
			} );
			frame.open();
		} );

		$( document ).on( 'click', '.favr-image__remove', function ( e ) {
			e.preventDefault();
			var $wrap = $( this ).closest( '.favr-image' );
			$wrap.find( 'input[type=hidden]' ).val( '' ).trigger( 'change' );
			$wrap.find( '.favr-image__preview img' ).remove();
			$wrap.removeClass( 'has-image' );
		} );
	}

	function syncGallery( $gallery ) {
		var ids = $gallery.find( '.favr-gallery__item' ).map( function () {
			return $( this ).data( 'id' );
		} ).get();
		$gallery.find( 'input[type=hidden]' ).val( ids.join( ',' ) ).trigger( 'change' );
	}

	function initGalleries() {
		$( '.favr-gallery__list' ).sortable( {
			items: '.favr-gallery__item',
			tolerance: 'pointer',
			placeholder: 'favr-gallery__placeholder',
			update: function () {
				syncGallery( $( this ).closest( '.favr-gallery' ) );
			}
		} );

		$( document ).on( 'click', '.favr-gallery__add', function ( e ) {
			e.preventDefault();
			var $gallery = $( this ).closest( '.favr-gallery' );
			var frame = wp.media( {
				title: i18n.addPhotos,
				button: { text: i18n.addToGallery },
				library: { type: 'image' },
				multiple: 'add'
			} );
			frame.on( 'select', function () {
				var $list = $gallery.find( '.favr-gallery__list' );
				frame.state().get( 'selection' ).each( function ( model ) {
					var att = model.toJSON();
					if ( $list.find( '[data-id="' + att.id + '"]' ).length ) {
						return;
					}
					var thumb = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
					$list.append(
						$( '<li class="favr-gallery__item"></li>' )
							.attr( 'data-id', att.id )
							.append( $( '<img alt="">' ).attr( 'src', thumb ) )
							.append( '<button type="button" class="favr-gallery__remove" aria-label="Remove">&times;</button>' )
					);
				} );
				syncGallery( $gallery );
			} );
			frame.open();
		} );

		$( document ).on( 'click', '.favr-gallery__remove', function ( e ) {
			e.preventDefault();
			var $gallery = $( this ).closest( '.favr-gallery' );
			$( this ).closest( '.favr-gallery__item' ).remove();
			syncGallery( $gallery );
		} );
	}

	/* -------------------------------------------------------------- Hours */

	function initHours() {
		$( document ).on( 'change', '.favr-hours__status', function () {
			$( this ).closest( '.favr-hours__row' ).attr( 'data-status', this.value );
		} );

		$( document ).on( 'click', '.favr-hours__start', function () {
			var $hours = $( this ).closest( '.favr-hours' );
			$hours.removeClass( 'is-empty' ).find( 'select, input' ).prop( 'disabled', false );
			$hours.find( 'select' ).first().trigger( 'focus' );
		} );

		$( document ).on( 'click', '.favr-hours__clear', function () {
			if ( ! window.confirm( i18n.confirmClear ) ) {
				return;
			}
			$( this ).closest( '.favr-hours' ).addClass( 'is-empty' ).find( 'select, input' ).prop( 'disabled', true );
		} );

		$( document ).on( 'click', '.favr-hours__copy', function () {
			var $rows = $( this ).closest( '.favr-hours' ).find( '.favr-hours__row' );
			var $mon = $rows.filter( '[data-day="mon"]' );
			var status = $mon.find( 'select' ).val();
			var times = $mon.find( 'input[type=time]' );
			$rows.filter( '[data-day="tue"],[data-day="wed"],[data-day="thu"],[data-day="fri"]' ).each( function () {
				var $row = $( this );
				$row.find( 'select' ).val( status ).trigger( 'change' );
				$row.find( 'input[type=time]' ).eq( 0 ).val( times.eq( 0 ).val() );
				$row.find( 'input[type=time]' ).eq( 1 ).val( times.eq( 1 ).val() );
				$row.addClass( 'is-flash' );
				setTimeout( function () {
					$row.removeClass( 'is-flash' );
				}, 700 );
			} );
		} );
	}

	/* ----------------------------------------------------------- Repeater */

	function initRepeaters() {
		var counter = Date.now();

		$( '.favr-repeater__rows' ).sortable( { handle: '.favr-repeater__handle', axis: 'y', tolerance: 'pointer' } );

		$( document ).on( 'click', '.favr-repeater__add', function () {
			var $rep = $( this ).closest( '.favr-repeater' );
			var html = $rep.find( '.favr-repeater__template' ).html().replace( /__INDEX__/g, String( counter++ ) );
			var $row = $( html );
			$rep.find( '.favr-repeater__rows' ).append( $row );
			$row.find( 'input' ).first().trigger( 'focus' );
		} );

		$( document ).on( 'click', '.favr-repeater__remove', function () {
			$( this ).closest( '.favr-repeater__row' ).remove();
		} );
	}

	/* ------------------------------------------------------------- Boot */

	$( function () {
		$( '.favr-panel' ).each( function () {
			initPanel( $( this ) );
		} );
		initImagePickers();
		initGalleries();
		initHours();
		initRepeaters();

		applyConditions( false );
		$( document ).on( 'change', '.favr-field input[type=checkbox], .favr-field input[type=radio], .favr-field select', function () {
			applyConditions( true );
		} );

		$( '.favr-field [maxlength]' ).each( function () {
			updateCounter( this );
		} ).on( 'input', function () {
			updateCounter( this );
		} );

		$( document ).on( 'blur', '.favr-field--url input[type=text], input.favr-url', function () {
			normalizeUrl( this );
		} );
	} );
} )( jQuery );
