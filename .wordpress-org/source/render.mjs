/**
 * Renders the banner and icon in this folder to the PNG sizes WordPress.org uses: `node .wordpress-org/source/render.mjs`.
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const here = dirname( fileURLToPath( import.meta.url ) );
const mark = readFileSync( join( here, 'mark.svg.inc' ), 'utf8' );
const html = ( name ) => readFileSync( join( here, name ), 'utf8' ).replace( '<!--#include mark.svg.inc -->', mark );

const images = [
	[ 'banner.html', 772, 250, 1, 'banner-772x250.png' ],
	[ 'banner.html', 772, 250, 2, 'banner-1544x500.png' ],
	[ 'icon.html', 128, 128, 1, 'icon-128x128.png' ],
	[ 'icon.html', 128, 128, 2, 'icon-256x256.png' ],
];

const browser = await chromium.launch();
for ( const [ source, width, height, scale, out ] of images ) {
	const page = await browser.newPage( { viewport: { width, height }, deviceScaleFactor: scale } );
	await page.setContent( html( source ) );
	await page.screenshot( { path: join( here, '..', out ) } );
	await page.close();
}
await browser.close();
