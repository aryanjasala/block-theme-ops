// Records .github/media/demo.gif for the README. Run on demand: npm run demo
const { test, expect, wp } = require( './utils' );
const { prepareSite, recordDemo } = require( './utils/media' );
const { PAGE, installTheme, resetState, installMocks, seedDemo, unseedDemo } = require( './helpers' );

test( 'demo: a theme deploy left a Site Editor change behind; compare, reset, undo, open a pull request', async ( { browser, request } ) => {
	test.setTimeout( 180_000 );
	prepareSite( 'Corner Bakery' );
	resetState();
	installTheme();
	installMocks();
	const nonce = ( await ( await request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim();
	const rest = async ( method, route, data ) => {
		const response = await request.fetch( `/index.php?rest_route=${ encodeURIComponent( route ) }`, { method, headers: { 'X-WP-Nonce': nonce }, data } );
		return { status: response.status(), body: await response.json() };
	};
	const ids = await seedDemo( rest );
	wp( 'option', 'update', 'block_theme_ops_settings', '{"repo":"corner-bakery/website","branch":"main","path":"wp-content/themes/corner-bakery","token":"test-token"}', '--format=json' );
	try {
		wp( 'block-theme-ops', 'check' );
	} catch ( error ) {
		// Exits 1: the header is theme-changed.
	}

	try {
		await recordDemo( browser, async ( page, demo ) => {
			await page.goto( '/wp-admin/themes.php' );
			await demo.start();
			await demo.moveTo( page.locator( '.block-theme-ops-notice p' ).first() );
			await demo.pause( 1800 );
			await demo.click( page.getByRole( 'link', { name: 'Review in Theme Drift' } ) );
			await page.goto( PAGE );
			await demo.moveTo( page.locator( '.bto-cards' ) );
			await demo.pause( 1500 );
			await demo.click( page.getByRole( 'link', { name: 'Header', exact: true } ) );
			await expect( page.locator( '#bto-diff-reset' ) ).toBeVisible();
			await demo.moveTo( page.locator( '#bto-diff-reset .diff-deletedline' ).first() );
			await demo.pause( 1800 );
			await demo.moveTo( page.locator( '#bto-diff-theme h2' ) );
			await demo.pause( 1500 );
			await page.evaluate( () => window.scrollTo( { top: 0, behavior: 'smooth' } ) );
			await demo.pause( 600 );
			await demo.click( page.getByRole( 'button', { name: 'Open pull request' } ), 300 );
			await expect( page.locator( '.bto-notice' ) ).toContainText( 'Pull request #42 opened.' );
			await demo.moveTo( page.locator( '.bto-pr-link' ) );
			await demo.pause( 1800 );
			await demo.click( page.getByRole( 'button', { name: 'Reset to theme file' } ), 300 );
			await expect( page.locator( '.bto-notice' ) ).toContainText( 'reset to the theme file' );
			await demo.pause( 1200 );
			await demo.click( page.locator( '.bto-notice' ).getByRole( 'button', { name: 'Undo' } ), 300 );
			await expect( page.locator( '.bto-notice' ) ).toContainText( 'Reset undone' );
			await demo.pause( 1800 );
		}, { fps: 8, width: 1100 } );
	} finally {
		resetState();
		unseedDemo();
		wp( 'theme', 'activate', process.env.WP_THEME || 'twentytwentyfive' );
	}
	expect( ids.header ).toBeGreaterThan( 0 );
} );
