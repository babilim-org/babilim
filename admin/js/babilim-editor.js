/**
 * Babilim translation editor (admin page `babilim-editor`): a workbench with
 * the strings of one component + text domain + language in a list above and
 * the chosen string below (fields, Details, History). Plain DOM, no
 * dependencies besides wp.a11y and babilimAdmin.ask(); text always goes in
 * through textContent / .value, never as markup; icons are core dashicons.
 *
 * Why this shape: every rule (checks, drafts, publish, history) lives on the
 * server (Babilim_Editor over REST); this file only shows the answers.
 * Typing keeps a draft on the server -- 1500 ms after the last key, when a
 * field is left, when another string is chosen, when the page is hidden,
 * never while an input method is composing -- and Save publishes every draft
 * in one request. A request that fails never costs the person their text: a
 * field is never cleared, a draft that did not reach the server arms
 * `beforeunload` (a stored one needs no warning: reload and it is still
 * there), and an expired REST nonce is renewed once from admin-ajax
 * (`rest-nonce`) before the same request is sent again. Every request asks
 * for the user's language (`_locale=user` and a JSON Accept) so the server's
 * sentences follow the person, not the site.
 *
 * A string with `review` (its original changed in an update) gets a panel above
 * the field: old and new original with the difference marked (<del>/<ins>),
 * and one button per choice; every choice just puts a text in the field and
 * stores it as a draft, so Save is still what makes it live. A string that is
 * no longer used (status `orphan`) is read-only with one Delete button, the
 * only write that skips Save (editor/remove).
 *
 * Settings: window.babilimEditor { restUrl, nonce, nonceUrl, scope, nplurals,
 * localeName, component {name, version}, ref?, strings } (Babilim_Editor_Page::settings()); the busy
 * label and the failure text come from window.babilimAdmin.strings.
 * Shortcuts (focus in a translation field; Ctrl or the Command key): Enter
 * stores the draft and opens the next string, Down / Up next / previous
 * string, Shift+Down / Shift+Up next / previous untranslated, B copies the
 * original, K clears the field. The arrows act only when the caret already
 * sits at the matching end of the text with nothing selected. On a Mac only
 * Command+B / Command+K act (Ctrl+B moves the caret, Ctrl+K deletes to the end
 * of the line). The field lists them in aria-keyshortcuts.
 */
( function () {
	'use strict';

	const S = window.babilimEditor;
	const $ = ( id ) => document.getElementById( id );
	const els = {};
	[ 'filter', 'search', 'sort', 'source', 'component', 'locale', 'scope-form', 'list', 'pager', 'edit', 'aside', 'status', 'progress', 'pending', 'publish', 'review' ].forEach( ( k ) => { els[ k ] = $( 'babilim-ed-' + k ); } );
	els.discardAll = $( 'babilim-ed-discard-all' );
	if ( ! S || ! S.scope || Object.values( els ).some( ( x ) => ! x ) ) {
		return;
	}
	const str = S.strings || {};
	const admin = ( window.babilimAdmin && window.babilimAdmin.strings ) || {};
	const LOGGED_OUT = { loggedOut: true };
	const FILTERS = [ 'all', 'untranslated', 'edited', 'problems', 'review' ];
	const lang = S.scope.locale.replace( /_/g, '-' );

	let items = [];
	let page = 1;
	let pages = 1;
	let nplurals = S.nplurals || 1;
	let examples = [];
	let filter = 'all';
	let run = 0;
	let cur = null;
	let base = [];
	let hist = [];
	let fields = [];
	let tabs = [];
	let panels = [];
	let tabLabel = [];
	let problems = [];
	let rows = [];
	let stop = 0;
	let box = null;
	let discardBtn = null;
	let saving = false;
	let armed = false;
	let composing = false;
	let lastSent = null;
	let typeToken = 0;
	let searchToken = 0;
	let sending = null;
	let flying = null;
	let spin = null;
	let orphan = null;
	let fieldLabel = null;
	let pend = { drafts: 0, problems: 0, save: str.save, note: '', fix: '', discard: '' };
	const waiting = new Map();
	const unsaved = {};

	const el = ( tag, text, attrs ) => {
		const node = document.createElement( tag );
		if ( text ) {
			node.textContent = text;
		}
		Object.keys( attrs || {} ).forEach( ( k ) => node.setAttribute( k, attrs[ k ] ) );
		return node;
	};
	const icon = ( name ) => el( 'span', '', { class: 'dashicons dashicons-' + name, 'aria-hidden': 'true' } );
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
	const send = ( path, method, params, keepalive ) => {
		const init = { method, credentials: 'same-origin', headers: { 'X-WP-Nonce': S.nonce, Accept: 'application/json' } };
		let url = S.restUrl + path;
		const join = () => ( url.includes( '?' ) ? '&' : '?' );
		if ( 'GET' === method ) {
			url += join() + new URLSearchParams( { ...params, _locale: 'user' } );
		} else {
			url += join() + '_locale=user';
			init.headers[ 'Content-Type' ] = 'application/json';
			init.body = JSON.stringify( params );
		}
		const go = ( opts ) => window.fetch( url, opts ).then( ( res ) => res.json().catch( () => null ).then( ( data ) => ( { status: res.status, ok: res.ok, data } ) ) );
		// A keepalive request outlives the page; the browser refuses one whose body is too big (all in-flight keepalive bodies together may not pass 64 KiB, Fetch spec) by rejecting the fetch: it then goes again without keepalive.
		return keepalive ? go( { ...init, keepalive: true } ).catch( () => go( init ) ) : go( init );
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
	const call = async ( path, method, params, keepalive ) => {
		let r = await send( path, method, params, keepalive );
		if ( expired( r ) ) {
			if ( ! await freshNonce() ) {
				throw LOGGED_OUT;
			}
			r = await send( path, method, params, keepalive );
			if ( expired( r ) ) {
				throw LOGGED_OUT;
			}
		}
		return r;
	};

	// -- what the list and the details say -----------------------------------

	const live = ( item ) => !! item.override && 'off' !== item.override.status;
	const isOrphan = ( item ) => !! item.override && 'orphan' === item.override.status;
	const blank = ( item ) => 'none' === item.source && ! live( item );
	const serverText = ( item ) => item.draft || ( live( item ) ? item.override.msgstr : item.current );
	const catalogName = ( item ) => ( { babilim: str.srcBabilim, wporg: str.srcWporg, bundled: str.srcBundled }[ item.source ] || str.srcNone );
	const srcName = ( item ) => ( live( item ) ? str.srcYours : catalogName( item ) );
	const chipOf = ( item ) => ( live( item ) ? str.chipYours : ( { babilim: str.chipBabilim, wporg: str.chipWporg, bundled: str.chipBundled }[ item.source ] || '' ) );
	// The first reason that holds: [ dashicon, class, words ].
	const statusOf = ( item ) => {
		if ( item.draft ) {
			return item.problems.length ? [ 'warning', 'problem', str.stDraftProblem ] : [ 'star-filled', 'draft', str.stDraft ];
		}
		if ( item.review ) {
			return [ 'update', 'review', str.review ];
		}
		if ( isOrphan( item ) ) {
			return [ 'hidden', 'orphan', str.orphan ];
		}
		return blank( item ) ? [ 'marker', 'none', str.untranslated ] : null;
	};
	const nav = window.navigator || {};
	const mac = /mac|iphone|ipad/i.test( ( nav.userAgentData && nav.userAgentData.platform ) || nav.platform || '' );
	const keys = 'Control+Enter Meta+Enter Control+ArrowDown Meta+ArrowDown Control+ArrowUp Meta+ArrowUp Shift+Control+ArrowDown Shift+Meta+ArrowDown Shift+Control+ArrowUp Shift+Meta+ArrowUp ' + ( mac ? 'Meta+B Meta+K' : 'Control+B Meta+B Control+K Meta+K' );
	const when = ( at ) => {
		const d = new Date( String( at ).replace( ' ', 'T' ) + 'Z' );
		return isNaN( d ) ? String( at ) : d;
	};
	const forms = ( item, from ) => {
		const n = '' !== item.msgid_plural ? nplurals : 1;
		return Array.from( { length: n }, ( _, i ) => ( from[ i ] === undefined ? '' : from[ i ] ) );
	};
	const values = () => fields.map( ( f ) => f.value );
	const same = ( a, b ) => a.length === b.length && a.every( ( v, i ) => v === b[ i ] );
	const dirty = () => !! cur && ! same( values(), base );

	// -- the list ----------------------------------------------------------

	const markCurrent = () => rows.forEach( ( b, i ) => {
		if ( cur && items[ i ].ref === cur.ref ) {
			b.setAttribute( 'aria-current', 'true' );
		} else {
			b.removeAttribute( 'aria-current' );
		}
		b.setAttribute( 'tabindex', i === stop ? '0' : '-1' );
	} );
	const fillRow = ( b, item ) => {
		const st = statusOf( item );
		const s0 = el( 'span', '', { class: 'babilim-ed-st' + ( st ? ' babilim-ed-st-' + st[ 1 ] : '' ) } );
		if ( st ) {
			s0.append( icon( st[ 0 ] ), el( 'span', st[ 2 ] ) );
		}
		const o = el( 'span', '', { dir: 'auto' } );
		o.append( el( 'span', item.msgid.slice( 0, 100 ) ) );
		if ( '' !== item.msgid_plural ) {
			o.append( el( 'span', ' ' + str.pluralTag, { class: 'pl' } ) );
		}
		const text = serverText( item )[ 0 ] || '';
		const t = el( 'span', text ? text.slice( 0, 100 ) : str.noTranslation, text ? { dir: 'auto', lang } : { class: 'muted' } );
		const c = chipOf( item );
		b.replaceChildren( s0, o, t, el( 'span', c, c ? { class: 'babilim-ed-chip' + ( live( item ) ? ' babilim-ed-chip-yours' : '' ) } : {} ) );
	};
	// The same string as the list and the open string know it: the answer is applied to each.
	const apply = ( ref, fn ) => {
		const seen = new Set();
		[ items.find( ( x ) => x.ref === ref ), cur && cur.ref === ref ? cur : null ].forEach( ( x ) => {
			if ( x && ! seen.has( x ) ) {
				seen.add( x );
				fn( x );
			}
		} );
		const k = items.findIndex( ( x ) => x.ref === ref );
		if ( k >= 0 && rows[ k ] ) {
			fillRow( rows[ k ], items[ k ] );
		}
	};
	const renderList = () => {
		els.list.replaceChildren();
		rows = items.map( ( item ) => {
			const b = el( 'button', '', { type: 'button', tabindex: '-1' } );
			fillRow( b, item );
			b.addEventListener( 'click', () => choose( item, true ) );
			const li = el( 'li' );
			li.appendChild( b );
			els.list.appendChild( li );
			return b;
		} );
		stop = Math.max( 0, items.findIndex( ( x ) => cur && x.ref === cur.ref ) );
		markCurrent();
	};
	// One tab stop for the whole list: the arrow keys move between rows, a click (Enter, Space) opens one.
	els.list.addEventListener( 'keydown', ( e ) => {
		const i = rows.indexOf( e.target );
		const to = { ArrowDown: i + 1, ArrowUp: i - 1, Home: 0, End: rows.length - 1 }[ e.key ];
		if ( i < 0 || undefined === to || e.ctrlKey || e.metaKey || e.altKey || to < 0 || to >= rows.length ) {
			return;
		}
		e.preventDefault();
		stop = to;
		markCurrent();
		rows[ to ].focus();
	} );
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

	// -- the header: progress, counts, the Save bar ----------------------------

	const count = ( f, n ) => {
		const span = $( 'babilim-ed-n-' + f );
		if ( span ) {
			span.textContent = undefined === n ? '' : String( n );
		}
	};
	const refreshButtons = () => {
		if ( saving ) {
			return;
		}
		els.publish.textContent = pend.save;
		const set = ( b, on ) => ( on ? b.removeAttribute( 'aria-disabled' ) : b.setAttribute( 'aria-disabled', 'true' ) );
		set( els.publish, pend.drafts > 0 || dirty() );
		set( els.discardAll, pend.drafts > 0 );
	};
	const renderPending = ( p ) => {
		pend = p;
		els.pending.replaceChildren();
		if ( p.note ) {
			const s = el( 'span', '', { class: 'd' } );
			s.append( icon( 'saved' ), el( 'span', ' ' + p.note ) );
			els.pending.appendChild( s );
		}
		if ( p.fix ) {
			const s = el( 'span', '', { class: 'p' } );
			const b = el( 'button', p.fix, { type: 'button', class: 'button-link' } );
			b.addEventListener( 'click', () => {
				const r = $( 'babilim-ed-filter-problems' );
				if ( r ) {
					r.checked = true;
				}
				filter = 'problems';
				load( 1, 'first', true );
			} );
			s.append( icon( 'warning' ), el( 'span', ' ' ), b );
			els.pending.appendChild( s );
		}
		count( 'problems', p.problems );
		refreshButtons();
	};
	const renderTop = ( d ) => {
		if ( d.progress ) {
			els.progress.replaceChildren();
			d.progress.forEach( ( t, i ) => {
				if ( i ) {
					els.progress.appendChild( el( 'span', ' · ', { 'aria-hidden': 'true' } ) );
				}
				els.progress.appendChild( el( 'span', t ) );
			} );
		}
		if ( d.counts ) {
			FILTERS.forEach( ( f ) => count( f, d.counts[ f ] ) );
			// No longer used: a choice only while there is something in it.
			const n = d.counts.orphan || 0;
			if ( n > 0 && ! orphan ) {
				const input = el( 'input', '', { type: 'radio', name: 'babilim-ed-filter', id: 'babilim-ed-filter-orphan' } );
				input.value = 'orphan';
				orphan = el( 'label', '', {} );
				orphan.append( input, el( 'span', ' ' + str.orphan + ' ' ), el( 'span', '', { id: 'babilim-ed-n-orphan', class: 'babilim-ed-n' } ) );
				els.filter.appendChild( orphan );
			} else if ( 0 === n && orphan && 'orphan' !== filter ) {
				orphan.remove();
				orphan = null;
			}
			count( 'orphan', n );
		}
		if ( d.pending ) {
			renderPending( d.pending );
		}
	};

	// -- loading a page of strings --------------------------------------------

	const load = async ( p, pick, focus ) => {
		const my = ++run;
		els.list.setAttribute( 'aria-busy', 'true' );
		try {
			const r = await call( 'editor/strings', 'GET', { ...scope(), filter, source: els.source.value, sort: els.sort.value, search: els.search.value.trim(), page: p } );
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
			examples = r.data.plural_examples || [];
			renderTop( r.data );
			renderList();
			renderPager( items.length ? '' : ( r.data.no_source ? str.noSource : str.empty ) );
			const fresh = cur && items.find( ( x ) => x.ref === cur.ref );
			if ( items.length && ( 'first' === pick || 'last' === pick ) ) {
				choose( 'first' === pick ? items[ 0 ] : items[ items.length - 1 ], focus );
			} else if ( items.length && ! cur ) {
				choose( items[ 0 ], false );
			} else if ( fresh ) {
				// The open string follows the server's newest answer; what is in the fields stays.
				cur = fresh;
				base = forms( fresh, serverText( fresh ) );
				refreshView();
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

	// -- the open string: problems, tabs, the box under the fields -----------------

	const describe = ( i ) => fields[ i ].setAttribute( 'aria-describedby', problems[ i ].map( ( n ) => n.id ).concat( 'babilim-ed-state' ).join( ' ' ) );
	const shown = () => problems.some( ( p ) => p.length );
	const selectTab = ( i, focus ) => tabs.forEach( ( t, k ) => {
		t.setAttribute( 'aria-selected', k === i ? 'true' : 'false' );
		t.setAttribute( 'tabindex', k === i ? '0' : '-1' );
		if ( k === i ) {
			panels[ k ].removeAttribute( 'hidden' );
			if ( focus ) {
				t.focus();
			}
		} else {
			panels[ k ].setAttribute( 'hidden', 'until-found' );
		}
	} );
	const updateTab = ( i ) => {
		const t = tabs[ i ];
		if ( ! t ) {
			return;
		}
		const bad = problems[ i ].length > 0;
		t.replaceChildren( el( 'span', tabLabel[ i ] ) );
		if ( bad || '' === fields[ i ].value ) {
			t.append( icon( bad ? 'warning' : 'marker' ), el( 'span', bad ? str.tabProblem : str.tabEmpty, { class: 'screen-reader-text' } ) );
		}
		t.setAttribute( 'class', bad ? 'is-problem' : '' );
	};
	const clearProblems = ( only ) => problems.forEach( ( nodes, i ) => {
		if ( ( undefined === only || only === i ) && nodes.length ) {
			nodes.forEach( ( n ) => n.remove() );
			problems[ i ] = [];
			fields[ i ].setAttribute( 'aria-invalid', 'false' );
			describe( i );
			updateTab( i );
		}
	} );
	// The box under the fields (every field points at it; it is not a live region).
	const renderState = () => {
		if ( ! box || ! cur ) {
			return;
		}
		box.replaceChildren();
		box.setAttribute( 'class', 'babilim-ed-state' );
		if ( unsaved[ cur.ref ] ) {
			box.setAttribute( 'class', 'babilim-ed-state is-problem' );
			box.appendChild( el( 'p', str.unsaved ) );
		} else if ( cur.draft && shown() ) {
			box.setAttribute( 'class', 'babilim-ed-state is-problem' );
			box.appendChild( el( 'p', str.keptNote ) );
		} else if ( cur.draft ) {
			const at = when( cur.draft_at );
			const first = el( 'p' );
			first.append( el( 'strong', str.draftSaved.replace( '%s', () => ( at instanceof Date ? at.toLocaleTimeString() : at ) ) ), el( 'span', ' ' + str.draftVisitors ) );
			const text = ( live( cur ) ? cur.override.msgstr : cur.current )[ 0 ] || str.noTranslation;
			box.append( first, el( 'p', str.liveNow.replace( '%1$s', () => text ).replace( '%2$s', () => srcName( cur ) ) ) );
		} else {
			box.setAttribute( 'hidden', '' );
			return;
		}
		box.removeAttribute( 'hidden' );
	};
	// Every problem goes under its own field; focus (when asked) goes to the first one, which makes a screen reader read it.
	const showProblems = ( list, focus, announce ) => {
		const had = shown();
		clearProblems();
		const by = {};
		list.forEach( ( p ) => {
			const i = Math.min( Math.max( parseInt( p.form, 10 ) || 0, 0 ), fields.length - 1 );
			( by[ i ] = by[ i ] || [] ).push( p.message );
		} );
		const order = Object.keys( by ).map( Number ).sort( ( a, b ) => a - b );
		order.forEach( ( i ) => {
			let prev = fields[ i ];
			by[ i ].forEach( ( m, n ) => {
				const node = el( 'p', m, { id: 'babilim-ed-p' + i + '-' + n, class: 'babilim-ed-problem' } );
				prev.after( node );
				prev = node;
				problems[ i ].push( node );
			} );
			fields[ i ].setAttribute( 'aria-invalid', 'true' );
			describe( i );
			updateTab( i );
		} );
		renderState();
		if ( order.length ) {
			selectTab( tabs.length ? order[ 0 ] : -1, false );
			if ( focus ) {
				fields[ order[ 0 ] ].focus();
			}
			if ( announce && ! had ) {
				speak( by[ order[ 0 ] ][ 0 ] );
			}
		}
	};
	const reveal = ( item, focus, announce ) => {
		if ( cur === item && item.problems.length && fields.length ) {
			showProblems( item.problems, focus, announce );
		}
	};
	const refreshActions = () => {
		if ( discardBtn ) {
			if ( cur && ( cur.draft || unsaved[ cur.ref ] ) ) {
				discardBtn.removeAttribute( 'hidden' );
			} else {
				discardBtn.setAttribute( 'hidden', '' );
			}
		}
	};

	// -- Details and History -----------------------------------------------------

	const stamp = ( at, tag ) => {
		const d = when( at );
		const s = el( 'span', d instanceof Date ? d.toLocaleString() : d );
		const w = el( 'div', '', { class: 'when' } );
		w.append( s );
		if ( tag ) {
			w.append( el( 'span', tag[ 0 ], { class: 'babilim-ed-tag ' + tag[ 1 ] } ) );
		}
		return w;
	};
	const renderAside = () => {
		els.aside.replaceChildren();
		if ( ! cur ) {
			return;
		}
		const dl = el( 'dl', '', { class: 'babilim-ed-dl' } );
		dl.append( el( 'dt', str.srcLabel ), el( 'dd', srcName( cur ) ) );
		if ( cur.msgctxt ) {
			dl.append( el( 'dt', str.context ), el( 'dd', cur.msgctxt, { dir: 'auto' } ) );
		}
		cur.comments.forEach( ( c ) => dl.append( el( 'dt', str.note ), el( 'dd', c, { dir: 'auto' } ) ) );
		if ( cur.references.length ) {
			const dd = el( 'dd' );
			cur.references.forEach( ( r ) => dd.append( el( 'code', r ) ) );
			dl.append( el( 'dt', str.found ), dd );
		}
		const ol = el( 'ol', '', { class: 'babilim-ed-hist' } );
		const entry = ( head, text, restoreOf ) => {
			const li = el( 'li' );
			li.append( head, el( 'p', text || str.noTranslation, { dir: 'auto', lang } ) );
			if ( restoreOf ) {
				const b = el( 'button', str.restore, { type: 'button', class: 'button-link' } );
				b.addEventListener( 'click', () => restore( restoreOf ) );
				li.append( b );
			}
			ol.append( li );
		};
		if ( cur.draft ) {
			entry( stamp( cur.draft_at, [ str.tagDraft, 'babilim-ed-tag-draft' ] ), cur.draft.join( ' / ' ) );
		}
		// What is live: the override and its time, or the catalog's text and where it comes from (nothing when there is none: Source already says so).
		if ( ! blank( cur ) ) {
			entry( live( cur ) ? stamp( cur.override.at, [ str.tagLive, '' ] ) : stamp( catalogName( cur ), [ str.tagLive, '' ] ), serverText( { ...cur, draft: null } ).join( ' / ' ) );
		}
		// A text that is no longer used is read-only everywhere: no Restore.
		const gone = isOrphan( cur );
		hist.forEach( ( h ) => entry( stamp( h.at ), h.msgstr.join( ' / ' ), gone ? null : h.msgstr ) );
		if ( live( cur ) ) {
			entry( stamp( catalogName( cur ) ), cur.current.join( ' / ' ), gone ? null : cur.current );
		}
		els.aside.append( el( 'h3', str.details ), dl );
		if ( ol.children.length ) {
			els.aside.append( el( 'h3', str.history ), ol );
		}
	};
	const loadHistory = async ( item ) => {
		try {
			const r = await call( 'editor/history', 'GET', { ...scope(), ref: item.ref } );
			if ( r.ok && cur === item ) {
				hist = r.data.history || [];
				renderAside();
			}
		} catch ( e ) {
			// The list keeps what it has; nothing else depends on it.
		}
	};
	const refreshView = () => {
		renderReview();
		refreshActions();
		renderState();
		renderAside();
		markCurrent();
		refreshButtons();
	};

	// -- review and no longer used --------------------------------------------------

	// "%1$s %2$s" filled with the component's name and version, both bold.
	const withComponent = ( tpl, cls ) => {
		const c = S.component || {};
		const p = el( 'p', '', cls ? { class: cls } : {} );
		tpl.split( /(%1\$s|%2\$s)/ ).forEach( ( part ) => {
			if ( '%1$s' === part || '%2$s' === part ) {
				p.append( el( 'strong', '%1$s' === part ? c.name : c.version ) );
			} else if ( part ) {
				p.append( el( 'span', part ) );
			}
		} );
		return p;
	};
	// Old and new text with what they share left plain, the rest struck (old) or underlined (new); the screen-reader words keep it from being colour or decoration alone.
	const marked = ( from, to ) => {
		const a = Array.from( from );
		const b = Array.from( to );
		let head = 0;
		while ( head < a.length && head < b.length && a[ head ] === b[ head ] ) {
			head++;
		}
		let tail = 0;
		while ( tail < a.length - head && tail < b.length - head && a[ a.length - 1 - tail ] === b[ b.length - 1 - tail ] ) {
			tail++;
		}
		const part = ( chars, tag, word ) => {
			const mid = chars.slice( head, chars.length - tail ).join( '' );
			if ( ! mid ) {
				return null;
			}
			const n = el( tag );
			// The space stays inside the hidden span (pre-wrap is inherited, so it is kept): outside it would be drawn and add a character neither original has.
			n.append( el( 'span', word + ' ', { class: 'screen-reader-text' } ), el( 'span', mid ) );
			return n;
		};
		const line = ( chars, tag, word ) => {
			const p = el( 'p', '', { class: 'babilim-ed-source', dir: 'auto' } );
			const mid = part( chars, tag, word );
			p.append( el( 'span', chars.slice( 0, head ).join( '' ) ) );
			if ( mid ) {
				p.append( mid );
			}
			p.append( el( 'span', chars.slice( chars.length - tail ).join( '' ) ) );
			return p;
		};
		return [ line( a, 'del', str.removed ), line( b, 'ins', str.added ) ];
	};
	// Puts a text in the field(s) and stores it as a draft at once, even when it is what the field already held.
	const choice = ( text ) => {
		if ( saving || ! cur ) {
			return;
		}
		forms( cur, text ).forEach( ( t, i ) => { fields[ i ].value = t; } );
		clearProblems();
		tabs.forEach( ( _, i ) => updateTab( i ) );
		refreshButtons();
		draft( true, true );
	};
	const card = ( title, body, label, text ) => {
		const sec = el( 'section' );
		const h = el( 'h3', title );
		const b = el( 'button', label, { type: 'button', class: 'button button-compact' } );
		b.addEventListener( 'click', () => choice( text ) );
		sec.append( h, el( 'p', body || str.noTranslation, { dir: 'auto', lang } ), b );
		return sec;
	};
	const renderReview = () => {
		els.review.replaceChildren();
		if ( fieldLabel ) {
			fieldLabel.textContent = cur && cur.review ? str.editYourself : str.translation.replace( '%s', () => S.localeName || S.scope.locale );
		}
		if ( ! cur ) {
			return;
		}
		if ( isOrphan( cur ) ) {
			els.review.append( withComponent( str.orphanNote, 'babilim-ed-orphan-note' ) );
			return;
		}
		const r = cur.review;
		if ( ! r ) {
			return;
		}
		const notice = el( 'div', '', { class: 'notice notice-warning inline' } );
		const p = withComponent( str.reviewChanged );
		p.append( el( 'span', ' ' + str.reviewWhy ) );
		notice.append( p );
		const [ was, now ] = marked( r.msgid, cur.msgid );
		const diff = el( 'div', '', { class: 'babilim-ed-diff' } );
		[ [ str.oldOriginal, was ], [ str.newOriginal, now ] ].forEach( ( [ title, line ] ) => {
			const d = el( 'div' );
			d.append( el( 'h3', title ), line );
			diff.append( d );
		} );
		// What is live now for the new string (the owner may have published a text for it already), else the catalog's.
		const liveNow = live( cur ) ? cur.override.msgstr : cur.current;
		const fresh = liveNow.some( ( t ) => '' !== t );
		const pick = el( 'div', '', { class: 'babilim-ed-choice' } );
		pick.append( card( str.mine, r.msgstr.join( ' / ' ), str.useMine, r.msgstr ) );
		pick.append( fresh
			? card( live( cur ) ? str.yourNow : str.newFrom.replace( '%s', () => catalogName( cur ) ), liveNow.join( ' / ' ), live( cur ) ? str.keepCurrent : str.useNew, liveNow )
			: card( str.noNew, '', str.discardMine, [ '' ] ) );
		els.review.append( notice, diff, pick, el( 'p', str.reviewNote, { class: 'babilim-ed-note' } ) );
	};
	const removeOrphan = ( item ) => ask( str.deleteAsk, async () => {
		if ( saving ) {
			return;
		}
		saving = true;
		try {
			const r = await call( 'editor/remove', 'POST', { ...scope(), ref: item.ref } );
			if ( ! r.ok ) {
				throw r;
			}
			cur = null;
			hist = [];
			fields = [];
			els.edit.replaceChildren();
			els.aside.replaceChildren();
			els.review.replaceChildren();
			saving = false;
			await load( page, 'first', false );
			renderPending( r.data.pending );
			say( str.deleted );
		} catch ( e ) {
			fail( e );
		} finally {
			saving = false;
			refreshButtons();
		}
	} );

	// -- drafts --------------------------------------------------------------------

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
	// Nothing is waiting to be kept: every text reached the server.
	const unwarn = () => {
		if ( armed && 0 === Object.keys( unsaved ).length && 0 === waiting.size ) {
			armed = false;
			window.removeEventListener( 'beforeunload', guard );
		}
	};
	const sendOne = async ( w ) => {
		const item = w.item;
		try {
			const r = await call( 'editor/draft', 'POST', { ...scope(), ref: item.ref, msgstr: w.v }, true );
			if ( ! r.ok ) {
				throw r;
			}
			const d = r.data;
			delete unsaved[ item.ref ];
			apply( item.ref, ( x ) => {
				x.draft = d.draft ? w.v : null;
				x.draft_at = d.draft ? d.at : '';
				x.problems = d.problems || [];
			} );
			if ( cur && cur.ref === item.ref ) {
				base = w.v;
				renderState();
				renderAside();
				refreshActions();
			}
			renderPending( d.pending );
			unwarn();
			if ( w.reveal ) {
				reveal( cur, false, true );
			}
			return { ok: true, problems: item.problems };
		} catch ( e ) {
			lastSent = null;
			unsaved[ item.ref ] = w.v;
			arm();
			say( e && e.loggedOut ? str.loggedOut : str.unsaved, 'assertive' );
			if ( cur && cur.ref === item.ref ) {
				renderState();
				refreshActions();
			}
			return { ok: false, problems: [] };
		}
	};
	// One draft request at a time; text that arrives while one is on its way is kept as the last one only.
	const pump = async () => {
		while ( waiting.size ) {
			const [ ref, w ] = waiting.entries().next().value;
			waiting.delete( ref );
			flying = ref;
			const res = await sendOne( w );
			flying = null;
			w.waiters.forEach( ( f ) => f( res ) );
		}
		sending = null;
	};
	const settle = async () => {
		while ( sending ) {
			await sending;
		}
	};
	// Keeps the open field's text as a draft on the server; resolves with { ok, problems }.
	const draft = ( showIt, force ) => {
		const item = cur;
		if ( ! item ) {
			return Promise.resolve( { ok: true, problems: [] } );
		}
		const v = values();
		const busy = waiting.has( item.ref ) || flying === item.ref;
		if ( ! force && same( v, base ) && ! busy ) {
			delete unsaved[ item.ref ];
			unwarn();
			if ( showIt ) {
				reveal( item, false, true );
			}
			return Promise.resolve( { ok: true, problems: item.problems } );
		}
		const key = item.ref + '\n' + JSON.stringify( v );
		if ( ! force && lastSent === key ) {
			return settle().then( () => {
				if ( showIt ) {
					reveal( item, false, true );
				}
				return { ok: ! unsaved[ item.ref ], problems: item.problems };
			} );
		}
		lastSent = key;
		const old = waiting.get( item.ref );
		const w = { item, v, reveal: !! showIt || ( !! old && old.reveal ), waiters: old ? old.waiters : [] };
		waiting.set( item.ref, w );
		// Typed but not yet confirmed by the server: leaving now would lose it.
		arm();
		if ( ! sending ) {
			sending = pump();
		}
		return new Promise( ( resolve ) => w.waiters.push( resolve ) );
	};
	const schedule = () => {
		const t = ++typeToken;
		window.setTimeout( () => {
			if ( t === typeToken && ! composing ) {
				draft( false );
			}
		}, 1500 );
	};
	const onInput = ( i ) => {
		clearProblems( i );
		updateTab( i );
		renderState();
		refreshButtons();
		if ( ! composing ) {
			schedule();
		}
	};
	// A hidden page may never come back: what is typed goes out now (keepalive, so leaving does not cancel it).
	document.addEventListener( 'visibilitychange', () => {
		if ( 'hidden' === document.visibilityState ) {
			draft( false );
			// Newer text queued behind a request in flight would never go out once the page is gone: send it now, keepalive; the queue keeps it in case the page comes back.
			if ( flying ) {
				waiting.forEach( ( w ) => {
					call( 'editor/draft', 'POST', { ...scope(), ref: w.item.ref, msgstr: w.v }, true ).catch( () => {} );
				} );
			}
		}
	} );

	// -- Save and Discard -------------------------------------------------------------

	const busyOn = ( on ) => {
		saving = on;
		if ( on ) {
			els.edit.setAttribute( 'aria-busy', 'true' );
			speak( admin.working );
			els.publish.textContent = admin.working;
			// aria-disabled, not disabled: the button keeps focus; the `saving` flag is what stops a second send.
			els.publish.setAttribute( 'aria-disabled', 'true' );
			spin = el( 'span', '', { class: 'spinner is-active' } );
			els.publish.after( spin );
		} else {
			els.edit.removeAttribute( 'aria-busy' );
			if ( spin ) {
				spin.remove();
				spin = null;
			}
			refreshButtons();
		}
	};
	const publish = async () => {
		if ( saving || ! ( pend.drafts > 0 || dirty() ) ) {
			return;
		}
		busyOn( true );
		try {
			const drafted = await draft( false );
			await settle();
			if ( ! drafted.ok ) {
				return;
			}
			const r = await call( 'editor/publish', 'POST', scope() );
			if ( ! r.ok ) {
				fail( r );
				return;
			}
			const d = r.data;
			lastSent = null;
			pend = d.pending;
			await load( page );
			// The string may have left the list (an Untranslated filter): ask for it by itself so the area shows what is live now.
			if ( cur && ! items.some( ( x ) => x.ref === cur.ref ) ) {
				const one = await call( 'editor/strings', 'GET', { ...scope(), ref: cur.ref } );
				if ( one.ok && one.data.items[ 0 ] ) {
					open( one.data.items[ 0 ], false );
				}
			}
			if ( cur && cur.history > 0 ) {
				loadHistory( cur );
			}
			reveal( cur, false, false );
			say( d.message, d.failed && d.failed.length ? 'assertive' : 'polite' );
		} catch ( e ) {
			fail( e );
		} finally {
			busyOn( false );
		}
	};
	const ask = ( q, yes ) => {
		if ( window.babilimAdmin && window.babilimAdmin.ask ) {
			window.babilimAdmin.ask( q, yes );
		} else if ( window.confirm( q ) ) {
			yes();
		}
	};
	const discardAll = () => {
		if ( saving || pend.drafts < 1 ) {
			return;
		}
		ask( pend.discard, async () => {
			saving = true;
			try {
				await settle();
				waiting.clear();
				typeToken++;
				const r = await call( 'editor/discard', 'POST', scope() );
				if ( ! r.ok ) {
					throw r;
				}
				Object.keys( unsaved ).forEach( ( k ) => { delete unsaved[ k ]; } );
				unwarn();
				lastSent = null;
				saving = false;
				await load( page );
				if ( cur ) {
					open( items.find( ( x ) => x.ref === cur.ref ) || { ...cur, draft: null, draft_at: '', problems: [] }, false );
				}
				say( str.discardedAll );
			} catch ( e ) {
				fail( e );
			} finally {
				saving = false;
				refreshButtons();
			}
		} );
	};
	const discardOne = async () => {
		const item = cur;
		if ( saving || ! item ) {
			return;
		}
		saving = true;
		try {
			waiting.delete( item.ref );
			typeToken++;
			await settle();
			const r = await call( 'editor/discard', 'POST', { ...scope(), ref: item.ref } );
			if ( ! r.ok ) {
				throw r;
			}
			delete unsaved[ item.ref ];
			unwarn();
			lastSent = null;
			apply( item.ref, ( x ) => {
				x.draft = null;
				x.draft_at = '';
				x.problems = [];
			} );
			if ( cur === item ) {
				base = forms( item, serverText( item ) );
				fields.forEach( ( f, i ) => { f.value = base[ i ]; } );
				clearProblems();
				tabs.forEach( ( _, i ) => updateTab( i ) );
				refreshView();
			}
			renderPending( r.data.pending );
			say( str.discardedOne );
		} catch ( e ) {
			fail( e );
		} finally {
			saving = false;
			refreshButtons();
		}
	};

	// -- the editing area ----------------------------------------------------------------

	const copyInto = ( i ) => {
		fields[ i ].value = 0 === i || '' === cur.msgid_plural ? cur.msgid : cur.msgid_plural;
		onInput( i );
	};
	// A version from History goes back into the fields and is stored as a draft; nothing goes live before Save.
	const restore = ( msgstr ) => {
		if ( saving || ! cur || isOrphan( cur ) ) {
			return;
		}
		forms( cur, msgstr ).forEach( ( t, i ) => { fields[ i ].value = t; } );
		clearProblems();
		tabs.forEach( ( _, i ) => updateTab( i ) );
		refreshButtons();
		draft( true );
	};
	const formLabel = ( i ) => ( examples[ i ] ? str.formEx.replace( '%1$d', i + 1 ).replace( '%2$s', () => examples[ i ] ) : str.form.replace( '%d', i + 1 ) );
	const hint = ( parts, text ) => {
		const s = el( 'span' );
		parts.forEach( ( p, i ) => {
			if ( i ) {
				s.append( el( 'span', '+' ) );
			}
			s.append( el( 'kbd', p ) );
		} );
		s.append( el( 'span', ' ' + text ) );
		return s;
	};
	const field = ( i, text ) => {
		const ta = el( 'textarea', '', { id: 'babilim-ed-f' + i, lang, dir: 'auto', rows: '3', 'aria-invalid': 'false', 'aria-keyshortcuts': keys, 'aria-describedby': 'babilim-ed-state' } );
		ta.value = text;
		ta.addEventListener( 'blur', () => draft( true ) );
		ta.addEventListener( 'input', () => onInput( i ) );
		ta.addEventListener( 'compositionstart', () => { composing = true; } );
		ta.addEventListener( 'compositionend', () => {
			composing = false;
			schedule();
		} );
		fields.push( ta );
		problems.push( [] );
		return ta;
	};
	const tabKeys = ( e ) => {
		const i = tabs.indexOf( e.target );
		const rtl = 'rtl' === document.documentElement.dir;
		const to = { ArrowRight: rtl ? i - 1 : i + 1, ArrowLeft: rtl ? i + 1 : i - 1, Home: 0, End: tabs.length - 1 }[ e.key ];
		if ( i < 0 || undefined === to ) {
			return;
		}
		e.preventDefault();
		selectTab( ( to + tabs.length ) % tabs.length, true );
	};
	const open = ( item, focus ) => {
		cur = item;
		lastSent = null;
		typeToken++;
		hist = [];
		fields = [];
		tabs = [];
		panels = [];
		tabLabel = [];
		problems = [];
		fieldLabel = null;
		base = forms( item, serverText( item ) );
		const start = unsaved[ item.ref ] ? forms( item, unsaved[ item.ref ] ) : base;
		const n = start.length;
		const e = els.edit;
		const name = str.translation.replace( '%s', () => S.localeName || S.scope.locale );
		const gone = isOrphan( item );
		const copy = ( at ) => {
			if ( gone ) {
				return el( 'span' );
			}
			const b = el( 'button', str.copy, { type: 'button', class: 'button-link' } );
			b.addEventListener( 'click', () => copyInto( at() ) );
			return b;
		};
		e.replaceChildren();
		if ( '' !== item.msgid_plural ) {
			e.append( el( 'h3', str.sourceSingular ), el( 'p', item.msgid, { class: 'babilim-ed-source', dir: 'auto' } ), el( 'h3', str.sourcePlural ), el( 'p', item.msgid_plural, { class: 'babilim-ed-source', dir: 'auto' } ) );
		} else {
			e.append( el( 'h3', str.original ), el( 'p', item.msgid, { class: 'babilim-ed-source', dir: 'auto' } ) );
		}
		if ( n >= 4 ) {
			const head = el( 'div', '', { class: 'babilim-ed-labelrow' } );
			head.append( el( 'strong', name ), copy( () => Math.max( 0, tabs.findIndex( ( t ) => 'true' === t.getAttribute( 'aria-selected' ) ) ) ) );
			const list = el( 'div', '', { role: 'tablist', 'aria-label': str.pluralForms, class: 'babilim-ed-tabs' } );
			list.addEventListener( 'keydown', tabKeys );
			e.append( head, list );
			start.forEach( ( text, i ) => {
				const t = el( 'button', '', { type: 'button', role: 'tab', id: 'babilim-ed-tab' + i, 'aria-selected': 'false', tabindex: '-1' } );
				const p = el( 'div', '', { role: 'tabpanel', 'aria-labelledby': t.id, hidden: 'until-found' } );
				const ta = field( i, text );
				ta.setAttribute( 'aria-labelledby', t.id );
				p.append( ta );
				t.addEventListener( 'click', () => selectTab( i, false ) );
				p.addEventListener( 'beforematch', () => selectTab( i, false ) );
				tabLabel.push( formLabel( i ) );
				tabs.push( t );
				panels.push( p );
				list.append( t );
			} );
			e.append( ...panels );
			selectTab( 0, false );
			tabs.forEach( ( _, i ) => updateTab( i ) );
		} else {
			start.forEach( ( text, i ) => {
				const ta = field( i, text );
				const row = el( 'div', '', { class: 'babilim-ed-labelrow' } );
				const lab = el( 'label', 1 === n ? name : formLabel( i ), { for: ta.id } );
				if ( 1 === n ) {
					fieldLabel = lab;
				}
				row.append( lab, copy( () => i ) );
				const wrap = el( 'div' );
				wrap.append( row, ta );
				e.append( wrap );
			} );
			if ( '' !== item.msgid_plural && 1 === n ) {
				e.append( el( 'p', str.oneForm, { class: 'babilim-ed-note' } ) );
			}
		}
		box = el( 'div', '', { id: 'babilim-ed-state', class: 'babilim-ed-state', hidden: '' } );
		discardBtn = el( 'button', str.discardOne, { type: 'button', class: 'button button-compact', hidden: '' } );
		discardBtn.addEventListener( 'click', discardOne );
		const mod = mac ? '⌘' : str.keyCtrl;
		const keysRow = el( 'span', '', { class: 'babilim-ed-keys' } );
		keysRow.append( hint( [ mod, str.keyEnter ], str.keyNext ), hint( [ mod, '↑/↓' ], str.keyMove ), hint( [ mod, 'B' ], str.keyCopy ), hint( [ mod, 'K' ], str.keyClear ) );
		const actions = el( 'div', '', { class: 'babilim-ed-actions' } );
		if ( gone ) {
			fields.forEach( ( f ) => f.setAttribute( 'readonly', '' ) );
			const del = el( 'button', str.deleteBtn, { type: 'button', class: 'button' } );
			del.addEventListener( 'click', () => removeOrphan( item ) );
			actions.append( del );
		} else {
			actions.append( discardBtn, keysRow );
		}
		e.append( box, actions );
		const k = items.indexOf( item );
		if ( k >= 0 ) {
			stop = k;
		}
		unwarn();
		refreshView();
		if ( item.draft && item.problems.length ) {
			showProblems( item.problems, false, false );
		}
		if ( focus && fields.length ) {
			fields[ 0 ].focus();
		}
		if ( item.history > 0 ) {
			loadHistory( item );
		}
	};

	function choose( item, focus ) {
		draft( false );
		open( item, focus );
	}

	// -- moving between strings ------------------------------------------------------------

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
	// Ctrl+Enter: keep the draft and move on; a draft with problems stays, with the problems shown.
	const store = async () => {
		const item = cur;
		const r = await draft( false );
		if ( ! r.ok ) {
			return;
		}
		if ( item.problems.length ) {
			reveal( item, true, true );
			return;
		}
		go( 1, false );
	};

	els.edit.addEventListener( 'keydown', ( e ) => {
		const i = fields.indexOf( e.target );
		if ( i < 0 || e.isComposing || ! ( e.ctrlKey || e.metaKey ) ) {
			return;
		}
		const key = String( e.key );
		const low = key.toLowerCase();
		// A text that is no longer used is read-only: only the keys that write (copy original, clear) are refused; moving on never writes.
		if ( cur && isOrphan( cur ) && ( 'b' === low || 'k' === low ) ) {
			return;
		}
		if ( 'Enter' === key ) {
			e.preventDefault();
			store();
		} else if ( 'ArrowDown' === key || 'ArrowUp' === key ) {
			const f = fields[ i ];
			const down = 'ArrowDown' === key;
			// Only from the matching end with nothing selected; anywhere else the key moves the caret or the selection.
			if ( f.selectionStart !== f.selectionEnd || f.selectionStart !== ( down ? f.value.length : 0 ) ) {
				return;
			}
			e.preventDefault();
			go( down ? 1 : -1, !! e.shiftKey );
		} else if ( 'b' === low && ( e.metaKey || ! mac ) ) {
			e.preventDefault();
			copyInto( i );
		} else if ( 'k' === low && ( e.metaKey || ! mac ) ) {
			// Handled here and stopped, so the command palette (also Ctrl/Cmd+K) does not open over the field.
			e.preventDefault();
			fields[ i ].value = '';
			onInput( i );
		}
	} );

	// -- the controls around the list ----------------------------------------------------------

	els.filter.addEventListener( 'change', ( e ) => {
		if ( e.target && e.target.value ) {
			filter = e.target.value;
			load( 1 );
		}
	} );
	[ els.sort, els.source ].forEach( ( s ) => s.addEventListener( 'change', () => load( 1 ) ) );
	els.search.addEventListener( 'input', () => {
		const token = ++searchToken;
		window.setTimeout( () => {
			if ( token === searchToken ) {
				load( 1 );
			}
		}, 300 );
	} );
	// Another component or language: the menus only choose; the Open button submits the GET form. What was typed goes to the server first (the form is held back until it has).
	els[ 'scope-form' ].addEventListener( 'submit', async ( e ) => {
		if ( ! dirty() && ! waiting.size && ! flying ) {
			return;
		}
		e.preventDefault();
		await draft( false );
		await settle();
		els[ 'scope-form' ].submit();
	} );
	els.publish.addEventListener( 'click', publish );
	els.discardAll.addEventListener( 'click', discardAll );

	( async () => {
		if ( S.ref ) {
			try {
				const r = await call( 'editor/strings', 'GET', { ...scope(), ref: S.ref } );
				if ( r.ok && r.data.items[ 0 ] ) {
					nplurals = r.data.nplurals || nplurals;
					examples = r.data.plural_examples || [];
					open( r.data.items[ 0 ], false );
				}
			} catch ( e ) {
				fail( e );
			}
		}
		await load( 1 );
	} )();
}() );
