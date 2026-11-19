/**
 * Babilim admin screens: a POST form is sent in the background and the
 * page's .wrap is replaced by the one in the answer, so a click never
 * changes the page. Plain DOM, no dependencies besides wp.a11y. Every form
 * works without this file (it posts to admin-post.php and redirects back);
 * this only makes the same round trip without a page load.
 *
 * Confirmations and multi-step screens open in one native modal <dialog>
 * (showModal(): the browser supplies focus containment, Esc and returning
 * focus to the opener; this file adds no focus trap and no Esc handler).
 *
 * Strings: window.babilimAdmin.strings { working, failed, confirm, cancel,
 * close }, printed inline by Babilim_Admin::enqueue_admin_assets().
 *
 * Attributes read from the submitting button:
 *   data-babilim-confirm        Question asked in a dialog before the form is sent.
 *   data-babilim-confirmed      Adds a hidden confirmed=1 field to the form.
 *   data-babilim-busy-label     Replaces the button text while the request runs.
 *
 * Attributes read from a link:
 *   data-babilim-dialog-link    Fetch the link's page and show its .wrap in
 *                               the dialog; a failed fetch follows the link.
 * Attributes read from a .wrap in an answer:
 *   data-babilim-dialog         The answer is a screen for the dialog (the
 *                               page behind it is not touched). Any answer
 *                               without it closes the dialog and replaces
 *                               the page's .wrap as usual.
 *
 * Attributes read from a form (or an element around it):
 *   data-babilim-native         Leave the form alone: it is sent the browser's
 *                               own way (checkout opens a new tab).
 * Attributes read from a form:
 *   data-babilim-autosubmit     Submits the form by itself after a moment (the
 *                               upload progress screen's Continue form).
 *                               Closing the dialog stops it.
 *   data-babilim-autosubmit-delay  Milliseconds to wait before that submit
 *                               (default 500; longer while the service asks to back off).
 *   data-babilim-poll-url       On the checkout form: after it is submitted (it
 *                               opens in a new tab), asks this address every 5
 *                               seconds for up to 10 minutes and reloads the
 *                               page once the site reports a paid license.
 *
 * Only method="post" forms are sent in place; GET forms (filters, search)
 * go the browser's way. A failed send leaves the page as it was, shows one
 * error notice and never retries or reloads by itself.
 */
( function () {
	'use strict';

	var WRAP = '#wpbody-content .wrap';
	var pollTimer = null;
	var pollRun = 0;
	var lastError = null;
	// One in-place send at a time: a second result would otherwise land on a
	// .wrap that the first answer already replaced.
	var busy = false;
	// The one open dialog, and a counter that goes up whenever a dialog
	// closes: a timer or an answer that began before then is dropped.
	var dialog = null;
	var dialogRun = 0;
	// The button whose confirm dialog was just accepted: its submit goes through.
	var accepted = null;

	function stopLicensePolling() {
		pollRun++;
		if ( null !== pollTimer ) {
			window.clearTimeout( pollTimer );
			pollTimer = null;
		}
	}

	function startLicensePolling( url ) {
		stopLicensePolling();
		var started = Date.now();
		// One request at a time: the next one is scheduled only after the
		// previous has finished, so a slow answer never piles requests up.
		var run = pollRun;
		function schedule() {
			if ( run === pollRun ) {
				pollTimer = window.setTimeout( tick, 5000 );
			}
		}
		function tick() {
			if ( Date.now() - started > 600000 ) {
				stopLicensePolling();
				return;
			}
			window.fetch( url )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					if ( json && json.success && json.data && 'paid' === json.data.kind ) {
						stopLicensePolling();
						window.location.reload();
					}
				} )
				.catch( function () {
					// Ignored: the next tick tries again.
				} )
				.then( schedule );
		}
		pollTimer = window.setTimeout( tick, 5000 );
	}

	function strings() {
		return ( window.babilimAdmin && window.babilimAdmin.strings ) || {};
	}

	// wp.a11y's live regions sit in <body>, which showModal() makes inert, so
	// nothing written there is read while a dialog is open. Every dialog
	// therefore carries its own two live nodes (polite: role=status, failures:
	// role=alert), present from the moment it opens and never replaced, and
	// speak() writes into the topmost open dialog's; with none open it falls
	// back to wp.a11y. role=alert (not toggling aria-live on one node) because
	// a role that is fixed at insertion time is the reliable way to get an
	// assertive region.
	var stack = [];

	function speak( message, politeness ) {
		if ( ! message ) {
			return;
		}
		// close() clears `open` at once but its close event comes later, so a
		// dialog that was just closed must not receive the message.
		var top = null;
		for ( var i = stack.length - 1; i >= 0 && ! top; i-- ) {
			top = stack[ i ].dialog.open ? stack[ i ] : null;
		}
		if ( top ) {
			var node = 'assertive' === politeness ? top.alert : top.status;
			// The same text twice is not announced again unless it changes.
			node.textContent = node.textContent === message ? message + '\u00a0' : message;
		} else if ( window.wp && window.wp.a11y ) {
			window.wp.a11y.speak( message, politeness );
		}
	}

	// A form's hidden fields without the nonce, referer and the confirmed
	// flag (added to the old form on submit, absent from the new page): what
	// tells two forms apart across a page swap. Never the form id: cell and
	// row ids are numbered per render, so after a swap one id can be another component.
	function signature( form ) {
		return Array.prototype.filter.call( form.querySelectorAll( 'input[type=hidden]' ), function ( input ) {
			return '_wpnonce' !== input.name && '_wp_http_referer' !== input.name && 'confirmed' !== input.name;
		} ).map( function ( input ) {
			return input.name + '=' + input.value;
		} ).join( '&' );
	}

	function findButton( form, text ) {
		var sig = signature( form );
		var buttons = document.querySelectorAll( WRAP + ' button' );
		for ( var i = 0; i < buttons.length; i++ ) {
			var other = buttons[ i ].form;
			if ( other && text === buttons[ i ].textContent && sig === signature( other ) ) {
				return buttons[ i ];
			}
		}
		return null;
	}

	// Where core's common.js puts page notices: after .wp-header-end, else after
	// the first h1/h2 of the content.
	function placeAfterHeading( wrap, nodes ) {
		var anchor = wrap.querySelector( '.wp-header-end' ) || wrap.querySelector( 'h1, h2' );
		if ( anchor ) {
			anchor.after.apply( anchor, nodes );
		} else {
			wrap.prepend.apply( wrap, nodes );
		}
	}

	function hasClass( node, name ) {
		return ( ' ' + ( node.getAttribute( 'class' ) || '' ) + ' ' ).indexOf( ' ' + name + ' ' ) > -1;
	}

	function armAutosubmit( root ) {
		var form = root.querySelector( 'form[data-babilim-autosubmit]' );
		if ( ! form ) {
			return;
		}
		var run = dialogRun;
		window.setTimeout( function () {
			if ( form.dataset.babilimSubmitted || run !== dialogRun ) {
				return;
			}
			form.requestSubmit();
		}, parseInt( form.getAttribute( 'data-babilim-autosubmit-delay' ) || '500', 10 ) );
	}

	// screen: the dialog showing a server screen (tracked in `dialog`; closing
	// it stops the autosubmit). A question dialog opened over it is not tracked.
	function makeDialog( screen ) {
		var d = document.createElement( 'dialog' );
		d.className = 'babilim-dialog';
		var live = { status: element( 'p', '', { role: 'status', class: 'screen-reader-text', 'data-babilim-live': '', 'data-babilim-keep': '' } ), alert: element( 'p', '', { role: 'alert', class: 'screen-reader-text', 'data-babilim-live': '', 'data-babilim-keep': '' } ) };
		live.dialog = d;
		d.appendChild( live.status );
		d.appendChild( live.alert );
		stack.push( live );
		if ( screen ) {
			// A visible way out that is not Cancel: closes, sends nothing (the
			// close handler below stops any autosubmit). Kept across content swaps.
			var out = element( 'div', '', { class: 'babilim-dialog-actions', 'data-babilim-keep': '' } );
			var closeButton = element( 'button', strings().close, { type: 'button', class: 'button' } );
			closeButton.addEventListener( 'click', function () {
				d.close();
			} );
			out.appendChild( closeButton );
			d.babilimClose = out;
		}
		d.addEventListener( 'close', function () {
			stack.splice( stack.indexOf( live ), 1 );
			if ( screen ) {
				dialogRun++;
				dialog = null;
			}
			d.remove();
		} );
		document.body.appendChild( d );
		if ( screen ) {
			dialog = d;
		}
		return d;
	}

	function element( tag, text, attrs ) {
		var node = document.createElement( tag );
		node.textContent = text;
		Object.keys( attrs || {} ).forEach( function ( key ) {
			node.setAttribute( key, attrs[ key ] );
		} );
		return node;
	}

	// Asks the question in a modal; calls yes() only when Confirm is pressed.
	// Cancel and Esc send nothing; the browser puts focus back on the opener.
	function ask( question, yes ) {
		var d = makeDialog( false );
		var title = element( 'p', question, { id: 'babilim-dialog-question' } );
		d.setAttribute( 'aria-labelledby', 'babilim-dialog-question' );
		var no = element( 'button', strings().cancel, { type: 'button', class: 'button', autofocus: '' } );
		var ok = element( 'button', strings().confirm, { type: 'button', class: 'button button-primary' } );
		no.addEventListener( 'click', function () {
			d.close( 'cancel' );
		} );
		ok.addEventListener( 'click', function () {
			d.close( 'confirm' );
		} );
		var actions = element( 'div', '', { class: 'babilim-dialog-actions' } );
		actions.appendChild( no );
		actions.appendChild( ok );
		d.appendChild( title );
		d.appendChild( actions );
		d.addEventListener( 'close', function () {
			var chosen = d.returnValue;
			d.returnValue = '';
			if ( 'confirm' === chosen ) {
				yes();
			}
		} );
		d.showModal();
	}

	// Puts a screen's .wrap content into the dialog: opens it, or swaps the
	// content of the one already open (focus then moves to the new title).
	function showScreen( next ) {
		var fresh = ! dialog;
		var d = dialog || makeDialog( true );
		var h1 = next.querySelector( 'h1' );
		var notice = next.querySelector( '.notice, .updated, .error' );
		var line = next.querySelector( '.babilim-upload-line' );
		if ( h1 ) {
			h1.setAttribute( 'id', 'babilim-dialog-title' );
			d.setAttribute( 'aria-labelledby', 'babilim-dialog-title' );
		}
		// The live nodes stay where they are; only the screen's own content is swapped.
		Array.prototype.slice.call( d.children ).forEach( function ( child ) {
			if ( ! child.hasAttribute( 'data-babilim-keep' ) ) {
				child.remove();
			}
		} );
		Array.prototype.slice.call( next.children ).forEach( function ( child ) {
			d.appendChild( child );
		} );
		if ( d.babilimClose ) {
			d.appendChild( d.babilimClose );
		}
		lastError = null;
		if ( fresh ) {
			d.showModal();
		} else if ( h1 ) {
			h1.setAttribute( 'tabindex', '-1' );
			h1.focus();
		}
		if ( notice ) {
			speak( notice.textContent.trim() );
		}
		if ( line ) {
			speak( line.textContent.trim() );
		}
		armAutosubmit( d );
	}

	// Fetches an admin page and hands back its .wrap.
	function readWrap( response ) {
		if ( ! response.ok ) {
			throw new Error( 'status ' + response.status );
		}
		return response.text().then( function ( html ) {
			var page = new window.DOMParser().parseFromString( html, 'text/html' );
			var next = page.querySelector( WRAP );
			if ( ! next ) {
				throw new Error( 'no container' );
			}
			return { page: page, next: next, url: response.url };
		} );
	}

	function sendInPlace( form, submitter, button ) {
		var wrap = document.querySelector( WRAP );
		var text = button ? button.textContent : '';
		var spinner = null;
		// A form inside the dialog reports its errors there, and its answer
		// is dropped when the dialog is dismissed meanwhile.
		var inDialog = form.closest( 'dialog' );
		var run = dialogRun;
		busy = true;
		wrap.setAttribute( 'aria-busy', 'true' );
		if ( button ) {
			var label = button.getAttribute( 'data-babilim-busy-label' );
			if ( null !== label ) {
				button.textContent = label;
			}
			// aria-disabled, not disabled: the button keeps its focus and
			// its state is announced; the page-wide busy flag is what
			// stops a second send.
			button.setAttribute( 'aria-disabled', 'true' );
			spinner = document.createElement( 'span' );
			spinner.className = 'spinner is-active';
			button.after( spinner );
		}
		speak( strings().working );

		function fail() {
			busy = false;
			wrap.removeAttribute( 'aria-busy' );
			if ( button ) {
				button.textContent = text;
				button.removeAttribute( 'aria-disabled' );
			}
			if ( spinner ) {
				spinner.remove();
			}
			if ( lastError ) {
				lastError.remove();
			}
			lastError = document.createElement( 'div' );
			lastError.className = 'notice notice-error';
			var p = document.createElement( 'p' );
			p.textContent = strings().failed;
			lastError.appendChild( p );
			placeAfterHeading( inDialog || wrap, [ lastError ] );
			speak( strings().failed, 'assertive' );
		}

		window.fetch( form.getAttribute( 'action' ) || window.location.href, {
			method: 'POST',
			body: new window.FormData( form, submitter ),
			credentials: 'same-origin',
		} )
			.then( readWrap )
			.then( function ( answer ) {
				if ( answer.next.hasAttribute( 'data-babilim-dialog' ) ) {
					// The page stays as it was: its button is itself again.
					busy = false;
					wrap.removeAttribute( 'aria-busy' );
					if ( button ) {
						button.textContent = text;
						button.removeAttribute( 'aria-disabled' );
					}
					if ( spinner ) {
						spinner.remove();
					}
					if ( ! inDialog || run === dialogRun ) {
						showScreen( answer.next );
					}
					return;
				}
				if ( dialog ) {
					dialog.close();
				}
				// Core leaves .inline (and the legacy .below-h2) notices where they
				// are, so the page behind already shows them; a notice the page
				// already has, word for word, is not added a second time.
				var present = Array.prototype.map.call( document.querySelectorAll( '#wpbody-content > .notice, #wpbody-content > .updated, #wpbody-content > .error' ), function ( n ) {
					return n.textContent;
				} );
				var notices = Array.prototype.filter.call(
					answer.page.querySelectorAll( '#wpbody-content > .notice, #wpbody-content > .updated, #wpbody-content > .error' ),
					function ( n ) {
						return ! hasClass( n, 'inline' ) && ! hasClass( n, 'below-h2' ) && present.indexOf( n.textContent ) < 0;
					}
				);
				// The plugin's own result notice sits inside the .wrap. Read it
				// before foreign page notices (core's update nag...) are moved
				// in ahead of it; those are moved but never announced.
				var first = answer.next.querySelector( '.notice, .updated, .error' );
				wrap.replaceWith( answer.next );
				busy = false;
				lastError = null;
				if ( notices.length ) {
					placeAfterHeading( answer.next, notices );
					// Core's common.js adds the dismiss buttons on this event.
					if ( window.jQuery ) {
						window.jQuery( document ).trigger( 'wp-updates-notice-added' );
					}
				}
				if ( first ) {
					speak( first.textContent.trim() );
				}
				if ( answer.url ) {
					window.history.replaceState( null, '', answer.url );
				}
				var again = button ? findButton( form, text ) : null;
				if ( ! again ) {
					again = answer.next.querySelector( 'h1' );
					if ( again ) {
						again.setAttribute( 'tabindex', '-1' );
					}
				}
				if ( again ) {
					again.focus();
				}
				armAutosubmit( document );
			} )
			.catch( fail );
	}

	document.addEventListener( 'submit',
		function ( event ) {
			var form = event.target;
			if ( ! form || 'FORM' !== form.tagName ) {
				return;
			}
			var native = form.closest( '[data-babilim-native]' ) || 'post' !== String( form.method ).toLowerCase();
			if ( busy && ! native ) {
				event.preventDefault();
				return;
			}
			var button = event.submitter || form.querySelector( 'button[type=submit]' );
			var message = button ? button.getAttribute( 'data-babilim-confirm' ) : null;
			if ( null !== message && accepted !== button ) {
				event.preventDefault();
				ask( message, function () {
					accepted = button;
					form.requestSubmit( button );
					accepted = null;
				} );
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

			if ( button && button.hasAttribute( 'data-babilim-confirmed' ) && ! form.querySelector( 'input[name=confirmed]' ) ) {
				var field = document.createElement( 'input' );
				field.type = 'hidden';
				field.name = 'confirmed';
				field.value = '1';
				form.appendChild( field );
			}

			if ( ! native && document.querySelector( WRAP ) ) {
				event.preventDefault();
				sendInPlace( form, event.submitter || null, button );
				return;
			}

			// Native send: the busy label and spinner, then the browser
			// does the rest.
			var label = button ? button.getAttribute( 'data-babilim-busy-label' ) : null;
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

	// A link that opens a screen in the dialog; anything that goes wrong
	// sends the browser to the link itself.
	document.addEventListener( 'click', function ( event ) {
		var link = event.target && event.target.closest ? event.target.closest( 'a[data-babilim-dialog-link]' ) : null;
		if ( ! link || 0 !== event.button || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey ) {
			return;
		}
		event.preventDefault();
		if ( busy ) {
			return;
		}
		var href = link.href;
		window.fetch( href, { credentials: 'same-origin' } )
			.then( readWrap )
			.then( function ( answer ) {
				if ( ! answer.next.hasAttribute( 'data-babilim-dialog' ) ) {
					throw new Error( 'not a dialog screen' );
				}
				showScreen( answer.next );
			} )
			.catch( function () {
				window.location.assign( href );
			} );
	} );

	document.addEventListener( 'DOMContentLoaded', function () {
		armAutosubmit( document );
	} );
}() );
