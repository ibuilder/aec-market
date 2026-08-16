/**
 * Vendor dashboard: toggle the program/service field groups on the
 * add/edit listing form.
 */
( function () {
	'use strict';

	var select = document.getElementById( 'wpaec_listing_type' );

	if ( ! select ) {
		return;
	}

	function toggleTypeFields() {
		document.querySelectorAll( '.wpaec-type-fields' ).forEach( function ( el ) {
			el.style.display = el.dataset.type === select.value ? '' : 'none';
		} );
	}

	select.addEventListener( 'change', toggleTypeFields );
	toggleTypeFields();
}() );
