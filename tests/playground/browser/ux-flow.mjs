// Drives the Babilim admin screens in a real browser (Playwright) against
// an already running WordPress.
//
//   node ux-flow.mjs <base-url> <results-file> <scenario> [--no-js]
//
//   base-url      WordPress root, e.g. http://127.0.0.1:9500
//   results-file  one line per check is appended: PASS|FAIL browser-<scenario>[-nojs] <msg>
//   scenario      "enable": welcome block, press Enable, reload until the
//                 list shows, then the Plugins screen has no explanation row
//                 "settings": enable first, then the two settings tabs, a
//                 language change and its save, and Disable Babilim
//               "list": enable first, then the translations list: five views,
//                 search and language filters, Download and its result after a
//                 reload, a queued cell, keyboard use, and 480px layout
//                 (needs the mock service reset with {"profile":"list"})
//               "bulk": enable first, then the bulk form: top Apply and bottom
//                 (action2) Apply with Download, six components with only two
//                 per run and the continue form pressed down to zero, and one
//                 component whose download fails with a 500
//                 (needs the mock service reset with {"profile":"bulk"}, a
//                 download_delay and fail_builds; fixtures bulk-a ... bulk-l)
//               "l10n": a zh_TW site (needs BABILIM_UX_EXPECT_ENABLE and
//                 BABILIM_UX_EXPECT_SETTINGS_TITLE, the bundled file's own
//                 strings, and the mock reset with {"profile":"list"}):
//                 translated labels, no PHP warning, list buttons aligned
//               "rowactions": enable first, download one line, then Remove
//                 from its row: confirm cancelled and accepted with the
//                 script, the confirmation page without it
//                 (needs the mock service reset with {"profile":"list"})
//               "screenshots": enable, then screenshot the welcome screen, the
//                 list and the settings page into BABILIM_UX_SHOTS as
//                 screenshot-1/2/3[-nojs].png, 1280x800 (run by
//                 `make wporg-screenshots`)
//               "seam": against a real local instance of the service, driven by
//                 run-seam.sh: enable (real callback), list, Download, a newer
//                 component version, "Check again now", the newer-version
//                 request cell, the service's scheduled run, and "Check
//                 again now" once more. Needs BABILIM_SEAM_UPGRADE_CMD and
//                 BABILIM_SEAM_RUN_DUE_CMD (shell commands) and BABILIM_SEAM_SLUG,
//                 BABILIM_SEAM_OLD_VERSION, BABILIM_SEAM_NEW_VERSION
//   --no-js       the plugin's admin script is blocked, so every form must
//                 work as a plain form post
//
// Credentials: BABILIM_UX_USER (default admin) with BABILIM_UX_PASS, then
// WordPress Playground's default account password; a session that is already
// logged in is used as is. Without Playwright on NODE_PATH the script writes
// "FAIL infra playwright missing" to the results file and exits 1. Playwright itself is resolved
// from NODE_PATH. Exit code: 0 when every check passed, 1 otherwise.

import { appendFileSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
let chromium;
try {
	( { chromium } = require( 'playwright' ) );
} catch ( error ) {
	const file = process.argv[ 3 ];
	const line = 'FAIL infra playwright missing (set NODE_PATH to a directory that contains it)';
	if ( file ) {
		appendFileSync( file, line + '\n' );
	}
	console.error( line );
	process.exit( 1 );
}

const args = process.argv.slice( 2 );
const noJs = args.includes( '--no-js' );
const [ baseUrlArg, resultsFile, scenario ] = args.filter( ( a ) => ! a.startsWith( '--' ) );
if ( ! baseUrlArg || ! resultsFile || ! scenario ) {
	console.error( 'usage: ux-flow.mjs <base-url> <results-file> <scenario> [--no-js]' );
	process.exit( 2 );
}
const baseUrl = baseUrlArg.replace( /\/$/, '' );
const tag = `browser-${ scenario }${ noJs ? '-nojs' : '' }`;
const MAX_RELOADS = 6;
const mockBase = ( process.env.BABILIM_UX_MOCK || 'http://127.0.0.1:8797' ).replace( /\/$/, '' );

let failures = 0;
function report( ok, message ) {
	if ( ! ok ) {
		failures++;
	}
	const line = `${ ok ? 'PASS' : 'FAIL' } ${ tag } ${ message }`;
	appendFileSync( resultsFile, line + '\n' );
	console.log( line );
}

async function login( page ) {
	// The password comes from BABILIM_UX_PASS; WordPress Playground's own
	// default account password is tried as well. Without either, a login
	// page is reported as an infrastructure problem.
	const passwords = [ process.env.BABILIM_UX_PASS, 'password' ].filter( Boolean );
	for ( const password of passwords ) {
		await page.goto( `${ baseUrl }/wp-admin/`, { waitUntil: 'domcontentloaded' } );
		if ( ! page.url().includes( 'wp-login.php' ) ) {
			return;
		}
		await page.fill( '#user_login', process.env.BABILIM_UX_USER || 'admin' );
		await page.fill( '#user_pass', password );
		await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
	}
	await page.goto( `${ baseUrl }/wp-admin/`, { waitUntil: 'domcontentloaded' } );
	if ( page.url().includes( 'wp-login.php' ) ) {
		throw new Error( 'FAIL infra could not log in (set BABILIM_UX_USER and BABILIM_UX_PASS)' );
	}
}

async function bodyText( page ) {
	return ( await page.locator( '#wpbody-content' ).innerText() ).replace( /\s+/g, ' ' );
}

async function enableScenario( page, state ) {
	const screen = `${ baseUrl }/wp-admin/admin.php?page=babilim`;
	await page.goto( screen, { waitUntil: 'domcontentloaded' } );

	// 1. The welcome block, before anything was agreed to.
	const enable = page.locator( 'button', { hasText: 'Enable Babilim' } );
	report( ( await enable.count() ) === 1, 'welcome block shows one "Enable Babilim" button' );
	report( ( await page.locator( 'details summary' ).count() ) >= 1, 'welcome block lists what is sent' );
	report( ( await page.locator( 'input[name="consent"]' ).count() ) === 0, 'no consent checkbox' );
	const welcomeText = await bodyText( page );
	report( /Available translations can be installed with one click, and missing ones can be requested\. After that, new translations and updates are installed automatically \(on the free plan, only for plugins and themes that already use a free slot\)\./.test( welcomeText ), 'welcome block explains installs, requests and the free plan' );
	report( /The free plan covers a limited number of plugins and themes/.test( welcomeText ), 'welcome block mentions the free plan limit' );
	if ( ( await enable.count() ) !== 1 ) {
		console.log( `  url: ${ page.url() } title: ${ await page.title() }` );
		console.log( `  body: ${ ( await page.locator( 'body' ).innerText() ).replace( /\s+/g, ' ' ).slice( 0, 400 ) }` );
		return;
	}

	// 2. Record what the click does to the button, in the bubble phase so
	// the plugin's own capture handler has already run. sessionStorage
	// survives the navigation the submit causes.
	await page.evaluate( () => {
		document.addEventListener( 'submit', ( event ) => {
			const button = event.submitter || event.target.querySelector( 'button[type=submit]' );
			sessionStorage.setItem(
				'babilim-ux',
				JSON.stringify( {
					label: button.textContent,
					spinner: !! button.parentNode.querySelector( '.spinner.is-active' ),
				} )
			);
		} );
	} );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'domcontentloaded' } ), enable.click() ] );
	const seen = JSON.parse( ( await page.evaluate( () => sessionStorage.getItem( 'babilim-ux' ) ) ) || 'null' );
	report( seen !== null, 'the form was submitted' );
	if ( noJs ) {
		report( seen && seen.label === 'Enable Babilim' && ! seen.spinner, 'without the script the button is left alone and the form still posts' );
		report( state.scriptRequests > 0, 'the page asked for the admin script and it was blocked' );
	} else {
		report( seen && seen.label === 'Enabling…' && seen.spinner, 'the button shows its busy label and a spinner' );
		report( state.scriptStatuses.length > 0 && state.scriptStatuses.every( ( s ) => s === 200 ), 'the admin script loaded (HTTP 200)' );
	}

	// 3. The result: reload, at most MAX_RELOADS times, until the list shows
	// and no "enabling" notice is left.
	let listed = false;
	let busyNotice = true;
	let reloads = 0;
	for ( ;; ) {
		listed = ( await page.locator( 'table.wp-list-table' ).count() ) > 0;
		busyNotice = /enabling and checking/i.test( await bodyText( page ) );
		if ( ( listed && ! busyNotice ) || reloads >= MAX_RELOADS ) {
			break;
		}
		reloads++;
		await page.goto( screen, { waitUntil: 'domcontentloaded' } );
	}
	const text = await bodyText( page );
	report( listed && ! busyNotice, `the component list shows after ${ reloads } reload(s) with no enabling notice` );
	if ( ! ( listed && ! busyNotice ) ) {
		console.log( `  page text: ${ text.slice( 0, 400 ) }` );
	}
	report( ( await page.locator( 'button', { hasText: 'Enable Babilim' } ).count() ) === 0, 'the Enable button is gone once enabled' );

	// 4. The Plugins screen has no explanation row under Babilim.
	await page.goto( `${ baseUrl }/wp-admin/plugins.php`, { waitUntil: 'domcontentloaded' } );
	report( ( await page.locator( 'tr[data-slug="babilim"], tr[data-plugin="babilim/babilim.php"]' ).count() ) >= 1, 'Babilim is listed on the Plugins screen' );
	report( ( await page.locator( 'tr.plugin-update-tr[data-plugin="babilim/babilim.php"], tr.plugin-update-tr[data-slug="babilim"]' ).count() ) === 0, 'no explanation row under Babilim on the Plugins screen' );
}

async function screenshotsScenario( page ) {
	const shots = process.env.BABILIM_UX_SHOTS;
	report( Boolean( shots ), 'BABILIM_UX_SHOTS names the output directory' );
	if ( ! shots ) return;
	const suffix = noJs ? '-nojs' : '';
	await page.setViewportSize( { width: 1280, height: 800 } );
	await page.goto( `${ baseUrl }/wp-admin/admin.php?page=babilim`, { waitUntil: 'domcontentloaded' } );
	await page.screenshot( { path: `${ shots }/screenshot-1${ suffix }.png` } );
	await submitAndSettle( page, page.locator( 'button', { hasText: 'Enable Babilim' } ) );
	report( await openList( page ), 'the translations list shows after enabling' );
	await page.screenshot( { path: `${ shots }/screenshot-2${ suffix }.png` } );
	await page.goto( `${ baseUrl }/wp-admin/admin.php?page=babilim-settings`, { waitUntil: 'domcontentloaded' } );
	await page.screenshot( { path: `${ shots }/screenshot-3${ suffix }.png` } );
}

// Clicks a submit button and waits until a new document has loaded: a
// marker set on the current window is gone once the form post and its
// redirect have replaced the page.
async function submitAndSettle( page, button ) {
	await page.evaluate( () => {
		window.__babilimBeforeSubmit = true;
	} );
	await button.click( { timeout: 120000 } );
	await page.waitForFunction( () => ! window.__babilimBeforeSubmit, null, { timeout: 120000 } );
	await page.waitForLoadState( 'load' );
}

async function settingsScenario( page, state ) {
	const settings = `${ baseUrl }/wp-admin/admin.php?page=babilim-settings`;
	// Enable first (a plain post of the welcome form; the enable scenario
	// covers its details).
	await page.goto( `${ baseUrl }/wp-admin/admin.php?page=babilim`, { waitUntil: 'domcontentloaded' } );
	const afterEnable = page.url();
	await submitAndSettle( page, page.locator( 'button', { hasText: 'Enable Babilim' } ) );
	const enabledText = await bodyText( page );

	// 1. General tab: one form, one primary button, Disable button inside its own form.
	await page.goto( settings, { waitUntil: 'domcontentloaded' } );
	report( ( await page.locator( 'h2.nav-tab-wrapper a.nav-tab' ).count() ) === 2, 'two tabs' );
	report( ( await page.locator( 'a.nav-tab-active' ).innerText() ).trim() === 'General', 'General is the active tab by default' );
	report( ( await page.locator( 'form#babilim-settings-form button.button-primary' ).count() ) === 1, 'one primary button in the settings form' );
	const disableCount = await page.locator( 'form#babilim-disable-form button.button-link-delete', { hasText: 'Disable Babilim' } ).count();
	report( disableCount === 1, 'Disable Babilim sits in its own form after the settings form' );
	report( ( await page.locator( 'form#babilim-settings-form button', { hasText: 'Disable Babilim' } ).count() ) === 0, 'Disable Babilim is not in the settings form' );
	if ( disableCount !== 1 ) {
		console.log( `  start url: ${ afterEnable }` );
		console.log( `  after Enable: ${ enabledText.slice( 0, 300 ) }` );
		console.log( `  settings page: ${ ( await bodyText( page ) ).slice( 0, 300 ) }` );
	}
	report( ( await page.locator( 'form#babilim-settings-form form' ).count() ) === 0, 'no nested form' );
	report( ( await page.locator( 'form#babilim-settings-form input[name="auto_install"]' ).count() ) === 1, 'General has the Automatic installs checkbox' );
	report( await page.locator( 'form#babilim-settings-form input[name="auto_install"]' ).isChecked(), 'Automatic installs is checked by default' );
	if ( ! noJs ) {
		report( state.scriptStatuses.length > 0 && state.scriptStatuses.every( ( s ) => s === 200 ), 'the admin script loaded (HTTP 200)' );
	} else {
		report( state.scriptRequests > 0, 'the page asked for the admin script and it was blocked' );
	}

	// 2. Stop managing a language, save, and see it unchecked.
	const box = page.locator( 'input[name="locales[]"]' ).first();
	const value = await box.getAttribute( 'value' );
	await box.uncheck();
	await submitAndSettle( page, page.locator( 'form#babilim-settings-form button.button-primary' ) );
	report( page.url().includes( 'tab=general' ), 'saving returns to the General tab' );
	report( /Settings saved/.test( await bodyText( page ) ), 'a saved notice is shown' );
	report( ! ( await page.locator( `input[name="locales[]"][value="${ value }"]` ).isChecked() ), 'the language stays listed, unchecked' );
	await page.locator( `input[name="locales[]"][value="${ value }"]` ).check();
	await submitAndSettle( page, page.locator( 'form#babilim-settings-form button.button-primary' ) );
	const restored = await page.locator( `input[name="locales[]"][value="${ value }"]` ).isChecked();
	report( restored, 'checking it again and saving restores it' );
	if ( ! restored ) {
		console.log( `  url: ${ page.url() } boxes: ${ JSON.stringify( await page.locator( 'input[name="locales[]"]' ).evaluateAll( ( els ) => els.map( ( e ) => [ e.value, e.checked ] ) ) ) }` );
		console.log( `  page text: ${ ( await bodyText( page ) ).slice( 0, 300 ) }` );
		await page.goto( settings, { waitUntil: 'load' } );
		console.log( `  after a plain reload: ${ JSON.stringify( await page.locator( 'input[name="locales[]"]' ).evaluateAll( ( els ) => els.map( ( e ) => [ e.value, e.checked ] ) ) ) }` );
	}

	// 3. Advanced tab.
	await page.locator( 'a.nav-tab', { hasText: 'Advanced' } ).click();
	await page.waitForLoadState( 'domcontentloaded' );
	for ( const label of [ 'Check again now', 'Rebuild index now', 'Sweep now' ] ) {
		report( ( await page.locator( 'button', { hasText: label } ).count() ) === 1, `Advanced has "${ label }"` );
	}
	const purge = page.locator( 'button', { hasText: 'Remove everything Babilim installed' } );
	report( ( await purge.count() ) === 1 && !! ( await purge.getAttribute( 'data-babilim-confirm' ) ), 'the danger zone button asks to confirm' );
	await submitAndSettle( page, page.locator( 'button', { hasText: 'Check again now' } ) );
	report( /Check again|Sync complete|enabling and checking|could not finish/i.test( await bodyText( page ) ), 'Check again now answers with a notice' );

	// 4. Disable (a confirm dialog with the script, none without).
	await page.goto( settings, { waitUntil: 'domcontentloaded' } );
	let dialog = '';
	page.on( 'dialog', ( d ) => {
		dialog = d.message();
		d.accept();
	} );
	await submitAndSettle( page, page.locator( 'button', { hasText: 'Disable Babilim' } ) );
	if ( noJs ) {
		report( dialog === '', 'without the script there is no confirm dialog and the form still posts' );
	} else {
		report( /Disable Babilim\?/.test( dialog ), 'with the script a confirm dialog is shown' );
	}
	report( ( await page.locator( 'button', { hasText: 'Enable Babilim' } ).count() ) === 1, 'after disabling, the welcome block is back' );
}

// Waits until the translations list shows (the first check runs on the
// first load after enabling), reloading a few times at most.
async function openList( page, query = '' ) {
	const url = `${ baseUrl }/wp-admin/admin.php?page=babilim${ query }`;
	for ( let i = 0; i <= MAX_RELOADS; i++ ) {
		await page.goto( url, { waitUntil: 'load' } );
		if ( ( await page.locator( 'table.wp-list-table' ).count() ) > 0 ) {
			return true;
		}
	}
	return false;
}

async function rowCount( page ) {
	return page.locator( 'table.wp-list-table tbody#the-list tr:not(.no-items)' ).count();
}

// A dated status ("Available · <date>") never leaves the separator at the end of a line: the separator and the date are one unbreakable
// piece, so the separator and the date's last character are painted on the same line. Returns the number of dated statuses and of stranded separators.
async function separatorCheck( page ) {
	return page.evaluate( () => {
		const tails = [ ...document.querySelectorAll( 'table.wp-list-table tbody#the-list .babilim-cell-status .babilim-cell-tail' ) ];
		const stranded = tails.filter( ( tail ) => {
			const text = tail.firstChild;
			const first = document.createRange();
			first.setStart( text, 0 );
			first.setEnd( text, 1 );
			const last = document.createRange();
			last.setStart( text, text.length - 1 );
			last.setEnd( text, text.length );
			return ! tail.textContent.startsWith( '·' ) || Math.abs( first.getBoundingClientRect().top - last.getBoundingClientRect().top ) > 2;
		} );
		return { dated: tails.length, stranded: stranded.length };
	} );
}

// Every list line, at any width: the status text (measured as painted text, not as a box) stays left of the action cell, never meets the
// button's box, and takes at most three lines. Returns the offending line descriptions.
async function lineLayoutProblems( page ) {
	return page.evaluate( () => {
		const problems = [];
		for ( const line of document.querySelectorAll( 'table.wp-list-table tbody#the-list .babilim-cell-line' ) ) {
			const status = line.querySelector( '.babilim-cell-status' );
			const action = line.querySelector( '.babilim-cell-action' );
			if ( ! status || ! action ) {
				continue;
			}
			const range = document.createRange();
			range.selectNodeContents( status );
			const rects = [ ...range.getClientRects() ].filter( ( r ) => r.width > 0 );
			const actionBox = action.getBoundingClientRect();
			const stacked = actionBox.top >= status.getBoundingClientRect().bottom - 1;
			// Lines are counted from the painted characters of the status' own text nodes (an inline-block's box and the text inside it
			// have tops about a pixel apart and would count twice); tops closer than half a line are one line.
			const lineHeight = parseFloat( getComputedStyle( status ).lineHeight ) || 20;
			const tops = [];
			const walker = document.createTreeWalker( status, NodeFilter.SHOW_TEXT );
			for ( let node = walker.nextNode(); node; node = walker.nextNode() ) {
				for ( let i = 0; i < node.length; i++ ) {
					if ( /\s/.test( node.data[ i ] ) ) {
						continue;
					}
					const c = document.createRange();
					c.setStart( node, i );
					c.setEnd( node, i + 1 );
					const r = c.getBoundingClientRect();
					if ( r.width > 0 ) {
						tops.push( r.top );
					}
				}
			}
			tops.sort( ( a, b ) => a - b );
			let lines = 0;
			let lineTop = -Infinity;
			for ( const t of tops ) {
				if ( t - lineTop > lineHeight / 2 ) {
					lines++;
					lineTop = t;
				}
			}
			const right = Math.max( ...rects.map( ( r ) => r.right ) );
			const name = line.querySelector( '.babilim-cell-lang' ).title;
			// The language name never reaches into the action cell while the button sits beside it.
			const lang = line.querySelector( '.babilim-cell-lang' );
			const langRange = document.createRange();
			langRange.selectNodeContents( lang );
			const langText = langRange.getBoundingClientRect();
			if ( actionBox.width > 0 && actionBox.top < langText.bottom - 1 && langText.right > actionBox.left + 0.5 ) {
				problems.push( `${ name }: language name ends at ${ Math.round( langText.right ) } past the action cell at ${ Math.round( actionBox.left ) }` );
			}
			if ( ! stacked && actionBox.width > 0 && right > actionBox.left + 0.5 ) {
				problems.push( `${ name }: status text ends at ${ Math.round( right ) } past the action cell at ${ Math.round( actionBox.left ) }` );
			}
			for ( const button of action.querySelectorAll( 'button, a' ) ) {
				const b = button.getBoundingClientRect();
				if ( rects.some( ( r ) => r.left < b.right && r.right > b.left && r.top < b.bottom && r.bottom > b.top ) ) {
					problems.push( `${ name }: status text meets the button` );
				}
				// The button's own box stays inside the action cell and inside the table cell around it.
				const label = button.textContent.trim();
				if ( b.left < actionBox.left - 0.5 || b.right > actionBox.right + 0.5 ) {
					problems.push( `${ name }: "${ label }" (${ Math.round( b.left ) }-${ Math.round( b.right ) }) leaves its action cell (${ Math.round( actionBox.left ) }-${ Math.round( actionBox.right ) })` );
				}
				const tdEl = button.closest( 'td' );
				const tdBox = tdEl.getBoundingClientRect();
				const tdStyle = getComputedStyle( tdEl );
				const tdLeft = tdBox.left + parseFloat( tdStyle.paddingLeft );
				const tdRight = tdBox.right - parseFloat( tdStyle.paddingRight );
				if ( b.left < tdLeft - 0.5 || b.right > tdRight + 0.5 ) {
					problems.push( `${ name }: "${ label }" ends at ${ Math.round( b.right ) } past its table cell's content at ${ Math.round( tdRight ) }` );
				}
			}
			if ( lines > 3 ) {
				problems.push( `${ name }: status text takes ${ lines } lines` );
			}
		}
		return problems;
	} );
}

async function countOverlaps( page ) {
	return page.evaluate( () => [ ...document.querySelectorAll( 'table.wp-list-table tbody#the-list .babilim-cell-line' ) ].filter( ( line ) => {
		const lang = line.querySelector( '.babilim-cell-lang' );
		const status = line.querySelector( '.babilim-cell-status' );
		if ( ! lang || ! status ) {
			return false;
		}
		// Stacked lines (the status under the name) cannot overlap sideways.
		if ( status.getBoundingClientRect().top >= lang.getBoundingClientRect().bottom - 1 ) {
			return false;
		}
		// The painted extent of the name's text, not its box: nowrap text that overflows a narrow grid column is drawn past the box.
		const range = document.createRange();
		range.selectNodeContents( lang );
		const textRight = range.getBoundingClientRect().right;
		return textRight > status.getBoundingClientRect().left + 0.5 || lang.scrollWidth > lang.clientWidth + 1;
	} ).length );
}

async function setDateFormat( page, format ) {
	await page.goto( `${ baseUrl }/wp-admin/options-general.php`, { waitUntil: 'load' } );
	await page.evaluate( ( f ) => {
		document.querySelector( '#date_format_custom_radio' ).checked = true;
		document.querySelector( '#date_format_custom' ).value = f;
	}, format );
	await submitAndSettle( page, page.locator( '#submit' ) );
}

// The narrowest viewport at which the lines still sit side by side (name | status | action), found by narrowing the window step by step
// until the es_DO line (the one with the longest name) stacks.
async function sideBySideThreshold( page ) {
	let narrowest = 0;
	for ( let width = 1500; width >= 783; width -= 10 ) {
		await page.setViewportSize( { width, height: 900 } );
		const sideBySide = await page.evaluate( () => {
			const lang = document.querySelector( 'table.wp-list-table tbody#the-list .babilim-cell-lang[title="es_DO"]' );
			const status = lang && lang.parentElement.querySelector( '.babilim-cell-status' );
			return !! status && status.getBoundingClientRect().top < lang.getBoundingClientRect().bottom - 1;
		} );
		if ( ! sideBySide ) {
			break;
		}
		narrowest = width;
	}
	return narrowest;
}

// The narrowest viewport at which every line's button still sits beside its text (side by side or in two columns), never under it.
async function buttonBesideThreshold( page ) {
	let narrowest = 0;
	for ( let width = 1500; width >= 783; width -= 10 ) {
		await page.setViewportSize( { width, height: 900 } );
		const beside = await page.evaluate( () => [ ...document.querySelectorAll( 'table.wp-list-table tbody#the-list .babilim-cell-line' ) ].every( ( line ) => {
			const status = line.querySelector( '.babilim-cell-status' );
			const button = line.querySelector( '.babilim-cell-action button' );
			return ! button || button.getBoundingClientRect().top < status.getBoundingClientRect().bottom - 1;
		} ) );
		if ( ! beside ) {
			break;
		}
		narrowest = width;
	}
	return narrowest;
}

// Every visible button of a list line: the right edges of all of them, and each one's distance from the right edge of its table cell's
// content. A right-aligned column has a spread and a distance of (nearly) zero.
async function buttonAlignment( page ) {
	return page.evaluate( () => {
		const rights = [];
		let gap = 0;
		for ( const button of document.querySelectorAll( 'table.wp-list-table tbody#the-list .babilim-cell-action button' ) ) {
			const b = button.getBoundingClientRect();
			if ( b.width === 0 ) {
				continue;
			}
			const td = button.closest( 'td' );
			const contentRight = td.getBoundingClientRect().right - parseFloat( getComputedStyle( td ).paddingRight );
			rights.push( b.right );
			gap = Math.max( gap, Math.abs( contentRight - b.right ) );
		}
		return { count: rights.length, spread: rights.length ? Math.max( ...rights ) - Math.min( ...rights ) : 0, gap };
	} );
}

// Layout problems, name-over-status overlaps and a horizontal page scroll at the current width.
async function widthProblems( page ) {
	const problems = [ ...await lineLayoutProblems( page ) ];
	const overlaps = await countOverlaps( page );
	if ( overlaps > 0 ) {
		problems.push( `${ overlaps } language name(s) run over their status` );
	}
	const scroll = await page.evaluate( () => ( { scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth } ) );
	if ( scroll.scroll > scroll.client ) {
		problems.push( `the page scrolls sideways (${ scroll.scroll } > ${ scroll.client })` );
	}
	return problems;
}

// Every list line around both thresholds -- where the three columns give way to two (name above status, button at the right), and where
// the button finally drops under the text -- from each threshold up to 120px wider in steps of 10px, plus 1024px and 1280px, with the
// default date format and with a long one: no language name over its status or the action cell, no status over the action cell or its
// button, no button outside its cells, no horizontal scroll, at most three lines. At 1024px and 1280px every button sits at the right
// edge of its table cell, all in one column.
// Within one list cell every line's status starts at the same x (side by side, where the status is a column of its own), and
// pressing a button (busy label plus spinner) leaves the width of its action column unchanged. Returns the offending descriptions.
async function statusAlignmentProblems( page ) {
	return page.evaluate( () => {
		const problems = [];
		for ( const grid of document.querySelectorAll( 'table.wp-list-table tbody#the-list .babilim-cell-grid' ) ) {
			const lines = [ ...grid.querySelectorAll( '.babilim-cell-line' ) ];
			const side = lines.filter( ( line ) => {
				const action = line.querySelector( '.babilim-cell-action' );
				const status = line.querySelector( '.babilim-cell-status' );
				return action && status && action.getBoundingClientRect().top < status.getBoundingClientRect().bottom - 1 && getComputedStyle( line ).display === 'grid';
			} );
			const lefts = side.map( ( line ) => line.querySelector( '.babilim-cell-status' ).getBoundingClientRect().left );
			if ( lefts.length > 1 && Math.max( ...lefts ) - Math.min( ...lefts ) > 1 ) {
				problems.push( `statuses start ${ ( Math.max( ...lefts ) - Math.min( ...lefts ) ).toFixed( 1 ) }px apart in ${ grid.textContent.slice( 0, 30 ) }` );
			}
		}
		for ( const button of document.querySelectorAll( 'table.wp-list-table tbody#the-list .babilim-cell-action button[data-babilim-busy-label]' ) ) {
			const cell = button.closest( '.babilim-cell-action' );
			const before = cell.getBoundingClientRect().width;
			const label = button.textContent;
			button.textContent = button.getAttribute( 'data-babilim-busy-label' );
			const spinner = document.createElement( 'span' );
			spinner.className = 'spinner is-active';
			cell.appendChild( spinner );
			const after = cell.getBoundingClientRect().width;
			spinner.remove();
			button.textContent = label;
			if ( Math.abs( after - before ) > 1 ) {
				problems.push( `busy state changes the action width by ${ ( after - before ).toFixed( 1 ) }px (${ label })` );
			}
		}
		return problems;
	} );
}

async function lineLayoutScenario( page ) {
	await page.setViewportSize( { width: 1500, height: 900 } );
	// The newer-demo line, once installed, offers the longest request label ("Request newer translation").
	await openList( page, '&s=newer' );
	const download = page.locator( 'table.wp-list-table tbody#the-list button', { hasText: 'Download' } );
	report( ( await download.count() ) === 1, 'the newer-demo line has a Download button' );
	if ( ( await download.count() ) === 1 ) {
		await submitAndSettle( page, download );
	}
	await openList( page, '&s=newer' );
	report( ( await page.locator( 'table.wp-list-table tbody#the-list button', { hasText: 'Request newer translation' } ).count() ) === 1, 'once installed, the newer-demo line offers Request newer translation' );
	await openList( page );
	const threshold = await sideBySideThreshold( page );
	report( threshold >= 800, `the lines sit side by side down to ${ threshold }px` );
	const beside = await buttonBesideThreshold( page );
	report( beside > 0 && beside <= 1024, `every button stays beside its text down to ${ beside }px` );
	const widths = [];
	for ( const start of [ threshold, beside ] ) {
		for ( let width = start; width <= start + 120; width += 10 ) {
			widths.push( width );
		}
	}
	widths.push( 1024, 1280 );
	const range = `from ${ threshold }px and from ${ beside }px up 120px, and at 1024px and 1280px`;
	for ( const format of [ '', 'l, F j, Y' ] ) {
		if ( '' !== format ) {
			await setDateFormat( page, format );
			await openList( page );
		}
		const failures = [];
		for ( const width of widths ) {
			await page.setViewportSize( { width, height: 900 } );
			const problems = await widthProblems( page );
			if ( problems.length ) {
				failures.push( `${ width }px: ${ problems.slice( 0, 4 ).join( '; ' ) }` );
			}
		}
		report( failures.length === 0, `${ range }${ '' === format ? '' : ' with a long date format' } every line keeps its name, status and action apart${ failures.length ? ': ' + failures.slice( 0, 3 ).join( ' | ' ) : '' }` );
		for ( const width of [ 1024, 1280 ] ) {
			await page.setViewportSize( { width, height: 900 } );
			const a = await buttonAlignment( page );
			report( a.count >= 3 && a.spread <= 2 && a.gap <= 4, `at ${ width }px${ '' === format ? '' : ' with a long date format' } the ${ a.count } buttons share one right edge (spread ${ a.spread.toFixed( 1 ) }px) at the right of their cells (up to ${ a.gap.toFixed( 1 ) }px away)` );
		}
	}
	for ( const width of [ 1024, 1280, 1500 ] ) {
		await page.setViewportSize( { width, height: 900 } );
		const problems = await statusAlignmentProblems( page );
		report( problems.length === 0, `at ${ width }px every status in a cell starts at the same x and a busy button keeps its column width${ problems.length ? ': ' + problems.slice( 0, 3 ).join( ' | ' ) : '' }` );
	}
	// A translation can make the label longer than English: every button gets a label half again as wide as "Request newer translation"
	// (the longest English one), so the line with the longest language name also carries the widest button.
	await openList( page );
	const longer = [];
	for ( const width of widths ) {
		await page.setViewportSize( { width, height: 900 } );
		const sizes = await page.evaluate( () => {
			const buttons = [ ...document.querySelectorAll( 'table.wp-list-table tbody#the-list .babilim-cell-action button' ) ];
			const newer = buttons.find( ( b ) => 'Request newer translation' === b.textContent.trim() );
			const english = newer ? newer.getBoundingClientRect().width : 0;
			for ( const b of buttons ) {
				b.textContent = 'Request a newer translation for this version';
			}
			return { english, longer: buttons.length ? buttons[ 0 ].getBoundingClientRect().width : 0 };
		} );
		if ( width === threshold ) {
			report( sizes.longer >= sizes.english * 1.4, `the longer label is ${ Math.round( sizes.longer ) }px against ${ Math.round( sizes.english ) }px in English` );
		}
		const problems = await widthProblems( page );
		if ( problems.length ) {
			longer.push( `${ width }px: ${ problems.slice( 0, 4 ).join( '; ' ) }` );
		}
	}
	report( longer.length === 0, `${ range } a label half again as long as English stays inside its cells${ longer.length ? ': ' + longer.slice( 0, 3 ).join( ' | ' ) : '' }` );
	// Optional screenshots of the list for a person to look at (BABILIM_UX_SHOTS: a directory outside the repository).
	const shots = process.env.BABILIM_UX_SHOTS;
	if ( shots ) {
		await openList( page );
		for ( const width of [ 1024, 1280, 1440 ] ) {
			await page.setViewportSize( { width, height: 900 } );
			await page.locator( 'table.wp-list-table' ).screenshot( { path: `${ shots }/list-${ width }${ noJs ? '-nojs' : '' }.png` } );
		}
	}
	await setDateFormat( page, 'F j, Y' );
	await page.setViewportSize( { width: 1280, height: 900 } );
}

// The bundled zh_TW translation on a site whose language is zh_TW (run-neutral-ux-flow.sh sets WPLANG; core strings stay English, only this
// plugin's are translated). The expected strings come from the bundled file itself via the environment, never from this script.
async function l10nScenario( page ) {
	const expectEnable = process.env.BABILIM_UX_EXPECT_ENABLE;
	const expectTitle = process.env.BABILIM_UX_EXPECT_SETTINGS_TITLE;
	const phpWarning = /Warning:|Notice:|Deprecated:|Fatal error/;
	if ( ! expectEnable || ! expectTitle ) {
		report( false, 'BABILIM_UX_EXPECT_ENABLE and BABILIM_UX_EXPECT_SETTINGS_TITLE are required' );
		return;
	}

	await page.goto( `${ baseUrl }/wp-admin/admin.php?page=babilim`, { waitUntil: 'domcontentloaded' } );
	const enable = page.locator( 'button.button-primary' );
	report( ( await enable.count() ) === 1 && ( await enable.innerText() ).trim() === expectEnable, `the enable button reads "${ expectEnable }"` );
	// The bundled file may keep this one string in English; the page then legitimately contains it.
	if ( expectEnable !== 'Enable Babilim' ) {
		report( ! ( await bodyText( page ) ).includes( 'Enable Babilim' ), 'the page does not contain the English "Enable Babilim"' );
	}
	await submitAndSettle( page, enable );
	const listed = await openList( page );
	report( listed, 'the translations list shows after enabling' );
	if ( ! listed ) {
		return;
	}
	report( ! phpWarning.test( await bodyText( page ) ), 'the list shows no PHP warning' );

	for ( const tab of [ 'general', 'advanced' ] ) {
		await page.goto( `${ baseUrl }/wp-admin/admin.php?page=babilim-settings&tab=${ tab }`, { waitUntil: 'load' } );
		report( ! phpWarning.test( await bodyText( page ) ), `the ${ tab } tab shows no PHP warning` );
		report( ( await page.locator( '.nav-tab-active' ).count() ) >= 1, `the ${ tab } tab has an active tab` );
		if ( 'general' === tab ) {
			report( ( await page.locator( '#wpbody-content h1' ).first().innerText() ).trim() === expectTitle, `the settings heading reads "${ expectTitle }" (got "${ await page.locator( "#wpbody-content h1" ).first().innerText() }")` );
		}
	}
	const shots = process.env.BABILIM_UX_SHOTS;
	await page.setViewportSize( { width: 1280, height: 900 } );
	if ( shots ) {
		await page.goto( `${ baseUrl }/wp-admin/admin.php?page=babilim-settings&tab=general`, { waitUntil: 'load' } );
		await page.screenshot( { path: `${ shots }/settings-1280-zh_TW.png` } );
	}

	await openList( page );
	const align = await buttonAlignment( page );
	report( align.count >= 3 && align.spread <= 2 && align.gap <= 4, `at 1280px the list buttons share one right edge (${ align.count } buttons, spread ${ align.spread.toFixed( 1 ) }, gap ${ align.gap.toFixed( 1 ) })` );
	const problems = await widthProblems( page );
	report( problems.length === 0, `at 1280px the translated list has no layout problem${ problems.length ? ': ' + problems.slice( 0, 3 ).join( '; ' ) : '' }` );
	if ( shots ) {
		await page.screenshot( { path: `${ shots }/list-1280-zh_TW.png` } );
	}
}

async function listScenario( page, state ) {
	// Enable (a plain post of the welcome form).
	await page.goto( `${ baseUrl }/wp-admin/admin.php?page=babilim`, { waitUntil: 'domcontentloaded' } );
	await submitAndSettle( page, page.locator( 'button', { hasText: 'Enable Babilim' } ) );
	const listed = await openList( page );
	report( listed, 'the translations list shows after enabling' );
	if ( ! listed ) {
		console.log( `  page text: ${ ( await bodyText( page ) ).slice( 0, 400 ) }` );
		return;
	}
	if ( noJs ) {
		report( state.scriptRequests > 0, 'the page asked for the admin script and it was blocked' );
	} else {
		report( state.scriptStatuses.length > 0 && state.scriptStatuses.every( ( s ) => s === 200 ), 'the admin script loaded (HTTP 200)' );
	}

	// The dated status is still on the page here (a later step installs it): at 1280px and just above the 782px breakpoint its separator travels with its date.
	for ( const width of [ 1280, 783 ] ) {
		await page.setViewportSize( { width, height: 900 } );
		await openList( page );
		const sep = await separatorCheck( page );
		report( sep.dated >= 1, `at ${ width }px a dated status carries its separator with its date (${ sep.dated })` );
		report( sep.stranded === 0, `at ${ width }px no status leaves its separator alone at the end of a line (${ sep.stranded } stranded)` );
	}
	await page.setViewportSize( { width: 1280, height: 900 } );
	await openList( page );

	await lineLayoutScenario( page );
	await openList( page );

	// 1. The five views, each with a count.
	const views = await page.locator( 'ul.subsubsub li a' ).allInnerTexts();
	const names = views.map( ( v ) => v.replace( /\s*\(\d+\)\s*$/, '' ).trim() );
	report( JSON.stringify( names ) === JSON.stringify( [ 'All', 'Available', 'Updates', 'Requestable', 'Installed' ] ), `five views: ${ names.join( ', ' ) }` );
	report( views.every( ( v ) => /\(\d+\)/.test( v ) ), 'every view shows a count' );
	const total = await rowCount( page );
	report( total >= 3, `the list has ${ total } rows` );
	report( ( await page.locator( 'form#babilim-translations form' ).count() ) === 0, 'no nested form' );
	const rows = await page.evaluate( () => {
		const table = document.querySelector( 'table.wp-list-table' );
		return {
			direct: table.querySelectorAll( ':scope > tr' ).length,
			head: table.querySelectorAll( ':scope > thead > tr' ).length,
			foot: table.querySelectorAll( ':scope > tfoot > tr' ).length,
			headerInBody: table.querySelectorAll( ':scope > tbody > tr > th[scope="col"]' ).length,
			bodies: table.querySelectorAll( ':scope > tbody' ).length,
		};
	} );
	report( rows.head === 1 && rows.foot === 1 && rows.direct === 0 && rows.headerInBody === 0 && rows.bodies === 1, `the header row is only in thead and tfoot (${ JSON.stringify( rows ) })` );
	report( ( await page.locator( 'form#babilim-translations' ).getAttribute( 'action' ) ) === null, 'the bulk form has no action attribute' );

	// 2. Search: type woo and press Enter (a plain GET form; the search box has its own submit button).
	await page.fill( 'form#babilim-filter input[name="s"]', 'woo' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.locator( 'form#babilim-filter input[name="s"]' ).press( 'Enter' ) ] );
	report( new URL( page.url() ).searchParams.get( 's' ) === 'woo', `search puts s=woo in the URL (${ page.url() })` );
	const woo = await rowCount( page );
	report( woo === 1 && /WooCommerce/.test( await bodyText( page ) ), 'only WooCommerce is left' );
	report( ( await page.locator( 'form#babilim-filter input[type="submit"][value="Filter"]' ).count() ) === 0 && ( await page.locator( 'select[name="babilim_locale"]' ).count() ) === 0, 'there is no language select and no Filter button' );

	// 3. Language filter: the language links under the views.
	await openList( page );
	const langLinks = page.locator( 'p.babilim-locale-links a' );
	report( ( await page.locator( 'p.babilim-locale-links' ).count() ) === 1 && ( await langLinks.count() ) >= 1, 'language links are shown with several languages' );
	const fr = page.locator( 'p.babilim-locale-links a[title="fr_FR"]' );
	report( ( await fr.count() ) === 1, 'there is a link for fr_FR' );
	if ( ( await fr.count() ) === 1 ) {
		await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), fr.click() ] );
		report( new URL( page.url() ).searchParams.get( 'babilim_locale' ) === 'fr_FR', 'the language is in the URL' );
		report( ( await page.locator( 'p.babilim-locale-links strong[aria-current][title="fr_FR"]' ).count() ) === 1 && ( await page.locator( 'p.babilim-locale-links a[title="fr_FR"]' ).count() ) === 0, 'the chosen language is bold text with aria-current, not a link' );
		const langs = page.locator( 'table.wp-list-table tbody#the-list .babilim-cell-lang' );
		report( ( await rowCount( page ) ) === 1 && ( await langs.count() ) === 1 && ( await langs.first().getAttribute( 'title' ) ) === 'fr_FR', 'only the fr_FR line of WooCommerce is shown' );
		// A search keeps the chosen language.
		await page.fill( 'form#babilim-filter input[name="s"]', 'woo' );
		await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.locator( 'form#babilim-filter input[name="s"]' ).press( 'Enter' ) ] );
		const kept = new URL( page.url() ).searchParams;
		report( kept.get( 'babilim_locale' ) === 'fr_FR' && kept.get( 's' ) === 'woo', 'a search keeps the chosen language' );
		report( ( await page.locator( 'p.babilim-locale-links a', { hasText: 'All languages' } ).count() ) === 1, 'All languages is a link while a language is chosen' );
	}
	await listDownload( page );
	await listOthers( page );
}

// Download from the list: after a reload the line says Installed and
// offers no Download button, because the installed flag comes from the
// plugin's own manifest while the mock keeps answering "available".
async function listDownload( page ) {
	await openList( page, '&s=woo' );
	const cell = page.locator( 'table.wp-list-table tbody#the-list .babilim-cell-row' ).filter( { has: page.locator( '.babilim-cell-lang[title="de_DE"]' ) } ).first();
	const button = cell.locator( 'button', { hasText: 'Download' } );
	report( ( await button.count() ) === 1, 'the WooCommerce de_DE line has a Download button' );
	if ( ( await button.count() ) !== 1 ) {
		return;
	}
	await submitAndSettle( page, button );
	const downloadText = await bodyText( page );
	report( /Downloaded and installed|Already installed/.test( downloadText ), 'the download answers with a notice' );
	if ( ! /Downloaded and installed|Already installed/.test( downloadText ) ) {
		console.log( `  page text: ${ downloadText.slice( 0, 600 ) }` );
	}
	report( new URL( page.url() ).searchParams.get( 's' ) === 'woo', 'the redirect keeps the search' );
	await openList( page, '&s=woo' );
	const after = page.locator( 'table.wp-list-table tbody#the-list .babilim-cell-row' ).filter( { has: page.locator( '.babilim-cell-lang[title="de_DE"]' ) } ).first();
	const done = after.locator( '.babilim-cell-status', { hasText: 'Installed' } );
	report( ( await done.count() ) === 1 && ( await after.locator( 'button', { hasText: 'Download' } ).count() ) === 0, 'after a reload the line says Installed and offers no Download button' );
	report( /^Installed( · \w+ \d+, \d{4})?$/.test( ( await after.locator( '.babilim-cell-status' ).innerText() ).trim() ), 'the line says Installed' );
}

async function listOthers( page ) {
	// Mock "queued" shows Scheduled.
	await openList( page, '&s=queued' );
	const queued = page.locator( 'table.wp-list-table tbody#the-list' );
	report( /Scheduled, waiting to be processed/.test( await queued.innerText() ), 'a queued translation says "Scheduled, waiting to be processed"' );
	report( ( await queued.locator( 'button', { hasText: 'Scheduled' } ).count() ) === 0, 'and the status text is not a button' );

	// Keyboard: Tab from the search button to the first request button, Enter.
	let dialog = '';
	page.on( 'dialog', ( d ) => {
		dialog = d.message();
		d.accept();
	} );
	await openList( page );
	await page.focus( 'form#babilim-filter input[name="s"]' );
	let reached = false;
	for ( let i = 0; i < 80 && ! reached; i++ ) {
		await page.keyboard.press( 'Tab' );
		reached = await page.evaluate( () => document.activeElement && 'BUTTON' === document.activeElement.tagName && /Request translation/.test( document.activeElement.textContent ) );
	}
	report( reached, 'Tab reaches a "Request translation" button' );
	if ( reached ) {
		await page.evaluate( () => {
			window.__babilimBeforeSubmit = true;
		} );
		await page.keyboard.press( 'Enter' );
		await page.waitForFunction( () => ! window.__babilimBeforeSubmit, null, { timeout: 120000 } );
		await page.waitForLoadState( 'load' );
		report( /Translation requested|requested/i.test( await bodyText( page ) ), 'Enter on the focused button sends the request' );
		if ( noJs ) {
			report( dialog === '', 'without the script there is no confirm dialog' );
		} else {
			report( /free slots/.test( dialog ), 'with the script a free-slot confirmation is shown' );
		}
	}
	if ( noJs ) {
		report( /free slots/.test( await bodyText( page ) ), 'without the script the free-slot sentence is on the page' );
	}

	// 480px: no horizontal scroll, and a long name is not cut off.
	await page.setViewportSize( { width: 480, height: 900 } );
	await openList( page );
	const metrics = await page.evaluate( () => ( { scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth } ) );
	report( metrics.scroll <= metrics.client, `no horizontal scroll at 480px (${ metrics.scroll } <= ${ metrics.client })` );
	const long = page.locator( 'table.wp-list-table tbody#the-list tr', { hasText: 'Remarkably Long' } );
	report( ( await long.count() ) === 1 && /Cut Off In The List/.test( await long.innerText() ), 'the long component name is in the DOM in full' );
	const clipped = await long.evaluate( ( row ) => [ ...row.querySelectorAll( 'td, th, strong, div' ) ].some( ( el ) => el.scrollWidth > el.clientWidth + 1 ) );
	report( ! clipped, 'nothing in the long-name row is clipped at 480px' );
	await page.setViewportSize( { width: 1280, height: 900 } );
	await openList( page );
	// A long language name (a wide first column) never runs over the status text beside it, at 1280px and just above the 782px breakpoint.
	const overlaps = await countOverlaps( page );
	report( overlaps === 0, `at 1280px no language name runs over its status (${ overlaps } overlaps)` );
	await page.setViewportSize( { width: 1280, height: 900 } );
	await openList( page );
	const longName = await page.locator( 'table.wp-list-table tbody#the-list .babilim-cell-lang[title="es_DO"]' ).count();
	report( longName >= 1, 'the long-named language (es_DO) is in the list' );
	const longText = longName >= 1 ? await page.locator( 'table.wp-list-table tbody#the-list .babilim-cell-lang[title="es_DO"]' ).first().innerText() : '';
	report( /Dominicana/.test( longText ), `the es_DO line shows its full native name ("${ longText }")` );
	const zz = page.locator( 'table.wp-list-table tbody#the-list tr', { hasText: 'zz_ZZ' } );
	report( ( await zz.count() ) === 1 && ( await zz.locator( 'button', { hasText: 'Request translation' } ).count() ) >= 1, 'the zz_ZZ language line is listed with its button' );
}

async function enableOnly( page ) {
	await page.goto( `${ baseUrl }/wp-admin/admin.php?page=babilim`, { waitUntil: 'domcontentloaded' } );
	await submitAndSettle( page, page.locator( 'button', { hasText: 'Enable Babilim' } ) );
	return openList( page );
}

async function mockDownloads() {
	try {
		const state = await ( await fetch( `${ mockBase }/_control/state` ) ).json();
		return ( state.download_log || [] ).length;
	} catch ( error ) {
		return -1;
	}
}

function bulkRow( page, slug ) {
	return page.locator( 'table.wp-list-table tbody#the-list tr' ).filter( { has: page.locator( `input[name="components[]"][value="plugin:${ slug }"]` ) } );
}

// Ticks the given components, picks Download in the top or bottom
// selector and presses its own Apply; waits for the page the post returns.
async function bulkApply( page, slugs, where ) {
	for ( const slug of slugs ) {
		await page.locator( `input[name="components[]"][value="plugin:${ slug }"]` ).check();
	}
	const selector = 'top' === where ? '#bulk-action-selector-top' : '#bulk-action-selector-bottom';
	await page.selectOption( selector, 'download' );
	await submitAndSettle( page, page.locator( 'top' === where ? '#doaction' : '#doaction2' ) );
}

async function bulkScenario( page, state ) {
	const listed = await enableOnly( page );
	report( listed, 'the translations list shows after enabling' );
	if ( ! listed ) {
		console.log( `  page text: ${ ( await bodyText( page ) ).slice( 0, 400 ) }` );
		return;
	}
	if ( noJs ) {
		report( state.scriptRequests > 0, 'the page asked for the admin script and it was blocked' );
	}
	report( ( await bulkRow( page, 'bulk-a' ).count() ) === 1, 'the fixture component bulk-a is listed' );

	// 1. Top Apply, two components.
	const before = await mockDownloads();
	await bulkApply( page, [ 'bulk-a', 'bulk-b' ], 'top' );
	let text = await bodyText( page );
	report( /Bulk download: 2 succeeded\./.test( text ), 'top Apply: the notice says 2 succeeded' );
	report( ( await mockDownloads() ) - before === 2, 'top Apply: the service saw two download requests' );
	report( ( await page.locator( 'form#babilim-translations .babilim-cell-status', { hasText: 'Installed' } ).count() ) >= 2, 'top Apply: two lines say Installed' );

	// 2. Bottom Apply (action2), two other components.
	await openList( page );
	const before2 = await mockDownloads();
	await bulkApply( page, [ 'bulk-c', 'bulk-d' ], 'bottom' );
	text = await bodyText( page );
	report( /Bulk download: 2 succeeded\./.test( text ), 'bottom Apply (action2): the notice says 2 succeeded' );
	report( ( await mockDownloads() ) - before2 === 2, 'bottom Apply: the service saw two download requests' );

	// 3. Six components, two per run, then the continue form down to zero.
	// Without JS the owner presses Continue; with JS the clean batches
	// continue by themselves and stop once nothing is left.
	const six = [ 'bulk-f', 'bulk-g', 'bulk-h', 'bulk-i', 'bulk-j', 'bulk-k' ];
	await openList( page );
	const before6 = await mockDownloads();
	if ( ! noJs ) {
		// Records each page that carries an autosubmit form (and its label)
		// as it loads; sessionStorage survives the navigations between them.
		await page.evaluate( () => window.sessionStorage.removeItem( 'babilimAuto' ) );
		await page.addInitScript( () => {
			document.addEventListener( 'DOMContentLoaded', () => {
				const f = document.querySelector( 'form[data-babilim-autosubmit]' );
				if ( f ) {
					const seen = JSON.parse( window.sessionStorage.getItem( 'babilimAuto' ) || '[]' );
					seen.push( ( f.querySelector( 'button' ) || { textContent: '' } ).textContent.trim() );
					window.sessionStorage.setItem( 'babilimAuto', JSON.stringify( seen ) );
				}
			} );
		} );
	}
	await bulkApply( page, six, 'top' );
	if ( noJs ) {
		text = await bodyText( page );
		report( /Bulk download: 2 succeeded and 4 not yet tried\./.test( text ), 'six components: the notice says 4 not yet tried' );
		let rounds = 0;
		for ( ; rounds < 6; rounds++ ) {
			const next = page.locator( 'button', { hasText: 'Continue next batch' } );
			if ( ( await next.count() ) !== 1 ) {
				break;
			}
			const label = ( await next.innerText() ).trim();
			if ( 0 === rounds ) {
				report( label === 'Continue next batch (4 left)', `the continue button says "${ label }"` );
				report( ( await page.locator( 'form input[name="pending[]"]' ).count() ) === 4, 'the continue form carries the four untried components' );
				report( ( await page.locator( 'form[data-babilim-autosubmit]' ).count() ) === 1, 'the continue form is marked to continue by itself (a script would use it)' );
			}
			await submitAndSettle( page, next );
		}
		report( rounds === 2, `the continue form was pressed ${ rounds } time(s) to reach zero` );
	} else {
		const finished = await page.waitForFunction(
			() => document.readyState === 'complete' && /Bulk download: \d+ succeeded\./.test( document.body.innerText ) && ! /not yet tried/.test( document.body.innerText ) && ! document.querySelector( 'form[data-babilim-autosubmit]' ),
			null,
			{ timeout: 120000 }
		).then( () => true, () => false );
		report( finished, 'with a script the batches continued by themselves until no "not yet tried" is left' );
		const seen = await page.evaluate( () => JSON.parse( window.sessionStorage.getItem( 'babilimAuto' ) || '[]' ) );
		report( JSON.stringify( seen ) === JSON.stringify( [ 'Continue next batch (4 left)', 'Continue next batch (2 left)' ] ), `the auto-continue forms seen were ${ JSON.stringify( seen ) }` );
	}
	text = await bodyText( page );
	report( ( await page.locator( 'button', { hasText: 'Continue next batch' } ).count() ) === 0 && ! /not yet tried/.test( text ), 'no continue form is left and nothing is not yet tried' );
	await page.waitForTimeout( 2000 );
	report( ( await mockDownloads() ) - before6 === 6, `the service saw exactly six download requests (${ ( await mockDownloads() ) - before6 }), none repeated, none missing, and it stopped` );
	for ( const slug of six ) {
		const done = ( await bulkRow( page, slug ).locator( '.babilim-cell-status', { hasText: 'Installed' } ).count() ) === 1;
		if ( ! done ) {
			report( false, `${ slug } says Installed after the last batch` );
		}
	}

	// 4. One component whose download fails with a 500.
	await openList( page );
	await bulkApply( page, [ 'bulk-e' ], 'top' );
	text = await bodyText( page );
	report( /Bulk download: 1 failed\./.test( text ), 'the 500: the notice says 1 failed' );
	const notices = await page.locator( '#wpbody-content .notice' ).allInnerTexts().then( ( t ) => t.join( ' ' ) );
	report( /Bulk e \(.+\): /.test( notices ) && ! /bulk-e:de_DE/.test( notices ), 'the 500: the failure list names the component and the language' );
	const retry = bulkRow( page, 'bulk-e' ).locator( 'button:not([disabled])', { hasText: 'Download' } );
	report( ( await retry.count() ) === 1, 'the 500: the line keeps an enabled Download button to retry' );
}

async function rowactionsScenario( page, state ) {
	const listed = await enableOnly( page );
	report( listed, 'the translations list shows after enabling' );
	if ( ! listed ) {
		return;
	}
	await openList( page, '&s=woo' );
	const line = () => page.locator( 'table.wp-list-table tbody#the-list tr' ).filter( { hasText: 'WooCommerce' } );
	await submitAndSettle( page, line().locator( 'button', { hasText: 'Download' } ).first() );
	await openList( page, '&s=woo' );
	report( ( await line().locator( '.babilim-cell-status', { hasText: 'Installed' } ).count() ) === 1, 'the de_DE line says Installed' );
	report( ( await line().locator( 'button', { hasText: /^Roll back/ } ).count() ) === 0, 'no Roll back without a previous build' );
	// Row actions are only shown while the row is hovered or focused.
	const remove = () => line().locator( 'button', { hasText: /^Remove$/ } );
	const reveal = async () => line().first().hover();
	report( ( await remove().count() ) === 1, 'the row has a Remove action (one language: direct button)' );

	if ( ! noJs ) {
		// With the script: confirm() cancelled, then accepted.
		let asked = '';
		page.once( 'dialog', ( d ) => {
			asked = d.message();
			d.dismiss();
		} );
		await page.evaluate( () => {
			window.__babilimBeforeSubmit = true;
		} );
		await reveal();
		await remove().click();
		await page.waitForTimeout( 1500 );
		report( /^Remove the (.+) translation of WooCommerce from this site\?$/.test( asked ), 'a confirm dialog asks the question' );
		report( await page.evaluate( () => window.__babilimBeforeSubmit === true ), 'cancelling the dialog sends nothing' );
		await openList( page, '&s=woo' );
		report( ( await line().locator( '.babilim-cell-status', { hasText: 'Installed' } ).count() ) === 1, 'after cancelling the files are still there' );

		page.once( 'dialog', ( d ) => d.accept() );
		await reveal();
		await submitAndSettle( page, remove() );
		report( ! page.url().includes( 'screen=confirm' ), 'with the script there is no confirmation page' );
		report( /Removed\./.test( await bodyText( page ) ), 'accepting removes in one step (Removed. notice)' );
		await openList( page, '&s=woo' );
		report( ( await line().locator( 'button:not([disabled])', { hasText: 'Download' } ).count() ) === 1, 'after removing the line offers Download again' );
	} else {
		let dialogs = 0;
		page.on( 'dialog', ( d ) => {
			dialogs++;
			d.accept();
		} );
		await reveal();
		await submitAndSettle( page, remove() );
		report( page.url().includes( 'screen=confirm' ), 'without the script the click leads to the confirmation page' );
		report( dialogs === 0, 'and no dialog is shown' );
		report( /Remove the (.+) translation of WooCommerce from this site\?/.test( await bodyText( page ) ), 'the page asks the question, naming the component' );
		await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.locator( 'a.button', { hasText: 'Cancel' } ).click() ] );
		await openList( page, '&s=woo' );
		report( ( await line().locator( '.babilim-cell-status', { hasText: 'Installed' } ).count() ) === 1, 'Cancel leaves the files in place' );
		await reveal();
		await submitAndSettle( page, remove() );
		await submitAndSettle( page, page.locator( 'form button.button-primary', { hasText: 'Remove' } ) );
		report( /Removed\./.test( await bodyText( page ) ), 'confirming on the page removes (Removed. notice)' );
		await openList( page, '&s=woo' );
		report( ( await line().locator( 'button:not([disabled])', { hasText: 'Download' } ).count() ) === 1, 'after removing the line offers Download again' );
	}
}

async function seamScenario( page ) {
	const slug = process.env.BABILIM_SEAM_SLUG;
	const oldVersion = process.env.BABILIM_SEAM_OLD_VERSION;
	const upgrade = process.env.BABILIM_SEAM_UPGRADE_CMD;
	const runDue = process.env.BABILIM_SEAM_RUN_DUE_CMD;
	if ( ! slug || ! oldVersion || ! upgrade || ! runDue ) {
		report( false, 'infra: the seam environment variables are not set' );
		return;
	}
	const row = () => page.locator( 'table.wp-list-table tbody#the-list tr' ).filter( { has: page.locator( `input[name="components[]"][value="plugin:${ slug }"]` ) } );
	const checkAgain = async () => {
		await page.goto( `${ baseUrl }/wp-admin/admin.php?page=babilim-settings&tab=advanced`, { waitUntil: 'load' } );
		await submitAndSettle( page, page.locator( 'button', { hasText: 'Check again now' } ) );
		return bodyText( page );
	};

	// 1. Enable: the service calls the site back for real.
	const listed = await enableOnly( page );
	report( listed, 'enabling against the real service ends on the translations list' );
	if ( ! listed ) {
		console.log( `  page text: ${ ( await bodyText( page ) ).slice( 0, 500 ) }` );
		return;
	}
	report( ( await row().count() ) === 1, `the list shows ${ slug }` );
	const download = row().locator( 'button:not([disabled])', { hasText: 'Download' } );
	report( ( await download.count() ) === 1, 'the de_DE line has a Download button' );
	if ( ( await download.count() ) !== 1 ) {
		console.log( `  row: ${ ( await row().innerText() ).replace( /\s+/g, ' ' ) }` );
		return;
	}

	// 2. Download; after a reload the line is Downloaded from the manifest.
	await submitAndSettle( page, download );
	report( /Downloaded and installed|Already installed/.test( await bodyText( page ) ), 'Download answers with a notice' );
	await openList( page );
	report( ( await row().locator( 'button', { hasText: /^Download$/ } ).count() ) === 0, 'after a reload the line no longer offers Download' );
	report( new RegExp( `built for ${ oldVersion.replace( /\./g, '\\.' ) }` ).test( await row().innerText() ) || /^Installed( · \w+ \d+, \d{4})?$/.test( ( await row().locator( '.babilim-cell-status' ).first().innerText() ).trim() ), 'the line says the translation is Babilim\'s' );

	// 3. A newer component version, then Check again now.
	execSync( upgrade, { stdio: 'pipe' } );
	await checkAgain();
	await openList( page );
	const text = ( await row().innerText() ).replace( /\s+/g, ' ' );
	report( new RegExp( `built for ${ oldVersion.replace( /\./g, '\\.' ) }` ).test( text ), `the line says built for ${ oldVersion } (${ text })` );
	report( ( await row().locator( 'button:not([disabled])', { hasText: 'Request newer translation' } ).count() ) === 1, 'the line offers Request newer translation' );

	// 4. The service's scheduled run takes the candidate, then Check again.
	execSync( runDue, { stdio: 'pipe' } );
	await checkAgain();
	await openList( page );
	const after = ( await row().innerText() ).replace( /\s+/g, ' ' );
	report( /new version scheduled/.test( after ), `after the scheduled run the line says new version scheduled (${ after })` );
}

const browser = await chromium.launch();
let exitCode = 1;
try {
	const context = await browser.newContext();
	const page = await context.newPage();
	const state = { scriptRequests: 0, scriptStatuses: [], pageErrors: [] };
	page.on( 'pageerror', ( error ) => state.pageErrors.push( String( error ) ) );
	if ( noJs ) {
		await page.route( '**/babilim-admin.js*', ( route ) => {
			state.scriptRequests++;
			return route.abort();
		} );
	} else {
		page.on( 'response', ( response ) => {
			if ( response.url().includes( 'babilim-admin.js' ) ) {
				state.scriptStatuses.push( response.status() );
			}
		} );
	}
	await login( page );
	if ( 'enable' === scenario ) {
		await enableScenario( page, state );
	} else if ( 'settings' === scenario ) {
		await settingsScenario( page, state );
	} else if ( 'list' === scenario ) {
		await listScenario( page, state );
	} else if ( 'l10n' === scenario ) {
		await l10nScenario( page );
	} else if ( 'bulk' === scenario ) {
		await bulkScenario( page, state );
	} else if ( 'seam' === scenario ) {
		await seamScenario( page );
	} else if ( 'rowactions' === scenario ) {
		await rowactionsScenario( page, state );
	} else if ( 'screenshots' === scenario ) {
		await screenshotsScenario( page );
	} else {
		report( false, `unknown scenario "${ scenario }"` );
	}
	report( state.pageErrors.length === 0, `no JavaScript errors${ state.pageErrors.length ? ': ' + state.pageErrors[ 0 ] : '' }` );
	exitCode = failures === 0 ? 0 : 1;
} catch ( error ) {
	report( false, `script error: ${ String( error ).split( '\n' )[ 0 ] }` );
} finally {
	await browser.close();
}
process.exit( exitCode );
