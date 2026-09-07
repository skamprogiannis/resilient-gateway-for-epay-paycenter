/**
 * Admin settings script for the ePay Paycenter (Piraeus Bank) gateway.
 *
 * Handles:
 *  - "Copy" buttons on the Bank integration data fields.
 *  - "Test callback URL" button on the Callback diagnostics card (fires a
 *    same-origin AJAX POST to admin-ajax.php -> ajax_run_waf_test() and
 *    renders the result inline).
 *  - Read-only ePay FOLLOW_UP channel verification for a known transaction.
 *
 * No external dependencies; uses `navigator.clipboard` with a
 * `document.execCommand` fallback for older browsers, and `fetch` for the
 * AJAX call. Localised strings / nonce / ajax URL are passed in via
 * wp_localize_script as `epayPaycenterAdmin`.
 */
( function () {
	'use strict';

	/**
	 * @param {HTMLInputElement|HTMLTextAreaElement} input
	 * @returns {Promise<void>}
	 */
	function copyText( input ) {
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

	/**
	 * @param {HTMLButtonElement} btn
	 * @param {string} labelCopied
	 * @param {string} labelDefault
	 */
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
		/** @type {EpayAdminStrings} */
		var strings = ( window.epayPaycenterAdmin && window.epayPaycenterAdmin.strings ) || {};
		var labelCopy   = strings.copy   || 'Copy';
		var labelCopied = strings.copied || 'Copied';
		var labelFailed = strings.failed || 'Press Ctrl+C to copy';

		var buttons = document.querySelectorAll( '.epay-copy__btn' );
		buttons.forEach( function ( btn ) {
			if ( ! ( btn instanceof HTMLButtonElement ) ) {
				return;
			}
			btn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				var targetId = btn.getAttribute( 'data-target' );
				if ( ! targetId ) {
					return;
				}
				const input = document.getElementById( targetId );
				if ( ! ( input instanceof HTMLInputElement ) && ! ( input instanceof HTMLTextAreaElement ) ) {
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
		inputs.forEach( function ( input ) {
			if ( ! ( input instanceof HTMLInputElement ) ) {
				return;
			}
			input.addEventListener( 'focus', function () {
				input.select();
			} );
		} );

		initWafTest( strings );
		initFollowUpTest( strings );
	}

	/**
	 * @param {EpayAdminStrings} strings
	 */
	function initFollowUpTest( strings ) {
		const config = window.epayPaycenterAdmin;
		const btn = document.getElementById( 'epay-follow-up-test' );
		const input = document.getElementById( 'epay-follow-up-order' );
		const box = document.getElementById( 'epay-follow-up-result' );
		const status = document.getElementById( 'epay-follow-up-status' );
		if (
			! config ||
			! ( btn instanceof HTMLButtonElement ) ||
			! ( input instanceof HTMLInputElement ) ||
			! box ||
			! config.ajaxUrl ||
			! config.followUpNonce
		) {
			return;
		}

		btn.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			var orderId = String( input.value || '' ).trim();
			if ( ! /^\d+$/.test( orderId ) || Number( orderId ) < 1 ) {
				setResultClass( box, 'error' );
				box.textContent = strings.followUpOrder || 'Enter an order ID.';
				return;
			}

			setButtonBusy( btn, true );
			setResultClass( box, 'pending' );
			box.textContent = strings.followUpTesting || 'Checking…';

			var body = new URLSearchParams();
			body.append( 'action', 'epay_paycenter_follow_up_test' );
			body.append( 'nonce', config.followUpNonce );
			body.append( 'order_id', orderId );

			postAdminAction( config.ajaxUrl, body, strings.wafTransport || 'Invalid response.' ).then( function ( payload ) {
				clearChildren( box );
				var data = ( payload && payload.data ) || {};
				setResultClass( box, payload && payload.success ? 'pass' : 'error' );

				var headline = document.createElement( 'p' );
				headline.className = 'epay-waftest__headline';
				headline.textContent = data.message || strings.wafTransport || 'Error';
				box.appendChild( headline );

				if ( payload && payload.success ) {
					if ( status && data.status_label ) {
						status.textContent = data.status_label;
					}
					appendTextBlock( box, strings.followUpChannel || 'Verified channel:', data.channel || '' );
					appendTextBlock( box, strings.followUpMethod || 'Payment method:', data.payment_method || '' );
					appendTextBlock( box, strings.followUpCode || 'Response code:', data.response_code || '' );
					appendTextBlock( box, strings.followUpTime || 'Transaction time:', data.transaction_at || '' );
					var enabled = document.getElementById( 'woocommerce_epay_paycenter_follow_up_enabled' );
					if ( enabled instanceof HTMLInputElement ) {
						enabled.checked = false;
					}
				}
			} ).catch( function () {
				setResultClass( box, 'error' );
				box.textContent = strings.wafNetworkErr || 'Network error.';
			} ).then( function () {
				setButtonBusy( btn, false );
			} );
		} );
	}

	/**
	 * @param {string} url
	 * @param {URLSearchParams} body
	 * @param {string} invalidResponseMessage
	 * @returns {Promise<EpayAjaxResponse>}
	 */
	function postAdminAction( url, body, invalidResponseMessage ) {
		return window.fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( response ) {
			return response.json().then( function ( payload ) {
				return parseAdminResponse( payload );
			} ).catch( function () {
				return { success: false, data: { message: invalidResponseMessage } };
			} );
		} );
	}

	/**
	 * JSON is untrusted until its fields match the admin response contract.
	 *
	 * @param {unknown} value
	 * @returns {value is Record<string, unknown>}
	 */
	function isRecord( value ) {
		return typeof value === 'object' && value !== null && ! Array.isArray( value );
	}

	/**
	 * @param {unknown} payload
	 * @returns {EpayAjaxResponse}
	 */
	function parseAdminResponse( payload ) {
		if ( ! isRecord( payload ) || typeof payload.success !== 'boolean' || ! isRecord( payload.data ) ) {
			throw new Error( 'Invalid admin response.' );
		}
		/** @type {EpayAjaxData} */
		var data = {};
		/** @type {(keyof Omit<EpayAjaxData, 'headers'|'status'|'verdict'>)[]} */
		var textFields = [ 'body_snippet', 'channel', 'detail', 'message', 'payment_method', 'response_code', 'status_label', 'target_url', 'transaction_at' ];
		for ( const key of textFields ) {
			const value = payload.data[ key ];
			if ( value !== undefined && typeof value !== 'string' ) {
				throw new Error( 'Invalid admin text field.' );
			}
			data[ key ] = value;
		}
		if ( typeof payload.data.status === 'number' && Number.isInteger( payload.data.status ) ) {
			data.status = payload.data.status;
		}
		const verdict = payload.data.verdict;
		if ( verdict === 'pass' || verdict === 'blocked' || verdict === 'no_response' || verdict === 'server_error' || verdict === 'unexpected_response' ) {
			data.verdict = verdict;
		}
		if ( isRecord( payload.data.headers ) ) {
			data.headers = {};
			for ( const [ key, value ] of Object.entries( payload.data.headers ) ) {
				if ( typeof value === 'string' ) {
					data.headers[ key ] = value;
				}
			}
		}
		return { success: payload.success, data: data };
	}

	/**
	 * @param {HTMLButtonElement} button
	 * @param {boolean} busy
	 */
	function setButtonBusy( button, busy ) {
		button.toggleAttribute( 'disabled', busy );
		button.classList.toggle( 'is-busy', busy );
	}

	/**
	 * Render one labelled block of plain text inside the result box.
	 * Uses textContent throughout to avoid any HTML interpretation of
	 * server-returned values (status codes, headers, body snippets).
	 *
	 * @param {HTMLElement} parent
	 * @param {string} label
	 * @param {number|string|null|undefined} value
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

	/**
	 * @param {HTMLElement} parent
	 * @param {string} label
	 * @param {Record<string, string>|undefined} headers
	 */
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

	/**
	 * @param {HTMLElement} box
	 * @param {string} verdict
	 */
	function setResultClass( box, verdict ) {
		box.className = 'epay-waftest__result';
		if ( verdict ) {
			box.classList.add( 'epay-waftest__result--' + verdict );
		}
	}

	/** @param {HTMLElement} node */
	function clearChildren( node ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	/** @param {EpayAdminStrings} strings */
	function initWafTest( strings ) {
		const config = window.epayPaycenterAdmin;
		const btn = document.getElementById( 'epay-waftest-run' );
		const box = document.getElementById( 'epay-waftest-result' );
		if ( ! ( btn instanceof HTMLButtonElement ) || ! box ) {
			return;
		}
		if ( ! config || ! config.ajaxUrl || ! config.wafTestNonce ) {
			return;
		}

		btn.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			setButtonBusy( btn, true );
			clearChildren( box );
			setResultClass( box, 'pending' );
			box.textContent = strings.wafTesting || 'Testing…';

			var body = new URLSearchParams();
			body.append( 'action', 'epay_paycenter_waf_test' );
			body.append( 'nonce', config.wafTestNonce );

			postAdminAction( config.ajaxUrl, body, strings.wafTransport || 'Invalid response.' ).then( function ( payload ) {
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
				var verdict = data.verdict || 'unexpected_response';

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
					setResultClass( box, 'error' );
					headline.textContent = strings.wafUnexpected || 'The callback handler could not be verified.';
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
				setButtonBusy( btn, false );
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
