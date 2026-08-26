/**
 * AEC_Forge_Tools front-end: load-sample + AJAX run/download.
 */
( function () {
	'use strict';

	var D = window.AECForgeToolsData || {};

	function ready( fn ) {
		if ( 'loading' !== document.readyState ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	ready( function () {
		var tool = document.querySelector( '.mp-tool' );
		if ( ! tool ) {
			return;
		}

		var form       = tool.querySelector( '.mp-form' );
		var sampleBtn  = tool.querySelector( '.mp-load-sample' );
		var resultWrap = tool.querySelector( '.mp-result-wrap' );
		var resultPre  = tool.querySelector( '.mp-result' );
		var dlBox      = tool.querySelector( '.mp-download' );
		var dlLink     = tool.querySelector( '.mp-download-link' );
		var dlName     = tool.querySelector( '.mp-download-name' );
		var service    = tool.getAttribute( 'data-service' );

		if ( sampleBtn ) {
			sampleBtn.addEventListener( 'click', function () {
				// Fill text inputs from their data-sample.
				tool.querySelectorAll( '[data-sample]' ).forEach( function ( el ) {
					el.value = el.getAttribute( 'data-sample' );
				} );
				var file = tool.getAttribute( 'data-sample-file' );
				var paste = tool.querySelector( '[data-paste="1"]' );
				if ( file && paste ) {
					sampleBtn.disabled = true;
					sampleBtn.textContent = D.i18n.loadingS;
					fetch( D.samplesUrl + file )
						.then( function ( r ) { return r.text(); } )
						.then( function ( txt ) {
							paste.value = txt;
							sampleBtn.disabled = false;
							sampleBtn.textContent = D.i18n.loaded;
						} )
						.catch( function () {
							sampleBtn.disabled = false;
							sampleBtn.textContent = D.i18n.sample;
						} );
				}
			} );
		}

		if ( ! form ) {
			return;
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var runBtn = form.querySelector( '.mp-run' );
			var original = runBtn ? runBtn.textContent : '';
			if ( runBtn ) {
				runBtn.disabled = true;
				runBtn.textContent = D.i18n.running;
			}

			var data = new FormData( form );
			data.append( 'action', 'aec_tools_run' );
			data.append( 'service', service );
			data.append( 'nonce', D.nonce );

			fetch( D.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( runBtn ) {
						runBtn.disabled = false;
						runBtn.textContent = original;
					}
					if ( ! res || ! res.success ) {
						showError( ( res && res.data && res.data.message ) || D.i18n.error );
						return;
					}
					var d = res.data;
					resultWrap.hidden = false;
					resultPre.textContent = d.text || '';
					if ( d.download_url ) {
						dlBox.hidden = false;
						dlLink.setAttribute( 'href', d.download_url );
						dlName.textContent = d.download_name || '';
					} else {
						dlBox.hidden = true;
					}
					updateCredits( d.credits );
					resultWrap.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				} )
				.catch( function () {
					if ( runBtn ) {
						runBtn.disabled = false;
						runBtn.textContent = original;
					}
					showError( D.i18n.error );
				} );
		} );

		function showError( msg ) {
			resultWrap.hidden = false;
			dlBox.hidden = true;
			resultPre.textContent = msg;
			resultWrap.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}

		function updateCredits( credits ) {
			if ( 'undefined' === typeof credits ) {
				return;
			}
			var chip = document.querySelector( '.mp-credit-chip' );
			if ( chip ) {
				chip.textContent = credits + ' ' + ( 1 === credits ? 'credit' : 'credits' );
			}
		}
	} );
} )();
