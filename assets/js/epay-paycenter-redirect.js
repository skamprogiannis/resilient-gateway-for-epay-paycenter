/** Auto-submit the payment form; optional observations never control payment. */
( function () {
	'use strict';

	/** @typedef {'script_started'|'form_found'|'form_missing'|'submission_attempted'|'submission_exception'|'javascript_error'|'unhandled_rejection'|'still_visible_after_submit'|'page_hidden'|'page_restored'} DiagnosticEvent */
	/** @typedef {Record<string, string|number|boolean>} Details */
	/** @typedef {(event: DiagnosticEvent, details?: Details) => void} Reporter */

	/** @param {string} text */
	function cleanText( text ) {
		return text.slice( 0, 1000 )
			.replace( /[a-z0-9+/]{40,}={0,2}\.[a-f0-9]{64}\b/gi, '[diagnostic authorization omitted]' )
			.replace( /[?#][^\s<>"']*/g, '[query omitted]' )
			.replace( /(https?:\/\/)[^\s/@]+@/gi, '$1[credentials omitted]@' )
			.replace( /\b(?:Bearer|Basic)\s+[^\s,;"']+/gi, '[authorization-omitted]' )
			.replace( /\b(?:authorization|password|passwd|username|secret|token|tranticket|ticket|hashkey|cookie|key)["']?\s*[=:]\s*(?:"[^"]*"|'[^']*'|[^\s,;"']+)/gi, '[credential omitted]' )
			.replace( /[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/gi, '[email omitted]' )
			.replace( /\b(?:\d[ -]*?){13,19}\b/g, '[card number omitted]' )
			.replace( /\b[a-f0-9]{32,64}\b/gi, '[digest omitted]' )
			.slice( 0, 400 );
	}

	/** @param {string} source */
	function scriptPath( source ) {
		if ( ! source ) {
			return '';
		}
		try {
			var url = new URL( source, window.location.href );
			return url.origin === window.location.origin ? cleanText( url.pathname ) : '[external script]';
		} catch ( error ) {
			return '';
		}
	}

	/** @param {unknown} error @return {Details} */
	function errorDetails( error ) {
		return error instanceof Error
			? { error_name: cleanText( error.name ), error_message: cleanText( error.message ) }
			: { error_name: 'NonErrorRejection' };
	}

	/** @return {Reporter} */
	function createReporter() {
		var noop = function () {};
		try {
			var wrapper = document.querySelector( '[data-epay-diagnostics]' );
			if ( ! wrapper ) {
				return noop;
			}
			/** @type {unknown} */
			var context = JSON.parse( wrapper.getAttribute( 'data-epay-diagnostics' ) || '' );
			if ( ! context || typeof context !== 'object'
				|| !( 'authorization' in context ) || typeof context.authorization !== 'string'
				|| !( 'ajax_url' in context ) || typeof context.ajax_url !== 'string'
				|| new URL( context.ajax_url ).origin !== window.location.origin ) {
				return noop;
			}
			var authorization = context.authorization;
			var ajaxUrl = context.ajax_url;
			var started = performance.now();
			var sequence = 0;
			var visibilityTimer = 0;

			/** @type {Reporter} */
			var report = function ( event, details ) {
				try {
					var elapsed = Math.round( performance.now() - started );
					if ( sequence >= 20 || elapsed < 0 || elapsed > 3600000 ) {
						return;
					}
					sequence++;
					var body = new URLSearchParams( {
						action: 'epay_paycenter_diagnostic',
						payload: JSON.stringify( { authorization: authorization, event: event, sequence: sequence, elapsed_ms: elapsed, details: details || {} } )
					} );
					var queued = typeof navigator.sendBeacon === 'function' && navigator.sendBeacon( ajaxUrl, body );
					if ( ! queued && typeof window.fetch === 'function' ) {
						window.fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body, keepalive: true } ).catch( function () {} );
					}
					if ( event === 'submission_attempted' ) {
						visibilityTimer = window.setTimeout( function () {
							if ( document.visibilityState === 'visible' ) {
								report( 'still_visible_after_submit', { visibility: 'visible' } );
							}
						}, 10000 );
					}
				} catch ( error ) {
					// A reporting failure must never delay payment or report itself recursively.
				}
			};

			window.addEventListener( 'error', function ( /** @type {Event} */ event ) {
				if ( event instanceof ErrorEvent ) {
					report( 'javascript_error', {
						error_name: 'ErrorEvent', error_message: cleanText( event.message ),
						script_path: scriptPath( event.filename ), line: event.lineno, column: event.colno
					} );
				} else if ( event.target instanceof HTMLScriptElement ) {
					report( 'javascript_error', { error_name: 'ScriptLoadError', script_path: scriptPath( event.target.src ) } );
				}
			}, true );
			window.addEventListener( 'unhandledrejection', function ( event ) {
				report( 'unhandled_rejection', errorDetails( event.reason ) );
			} );
			window.addEventListener( 'pagehide', function ( event ) {
				window.clearTimeout( visibilityTimer );
				report( 'page_hidden', { persisted: event.persisted } );
			} );
			window.addEventListener( 'pageshow', function ( event ) {
				if ( event.persisted ) {
					report( 'page_restored', { persisted: true } );
				}
			} );
			report( 'script_started', { browser: cleanText( navigator.userAgent ) } );
			return report;
		} catch ( error ) {
			// Missing or unusable diagnostic context cannot disable the redirect helper.
			return noop;
		}
	}

	function submitRedirectForm() {
		// An optimizer may move the script ahead of the receipt's reporting context.
		var report = createReporter();
		const form = document.getElementById( 'epay-paycenter-form' );
		if ( ! ( form instanceof HTMLFormElement ) ) {
			report( 'form_missing' );
			return;
		}
		report( 'form_found' );
		window.setTimeout( function () {
			report( 'submission_attempted' );
			try {
				form.submit();
			} catch ( error ) {
				report( 'submission_exception', errorDetails( error ) );
				throw error;
			}
		}, 100 );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', submitRedirectForm );
	} else {
		submitRedirectForm();
	}
} )();
