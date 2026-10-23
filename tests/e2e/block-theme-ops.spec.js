const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { test, expect, wp, wpEval } = require( './utils' );
const { THEME, PAGE, installTheme, resetState, themeFile, editThemeFile, customize, customizeStyles, report, installMocks, installRtl, githubLog } = require( './helpers' );

const HEADER_EDIT = [ 'Fresh bread every morning.', 'Fresh bread and coffee every morning.' ];
const HEADER_DEPLOY = [ '<!-- wp:site-title /-->', '<!-- wp:site-title /-->\n\n\t<!-- wp:navigation /-->' ];

/**
 * The header part with the Site Editor change, as the editor saves it (with the theme attribute on nested parts).
 *
 * @return {string} Block markup.
 */
const editedHeader = () => themeFile( 'parts/header.html' ).replace( ...HEADER_EDIT );

const row = ( page, title ) => page.locator( '.bto-table tbody tr' ).filter( { has: page.getByRole( 'link', { name: title, exact: true } ) } );

test.describe( 'Block Theme Ops', () => {
	test.beforeAll( installTheme );

	test.beforeEach( () => {
		resetState();
		installTheme();
	} );

	test.afterAll( () => {
		resetState();
		wp( 'theme', 'activate', process.env.WP_THEME || 'twentytwentyfive' );
	} );

	test( 'a Site Editor change shows as customized, with who saved it and a snapshot of the file', async ( { page, rest } ) => {
		const id = await customize( rest, 'template-parts', 'header', editedHeader() );

		await page.goto( PAGE );
		await expect( page.locator( '.bto-card--customized .bto-card__count' ) ).toHaveText( '1' );
		const header = row( page, 'Header' );
		await expect( header.locator( '.bto-badge' ) ).toHaveText( 'Customized' );
		await expect( header ).toContainText( 'parts/header.html' );
		await expect( header ).toContainText( 'by admin' );

		const item = report()[ 'parts/header' ];
		expect( item ).toMatchObject( { id, status: 'customized', probable: 'no', file: 'parts/header.html', author: 'admin' } );
		const snapshot = JSON.parse( wp( 'post', 'meta', 'get', String( id ), '_block_theme_ops_snapshot', '--format=json' ) );
		expect( snapshot ).toMatchObject( { file: 'parts/header.html', theme: THEME, source: 'save' } );
		expect( snapshot.content.trim() ).toBe( themeFile( 'parts/header.html' ) );
		expect( wp( 'post', 'meta', 'get', String( id ), '_block_theme_ops_editor' ) ).toBe( '1' );
	} );

	test( 'a theme deploy after the edit is theme-changed, with the three-way view', async ( { page, rest } ) => {
		const id = await customize( rest, 'template-parts', 'header', editedHeader() );
		editThemeFile( 'parts/header.html', ...HEADER_DEPLOY );

		await page.goto( PAGE );
		await expect( page.locator( '.bto-card--theme-changed .bto-card__count' ) ).toHaveText( '1' );
		await expect( row( page, 'Header' ).locator( '.bto-badge' ) ).toHaveText( 'Theme changed' );
		expect( report()[ 'parts/header' ].status ).toBe( 'theme-changed' );

		await page.getByRole( 'link', { name: 'Header', exact: true } ).click();
		await expect( page.locator( 'h1' ) ).toContainText( 'Header' );
		await expect( page.locator( '.bto-explain' ).first() ).toContainText( 'The theme file changed after this was saved in the Site Editor' );
		// Database copy against the file now: the deploy's navigation is missing and the edit is there.
		await expect( page.locator( '#bto-diff-reset .diff-deletedline' ) ).toContainText( [ /wp:navigation/ ] );
		await expect( page.locator( '#bto-diff-reset .diff-addedline' ) ).toContainText( [ /and coffee/ ] );
		// The theme's own change since the snapshot.
		await expect( page.locator( '#bto-diff-theme .diff-addedline' ) ).toHaveCount( 1 );
		await expect( page.locator( '#bto-diff-theme .diff-addedline' ) ).toContainText( 'wp:navigation' );
		// The customization itself, without the deploy.
		await expect( page.locator( '#bto-diff-custom .diff-addedline' ) ).toContainText( 'and coffee' );
		await expect( page.locator( '#bto-diff-custom' ) ).not.toContainText( 'wp:navigation' );

		const theme = wp( 'block-theme-ops', 'diff', String( id ), '--against=theme' );
		expect( theme ).toContain( '+\t<!-- wp:navigation /-->' );
		const custom = wp( 'block-theme-ops', 'diff', 'parts/header', '--against=snapshot' );
		expect( custom ).toContain( '+\t\t<p>Fresh bread and coffee every morning.</p>' );
		expect( custom ).not.toContain( 'navigation' );
	} );

	test( 'reset keeps a backup, and undo brings the customization back with its snapshot', async ( { page, rest } ) => {
		const id = await customize( rest, 'template-parts', 'header', editedHeader() );
		editThemeFile( 'parts/header.html', ...HEADER_DEPLOY );

		await page.goto( `${ PAGE }&item=${ id }` );
		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await page.getByRole( 'button', { name: 'Reset to theme file' } ).click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( '1 item reset to the theme file. A backup is kept.' );
		await expect( page.locator( '.bto-table' ) ).not.toContainText( 'Header' );

		expect( wp( 'post', 'list', '--post_type=wp_template_part', '--format=count' ) ).toBe( '0' );
		expect( wpEval( `echo get_block_template( '${ THEME }//header', 'wp_template_part' )->source;` ) ).toBe( 'theme' );
		const backups = JSON.parse( wp( 'block-theme-ops', 'backups', '--format=json' ) );
		expect( backups ).toHaveLength( 1 );
		expect( backups[ 0 ] ).toMatchObject( { key: 'parts/header', theme: THEME, reset_by: 'admin' } );

		await page.getByRole( 'link', { name: 'Backups' } ).click();
		await expect( page.locator( '.bto-backups tbody tr' ) ).toHaveCount( 1 );
		await expect( page.locator( '.bto-backups' ) ).toContainText( 'parts/header' );
		await page.getByRole( 'button', { name: 'Undo reset' } ).click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( 'Reset undone. The database copy is back.' );
		await expect( page.locator( '.bto-badge' ).first() ).toHaveText( 'Theme changed' );

		expect( wp( 'post', 'get', String( id ), '--field=post_content' ) ).toContain( 'and coffee' );
		expect( wpEval( `echo get_block_template( '${ THEME }//header', 'wp_template_part' )->source;` ) ).toBe( 'custom' );
		expect( report()[ 'parts/header' ].status ).toBe( 'theme-changed' );
		expect( wp( 'block-theme-ops', 'backups', '--format=count' ) ).toBe( '0' );
	} );

	test( 'the undo link in the notice restores a reset straight away', async ( { page, rest } ) => {
		const id = await customize( rest, 'template-parts', 'header', editedHeader() );

		await page.goto( PAGE );
		await row( page, 'Header' ).hover();
		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await row( page, 'Header' ).getByRole( 'button', { name: 'Reset' } ).click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( 'reset to the theme file' );
		expect( wp( 'post', 'list', '--post_type=wp_template_part', '--format=count' ) ).toBe( '0' );

		await page.locator( '.bto-notice' ).getByRole( 'button', { name: 'Undo' } ).click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( 'Reset undone' );
		expect( wp( 'post', 'get', String( id ), '--field=post_type' ) ).toBe( 'wp_template_part' );
		expect( wpEval( `echo wp_get_object_terms( ${ id }, 'wp_template_part_area', array( 'fields' => 'names' ) )[0];` ) ).toBe( 'header' );
	} );

	test( 'keep hides an item until its theme file changes again', async ( { page, rest } ) => {
		const id = await customize( rest, 'template-parts', 'header', editedHeader() );
		editThemeFile( 'parts/header.html', ...HEADER_DEPLOY );

		await page.goto( PAGE );
		await row( page, 'Header' ).hover();
		await row( page, 'Header' ).getByRole( 'button', { name: 'Keep' } ).click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( '1 item kept.' );
		await expect( page.locator( '.bto-table tbody' ) ).not.toContainText( 'Header' );
		await expect( page.locator( '.subsubsub' ) ).toContainText( 'Kept (1)' );
		expect( wp( 'post', 'meta', 'get', String( id ), '_block_theme_ops_kept' ) ).not.toBe( '' );
		expect( report()[ 'parts/header' ] ).toBeUndefined();
		expect( report( '--kept' )[ 'parts/header' ].kept ).toBe( 'yes' );

		await page.getByRole( 'link', { name: /^Kept/ } ).click();
		await expect( row( page, 'Header' ).locator( '.bto-badge--kept' ) ).toBeVisible();

		// The next deploy brings it back.
		editThemeFile( 'parts/header.html', 'Fresh bread every morning.', 'Fresh bread daily.' );
		await page.goto( PAGE );
		await expect( row( page, 'Header' ).locator( '.bto-badge' ) ).toHaveText( 'Theme changed' );
		expect( report()[ 'parts/header' ].kept ).toBe( 'no' );
	} );

	test( 'identical items reset in bulk, and an item with no theme file cannot be reset', async ( { page, rest } ) => {
		await customize( rest, 'templates', 'page', themeFile( 'templates/page.html' ) );
		const created = await rest( 'POST', '/wp/v2/templates', { slug: 'wp-custom-template-landing', title: 'Landing', content: '<!-- wp:paragraph --><p>Spring sale</p><!-- /wp:paragraph -->' } );
		expect( created.status ).toBe( 201 );

		await page.goto( PAGE );
		await expect( row( page, 'Pages' ).locator( '.bto-badge' ) ).toHaveText( 'Identical' );
		await expect( row( page, 'Landing' ).locator( '.bto-badge' ) ).toHaveText( 'Database only' );
		await expect( row( page, 'Landing' ).getByRole( 'button', { name: 'Reset' } ) ).toHaveCount( 0 );
		expect( report() ).toMatchObject( { 'templates/page': { status: 'identical' }, 'templates/wp-custom-template-landing': { status: 'db-only', file: '' } } );

		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await page.getByRole( 'button', { name: 'Reset 1 identical item' } ).click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( '1 identical item reset. Nothing changes on the site.' );
		await expect( page.locator( '.bto-table' ) ).not.toContainText( 'Pages' );

		const left = report();
		expect( left[ 'templates/page' ] ).toBeUndefined();
		expect( left[ 'templates/wp-custom-template-landing' ].status ).toBe( 'db-only' );
		expect( () => wp( 'block-theme-ops', 'reset', 'templates/wp-custom-template-landing', '--yes' ) ).toThrow( /no theme file/ );
	} );

	test( 'global styles only drift when the theme changes a value the user changed too', async ( { page, rest } ) => {
		const id = await customizeStyles( rest, { color: { background: '#f4ecdf' } } );
		expect( report().styles ).toMatchObject( { id, status: 'customized', file: 'theme.json' } );

		// A theme change to another value does not hide anything.
		editThemeFile( 'theme.json', '"lineHeight": "1.6"', '"lineHeight": "1.7"' );
		expect( report().styles.status ).toBe( 'customized' );

		// A theme change to the same value does.
		editThemeFile( 'theme.json', '"background": "var(--wp--preset--color--base)"', '"background": "#ffffff"' );
		expect( report().styles.status ).toBe( 'theme-changed' );

		await page.goto( `${ PAGE }&item=${ id }` );
		await expect( page.locator( 'h1' ) ).toContainText( 'Global styles' );
		await expect( page.locator( '#bto-diff-reset .diff-addedline' ) ).toContainText( '#f4ecdf' );
		await expect( page.locator( '#bto-diff-theme .diff-addedline' ) ).toContainText( [ /#ffffff/, /1\.7/ ] );

		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await page.getByRole( 'button', { name: 'Reset to theme file' } ).click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( 'reset to the theme file' );
		const reset = JSON.parse( wp( 'post', 'get', String( id ), '--field=post_content' ) );
		expect( reset ).toEqual( { version: 3, isGlobalStylesUserThemeJSON: true } );
		expect( report().styles ).toBeUndefined();

		await page.locator( '.bto-notice' ).getByRole( 'button', { name: 'Undo' } ).click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( 'Reset undone' );
		expect( JSON.parse( wp( 'post', 'get', String( id ), '--field=post_content' ) ).styles.color.background ).toBe( '#f4ecdf' );
		expect( report().styles.status ).toBe( 'theme-changed' );
	} );

	test( 'the zip export is laid out like the theme', async ( { page, rest } ) => {
		await customize( rest, 'template-parts', 'header', editedHeader() );
		// The editor saves nested template parts with the theme name; the export leaves it out.
		await customize( rest, 'templates', 'index', themeFile( 'templates/index.html' ).replace( '{"slug":"footer","tagName":"footer"}', `{"slug":"footer","theme":"${ THEME }","tagName":"footer"}` ).replace( 'Latest from the bakery', 'Fresh from the oven' ) );
		await customizeStyles( rest, { color: { background: '#f4ecdf' } } );

		await page.goto( PAGE );
		await page.locator( '#cb-select-all-1' ).check();
		await page.locator( '#bto-bulk-action' ).selectOption( 'export' );
		const [ download ] = await Promise.all( [ page.waitForEvent( 'download' ), page.getByRole( 'button', { name: 'Apply' } ).click() ] );
		expect( download.suggestedFilename() ).toMatch( new RegExp( `^${ THEME }-site-editor-changes-\\d{4}-\\d{2}-\\d{2}\\.zip$` ) );

		const zip = path.join( fs.mkdtempSync( path.join( os.tmpdir(), 'bto-' ) ), 'export.zip' );
		await download.saveAs( zip );
		const listing = execFileSync( 'unzip', [ '-Z1', zip ], { encoding: 'utf8' } ).trim().split( '\n' ).sort();
		expect( listing ).toEqual( [ `${ THEME }/parts/header.html`, `${ THEME }/templates/index.html`, `${ THEME }/theme.json` ] );
		const index = execFileSync( 'unzip', [ '-p', zip, `${ THEME }/templates/index.html` ], { encoding: 'utf8' } );
		expect( index ).toContain( 'Fresh from the oven' );
		expect( index ).toContain( '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->' );
		expect( index ).not.toContain( '"theme"' );
		const themeJson = JSON.parse( execFileSync( 'unzip', [ '-p', zip, `${ THEME }/theme.json` ], { encoding: 'utf8' } ) );
		expect( themeJson.styles.color.background ).toBe( '#f4ecdf' );
		expect( themeJson.settings.color.palette ).toHaveLength( 3 );

		// The same files from WP-CLI, written inside the site.
		wp( 'block-theme-ops', 'export', '--zip=/tmp/bto-export.zip' );
		expect( wpEval( "$z = new ZipArchive(); $z->open( '/tmp/bto-export.zip' ); $n = array(); for ( $i = 0; $i < $z->numFiles; $i++ ) { $n[] = $z->getNameIndex( $i ); } sort( $n ); echo implode( ',', $n );" ) ).toBe( `${ THEME }/parts/header.html,${ THEME }/templates/index.html,${ THEME }/theme.json` );
	} );

	test( 'a pull request goes to GitHub as one commit, and the token is never shown again', async ( { page, rest } ) => {
		installMocks();
		const id = await customize( rest, 'template-parts', 'header', editedHeader() );

		await page.goto( `${ PAGE }&tab=settings` );
		await page.locator( '#bto-repo' ).fill( 'https://github.com/acme/corner-bakery-site' );
		await page.locator( '#bto-path' ).fill( '/wp-content/themes/corner-bakery/' );
		await page.locator( '#bto-token' ).fill( 'test-token' );
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( 'Settings saved.' );
		await expect( page.locator( '#bto-token' ) ).toHaveValue( '' );
		await expect( page.locator( '.bto-token-status' ) ).toHaveText( 'A token is saved. It is never shown again.' );
		expect( await page.content() ).not.toContain( 'test-token' );
		const settings = JSON.parse( wp( 'option', 'get', 'block_theme_ops_settings', '--format=json' ) );
		expect( settings ).toMatchObject( { repo: 'acme/corner-bakery-site', branch: 'main', path: 'wp-content/themes/corner-bakery', token: 'test-token' } );

		await page.goto( `${ PAGE }&item=${ id }` );
		let question = '';
		page.once( 'dialog', ( dialog ) => {
			question = dialog.message();
			dialog.accept();
		} );
		await page.getByRole( 'button', { name: 'Open pull request' } ).click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( 'Pull request #42 opened.' );
		await expect( page.locator( '.bto-pr-link' ) ).toHaveAttribute( 'href', 'https://github.com/acme/corner-bakery-site/pull/42' );
		expect( question ).toBe( 'Open a pull request on acme/corner-bakery-site (main) with these changes?' );

		const calls = githubLog();
		expect( calls.map( ( call ) => `${ call.method } ${ call.path }` ) ).toEqual( [
			'GET /repos/acme/corner-bakery-site/git/ref/heads/main',
			'GET /repos/acme/corner-bakery-site/git/commits/base000',
			'POST /repos/acme/corner-bakery-site/git/trees',
			'POST /repos/acme/corner-bakery-site/git/commits',
			'POST /repos/acme/corner-bakery-site/git/refs',
			'POST /repos/acme/corner-bakery-site/pulls',
		] );
		expect( calls.every( ( call ) => 'Bearer test-token' === call.auth ) ).toBe( true );
		expect( calls[ 2 ].body ).toMatchObject( { base_tree: 'tree000', tree: [ { path: 'wp-content/themes/corner-bakery/parts/header.html', mode: '100644', type: 'blob' } ] } );
		expect( calls[ 2 ].body.tree[ 0 ].content ).toContain( 'Fresh bread and coffee every morning.' );
		expect( calls[ 3 ].body ).toMatchObject( { tree: 'tree111', parents: [ 'base000' ] } );
		expect( calls[ 4 ].body.ref ).toMatch( new RegExp( `^refs/heads/block-theme-ops/${ THEME }-\\d{8}-\\d{6}$` ) );
		expect( calls[ 5 ].body ).toMatchObject( { base: 'main', head: calls[ 4 ].body.ref.replace( 'refs/heads/', '' ) } );
		expect( calls[ 5 ].body.body ).toContain( '| Header (Template part) | `parts/header.html` | Customized | admin |' );

		// WP-CLI opens one too.
		expect( wp( 'block-theme-ops', 'export', 'parts/header', '--pr' ) ).toContain( 'Pull request #42 opened: https://github.com/acme/corner-bakery-site/pull/42' );
		expect( githubLog() ).toHaveLength( 12 );

		// A refused token comes back as a readable error.
		wpEval( "$s = get_option( 'block_theme_ops_settings' ); $s['token'] = 'wrong'; update_option( 'block_theme_ops_settings', $s );" );
		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await page.getByRole( 'button', { name: 'Open pull request' } ).click();
		await expect( page.locator( '.notice-error.bto-notice' ) ).toContainText( 'GitHub refused the request: Bad credentials (HTTP 401' );

		// The token can be removed.
		await page.goto( `${ PAGE }&tab=settings` );
		await page.getByLabel( 'Remove the saved token' ).check();
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( page.locator( '.bto-token-status' ) ).toHaveCount( 0 );
		expect( JSON.parse( wp( 'option', 'get', 'block_theme_ops_settings', '--format=json' ) ).token ).toBe( '' );
	} );

	test( 'a theme update raises the notice, the menu count, the email and Site Health, once', async ( { page, rest } ) => {
		installMocks();
		await customize( rest, 'template-parts', 'header', editedHeader() );
		await page.goto( `${ PAGE }&tab=settings` );
		await page.locator( '#bto-notify' ).check();
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( 'Settings saved.' );

		editThemeFile( 'parts/header.html', ...HEADER_DEPLOY );
		// What WordPress fires after it updates a theme.
		// Core's own listener (translation updates) is left out: it needs the upgrader classes and the network.
		wpEval( `remove_all_actions( 'upgrader_process_complete', 20 ); do_action( 'upgrader_process_complete', null, array( 'type' => 'theme', 'action' => 'update', 'themes' => array( '${ THEME }' ) ) );` );
		expect( JSON.parse( wp( 'option', 'get', 'block_theme_ops_alerts', '--format=json' ) ) ).toMatchObject( { context: 'upgrade' } );

		const mail = JSON.parse( wp( 'option', 'get', 'bto_e2e_mail', '--format=json' ) );
		expect( mail ).toHaveLength( 1 );
		expect( mail[ 0 ].to ).toEqual( [ 'admin@example.com' ] );
		expect( mail[ 0 ].subject ).toContain( '1 Site Editor change now hides a newer theme file' );
		expect( mail[ 0 ].message ).toContain( '- Header (Template part): parts/header.html' );

		// The daily check does not report it again.
		wp( 'cron', 'event', 'run', 'block_theme_ops_check' );
		expect( JSON.parse( wp( 'option', 'get', 'bto_e2e_mail', '--format=json' ) ) ).toHaveLength( 1 );

		await page.goto( '/wp-admin/index.php' );
		const notice = page.locator( '.block-theme-ops-notice' );
		await expect( notice ).toContainText( '1 Site Editor change still overrides a newer theme file: Header (template part).' );
		await expect( page.locator( '#menu-appearance .awaiting-mod' ) ).toHaveText( '1' );

		// Only where themes are looked after, not on every other screen.
		for ( const screen of [ '/wp-admin/edit.php', '/wp-admin/plugins.php', '/wp-admin/tools.php' ] ) {
			await page.goto( screen );
			await expect( page.locator( '#wpbody-content' ) ).toBeVisible();
			await expect( page.locator( '.block-theme-ops-notice' ) ).toHaveCount( 0 );
		}
		await page.goto( '/wp-admin/update-core.php' );
		await expect( page.locator( '.block-theme-ops-notice' ) ).toHaveCount( 1 );

		await page.goto( '/wp-admin/site-health.php' );
		await expect( page.locator( '#health-check-site-status-recommended' ) ).toContainText( '1 Site Editor change hides a newer theme file', { timeout: 30_000 } );

		await page.goto( '/wp-admin/index.php' );
		await notice.getByRole( 'button', { name: 'Dismiss' } ).click();
		await expect( page.locator( '.block-theme-ops-notice' ) ).toHaveCount( 0 );
		expect( () => wp( 'option', 'get', 'block_theme_ops_alerts' ) ).toThrow();
	} );

	test( 'a deploy outside WordPress is caught by the daily check and wp block-theme-ops check', async ( { page, rest } ) => {
		await customize( rest, 'template-parts', 'header', editedHeader() );
		expect( wp( 'block-theme-ops', 'check' ) ).toContain( 'No Site Editor change hides a newer theme file.' );
		expect( wpEval( "echo wp_get_schedule( 'block_theme_ops_check' );" ) ).toBe( 'daily' );

		editThemeFile( 'parts/header.html', ...HEADER_DEPLOY );
		let failure;
		try {
			wp( 'block-theme-ops', 'check' );
		} catch ( error ) {
			failure = error;
		}
		expect( failure.status ).toBe( 1 );
		expect( failure.stdout ).toContain( 'parts/header: parts/header.html changed after the database copy was saved.' );
		expect( JSON.parse( wp( 'option', 'get', 'block_theme_ops_alerts', '--format=json' ) ) ).toMatchObject( { context: 'cli' } );

		await page.goto( '/wp-admin/themes.php' );
		await expect( page.locator( '.block-theme-ops-notice' ) ).toContainText( 'Header (template part)' );
		await page.locator( '.block-theme-ops-notice' ).getByRole( 'link', { name: 'Review in Theme Drift' } ).click();
		await expect( page ).toHaveURL( /status=theme-changed/ );
		await expect( row( page, 'Header' ) ).toBeVisible();
	} );

	test( 'on a right-to-left site, diffs and code still read left to right, and the columns are named', async ( { page, rest } ) => {
		installRtl();
		const id = await customize( rest, 'template-parts', 'header', editedHeader() );
		editThemeFile( 'parts/header.html', ...HEADER_DEPLOY );
		const landing = await rest( 'POST', '/wp/v2/templates', { slug: 'wp-custom-template-landing', title: 'Landing', content: '<!-- wp:paragraph --><p>Spring sale</p><!-- /wp:paragraph -->' } );

		await page.goto( `${ PAGE }&item=${ id }` );
		await expect( page.locator( 'html' ) ).toHaveAttribute( 'dir', 'rtl' );
		for ( const diff of [ '#bto-diff-reset', '#bto-diff-theme', '#bto-diff-custom' ] ) {
			await expect( page.locator( `${ diff } .bto-diff__table` ) ).toHaveAttribute( 'dir', 'ltr' );
			expect( await page.locator( `${ diff } table.diff` ).evaluate( ( node ) => getComputedStyle( node ).direction ) ).toBe( 'ltr' );
		}
		await expect( page.locator( '#bto-diff-reset' ) ).toContainText( 'Reset throws the database copy away.' );
		await expect( page.locator( '.bto-facts code' ) ).toHaveAttribute( 'dir', 'ltr' );
		// The way back points the way the language reads.
		await expect( page.locator( '.bto-back a' ) ).toHaveText( '→ Theme Drift' );

		await page.goto( `${ PAGE }&item=${ landing.body.wp_id }` );
		await expect( page.locator( 'pre.bto-code' ) ).toHaveAttribute( 'dir', 'ltr' );
		expect( await page.locator( 'pre.bto-code' ).evaluate( ( node ) => getComputedStyle( node ).direction ) ).toBe( 'ltr' );
	} );

	test( 'the tabs have a name, and the slug chips are readable', async ( { page, rest } ) => {
		await customize( rest, 'template-parts', 'header', editedHeader() );
		await customize( rest, 'template-parts', 'footer', themeFile( 'parts/footer.html' ).replace( 'Open Tuesday to Sunday', 'Open every day' ) );
		await customize( rest, 'templates', 'single', themeFile( 'templates/single.html' ).replace( '</main>', '<!-- wp:paragraph --><p>Thanks for reading.</p><!-- /wp:paragraph --></main>' ) );

		await page.goto( PAGE );
		await expect( page.getByRole( 'navigation', { name: 'Theme Drift sections' } ) ).toBeVisible();

		// WCAG AA: 4.5:1 for the small code chips, on white and on the grey striped rows.
		const ratios = await page.locator( '.bto-type code' ).evaluateAll( ( nodes ) => {
			const parse = ( value ) => ( value.match( /[\d.]+/g ) || [] ).map( Number );
			const over = ( top, bottom ) => {
				const alpha = top.length > 3 ? top[ 3 ] : 1;
				return [ 0, 1, 2 ].map( ( i ) => top[ i ] * alpha + bottom[ i ] * ( 1 - alpha ) );
			};
			const background = ( node ) => {
				const layers = [];
				for ( let el = node; el; el = el.parentElement ) {
					const color = parse( getComputedStyle( el ).backgroundColor );
					if ( color.length && ( color.length < 4 || color[ 3 ] > 0 ) ) {
						layers.push( color );
						if ( color.length < 4 || color[ 3 ] === 1 ) {
							break;
						}
					}
				}
				return layers.reverse().reduce( ( below, layer ) => over( layer, below ), [ 255, 255, 255 ] );
			};
			const luminance = ( rgb ) => {
				const [ r, g, b ] = rgb.map( ( v ) => {
					v /= 255;
					return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
				} );
				return 0.2126 * r + 0.7152 * g + 0.0722 * b;
			};
			return nodes.map( ( node ) => {
				const back = background( node );
				const a = luminance( over( parse( getComputedStyle( node ).color ), back ) );
				const b = luminance( back );
				return ( Math.max( a, b ) + 0.05 ) / ( Math.min( a, b ) + 0.05 );
			} );
		} );
		expect( ratios ).toHaveLength( 3 );
		for ( const ratio of ratios ) {
			expect( ratio ).toBeGreaterThanOrEqual( 4.5 );
		}
	} );

	test( 'Open pull request waits until GitHub is set up, and says where', async ( { page, rest } ) => {
		const id = await customize( rest, 'template-parts', 'header', editedHeader() );

		await page.goto( `${ PAGE }&item=${ id }` );
		await expect( page.getByRole( 'button', { name: 'Open pull request' } ) ).toBeDisabled();
		const hint = page.locator( '#bto-pr-hint' );
		await expect( hint ).toContainText( 'Pull requests need a repository and a token.' );
		await expect( hint.getByRole( 'link', { name: 'Settings' } ) ).toHaveAttribute( 'href', /tab=settings/ );
		await expect( page.getByRole( 'button', { name: 'Open pull request' } ) ).toHaveAttribute( 'aria-describedby', 'bto-pr-hint' );

		await page.goto( PAGE );
		await expect( page.locator( '#bto-bulk-action option[value="pr"]' ) ).toBeDisabled();

		// Sent anyway, say from an old tab: the error links to the settings.
		await row( page, 'Header' ).locator( 'input[type="checkbox"]' ).check();
		await page.locator( '#bto-bulk-action option[value="pr"]' ).evaluate( ( option ) => ( option.disabled = false ) );
		await page.locator( '#bto-bulk-action' ).selectOption( 'pr' );
		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await page.getByRole( 'button', { name: 'Apply' } ).click();
		await expect( page.locator( '.notice-error.bto-notice' ) ).toContainText( 'Set the repository and a token' );
		await page.locator( '.bto-notice' ).getByRole( 'link', { name: 'Open the GitHub settings' } ).click();
		await expect( page.locator( '#bto-repo' ) ).toBeVisible();

		// Once set up, the button works again.
		wpEval( "update_option( 'block_theme_ops_settings', array( 'repo' => 'acme/corner-bakery-site', 'branch' => 'main', 'path' => '', 'token' => 'test-token', 'notify' => false ) );" );
		await page.goto( `${ PAGE }&item=${ id }` );
		await expect( page.getByRole( 'button', { name: 'Open pull request' } ) ).toBeEnabled();
		await expect( page.locator( '#bto-pr-hint' ) ).toHaveCount( 0 );
	} );

	test( 'Backups says up front when an item is back already or customized again', async ( { page, rest } ) => {
		await customize( rest, 'template-parts', 'header', editedHeader() );
		wp( 'block-theme-ops', 'reset', 'parts/header', '--yes' );
		const backup = JSON.parse( wp( 'block-theme-ops', 'backups', '--format=json' ) )[ 0 ].id;

		await page.goto( `${ PAGE }&tab=backups` );
		await expect( page.locator( '.bto-backups' ).getByRole( 'button', { name: 'Undo reset' } ) ).toBeVisible();

		// An undo in an activity log puts the same customization back.
		await customize( rest, 'template-parts', 'header', editedHeader() );
		await page.goto( `${ PAGE }&tab=backups` );
		const blocked = page.locator( `.bto-backup[data-id="${ backup }"] .bto-blocked` );
		await expect( blocked ).toHaveAttribute( 'data-reason', 'restored' );
		await expect( blocked ).toContainText( 'Back already' );
		await expect( blocked ).toContainText( 'This database copy is back already, for example through an undo in an activity log. There is nothing to undo.' );
		await expect( page.locator( '.bto-backups' ).getByRole( 'button', { name: 'Undo reset' } ) ).toHaveCount( 0 );
		expect( () => wp( 'block-theme-ops', 'undo', String( backup ) ) ).toThrow( /back already/ );

		// Someone changes it again: that is a different message.
		await customize( rest, 'template-parts', 'header', editedHeader().replace( 'and coffee', 'and cake' ) );
		await page.goto( `${ PAGE }&tab=backups` );
		await expect( blocked ).toHaveAttribute( 'data-reason', 'customized' );
		await expect( blocked ).toContainText( 'Customized again since' );
		await expect( blocked ).toContainText( 'Someone customized this item again since the reset.' );
		expect( wp( 'post', 'list', '--post_type=wp_template_part', '--format=count' ) ).toBe( '1' );
	} );

	test( 'an item recreated with no user logged in shows System, not Unknown', async ( { page } ) => {
		// What an undo from WP-CLI or another plugin leaves: a footer part with no author and no editor recorded.
		const id = Number(
			wpEval( `$id = wp_insert_post( array( 'post_type' => 'wp_template_part', 'post_name' => 'footer', 'post_title' => 'Footer', 'post_status' => 'publish', 'post_author' => 0, 'post_content' => '<!-- wp:paragraph --><p>Open every day.</p><!-- /wp:paragraph -->' ) ); wp_set_object_terms( $id, '${ THEME }', 'wp_theme' ); wp_set_object_terms( $id, 'footer', 'wp_template_part_area' ); echo $id;` )
		);
		expect( report()[ 'parts/footer' ] ).toMatchObject( { id, author: 'System' } );

		await page.goto( PAGE );
		await expect( row( page, 'Footer' ) ).toContainText( 'by System' );
		await expect( row( page, 'Footer' ) ).not.toContainText( 'Unknown' );
	} );

	test( 'a customization from before the plugin was active is probably theme-changed when the file is newer', async ( { page, rest } ) => {
		const id = await customize( rest, 'template-parts', 'header', editedHeader() );
		// As if Block Theme Ops was not active at the time: no snapshot, and the edit is a day older than the file.
		wp( 'post', 'meta', 'delete', String( id ), '_block_theme_ops_snapshot' );
		wpEval( `global $wpdb; $wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ), array( 'ID' => ${ id } ) ); clean_post_cache( ${ id } );` );

		expect( report()[ 'parts/header' ] ).toMatchObject( { status: 'theme-changed', probable: 'yes' } );
		await page.goto( PAGE );
		await expect( row( page, 'Header' ).locator( '.bto-probable' ) ).toHaveText( 'probably' );
		await page.goto( `${ PAGE }&item=${ id }` );
		await expect( page.locator( '.bto-facts' ) ).toContainText( 'None. This was saved before Block Theme Ops was active.' );
		await expect( page.locator( '#bto-diff-theme' ) ).toHaveCount( 0 );

		// The check takes a baseline snapshot, so the next deploy is certain.
		try {
			wp( 'block-theme-ops', 'check' );
		} catch ( error ) {
			// Exits 1: the item is theme-changed.
		}
		expect( JSON.parse( wp( 'post', 'meta', 'get', String( id ), '_block_theme_ops_snapshot', '--format=json' ) ).source ).toBe( 'baseline' );
		editThemeFile( 'parts/header.html', ...HEADER_DEPLOY );
		expect( report()[ 'parts/header' ] ).toMatchObject( { status: 'theme-changed', probable: 'no' } );
	} );

	test( 'confirmations go through wp.hooks', async ( { page, rest } ) => {
		const id = await customize( rest, 'template-parts', 'header', editedHeader() );
		await page.goto( `${ PAGE }&item=${ id }` );

		await page.evaluate( () => {
			window.wp.hooks.addFilter( 'blockThemeOps.confirmMessage', 'e2e', ( message, details ) => ( 'keep' === details.action ? 'Keep it?' : message ) );
			window.wp.hooks.addAction( 'blockThemeOps.beforeSubmit', 'e2e', ( details ) => sessionStorage.setItem( 'bto-e2e', JSON.stringify( details ) ) );
		} );
		let question = '';
		page.once( 'dialog', ( dialog ) => {
			question = dialog.message();
			dialog.dismiss();
		} );
		await page.getByRole( 'button', { name: 'Keep the database copy' } ).click();
		await expect.poll( () => question ).toBe( 'Keep it?' );
		// Dismissed: nothing was sent.
		expect( wp( 'post', 'meta', 'list', String( id ), '--keys=_block_theme_ops_kept', '--format=count' ) ).toBe( '0' );
		expect( await page.evaluate( () => sessionStorage.getItem( 'bto-e2e' ) ) ).toBeNull();

		// A filter that returns an empty message skips the question.
		await page.evaluate( () => window.wp.hooks.addFilter( 'blockThemeOps.confirmMessage', 'e2e-skip', () => '' ) );
		let asked = false;
		page.on( 'dialog', ( dialog ) => {
			asked = true;
			dialog.accept();
		} );
		await page.getByRole( 'button', { name: 'Reset to theme file' } ).click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( 'reset to the theme file' );
		expect( asked ).toBe( false );
		expect( JSON.parse( await page.evaluate( () => sessionStorage.getItem( 'bto-e2e' ) ) ) ).toEqual( { action: 'reset', items: [ id ] } );
		expect( wp( 'post', 'list', '--post_type=wp_template_part', '--format=count' ) ).toBe( '0' );
	} );

	test( 'abilities report, diff, export and reset (WordPress 6.9+)', async ( { page, rest } ) => {
		test.skip( '1' !== wpEval( "echo function_exists( 'wp_register_ability' ) ? 1 : 0;" ), 'The Abilities API arrived in WordPress 6.9.' );
		const id = await customize( rest, 'template-parts', 'header', editedHeader() );
		editThemeFile( 'parts/header.html', ...HEADER_DEPLOY );

		const listed = await rest( 'GET', '/wp-abilities/v1/abilities?category=block-theme-ops' );
		expect( listed.body.map( ( ability ) => ability.name ).sort() ).toEqual( [ 'block-theme-ops/export-changes', 'block-theme-ops/get-drift-report', 'block-theme-ops/get-item-diff', 'block-theme-ops/reset-to-theme' ] );
		const reset = listed.body.find( ( ability ) => 'block-theme-ops/reset-to-theme' === ability.name );
		expect( reset.meta.annotations ).toMatchObject( { readonly: false, destructive: true } );

		const report = await rest( 'GET', '/wp-abilities/v1/abilities/block-theme-ops/get-drift-report/run?input[status]=theme-changed' );
		expect( report.status ).toBe( 200 );
		expect( report.body ).toMatchObject( { theme: THEME, counts: { 'theme-changed': 1 }, items: [ { id, key: 'parts/header', status: 'theme-changed', author: 'admin' } ] } );

		const diff = await rest( 'GET', '/wp-abilities/v1/abilities/block-theme-ops/get-item-diff/run?input[item]=parts/header' );
		expect( diff.body.diffs.theme_changes ).toContain( '+\t<!-- wp:navigation /-->' );
		expect( diff.body.diffs.customization ).toContain( '+\t\t<p>Fresh bread and coffee every morning.</p>' );

		const exported = await rest( 'GET', '/wp-abilities/v1/abilities/block-theme-ops/export-changes/run' );
		expect( Object.keys( exported.body.files ) ).toEqual( [ 'parts/header.html' ] );
		expect( exported.body.files[ 'parts/header.html' ] ).toContain( 'and coffee' );

		const done = await rest( 'POST', '/wp-abilities/v1/abilities/block-theme-ops/reset-to-theme/run', { input: { item: String( id ) } } );
		expect( done.status ).toBe( 200 );
		expect( done.body.reset ).toEqual( [ { key: 'parts/header', backup_id: expect.any( Number ) } ] );
		expect( wp( 'post', 'list', '--post_type=wp_template_part', '--format=count' ) ).toBe( '0' );

		await page.goto( `${ PAGE }&tab=backups` );
		await expect( page.locator( '.bto-backups tbody tr' ) ).toHaveCount( 1 );
	} );

	test( 'personal data: privacy text, export and erase', async ( { page, rest } ) => {
		const id = await customize( rest, 'template-parts', 'header', editedHeader() );
		wp( 'block-theme-ops', 'reset', 'parts/header', '--yes', '--user=admin' );
		const backup = JSON.parse( wp( 'block-theme-ops', 'backups', '--format=json' ) )[ 0 ];

		const exported = JSON.parse( wpEval( "$e = apply_filters( 'wp_privacy_personal_data_exporters', array() ); echo wp_json_encode( call_user_func( $e['block-theme-ops']['callback'], 'admin@example.com', 1 ) );" ) );
		expect( exported.done ).toBe( true );
		expect( exported.data[ 0 ].item_id ).toBe( `block-theme-ops-${ backup.id }` );
		expect( exported.data[ 0 ].data ).toEqual( expect.arrayContaining( [ { name: 'Reset by this person', value: 'Yes' } ] ) );

		const erased = JSON.parse( wpEval( "$e = apply_filters( 'wp_privacy_personal_data_erasers', array() ); echo wp_json_encode( call_user_func( $e['block-theme-ops']['callback'], 'admin@example.com', 1 ) );" ) );
		expect( erased.items_removed ).toBe( true );
		expect( wp( 'post', 'meta', 'get', String( backup.id ), '_block_theme_ops_reset_by' ) ).toBe( '0' );
		expect( id ).toBeGreaterThan( 0 );

		await page.goto( '/wp-admin/options-privacy.php?tab=policyguide' );
		await expect( page.locator( 'body' ) ).toContainText( 'Block Theme Ops records the user ID of the person who saved them' );
	} );

	test( 'uninstall removes settings, alerts, snapshots and backups but keeps the Site Editor changes', async ( { page, rest } ) => {
		const id = await customize( rest, 'template-parts', 'header', editedHeader() );
		const page_id = await customize( rest, 'templates', 'page', themeFile( 'templates/page.html' ) );
		wp( 'block-theme-ops', 'reset', 'templates/page', '--yes' );
		editThemeFile( 'parts/header.html', ...HEADER_DEPLOY );
		try {
			wp( 'block-theme-ops', 'check' );
		} catch ( error ) {
			// Exits 1: the header is theme-changed.
		}
		wp( 'option', 'update', 'block_theme_ops_settings', '{"repo":"acme/site","token":"secret"}', '--format=json' );
		expect( wp( 'block-theme-ops', 'backups', '--format=count' ) ).toBe( '1' );

		// Runs uninstall.php the way WordPress does, without deleting the plugin folder (it is mounted from the repository).
		// The plugin is still active here, so the next request would schedule the check again; read it in the same request.
		expect( wpEval( "define( 'WP_UNINSTALL_PLUGIN', 'block-theme-ops/block-theme-ops.php' ); include WP_PLUGIN_DIR . '/block-theme-ops/uninstall.php'; echo wp_next_scheduled( 'block_theme_ops_check' ) ? 'scheduled' : 'none';" ) ).toBe( 'none' );

		for ( const option of [ 'block_theme_ops_settings', 'block_theme_ops_alerts' ] ) {
			expect( () => wp( 'option', 'get', option ) ).toThrow();
		}
		expect( wp( 'post', 'list', '--post_type=bto_backup', '--post_status=any', '--format=count' ) ).toBe( '0' );
		const keys = JSON.parse( wp( 'post', 'meta', 'list', String( id ), '--format=json' ) ).map( ( meta ) => meta.meta_key );
		expect( keys.filter( ( key ) => key.startsWith( '_block_theme_ops_' ) ) ).toEqual( [] );
		expect( keys ).toContain( 'origin' );
		expect( wp( 'post', 'get', String( id ), '--field=post_content' ) ).toContain( 'and coffee' );
		expect( page_id ).toBeGreaterThan( 0 );

		await page.goto( `${ PAGE }&tab=backups` );
		await expect( page.locator( '.bto-backups' ) ).toContainText( 'No resets to undo.' );
		await page.goto( `${ PAGE }&tab=settings` );
		await expect( page.locator( '#bto-repo' ) ).toHaveValue( '' );
	} );
} );
