/**
 * Admin settings script for the ePay Paycenter (Piraeus Bank) gateway.
 *
 * Handles:
 *  - "Copy" buttons on the Bank integration data fields.
 *  - "Test callback URL" button on the Callback diagnostics card (fires a
 *    same-origin AJAX POST to admin-ajax.php -> ajax_run_waf_test() and
 *    renders the result inline).
 *
 * No external dependencies; uses `navigator.clipboard` with a
 * `document.execCommand` fallback for older browsers, and `fetch` for the
 * AJAX call. Localised strings / nonce / ajax URL are passed in via
 * wp_localize_script as `epayPaycenterAdmin`.
 */
( function () {
	'use strict';

	function copyText( input ) {
		if ( ! input ) {
			return Promise.reject();
		}
		if ( window.navigator && window.navigator.clipboard && window.isSecureContext ) {
			return window.navigator.clipboard.writeText( input.value );
		}
		return new Promise( function ( resolve, reject ) {
			try {
				input.focus();
				input.select();
				input.setSelectionRange( 0, input.value.length );
				var ok = document.execCommand( 'copy' );
				if ( ok ) {
					resolve();
				} else {
					reject();
				}
			} catch ( err ) {
				reject( err );
			}
		} );
	}

	function flashButton( btn, labelCopied, labelDefault ) {
		var originalLabel = btn.querySelector( '.epay-copy__btn-label' );
		if ( originalLabel ) {
			originalLabel.textContent = labelCopied;
		}
		btn.setAttribute( 'data-copied', '1' );
		window.setTimeout( function () {
			btn.removeAttribute( 'data-copied' );
			if ( originalLabel ) {
				originalLabel.textContent = labelDefault;
			}
		}, 1600 );
	}

	function init() {
		var strings = ( window.epayPaycenterAdmin && window.epayPaycenterAdmin.strings ) || {};
		var labelCopy   = strings.copy   || 'Copy';
		var labelCopied = strings.copied || 'Copied';
		var labelFailed = strings.failed || 'Press Ctrl+C to copy';

		var buttons = document.querySelectorAll( '.epay-copy__btn' );
		Array.prototype.forEach.call( buttons, function ( btn ) {
			btn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				var targetId = btn.getAttribute( 'data-target' );
				if ( ! targetId ) {
					return;
				}
				var input = document.getElementById( targetId );
				if ( ! input ) {
					return;
				}
				copyText( input ).then(
					function () {
						flashButton( btn, labelCopied, labelCopy );
					},
					function () {
						input.focus();
						input.select();
						flashButton( btn, labelFailed, labelCopy );
					}
				);
			} );
		} );

		var inputs = document.querySelectorAll( '.epay-copy__input' );
		Array.prototype.forEach.call( inputs, function ( input ) {
			input.addEventListener( 'focus', function () {
				input.select();
			} );
		} );

		initWafTest( strings );
	}

	/**
	 * Render one labelled block of plain text inside the result box.
	 * Uses textContent throughout to avoid any HTML interpretation of
	 * server-returned values (status codes, headers, body snippets).
	 */
	function appendTextBlock( parent, label, value ) {
		if ( value === null || value === undefined || value === '' ) {
			return;
		}
		var row = document.createElement( 'div' );
		row.className = 'epay-waftest__row';

		var strong = document.createElement( 'strong' );
		strong.textContent = String( label );
		row.appendChild( strong );

		var pre = document.createElement( 'pre' );
		pre.className = 'epay-waftest__code';
		pre.textContent = String( value );
		row.appendChild( pre );

		parent.appendChild( row );
	}

	function renderHeadersBlock( parent, label, headers ) {
		if ( ! headers || typeof headers !== 'object' ) {
			return;
		}
		var keys = Object.keys( headers );
		if ( keys.length === 0 ) {
			return;
		}
		var lines = keys.map( function ( k ) {
			return String( k ) + ': ' + String( headers[ k ] );
		} );
		appendTextBlock( parent, label, lines.join( '\n' ) );
	}

	function setResultClass( box, verdict ) {
		box.className = 'epay-waftest__result';
		if ( verdict ) {
			box.classList.add( 'epay-waftest__result--' + verdict );
		}
	}

	function clearChildren( node ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	function initWafTest( strings ) {
		var config = window.epayPaycenterAdmin || {};
		var btn = document.getElementById( 'epay-waftest-run' );
		var box = document.getElementById( 'epay-waftest-result' );
		if ( ! btn || ! box ) {
			return;
		}
		if ( ! config.ajaxUrl || ! config.wafTestNonce ) {
			return;
		}

		btn.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			btn.setAttribute( 'disabled', 'disabled' );
			btn.classList.add( 'is-busy' );
			clearChildren( box );
			setResultClass( box, 'pending' );
			box.textContent = strings.wafTesting || 'Testing…';

			var body = new URLSearchParams();
			body.append( 'action', 'epay_paycenter_waf_test' );
			body.append( 'nonce', config.wafTestNonce );

			window.fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			} ).then( function ( resp ) {
				return resp.json().catch( function () {
					return { success: false, data: { message: strings.wafTransport } };
				} );
			} ).then( function ( payload ) {
				clearChildren( box );

				if ( ! payload || ! payload.success ) {
					setResultClass( box, 'error' );
					var errData = ( payload && payload.data ) || {};
					var errMsg = errData.message || strings.wafTransport || 'Error';
					var header = document.createElement( 'p' );
					header.className = 'epay-waftest__headline';
					header.textContent = errMsg;
					box.appendChild( header );
					if ( errData.detail ) {
						appendTextBlock( box, strings.wafHttpStatus || 'Detail:', errData.detail );
					}
					if ( errData.target_url ) {
						appendTextBlock( box, strings.wafTarget || 'Target URL:', errData.target_url );
					}
					return;
				}

				var data = payload.data || {};
				var verdict = data.verdict || 'pass';

				var headline = document.createElement( 'p' );
				headline.className = 'epay-waftest__headline';
				if ( verdict === 'pass' ) {
					setResultClass( box, 'pass' );
					headline.textContent = strings.wafPass || 'OK';
				} else if ( verdict === 'blocked' ) {
					setResultClass( box, 'blocked' );
					headline.textContent = strings.wafBlocked || 'Blocked';
				} else if ( verdict === 'server_error' ) {
					setResultClass( box, 'error' );
					headline.textContent = strings.wafServerError || 'Server error';
				} else if ( verdict === 'no_response' ) {
					setResultClass( box, 'error' );
					headline.textContent = strings.wafNoResponse || 'No response';
				} else {
					setResultClass( box, 'pass' );
					headline.textContent = strings.wafPass || 'OK';
				}
				box.appendChild( headline );

				appendTextBlock( box, strings.wafHttpStatus || 'HTTP status:', String( data.status || 0 ) );
				appendTextBlock( box, strings.wafTarget || 'Target URL:', data.target_url || '' );
				renderHeadersBlock( box, strings.wafHeaders || 'Headers:', data.headers );
				appendTextBlock( box, strings.wafBody || 'Body:', data.body_snippet || '' );
			} ).catch( function () {
				clearChildren( box );
				setResultClass( box, 'error' );
				var errP = document.createElement( 'p' );
				errP.className = 'epay-waftest__headline';
				errP.textContent = strings.wafNetworkErr || 'Network error.';
				box.appendChild( errP );
			} ).then( function () {
				btn.removeAttribute( 'disabled' );
				btn.classList.remove( 'is-busy' );
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
