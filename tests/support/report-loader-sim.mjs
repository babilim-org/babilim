// Runs the report panel loader (the inline script Babilim_Admin::report_loader_js()
// returns, read from the file named in argv[2]) against a minimal fake DOM and
// prints one JSON line with the result of each scenario. No network: fetch and
// script loading are simulated. Used by Test_Admin_Report_Panel.
import { readFileSync } from 'node:fs';

const code = readFileSync( process.argv[ 2 ], 'utf8' );
const tick = () => new Promise( ( r ) => setTimeout( r, 20 ) );

// answers: what each fetch in turn answers ('network' throws).
// scripts: whether each added script in turn loads (true) or fails (false).
async function scenario( { answers, scripts = [ true ] } ) {
	const listeners = [];
	const appended = [];
	const removed = [];
	const errors = [];
	const nav = { assigned: 0, reloads: 0 };
	let fetches = 0;
	let opens = 0;
	const link = {
		getAttribute: ( name ) => ( 'href' === name ? '#' : null ),
		click() {
			opens++;
			dispatch();
		},
	};
	const target = { closest: ( sel ) => ( '#wp-admin-bar-babilim-report > a' === sel ? link : null ) };
	function dispatch() {
		const ev = { target, prevented: false, preventDefault() { this.prevented = true; } };
		listeners.forEach( ( l ) => l( ev ) );
		return ev;
	}
	const win = {
		babilimReportLoader: { ajaxUrl: '/ajax', nonce: 'n1', src: '/r.js?ver=1' },
		URLSearchParams,
		location: { assign: () => { nav.assigned++; }, reload: () => { nav.reloads++; } },
		console: { error: ( ...args ) => { errors.push( args.map( String ).join( ' ' ) ); } },
		fetch: async () => {
			const answer = answers[ Math.min( fetches, answers.length - 1 ) ];
			fetches++;
			if ( 'network' === answer ) {
				throw new Error( 'offline' );
			}
			return { json: async () => answer };
		},
	};
	win.window = win;
	const doc = {
		addEventListener: ( type, l ) => { if ( 'click' === type ) { listeners.push( l ); } },
		createElement: () => {
			const el = { remove() { removed.push( el ); } };
			return el;
		},
		body: {
			appendChild: ( s ) => {
				const loads = scripts[ Math.min( appended.length, scripts.length - 1 ) ];
				appended.push( s );
				setTimeout( () => ( loads ? s.onload() : s.onerror() ), 0 );
			},
		},
	};
	new Function( 'window', 'document', 'console', code )( win, doc, win.console );

	const snap = () => ( {
		fetches,
		scripts: appended.length,
		removed: removed.length,
		opens,
		settings: undefined !== win.babilimReport,
		reloads: nav.reloads,
		assigned: nav.assigned,
		errors: errors.length,
	} );
	const first = dispatch();
	const whileLoading = dispatch();
	await tick();
	const afterFirst = snap();
	const second = dispatch();
	await tick();
	const afterSecond = snap();
	const third = dispatch();
	await tick();
	return {
		firstPrevented: first.prevented,
		whileLoadingPrevented: whileLoading.prevented,
		secondPrevented: second.prevented,
		thirdPrevented: third.prevented,
		afterFirst,
		afterSecond,
		end: snap(),
		errorText: errors,
	};
}

const ok = { success: true, data: { strings: {} } };
const results = {
	ok: await scenario( { answers: [ ok ] } ),
	refusedThenOk: await scenario( { answers: [ -1, ok ] } ),
	errorThenOk: await scenario( { answers: [ { success: false, data: { code: 'forbidden' } }, ok ] } ),
	networkThenOk: await scenario( { answers: [ 'network', ok ] } ),
	scriptFailsThenOk: await scenario( { answers: [ ok ], scripts: [ false, true ] } ),
	alwaysRefused: await scenario( { answers: [ -1 ] } ),
};
console.log( JSON.stringify( results ) );
