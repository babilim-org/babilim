/**
 * Babilim admin screens: confirm prompts and a busy state for the
 * buttons that start a request. Plain DOM, no dependencies. Every form
 * works without this file; it only adds the prompt and the busy label.
 *
 * Attributes read from the submitting button:
 *   data-babilim-confirm        Text to confirm() before the form is sent.
 *   data-babilim-confirmed      Adds a hidden confirmed=1 field to the form.
 *   data-babilim-busy-label     Replaces the button text while the request runs.
 *
 * Attribute read from a form:
 *   data-babilim-autosubmit     Submits the form by itself after a moment (the
 *                               upload progress screen's Continue form).
 *   data-babilim-poll-url       On the checkout form: after it is submitted (it
 *                               opens in a new tab), asks this address every 5
 *                               seconds for up to 10 minutes and reloads the
 *                               page once the site reports a paid license.
 */
( function () {
	'use strict';

	var pollTimer = null;

	function startLicensePolling( url ) {
		if ( null !== pollTimer ) {
			window.clearInterval( pollTimer );
		}
		var started = Date.now();
		pollTimer = window.setInterval( function () {
			if ( Date.now() - started > 600000 ) {
				window.clearInterval( pollTimer );
				pollTimer = null;
				return;
			}
			window.fetch( url, { credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					if ( json && json.success && json.data && 'paid' === json.data.kind ) {
						window.clearInterval( pollTimer );
						pollTimer = null;
						window.location.reload();
					}
				} )
				.catch( function () {
					// Ignored: the next tick tries again.
				} );
		}, 5000 );
	}

	document.addEventListener( 'submit',
		function ( event ) {
			var form = event.target;
			if ( ! form || 'FORM' !== form.tagName ) {
				return;
			}
			// Remember that this form went out, so a pending autosubmit
			// timer does not send the same batch a second time.
			form.dataset.babilimSubmitted = '1';
			var pollUrl = 'babilim-checkout-form' === form.id ? form.getAttribute( 'data-babilim-poll-url' ) : null;
			if ( pollUrl ) {
				// The form opens the checkout page in a new tab (never
				// preventDefault here); this tab polls until the site is
				// licensed, for at most ten minutes.
				startLicensePolling( pollUrl );
			}
			var button = event.submitter || form.querySelector( 'button[type=submit]' );
			if ( ! button ) {
				return;
			}

			var message = button.getAttribute( 'data-babilim-confirm' );
			if ( null !== message && ! window.confirm( message ) ) {
				event.preventDefault();
				return;
			}

			if ( button.hasAttribute( 'data-babilim-confirmed' ) ) {
				var field = document.createElement( 'input' );
				field.type = 'hidden';
				field.name = 'confirmed';
				field.value = '1';
				form.appendChild( field );
			}

			var label = button.getAttribute( 'data-babilim-busy-label' );
			if ( null !== label ) {
				button.textContent = label;
				var spinner = document.createElement( 'span' );
				spinner.className = 'spinner is-active';
				button.parentNode.insertBefore( spinner, button.nextSibling );
				// Disabled after the submit has been taken, so the browser
				// still sends the form.
				setTimeout(
					function () {
						button.disabled = true;
					},
					0
				);
			}
		},
		true
	);

	document.addEventListener( 'DOMContentLoaded', function () {
		var form = document.querySelector( 'form[data-babilim-autosubmit]' );
		if ( ! form ) {
			return;
		}
		window.setTimeout( function () {
			if ( form.dataset.babilimSubmitted ) {
				return;
			}
			if ( form.requestSubmit ) {
				form.requestSubmit();
			} else {
				form.submit();
			}
		}, 500 );
	} );
}() );
