/**
 * Settings screen behaviour.
 *
 * Everything here is an enhancement. The panel switcher is a list of real links
 * and every panel is in the DOM, so with JavaScript off the screen still works:
 * a click loads the same page at ?tab=<panel>, and the form still submits every
 * field. The setup assistant's steps are real links too. This file removes page
 * loads; it does not carry behaviour that exists nowhere else.
 *
 * The one exception is the connection test, which has no non-JavaScript
 * equivalent — and says so on the screen rather than rendering a button that
 * would do nothing.
 *
 * Configuration (ajaxUrl, nonce, action, labels) comes from wp_localize_script.
 */
( function () {
	'use strict';

	var config = window.mcpConnectorAdmin || {};

	document.addEventListener( 'DOMContentLoaded', function () {
		var root = document.querySelector( '.mcpc' );

		if ( ! root ) {
			return;
		}

		setUpPanels( root );
		setUpCopyButtons();
		setUpConnectionTest();
	} );

	/* ------------------------------------------------------------- panels */

	function setUpPanels( root ) {
		var tabs = Array.prototype.slice.call( root.querySelectorAll( '[role="tab"]' ) );
		var saveBar = root.querySelector( '.mcpc__savebar' );
		var quiet = ( config.panelsWithoutSettings || '' ).split( ',' );

		if ( ! tabs.length ) {
			return;
		}

		function activate( name, moveFocus ) {
			var matched = false;

			tabs.forEach( function ( tab ) {
				var isTarget = tab.dataset.panel === name;
				var panel = document.getElementById( 'mcpc-panel-' + tab.dataset.panel );

				tab.setAttribute( 'aria-selected', isTarget ? 'true' : 'false' );
				tab.tabIndex = isTarget ? 0 : -1;

				if ( panel ) {
					panel.hidden = ! isTarget;
				}

				if ( isTarget ) {
					matched = true;

					if ( moveFocus ) {
						tab.focus();
					}
				}
			} );

			if ( ! matched ) {
				return;
			}

			root.dataset.activePanel = name;

			// A panel that holds no setting has nothing to save; offering the
			// button there invites a save nobody asked for.
			if ( saveBar ) {
				saveBar.hidden = quiet.indexOf( name ) !== -1;
			}

			rememberPanel( name );
		}

		/**
		 * Keep the panel in the address bar, and in the referer WordPress
		 * redirects to after saving — otherwise saving always lands back on
		 * the first panel, which is exactly where the user was not.
		 */
		function rememberPanel( name ) {
			var url = new URL( window.location.href );
			url.searchParams.set( 'tab', name );
			window.history.replaceState( null, '', url.toString() );

			document.querySelectorAll( 'input[name="_wp_http_referer"]' ).forEach( function ( referer ) {
				var target = new URL( referer.value, window.location.origin );
				target.searchParams.set( 'tab', name );
				referer.value = target.pathname + target.search;
			} );
		}

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( event ) {
				// Let modified clicks open a new tab, as any link should.
				if ( event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0 ) {
					return;
				}

				event.preventDefault();
				activate( tab.dataset.panel, false );
			} );

			tab.addEventListener( 'keydown', function ( event ) {
				var index = tabs.indexOf( tab );
				var next = null;

				if ( event.key === 'ArrowDown' || event.key === 'ArrowRight' ) {
					next = tabs[ ( index + 1 ) % tabs.length ];
				} else if ( event.key === 'ArrowUp' || event.key === 'ArrowLeft' ) {
					next = tabs[ ( index - 1 + tabs.length ) % tabs.length ];
				} else if ( event.key === 'Home' ) {
					next = tabs[ 0 ];
				} else if ( event.key === 'End' ) {
					next = tabs[ tabs.length - 1 ];
				}

				if ( next ) {
					event.preventDefault();
					activate( next.dataset.panel, true );
				}
			} );
		} );

		// The server already rendered the right panel; sync the save bar and
		// the referer without touching focus.
		activate( root.dataset.activePanel || tabs[ 0 ].dataset.panel, false );

		window.addEventListener( 'popstate', function () {
			var requested = new URL( window.location.href ).searchParams.get( 'tab' );
			activate( requested || tabs[ 0 ].dataset.panel, false );
		} );
	}

	/* --------------------------------------------------- connection test */

	/**
	 * Exercise the whole chain and report which link broke.
	 *
	 * The run happens in one request rather than one per step: the steps share
	 * a throwaway client and an authorization code that expires in minutes, and
	 * splitting them across round trips would mean holding that state somewhere
	 * between calls. So the timings shown are measured server-side and painted
	 * on arrival — the list is not animated to look busier than it was.
	 */
	function setUpConnectionTest() {
		var button = document.getElementById( 'mcpc-run-test' );
		var list = document.getElementById( 'mcpc-test-steps' );
		var status = document.getElementById( 'mcpc-test-status' );

		if ( ! button || ! list || ! status || ! config.ajaxUrl ) {
			return;
		}

		button.hidden = false;

		button.addEventListener( 'click', function () {
			button.disabled = true;
			button.dataset.state = 'loading';
			status.dataset.state = '';
			status.textContent = config.testing || '';
			list.hidden = false;
			list.replaceChildren();

			window.fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams( {
					action: config.testAction,
					_ajax_nonce: config.testNonce
				} )
			} )
				.then( function ( response ) {
					return response.json().catch( function () {
						return null;
					} );
				} )
				.then( function ( payload ) {
					var data = ( payload && payload.data ) || {};
					var steps = data.steps || [];

					steps.forEach( function ( step ) {
						list.appendChild( renderStep( step ) );
					} );

					var ok = !! ( payload && payload.success );

					status.dataset.state = ok ? 'success' : 'error';
					status.textContent = data.message || config.testFailed || '';
				} )
				.catch( function ( error ) {
					status.dataset.state = 'error';
					status.textContent = error.message || config.testFailed || '';
				} )
				.finally( function () {
					button.disabled = false;
					delete button.dataset.state;
				} );
		} );
	}

	/**
	 * One reported step as a list item.
	 *
	 * Built with createElement rather than innerHTML: the detail carries server
	 * messages, and one of them is a client-supplied error string.
	 */
	function renderStep( step ) {
		var item = document.createElement( 'li' );
		item.className = 'mcpc-test__step';
		item.dataset.state = step.state || 'skipped';

		var marker = document.createElement( 'span' );
		marker.className = 'mcpc-test__marker';
		marker.setAttribute( 'aria-hidden', 'true' );
		marker.textContent = markerFor( step.state );
		item.appendChild( marker );

		var body = document.createElement( 'span' );
		body.className = 'mcpc-test__label';
		body.textContent = step.label || '';

		if ( step.detail ) {
			var detail = document.createElement( 'span' );
			detail.className = 'mcpc-test__detail';
			detail.textContent = step.detail;
			body.appendChild( detail );
		}

		item.appendChild( body );

		if ( step.ms || step.ms === 0 ) {
			var timing = document.createElement( 'span' );
			timing.className = 'mcpc-test__timing';
			timing.textContent = step.ms + ' ms';
			item.appendChild( timing );
		}

		return item;
	}

	function markerFor( state ) {
		if ( state === 'pass' ) {
			return '✓';
		}

		if ( state === 'fail' ) {
			return '✗';
		}

		return '·';
	}

	/* ------------------------------------------------------------- copy */

	function setUpCopyButtons() {
		document.querySelectorAll( '.mcpc-copy' ).forEach( function ( button ) {
			var original = button.textContent;

			button.addEventListener( 'click', function () {
				var source = button.dataset.copyFrom
					? document.getElementById( button.dataset.copyFrom )
					: null;
				var value = source ? source.value : ( button.dataset.copy || '' );

				write( value )
					.then( function () {
						button.textContent = config.copied || 'Copied';
					} )
					.catch( function () {
						button.textContent = config.copyFailed || '';
					} )
					.finally( function () {
						window.setTimeout( function () {
							button.textContent = original;
						}, 1600 );
					} );
			} );
		} );

		// Selecting the whole URL on focus makes the keyboard route as short as
		// the button: tab to it, Ctrl+C.
		document.querySelectorAll( '.mcpc-address__value' ).forEach( function ( field ) {
			field.addEventListener( 'focus', function () {
				field.select();
			} );
		} );
	}

	/**
	 * navigator.clipboard is unavailable outside a secure context, which a
	 * local WordPress on plain http very often is.
	 */
	function write( value ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( value );
		}

		return new Promise( function ( resolve, reject ) {
			var field = document.createElement( 'textarea' );
			field.value = value;
			field.setAttribute( 'readonly', '' );
			field.style.position = 'fixed';
			field.style.opacity = '0';
			document.body.appendChild( field );
			field.select();

			try {
				document.execCommand( 'copy' ) ? resolve() : reject( new Error( 'copy rejected' ) );
			} catch ( error ) {
				reject( error );
			} finally {
				document.body.removeChild( field );
			}
		} );
	}
} )();
