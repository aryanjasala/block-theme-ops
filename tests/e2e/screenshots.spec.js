// Regenerates .wordpress-org/screenshot-N.png (readme.txt "== Screenshots ==" and README.md).
// Run on demand: npm run screenshots
const { test, expect, wp, wpEval } = require( './utils' );
const { prepareSite, shot } = require( './utils/media' );
const { PAGE, installTheme, resetState, installMocks, seedDemo, unseedDemo, editThemeFile } = require( './helpers' );

test.describe.serial( 'Screenshots', () => {
	test.use( { viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 } );
	let ids;

	test.beforeAll( async ( { request } ) => {
		prepareSite( 'Corner Bakery' );
		resetState();
		installTheme();
		installMocks();
		const nonce = ( await ( await request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim();
		const rest = async ( method, route, data ) => {
			const response = await request.fetch( `/index.php?rest_route=${ encodeURIComponent( route ) }`, { method, headers: { 'X-WP-Nonce': nonce }, data } );
			return { status: response.status(), body: await response.json() };
		};
		ids = await seedDemo( rest );
		wpEval( "update_option( 'block_theme_ops_settings', array( 'notify' => true, 'repo' => 'corner-bakery/website', 'branch' => 'main', 'path' => 'wp-content/themes/corner-bakery', 'token' => 'test-token' ), false );" );
	} );

	test.afterAll( () => {
		resetState();
		unseedDemo();
		wp( 'theme', 'activate', process.env.WP_THEME || 'twentytwentyfive' );
	} );

	test( '1: Block Theme Ops with every status', async ( { page } ) => {
		await page.goto( PAGE );
		await expect( page.locator( '.bto-table tbody tr' ) ).toHaveCount( 5 );
		await shot( page, 1 );
	} );

	test( '2: one template part with the three-way view', async ( { page } ) => {
		await page.goto( `${ PAGE }&item=${ ids.header }` );
		await expect( page.locator( '#bto-diff-theme' ) ).toBeVisible();
		// Down to the end of the second diff: what a reset throws away, and what the theme changed.
		const box = await page.locator( '#bto-diff-theme' ).boundingBox();
		await shot( page, 2, { fullPage: true, clip: { x: 0, y: 0, width: 1280, height: Math.ceil( box.y + box.height + 16 ) } } );
	} );

	test( '3: a pull request opened', async ( { page } ) => {
		await page.goto( PAGE );
		await page.locator( `#bto-cb-${ ids.header }` ).check();
		await page.locator( `#bto-cb-${ ids.footer }` ).check();
		await page.locator( `#bto-cb-${ ids.styles }` ).check();
		await page.locator( '#bto-bulk-action' ).selectOption( 'pr' );
		await page.getByRole( 'button', { name: 'Apply' } ).click();
		await page.locator( '.bto-confirm' ).getByRole( 'button' ).first().click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( 'Pull request #42 opened.' );
		await shot( page, 3 );
	} );

	test( '4: the notice after a theme update', async ( { page } ) => {
		try {
			wp( 'block-theme-ops', 'check' );
		} catch ( error ) {
			// Exits 1: the header is theme-changed.
		}
		await page.goto( '/wp-admin/index.php' );
		await expect( page.locator( '.block-theme-ops-notice' ) ).toBeVisible();
		await shot( page, 4 );
	} );

	test( '5: the Import tab previews what a zip would change', async ( { page } ) => {
		const payload = "$d = array( 'theme' => 'corner-bakery', 'manifest' => array(), 'rejected' => array(), 'files' => array( 'parts/header.html' => '<!-- wp:group {\"tagName\":\"header\"} --><header class=\"wp-block-group\"><!-- wp:site-title /--><!-- wp:paragraph --><p>Fresh bread and coffee, baked at dawn.</p><!-- /wp:paragraph --></header><!-- /wp:group -->', 'parts/footer.html' => '<!-- wp:paragraph --><p>Open every day, 7 am to 4 pm.</p><!-- /wp:paragraph -->', 'templates/single.html' => '<!-- wp:post-content /-->', 'theme.json' => '{\"version\":3,\"styles\":{\"color\":{\"background\":\"#fbf4ea\"}}}' ) ); set_transient( 'block_theme_ops_import_1_abcdefghijklmnopqrst', $d, HOUR_IN_SECONDS );";
		wpEval( payload );
		await page.goto( `${ PAGE }&tab=import&review=abcdefghijklmnopqrst` );
		await expect( page.locator( '.bto-import-table' ) ).toBeVisible();
		await shot( page, 5 );
	} );

	test( '6: the Locks tab', async ( { page } ) => {
		wp( 'block-theme-ops', 'lock', 'parts/header', 'parts/footer', 'styles' );
		await page.goto( `${ PAGE }&tab=locks` );
		await expect( page.locator( '.bto-locks-table' ) ).toBeVisible();
		await shot( page, 6 );
	} );

	test( '7: merging a theme update, with one conflict to choose', async ( { page } ) => {
		// The theme also reworded the line the Site Editor change reworded: one conflict, and the new navigation merges on its own.
		editThemeFile( 'parts/header.html', 'Fresh bread every morning.', 'Fresh bread, baked at dawn.' );
		await page.goto( `${ PAGE }&item=${ ids.header }&merge=1` );
		await expect( page.locator( '.bto-conflict' ) ).toHaveCount( 1 );
		await page.getByLabel( 'Use the theme\'s version' ).check();
		await page.getByRole( 'button', { name: 'Update the preview' } ).click();
		await expect( page.locator( '.bto-merge-frame' ) ).toBeVisible();
		await page.locator( '.bto-merge-summary' ).scrollIntoViewIfNeeded();
		await shot( page, 7, { fullPage: true, clip: { x: 0, y: 0, width: 1280, height: 1500 } } );
	} );

	test( '8: the Site Editor says the theme changed a template', async ( { page } ) => {
		await page.setViewportSize( { width: 1280, height: 720 } );
		await page.goto( `/wp-admin/site-editor.php?postType=wp_template_part&postId=corner-bakery%2F%2Fheader&canvas=edit` );
		const notice = page.locator( '.components-notice' ).filter( { hasText: 'The theme changed this since you saved your version' } );
		await expect( notice ).toBeVisible( { timeout: 30_000 } );
		const guide = page.getByRole( 'dialog', { name: /welcome to the site editor/i } );
		if ( await guide.isVisible().catch( () => false ) ) {
			await guide.getByRole( 'button', { name: 'Get started' } ).click();
		}
		await shot( page, 8 );
	} );

	test( '9: Undo after a merge and a reset', async ( { page } ) => {
		await page.goto( `${ PAGE }&item=${ ids.header }&merge=1` );
		await page.getByLabel( 'Use the theme\'s version' ).check();
		await page.getByRole( 'button', { name: 'Update the preview' } ).click();
		await page.getByRole( 'button', { name: 'Merge', exact: true } ).click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( 'Merged.' );
		wp( 'block-theme-ops', 'reset', 'templates/page', '--yes' );
		await page.goto( PAGE );
		await expect( page.locator( '.bto-backups tbody tr' ) ).toHaveCount( 2 );
		await page.locator( '#bto-undo' ).scrollIntoViewIfNeeded();
		await shot( page, 9 );
	} );
} );
