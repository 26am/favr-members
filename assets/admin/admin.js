/**
 * Favr Members — admin. Shows Individual- or Business-only boxes to match the chosen type.
 */
( function ( $ ) {
	'use strict';

	function currentType() {
		return $( 'input[name="favr_member[type]"]:checked' ).val() || 'individual';
	}

	function applyType() {
		var type = currentType();
		$( 'body' ).toggleClass( 'favr-type-business', 'business' === type ).toggleClass( 'favr-type-individual', 'business' !== type );
	}

	$( function () {
		applyType();
		$( document ).on( 'change', 'input[name="favr_member[type]"]', applyType );
		if ( $.fn.wpColorPicker ) {
			$( '.favr-color' ).wpColorPicker();
		}
	} );
} )( jQuery );
