// Renders the wordpress.org directory icons and banners from the SVG
// sources in .wordpress-org/src/. Run via `make wporg-assets`.
//
// Playwright is resolved from NODE_PATH. Without it the script prints
// "FAIL infra playwright missing" and exits 1.

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
let chromium;
try {
	( { chromium } = require( 'playwright' ) );
} catch ( error ) {
	console.error( 'FAIL infra playwright missing (set NODE_PATH to a directory that contains it)' );
	process.exit( 1 );
}

const dir = join( dirname( fileURLToPath( import.meta.url ) ), '..', '.wordpress-org' );

// [ source, width, height, output ]
const table = [
	[ 'src/icon.svg', 128, 128, 'icon-128x128.png' ],
	[ 'src/icon.svg', 256, 256, 'icon-256x256.png' ],
	[ 'src/banner.svg', 772, 250, 'banner-772x250.png' ],
	[ 'src/banner.svg', 1544, 500, 'banner-1544x500.png' ],
];

const browser = await chromium.launch();
try {
	for ( const [ src, width, height, out ] of table ) {
		const svg = readFileSync( join( dir, src ), 'utf8' ).replace(
			'<svg ',
			`<svg width="${ width }" height="${ height }" `
		);
		const page = await browser.newPage( {
			viewport: { width, height },
			deviceScaleFactor: 1,
		} );
		await page.setContent(
			'<!doctype html><html><body style="margin:0;background:transparent">' + svg + '</body></html>'
		);
		await page.screenshot( {
			path: join( dir, out ),
			clip: { x: 0, y: 0, width, height },
			omitBackground: true,
		} );
		await page.close();
		console.log( 'wrote ' + out );
	}
} finally {
	await browser.close();
}
