/**
 * Babilim "Report translation" toolbar panel: search the translations this
 * site has installed, pick one, add a category / suggestion / note, preview
 * what will be sent, and send it. Plain DOM, no dependencies. Every text is
 * set with textContent (never innerHTML). The panel is built on the first
 * click on the toolbar entry. Configuration comes from window.babilimReport.
 */
( function () {
	'use strict';

	var cfg = window.babilimReport;
	if ( ! cfg ) {
		return;
	}
	var S = cfg.strings || {};
	var panel = null;
	var body = null;
	var selected = null;
	var opener = null;

	var SHOW_MAX = 300;
	var MIN_QUERY = 2;

	// Shortens a string for display only; what is sent is never shortened.
	function shown( text ) {
		text = String( text );
		return text.length > SHOW_MAX ? text.slice( 0, SHOW_MAX ) + '\u2026' : text;
	}

	function el( tag, text, attrs ) {
		var node = document.createElement( tag );
		if ( text ) {
			node.textContent = text;
		}
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( key ) {
				node.setAttribute( key, attrs[ key ] );
			} );
		}
		return node;
	}

	function clear( node ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	function post( action, nonce, fields ) {
		var data = new window.URLSearchParams();
		data.append( 'action', action );
		data.append( '_ajax_nonce', nonce );
		Object.keys( fields ).forEach( function ( key ) {
			data.append( key, fields[ key ] );
		} );
		return window.fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: data.toString()
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function message( text ) {
		clear( body );
		body.appendChild( el( 'p', text ) );
		var close = el( 'button', S.close, { type: 'button', 'class': 'button' } );
		close.addEventListener( 'click', closePanel );
		body.appendChild( close );
	}

	function closePanel() {
		if ( panel && panel.parentNode ) {
			panel.parentNode.removeChild( panel );
		}
		panel = null;
		body = null;
		selected = null;
		if ( opener && opener.focus && document.body.contains( opener ) ) {
			opener.focus();
		}
		opener = null;
	}

	// Keeps Tab and Shift+Tab inside the open dialog.
	function trapTab( event ) {
		var items = panel.querySelectorAll( 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])' );
		if ( ! items.length ) {
			event.preventDefault();
			panel.focus();
			return;
		}
		var first = items[ 0 ];
		var last = items[ items.length - 1 ];
		var active = document.activeElement;
		if ( event.shiftKey && ( active === first || active === panel || ! panel.contains( active ) ) ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && ( active === last || ! panel.contains( active ) ) ) {
			event.preventDefault();
			first.focus();
		}
	}

	function errorText( json ) {
		var code = json && json.data ? json.data.code : '';
		if ( 'babilim_unprocessable' === code ) {
			return S.unprocessable;
		}
		if ( 'babilim_rate_limited' === code ) {
			return S.rate_limited;
		}
		if ( json && json.data && json.data.message ) {
			return json.data.message;
		}
		return S.failed;
	}

	function showSearch() {
		selected = null;
		clear( body );
		var form = el( 'form' );
		var label = el( 'label', S.search_label, { 'for': 'babilim-report-q' } );
		var input = el( 'input', '', { type: 'search', id: 'babilim-report-q', maxlength: '200' } );
		var button = el( 'button', S.search, { type: 'submit', 'class': 'button' } );
		form.appendChild( label );
		form.appendChild( input );
		form.appendChild( button );
		var results = el( 'div' );
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			clear( results );
			if ( Array.from( input.value.trim() ).length < MIN_QUERY ) {
				results.appendChild( el( 'p', S.query_too_short ) );
				return;
			}
			post( 'babilim_translation_search', cfg.searchNonce, { q: input.value } ).then( function ( json ) {
				if ( ! json || ! json.success ) {
					results.appendChild( el( 'p', S.search_failed ) );
					return;
				}
				if ( json.data.no_locale ) {
					results.appendChild( el( 'p', S.no_locale ) );
					return;
				}
				if ( ! json.data.candidates.length ) {
					results.appendChild( el( 'p', S.no_results ) );
					return;
				}
				json.data.candidates.forEach( function ( candidate ) {
					results.appendChild( candidateRow( candidate ) );
				} );
			} ).catch( function () {
				results.appendChild( el( 'p', S.search_failed ) );
			} );
		} );
		body.appendChild( form );
		body.appendChild( results );
	}

	function candidateRow( candidate ) {
		var row = el( 'div', '', { 'class': 'babilim-report-candidate' } );
		row.appendChild( el( 'strong', candidate.label ) );
		row.appendChild( el( 'div', shown( candidate.msgid ) ) );
		var forms = el( 'ol' );
		candidate.msgstr.forEach( function ( form ) {
			forms.appendChild( el( 'li', shown( form ) ) );
		} );
		row.appendChild( forms );
		var choose = el( 'button', S.choose, { type: 'button', 'class': 'button' } );
		choose.addEventListener( 'click', function () {
			selected = candidate;
			showForm();
		} );
		row.appendChild( choose );
		return row;
	}

	function showForm() {
		clear( body );
		body.appendChild( el( 'strong', selected.label ) );
		body.appendChild( el( 'p', S.original + ': ' + shown( selected.msgid ) ) );
		body.appendChild( el( 'p', S.translation + ': ' + shown( selected.msgstr.join( ' | ' ) ) ) );

		var category = el( 'select', '', { id: 'babilim-report-category' } );
		cfg.categories.forEach( function ( value ) {
			var option = el( 'option', S[ 'category_' + value ] || value, { value: value } );
			if ( 'other' === value ) {
				option.selected = true;
			}
			category.appendChild( option );
		} );
		var suggested = el( 'textarea', '', { id: 'babilim-report-suggested', rows: '2' } );
		var note = el( 'textarea', '', { id: 'babilim-report-note', rows: '3' } );

		body.appendChild( el( 'label', S.category, { 'for': 'babilim-report-category' } ) );
		body.appendChild( category );
		body.appendChild( el( 'label', S.suggested, { 'for': 'babilim-report-suggested' } ) );
		body.appendChild( suggested );
		body.appendChild( el( 'label', S.note, { 'for': 'babilim-report-note' } ) );
		body.appendChild( note );

		var back = el( 'button', S.back, { type: 'button', 'class': 'button' } );
		back.addEventListener( 'click', showSearch );
		var preview = el( 'button', S.preview, { type: 'button', 'class': 'button button-primary' } );
		preview.addEventListener( 'click', function () {
			showPreview( {
				category: category.value,
				category_label: category.options[ category.selectedIndex ].textContent,
				suggested: suggested.value,
				note: note.value
			} );
		} );
		body.appendChild( back );
		body.appendChild( preview );
	}

	function showPreview( extra ) {
		clear( body );
		body.appendChild( el( 'p', S.preview_intro ) );
		var list = el( 'ul' );
		[
			[ S.field_kind, selected.kind ],
			[ S.field_slug, selected.slug ],
			[ S.field_locale, selected.locale ],
			[ S.field_build, selected.build_id ],
			[ S.field_context, shown( selected.msgctxt ) ],
			[ S.original, shown( selected.msgid ) ],
			[ S.translation, shown( selected.msgstr.join( ' | ' ) ) ],
			[ S.category, extra.category_label ],
			[ S.suggested, extra.suggested ],
			[ S.note, extra.note ]
		].forEach( function ( pair ) {
			if ( pair[ 1 ] ) {
				list.appendChild( el( 'li', pair[ 0 ] + ': ' + pair[ 1 ] ) );
			}
		} );
		body.appendChild( list );
		body.appendChild( el( 'p', S.processed ) );

		var back = el( 'button', S.back, { type: 'button', 'class': 'button' } );
		back.addEventListener( 'click', showForm );
		var send = el( 'button', S.send, { type: 'button', 'class': 'button button-primary' } );
		send.addEventListener( 'click', function () {
			send.disabled = true;
			post( 'babilim_translation_report', cfg.reportNonce, {
				kind: selected.kind,
				slug: selected.slug,
				locale: selected.locale,
				build_id: selected.build_id,
				string_ref: selected.ref,
				category: extra.category,
				suggested: extra.suggested,
				note: extra.note
			} ).then( function ( json ) {
				message( json && json.success ? S.sent : errorText( json ) );
			} ).catch( function () {
				message( S.failed );
			} );
		} );
		body.appendChild( back );
		body.appendChild( send );
	}

	function openPanel( trigger ) {
		if ( panel ) {
			return;
		}
		panel = el( 'div', '', {
			id: 'babilim-report-panel',
			role: 'dialog',
			'aria-modal': 'true',
			'aria-labelledby': 'babilim-report-title',
			tabindex: '-1',
			style: 'position:fixed;top:40px;right:20px;z-index:100000;width:380px;max-width:calc(100vw - 40px);box-sizing:border-box;max-height:80vh;overflow:auto;background:#fff;color:#1d2327;border:1px solid #c3c4c7;box-shadow:0 2px 8px rgba(0,0,0,.2);padding:12px;'
		} );
		var head = el( 'div', '', { style: 'display:flex;flex-wrap:wrap;justify-content:space-between;align-items:baseline;gap:8px;margin-bottom:8px;' } );
		head.appendChild( el( 'strong', S.title, { id: 'babilim-report-title' } ) );
		var close = el( 'button', S.close, { type: 'button', 'class': 'button-link' } );
		close.addEventListener( 'click', closePanel );
		head.appendChild( close );
		panel.appendChild( head );
		body = el( 'div' );
		panel.appendChild( body );
		document.body.appendChild( panel );
		opener = trigger || null;
		showSearch();
		var first = panel.querySelector( 'input' );
		( first || panel ).focus();
	}

	document.addEventListener( 'keydown', function ( event ) {
		if ( ! panel ) {
			return;
		}
		if ( 'Escape' === event.key ) {
			closePanel();
		} else if ( 'Tab' === event.key ) {
			trapTab( event );
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		var target = event.target && event.target.closest ? event.target.closest( '#wp-admin-bar-babilim-report > a' ) : null;
		if ( target ) {
			event.preventDefault();
			openPanel( target );
		}
	} );
}() );
