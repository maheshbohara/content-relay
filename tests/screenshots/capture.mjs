/**
 * Captures the WordPress.org screenshots listed in readme.txt into .wordpress-org/.
 *
 * Not part of the test suite: run seed.sh first, then `node tests/screenshots/capture.mjs`.
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const here = dirname( fileURLToPath( import.meta.url ) );
const out = join( here, '..', '..', '.wordpress-org' );
const [ user, pass ] = readFileSync( join( here, '.auth' ), 'utf8' ).trim().split( '\n' );
const STAGING = 'http://localhost:8900';
const LIVE = 'http://localhost:8910';

const browser = await chromium.launch();
const context = await browser.newContext( { viewport: { width: 1400, height: 900 } } );
const page = await context.newPage();
// The relay button asks for confirmation.
page.on( 'dialog', ( dialog ) => dialog.accept() );

async function logIn( base ) {
	// Straight to a light admin screen: the dashboard can wait a long time on its news widgets.
	await page.goto( `${ base }/wp-login.php?redirect_to=${ encodeURIComponent( `${ base }/wp-admin/profile.php` ) }` );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', pass );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin\/profile\.php/, { waitUntil: 'domcontentloaded' } );
}

async function shot( n ) {
	await page.mouse.move( 0, 0 );
	await page.screenshot( { path: join( out, `screenshot-${ n }.png` ) } );
}

await logIn( STAGING );
await logIn( LIVE );

// 1. The "Relay to…" action in the pages list.
await page.goto( `${ STAGING }/wp-admin/edit.php?post_type=page` );
const row = page.locator( '#the-list tr', { hasText: 'Spring Menu 2027' } );
await row.hover();
await page.screenshot( { path: join( out, 'screenshot-1.png' ) } );

// 2. The plan from the receiving site, with the diff of the page it would update.
await row.getByRole( 'link', { name: /Relay .* to another site/ } ).click();
await page.getByRole( 'button', { name: 'Prepare relay' } ).click();
await page.getByRole( 'heading', { name: /^Plan from/ } ).waitFor();
await page.getByRole( 'button', { name: 'Show changes' } ).first().click();
await page.locator( '.content-relay-diff' ).waitFor();
await page.getByRole( 'heading', { name: /^Plan from/ } ).scrollIntoViewIfNeeded();
await page.evaluate( () => window.scrollBy( 0, -24 ) );
await shot( 2 );

// 3. The result and the checks of the relayed page.
await page.getByRole( 'button', { name: /^Relay \d+ changes?$/ } ).click();
await page.getByText( 'Every check passed.' ).waitFor( { timeout: 120_000 } );
await page.getByText( /^Relay applied on/ ).scrollIntoViewIfNeeded();
await page.evaluate( () => window.scrollBy( 0, -24 ) );
await shot( 3 );

// 4. The relay history, where any relay can be rolled back.
await page.goto( `${ STAGING }/wp-admin/tools.php?page=content-relay-history` );
await shot( 4 );

// 5. The settings of the receiving site: receiving on, and the key issued to the staging site.
await page.goto( `${ LIVE }/wp-admin/options-general.php?page=content-relay` );
// Taller, so the receiving switch and the key table both fit.
await page.setViewportSize( { width: 1400, height: 1100 } );
await shot( 5 );

await browser.close();
