/**
 * Babilim translation editor (admin page `babilim-editor`): a list of the
 * strings of one component + text domain + language on one side, the editing
 * area for the chosen string on the other. Plain DOM, no dependencies besides
 * wp.a11y; text always goes in through textContent / .value, never as markup.
 *
 * Why this shape: every rule (checks, history, drafts) lives on the server
 * (Babilim_Editor over REST); this file only shows the answers. It is built
 * so a request that fails never costs the visitor their text: the field is
 * never cleared, a draft is sent when a changed field loses focus, a
 * failed one arms a `beforeunload` warning, and an expired REST nonce is
 * renewed once from admin-ajax (`rest-nonce`, plain-text nonce) before the
 * same request is sent again.
 *
 * Settings: window.babilimEditor { restUrl, nonce, nonceUrl, scope, nplurals,
 * ref?, strings } (Babilim_Editor_Page::settings()); busy label and
 * failure text come from window.babilimAdmin.strings like the other admin
 * script. Shortcuts (focus in a translation field; Ctrl or the Command key):
 * Enter saves and opens the next string, Down / Up next / previous string,
 * Shift+Down / Shift+Up next / previous untranslated, B copies the original
 * into the field. The arrows act only when the caret already sits at the
 * matching end of the text with nothing selected; otherwise they keep their
 * own caret and selection meaning. On a Mac only Command+B copies (Ctrl+B
 * moves the caret there). The field lists them in aria-keyshortcuts.
 */
( function () {
	'use strict';

	const S = window.babilimEditor;
	const $ = ( id ) => document.getElementById( id );
	const els = { filter: $( 'babilim-ed-filter' ), search: $( 'babilim-ed-search' ), list: $( 'babilim-ed-list' ), pager: $( 'babilim-ed-pager' ), edit: $( 'babilim-ed-edit' ), status: $( 'babilim-ed-status' ) };
	if ( ! S || ! S.scope || ! els.list || ! els.edit || ! els.status ) {
		return;
	}
	const str = S.strings || {};
	const admin = ( window.babilimAdmin && window.babilimAdmin.strings ) || {};
	const LOGGED_OUT = { loggedOut: true };

	let items = [];
	let page = 1;
	let pages = 1;
	let nplurals = S.nplurals || 1;
	let run = 0;
	let cur = null;
	let fields = [];
	let base = [];
	let problems = [];
	let rows = [];
	let saving = false;
	let tail = Promise.resolve();
	let lastSent = null;
	let armed = false;
	let stale = false;
	let revertSel = null;
	let revertBox = null;
	let saveBtn = null;
	let spin = null;
	let searchToken = 0;
	const unsaved = {};

	const el = ( tag, text, attrs ) => {
		const node = document.createElement( tag );
		if ( text ) {
			node.textContent = text;
		}
		Object.keys( attrs || {} ).forEach( ( k ) => node.setAttribute( k, attrs[ k ] ) );
		return node;
	};
	const speak = ( message, politeness ) => {
		if ( message && window.wp && window.wp.a11y ) {
			window.wp.a11y.speak( message, politeness || 'polite' );
		}
	};
	// The status line is a role=status region that is not itself live (aria-live=off): wp.a11y.speak() announces, so nothing is read twice.
	const say = ( message, politeness ) => {
		els.status.textContent = message;
		speak( message, politeness );
	};
	const fail = ( e ) => {
		if ( e && e.loggedOut ) {
			say( str.loggedOut, 'assertive' );
		} else {
			const d = e && e.data;
			say( d && 'string' === typeof d.message && 0 === String( d.code ).indexOf( 'babilim_' ) ? d.message : admin.failed, 'assertive' );
		}
	};

	// -- REST --------------------------------------------------------------

	const scope = () => ( { kind: S.scope.kind, slug: S.scope.slug, domain: S.scope.domain, locale: S.scope.locale } );
	const send = ( path, method, params ) => {
		const init = { method, credentials: 'same-origin', headers: { 'X-WP-Nonce': S.nonce } };
		let url = S.restUrl + path;
		if ( 'GET' === method ) {
			url += ( url.includes( '?' ) ? '&' : '?' ) + new URLSearchParams( params );
		} else {
			init.headers[ 'Content-Type' ] = 'application/json';
			init.body = JSON.stringify( params );
		}
		return window.fetch( url, init ).then( ( res ) => res.json().catch( () => null ).then( ( data ) => ( { status: res.status, ok: res.ok, data } ) ) );
	};
	// core's wp_ajax_rest_nonce answers with the bare nonce (10 hex characters); anything else (0, -1, a login page) means the session is gone.
	const freshNonce = () => window.fetch( S.nonceUrl, { credentials: 'same-origin' } )
		.then( ( res ) => ( res.ok ? res.text() : '' ) )
		.then( ( text ) => {
			const nonce = text.trim();
			if ( /^[0-9a-f]{10}$/i.test( nonce ) ) {
				S.nonce = nonce;
				return true;
			}
			return false;
		} )
		.catch( () => false );
	const expired = ( r ) => 403 === r.status && r.data && 'rest_cookie_invalid_nonce' === r.data.code;
	// An expired nonce is renewed once and the same request is sent once more; a second refusal means logged out.
	const call = async ( path, method, params ) => {
		let r = await send( path, method, params );
		if ( expired( r ) ) {
			if ( ! await freshNonce() ) {
				throw LOGGED_OUT;
			}
			r = await send( path, method, params );
			if ( expired( r ) ) {
				throw LOGGED_OUT;
			}
		}
		return r;
	};
	// Writes go one after the other, so a draft sent on blur never lands after the save that follows it.
	const queue = ( fn ) => {
		const p = tail.then( fn );
		tail = p.catch( () => {} );
		return p;
	};

	// -- the list ----------------------------------------------------------

	const live = ( item ) => !! item.override && 'off' !== item.override.status;
	const marker = ( item ) => {
		if ( live( item ) ) {
			return { review: str.review, orphan: str.orphan }[ item.override.status ] || str.edited;
		}
		return { none: str.untranslated, babilim: str.babilim, wporg: str.wporg, bundled: str.bundled }[ item.source ] || '';
	};
	const shown = ( item ) => ( live( item ) ? item.override.msgstr : item.current )[ 0 ] || '';
	const blank = ( item ) => 'none' === item.source && ! live( item );
	const nav = window.navigator || {};
	const mac = /mac|iphone|ipad/i.test( ( nav.userAgentData && nav.userAgentData.platform ) || nav.platform || '' );
	const keys = 'Control+Enter Meta+Enter Control+ArrowDown Meta+ArrowDown Control+ArrowUp Meta+ArrowUp Shift+Control+ArrowDown Shift+Meta+ArrowDown Shift+Control+ArrowUp Shift+Meta+ArrowUp ' + ( mac ? 'Meta+B' : 'Control+B Meta+B' );

	const markCurrent = () => rows.forEach( ( b, i ) => {
		if ( cur && items[ i ].ref === cur.ref ) {
			b.setAttribute( 'aria-current', 'true' );
		} else {
			b.removeAttribute( 'aria-current' );
		}
	} );
	const renderList = () => {
		els.list.replaceChildren();
		rows = items.map( ( item ) => {
			const b = el( 'button', '', { type: 'button' } );
			b.appendChild( el( 'span', item.msgid.slice( 0, 100 ), { dir: 'auto' } ) );
			b.appendChild( el( 'small', shown( item ).slice( 0, 100 ), { dir: 'auto' } ) );
			b.appendChild( el( 'small', marker( item ) ) );
			b.addEventListener( 'click', () => choose( item, true ) );
			const li = el( 'li' );
			li.appendChild( b );
			els.list.appendChild( li );
			return b;
		} );
		markCurrent();
	};
	const renderPager = ( empty ) => {
		els.pager.replaceChildren();
		if ( empty ) {
			els.pager.appendChild( el( 'p', empty ) );
		}
		if ( pages < 2 ) {
			return;
		}
		const step = ( label, to ) => {
			const b = el( 'button', label, { type: 'button', class: 'button' } );
			if ( to < 1 || to > pages ) {
				b.setAttribute( 'disabled', '' );
			}
			b.addEventListener( 'click', () => load( to, 'first', false ) );
			return b;
		};
		els.pager.append( step( str.prev, page - 1 ), el( 'span', str.pageOf.replace( '%1$d', page ).replace( '%2$d', pages ) ), step( str.next, page + 1 ) );
	};

	const load = async ( p, pick, focus ) => {
		const my = ++run;
		els.list.setAttribute( 'aria-busy', 'true' );
		try {
			const r = await call( 'editor/strings', 'GET', { ...scope(), filter: els.filter ? els.filter.value || 'all' : 'all', search: els.search ? els.search.value.trim() : '', page: p } );
			if ( my !== run ) {
				return;
			}
			if ( ! r.ok ) {
				throw r;
			}
			items = r.data.items;
			page = r.data.page;
			pages = r.data.pages;
			nplurals = r.data.nplurals || nplurals;
			renderList();
			renderPager( items.length ? '' : ( r.data.no_source ? str.noSource : str.empty ) );
			if ( items.length && ( 'first' === pick || 'last' === pick ) ) {
				choose( 'first' === pick ? items[ 0 ] : items[ items.length - 1 ], focus );
			} else if ( items.length && ! cur ) {
				choose( items[ 0 ], false );
			}
		} catch ( e ) {
			if ( my === run ) {
				fail( e );
			}
		} finally {
			if ( my === run ) {
				els.list.removeAttribute( 'aria-busy' );
			}
		}
	};

	// -- the editing area --------------------------------------------------

	const values = () => fields.map( ( f ) => f.value );
	const same = ( a, b ) => a.length === b.length && a.every( ( v, i ) => v === b[ i ] );
	const forms = ( item, from ) => {
		const n = '' !== item.msgid_plural ? nplurals : 1;
		return Array.from( { length: n }, ( _, i ) => ( from[ i ] === undefined ? '' : from[ i ] ) );
	};
	const guard = ( e ) => {
		e.preventDefault();
		e.returnValue = '';
	};
	const arm = () => {
		if ( ! armed ) {
			armed = true;
			window.addEventListener( 'beforeunload', guard );
		}
	};
	// Nothing is waiting to be kept: no failed draft and the fields hold what the server has.
	const settle = () => {
		if ( armed && 0 === Object.keys( unsaved ).length && same( values(), base ) ) {
			armed = false;
			window.removeEventListener( 'beforeunload', guard );
		}
	};

	const clearProblems = ( only ) => problems.forEach( ( nodes, i ) => {
		if ( ( undefined === only || only === i ) && nodes.length ) {
			nodes.forEach( ( n ) => n.remove() );
			problems[ i ] = [];
			fields[ i ].setAttribute( 'aria-invalid', 'false' );
			fields[ i ].removeAttribute( 'aria-describedby' );
		}
	} );
	const onInput = ( i ) => {
		clearProblems( i );
		if ( ! same( values(), base ) ) {
			arm();
		}
	};

	// Leaving a changed field keeps the text as a draft on the server (never in the files).
	const draft = () => {
		const item = cur;
		const v = values();
		if ( ! item ) {
			return;
		}
		if ( same( v, base ) ) {
			delete unsaved[ item.ref ];
			settle();
			return;
		}
		const key = item.ref + '\n' + JSON.stringify( v );
		if ( lastSent === key ) {
			return;
		}
		lastSent = key;
		queue( async () => {
			try {
				const r = await call( 'editor/draft', 'POST', { ...scope(), ref: item.ref, msgstr: v } );
				if ( ! r.ok ) {
					throw r;
				}
				delete unsaved[ item.ref ];
				item.draft = v;
				if ( cur === item ) {
					base = v;
					settle();
				}
			} catch ( e ) {
				lastSent = null;
				unsaved[ item.ref ] = v;
				arm();
				if ( e && e.loggedOut ) {
					say( str.loggedOut, 'assertive' );
				} else {
					say( str.unsaved, 'assertive' );
				}
			}
		} );
	};

	const busy = ( on ) => {
		if ( on ) {
			els.edit.setAttribute( 'aria-busy', 'true' );
			speak( admin.working );
		} else {
			els.edit.removeAttribute( 'aria-busy' );
		}
		if ( ! saveBtn ) {
			return;
		}
		if ( on ) {
			saveBtn.textContent = admin.working;
			// aria-disabled, not disabled: the button keeps focus; the `saving` flag is what stops a second send.
			saveBtn.setAttribute( 'aria-disabled', 'true' );
			spin = el( 'span', '', { class: 'spinner is-active' } );
			saveBtn.after( spin );
		} else {
			saveBtn.textContent = str.save;
			saveBtn.removeAttribute( 'aria-disabled' );
			if ( spin ) {
				spin.remove();
				spin = null;
			}
		}
	};

	// Every problem goes under its own field (aria-describedby, aria-invalid); focus goes to the first one, which makes a screen reader read it.
	const showProblems = ( list, message ) => {
		const by = {};
		list.forEach( ( p ) => {
			const i = Math.min( Math.max( parseInt( p.form, 10 ) || 0, 0 ), fields.length - 1 );
			( by[ i ] = by[ i ] || [] ).push( p.message );
		} );
		const order = Object.keys( by ).map( Number ).sort( ( a, b ) => a - b );
		order.forEach( ( i ) => {
			let prev = fields[ i ];
			const ids = by[ i ].map( ( m, n ) => {
				const id = 'babilim-ed-p' + i + '-' + n;
				const node = el( 'p', m, { id, class: 'babilim-ed-problem' } );
				prev.after( node );
				prev = node;
				problems[ i ].push( node );
				return id;
			} );
			fields[ i ].setAttribute( 'aria-invalid', 'true' );
			fields[ i ].setAttribute( 'aria-describedby', ids.join( ' ' ) );
		} );
		if ( order.length ) {
			fields[ order[ 0 ] ].focus();
		}
		say( message || '', 'assertive' );
	};

	const save = async () => {
		if ( saving || ! cur ) {
			return false;
		}
		const item = cur;
		const v = values();
		// Success: what is being saved needs no draft of its own, and a change back to an earlier draft differs from it. (Failure is handled in finally.)
		lastSent = item.ref + '\n' + JSON.stringify( v );
		saving = true;
		busy( true );
		clearProblems();
		try {
			const r = await queue( () => call( 'editor/save', 'POST', { ...scope(), ref: item.ref, msgstr: v } ) );
			if ( r.ok ) {
				say( false === r.data.live ? str.notLive.replace( '%s', () => r.data.reason ) : str.saved );
				item.history = live( item ) ? ( item.history || 0 ) + 1 : item.history;
				item.override = { msgstr: v, status: 'active' };
				item.draft = null;
				delete unsaved[ item.ref ];
				if ( cur === item ) {
					base = v;
					stale = true;
					renderRevert( [] );
					settle();
				}
				renderList();
				return true;
			}
			if ( 422 === r.status && r.data && 'babilim_check_failed' === r.data.code && r.data.data && Array.isArray( r.data.data.problems ) ) {
				showProblems( r.data.data.problems, r.data.message );
			} else {
				fail( r );
			}
		} catch ( e ) {
			fail( e );
		} finally {
			// Failure: the text was not saved, so the next blur must be able to draft it again.
			lastSent = null;
			saving = false;
			busy( false );
		}
		return false;
	};

	const when = ( at ) => {
		const d = new Date( String( at ).replace( ' ', 'T' ) + 'Z' );
		return isNaN( d ) ? String( at ) : d.toLocaleString();
	};
	const renderRevert = ( history ) => {
		if ( ! revertSel ) {
			return;
		}
		const chosen = revertSel.value;
		revertSel.replaceChildren( el( 'option', str.chooseVersion, { value: '' } ), el( 'option', str.revertOriginal, { value: 'original' } ) );
		history.forEach( ( h ) => revertSel.appendChild( el( 'option', when( h.at ) + ' - ' + ( h.msgstr[ 0 ] || '' ).slice( 0, 60 ), { value: String( h.index ) } ) ) );
		// A choice made while the history was still loading stays, when that version is still there.
		revertSel.value = Array.from( revertSel.children ).some( ( o ) => o.getAttribute( 'value' ) === chosen ) ? chosen : '';
		if ( cur && ( cur.override || cur.history > 0 ) ) {
			revertBox.removeAttribute( 'hidden' );
		} else {
			revertBox.setAttribute( 'hidden', '' );
		}
	};
	const loadHistory = async ( item ) => {
		stale = false;
		try {
			const r = await call( 'editor/history', 'GET', { ...scope(), ref: item.ref } );
			if ( r.ok && cur === item ) {
				renderRevert( r.data.history || [] );
			}
		} catch ( e ) {
			// The menu keeps its two fixed entries; nothing else depends on it.
		}
	};

	const revert = async ( to ) => {
		if ( saving || ! cur ) {
			return;
		}
		if ( ! to ) {
			say( str.chooseFirst );
			return;
		}
		const item = cur;
		saving = true;
		busy( true );
		try {
			const r = await queue( () => call( 'editor/revert', 'POST', { ...scope(), ref: item.ref, to } ) );
			if ( ! r.ok ) {
				fail( r );
				return;
			}
			delete unsaved[ item.ref ];
			lastSent = null;
			const again = await call( 'editor/strings', 'GET', { ...scope(), ref: item.ref } );
			const fresh = again.ok && again.data.items[ 0 ];
			if ( fresh ) {
				items = items.map( ( x ) => ( x.ref === fresh.ref ? fresh : x ) );
				renderList();
				open( fresh, true );
			}
			// Said last: the field already shows the restored text when this is read.
			say( false === r.data.live ? str.revertedNotLive.replace( '%s', () => r.data.reason ) : str.reverted );
		} catch ( e ) {
			fail( e );
		} finally {
			saving = false;
			busy( false );
		}
	};

	const open = ( item, focus ) => {
		cur = item;
		lastSent = null;
		const plural = '' !== item.msgid_plural;
		base = forms( item, item.draft || ( live( item ) ? item.override.msgstr : item.current ) );
		const start = unsaved[ item.ref ] ? forms( item, unsaved[ item.ref ] ) : base;
		const note = ( label, text ) => {
			const p = el( 'p' );
			p.appendChild( el( 'strong', label + ': ' ) );
			p.appendChild( el( 'span', text, { dir: 'auto' } ) );
			return p;
		};
		els.edit.replaceChildren();
		els.edit.append( el( 'h3', str.source ), el( 'p', item.msgid, { class: 'babilim-ed-source', dir: 'auto' } ) );
		if ( plural ) {
			els.edit.append( el( 'h3', str.sourcePlural ), el( 'p', item.msgid_plural, { class: 'babilim-ed-source', dir: 'auto' } ) );
		}
		if ( item.msgctxt ) {
			els.edit.appendChild( note( str.context, item.msgctxt ) );
		}
		item.comments.forEach( ( c ) => els.edit.appendChild( note( str.note, c ) ) );
		fields = [];
		problems = [];
		start.forEach( ( text, i ) => {
			const id = 'babilim-ed-f' + i;
			const ta = el( 'textarea', '', { id, lang: S.scope.locale.replace( /_/g, '-' ), dir: 'auto', rows: '3', 'aria-invalid': 'false', 'aria-keyshortcuts': keys } );
			ta.value = text;
			ta.addEventListener( 'blur', draft );
			ta.addEventListener( 'input', () => onInput( i ) );
			const box = el( 'div' );
			box.append( el( 'label', plural ? str.form.replace( '%d', i + 1 ) : str.translation, { for: id } ), ta );
			els.edit.appendChild( box );
			fields.push( ta );
			problems.push( [] );
		} );
		saveBtn = el( 'button', str.save, { type: 'button', class: 'button button-primary' } );
		saveBtn.addEventListener( 'click', () => save() );
		revertSel = el( 'select', '', { id: 'babilim-ed-revert' } );
		revertSel.addEventListener( 'focus', () => {
			if ( stale && cur ) {
				loadHistory( cur );
			}
		} );
		revertBox = el( 'div' );
		// The menu only chooses (an arrow key changes it); nothing is written until the button is pressed.
		const revertBtn = el( 'button', str.revert, { type: 'button', class: 'button' } );
		revertBtn.addEventListener( 'click', () => revert( revertSel.value ) );
		revertBox.append( el( 'label', str.revertTo + ' ', { for: 'babilim-ed-revert' } ), revertSel, revertBtn );
		const actions = el( 'div', '', { class: 'babilim-ed-actions' } );
		actions.append( saveBtn, revertBox );
		els.edit.appendChild( actions );
		renderRevert( [] );
		markCurrent();
		if ( same( start, base ) ) {
			settle();
		} else {
			arm();
		}
		if ( focus && fields.length ) {
			fields[ 0 ].focus();
		}
		if ( item.history > 0 ) {
			loadHistory( item );
		}
	};

	function choose( item, focus ) {
		draft();
		open( item, focus );
	}

	// -- moving between strings --------------------------------------------

	const go = async ( delta, untranslated ) => {
		const at = items.findIndex( ( x ) => cur && x.ref === cur.ref );
		if ( untranslated ) {
			const next = delta > 0 ? items.slice( at + 1 ).find( blank ) : items.slice( 0, Math.max( at, 0 ) ).reverse().find( blank );
			if ( next ) {
				choose( next, true );
			} else {
				say( str.noMore );
			}
			return;
		}
		const to = at + delta;
		if ( to >= 0 && to < items.length ) {
			choose( items[ to ], true );
		} else if ( delta > 0 && page < pages ) {
			await load( page + 1, 'first', true );
		} else if ( delta < 0 && page > 1 ) {
			await load( page - 1, 'last', true );
		}
	};

	els.edit.addEventListener( 'keydown', ( e ) => {
		const i = fields.indexOf( e.target );
		if ( i < 0 || e.isComposing || ! ( e.ctrlKey || e.metaKey ) ) {
			return;
		}
		const key = String( e.key );
		if ( 'Enter' === key ) {
			e.preventDefault();
			save().then( ( ok ) => ok && go( 1, false ) );
		} else if ( 'ArrowDown' === key || 'ArrowUp' === key ) {
			const f = fields[ i ];
			const down = 'ArrowDown' === key;
			// Only from the matching end with nothing selected; anywhere else the key moves the caret or the selection.
			if ( f.selectionStart !== f.selectionEnd || f.selectionStart !== ( down ? f.value.length : 0 ) ) {
				return;
			}
			e.preventDefault();
			go( down ? 1 : -1, !! e.shiftKey );
		} else if ( 'b' === key.toLowerCase() && ( e.metaKey || ! mac ) ) {
			e.preventDefault();
			fields[ i ].value = 0 === i || '' === cur.msgid_plural ? cur.msgid : cur.msgid_plural;
			onInput( i );
		}
	} );

	if ( els.filter ) {
		els.filter.addEventListener( 'change', () => load( 1 ) );
	}
	if ( els.search ) {
		els.search.addEventListener( 'input', () => {
			const token = ++searchToken;
			window.setTimeout( () => {
				if ( token === searchToken ) {
					load( 1 );
				}
			}, 300 );
		} );
	}

	( async () => {
		if ( S.ref ) {
			try {
				const r = await call( 'editor/strings', 'GET', { ...scope(), ref: S.ref } );
				if ( r.ok && r.data.items[ 0 ] ) {
					nplurals = r.data.nplurals || nplurals;
					open( r.data.items[ 0 ], false );
				}
			} catch ( e ) {
				fail( e );
			}
		}
		await load( 1 );
	} )();
}() );
