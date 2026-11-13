// Import from a zip, and locks against Site Editor saves.
const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { test, expect, wp, wpEval } = require( './utils' );
const { THEME, PAGE, installTheme, resetState, themeFile, customize, customizeStyles, report } = require( './helpers' );

const IMPORT = `${ PAGE }&tab=import`;
const LOCKS = `${ PAGE }&tab=locks`;
const ALL_ENVIRONMENTS = 'production,staging,development,local';

/**
 * The header part as it would be edited on a staging site.
 *
 * @return {string} Block markup.
 */
const stagingHeader = () => themeFile( 'parts/header.html' ).replace( 'Fresh bread every morning.', 'Fresh bread and coffee every morning.' );

const stagingIndex = () => themeFile( 'templates/index.html' ).replace( 'Latest from the bakery', 'Fresh from the oven' );

const importRow = ( page, key ) => page.locator( `.bto-import-row[data-key="${ key }"]` );

/**
 * Downloads the zip Theme Drift makes for every item, as someone moving changes off staging would.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {Promise<string>} Path of the zip on this computer.
 */
async function downloadZip( page ) {
	await page.goto( PAGE );
	await page.locator( '#cb-select-all-1' ).check();
	await page.locator( '#bto-bulk-action' ).selectOption( 'export' );
	const [ download ] = await Promise.all( [ page.waitForEvent( 'download' ), page.getByRole( 'button', { name: 'Apply' } ).click() ] );
	const file = path.join( fs.mkdtempSync( path.join( os.tmpdir(), 'bto-zip-' ) ), 'export.zip' );
	await download.saveAs( file );
	return file;
}

/**
 * Makes a zip from files on this computer, laid out the way the test says.
 *
 * @param {Object} files Path inside the zip => contents.
 * @return {string} Path of the zip.
 */
function makeZip( files ) {
	const dir = fs.mkdtempSync( path.join( os.tmpdir(), 'bto-src-' ) );
	for ( const [ name, contents ] of Object.entries( files ) ) {
		fs.mkdirSync( path.dirname( path.join( dir, name ) ), { recursive: true } );
		fs.writeFileSync( path.join( dir, name ), contents );
	}
	const zip = path.join( fs.mkdtempSync( path.join( os.tmpdir(), 'bto-out-' ) ), 'made.zip' );
	execFileSync( 'zip', [ '-qr', zip, '.' ], { cwd: dir } );
	return zip;
}

/**
 * Puts a zip inside the test site, for WP-CLI.
 *
 * @param {string} file Path on this computer.
 * @return {string} Path inside the site's container.
 */
function putZip( file ) {
	const encoded = fs.readFileSync( file ).toString( 'base64' );
	wpEval( `file_put_contents( '/tmp/bto-import.zip', base64_decode( '${ encoded }' ) );` );
	return '/tmp/bto-import.zip';
}

async function upload( page, file ) {
	await page.goto( IMPORT );
	await page.locator( '#bto-zip' ).setInputFiles( file );
	await page.getByRole( 'button', { name: 'Upload and preview' } ).click();
}

test.describe( 'Block Theme Ops import and locks', () => {
	test.beforeAll( installTheme );

	test.beforeEach( () => {
		resetState();
		installTheme();
	} );

	test.afterAll( () => {
		resetState();
		wp( 'theme', 'activate', process.env.WP_THEME || 'twentytwentyfive' );
	} );

	test( 'a zip from another site imports with a preview first and one undo', async ( { page, rest } ) => {
		await customize( rest, 'template-parts', 'header', stagingHeader() );
		await customize( rest, 'templates', 'index', stagingIndex() );
		await customizeStyles( rest, { color: { background: '#f4ecdf' } } );
		const file = await downloadZip( page );

		// "Production": nothing customized yet.
		resetState();
		expect( wp( 'post', 'list', '--post_type=wp_template_part,wp_template,wp_global_styles', '--format=count' ) ).toBe( '0' );

		await page.goto( IMPORT );
		await expect( page.locator( '.bto-upload' ) ).toBeVisible();
		await page.locator( '#bto-zip' ).setInputFiles( file );
		await page.getByRole( 'button', { name: 'Upload and preview' } ).click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( 'Nothing has changed yet' );

		await expect( page.locator( '.bto-import-row' ) ).toHaveCount( 3 );
		for ( const key of [ 'parts/header', 'templates/index', 'styles' ] ) {
			await expect( importRow( page, key ).locator( '.bto-badge--new' ) ).toHaveText( 'New' );
			await expect( importRow( page, key ).locator( 'input[type="checkbox"]' ) ).toBeChecked();
		}
		await importRow( page, 'parts/header' ).locator( 'summary' ).click();
		await expect( importRow( page, 'parts/header' ).locator( '.diff-addedline' ).first() ).toBeVisible();
		// Looking changes nothing.
		expect( wp( 'post', 'list', '--post_type=wp_template_part,wp_template,wp_global_styles', '--format=count' ) ).toBe( '0' );

		await importRow( page, 'styles' ).locator( 'input[type="checkbox"]' ).uncheck();
		await page.getByRole( 'button', { name: 'Import selected items' } ).click();
		await expect( page.locator( '.bto-confirm' ) ).toContainText( 'Import 2 selected item(s)?' );
		await expect( page.locator( '.bto-confirm' ) ).toContainText( 'undo the whole import' );
		await page.locator( '.bto-confirm' ).getByRole( 'button' ).first().click();

		await expect( page.locator( '.bto-notice' ) ).toContainText( '2 items imported (2 new, 0 changed). A backup is kept.' );
		expect( wp( 'post', 'get', '--field=post_content', wpEval( `echo get_block_template( '${ THEME }//header', 'wp_template_part' )->wp_id;` ) ) ).toContain( 'and coffee' );
		expect( wpEval( `echo get_block_template( '${ THEME }//index', 'wp_template' )->source;` ) ).toBe( 'custom' );
		expect( wpEval( `echo wp_get_object_terms( get_block_template( '${ THEME }//header', 'wp_template_part' )->wp_id, 'wp_template_part_area', array( 'fields' => 'names' ) )[0];` ) ).toBe( 'header' );
		const items = report();
		expect( items[ 'parts/header' ] ).toMatchObject( { status: 'customized', author: 'admin' } );
		expect( items.styles ).toBeUndefined();

		// One Undo for the whole import.
		await page.locator( '.bto-notice' ).getByRole( 'button', { name: 'Undo' } ).click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( 'Import undone. 2 items are back as they were.' );
		expect( wp( 'post', 'list', '--post_type=wp_template_part,wp_template', '--format=count' ) ).toBe( '0' );
		expect( wp( 'block-theme-ops', 'backups', '--format=count' ) ).toBe( '0' );
	} );

	test( 'an item changed here too is not ticked, shows both diffs, and Undo brings this site\'s version back', async ( { page, rest } ) => {
		await customize( rest, 'template-parts', 'header', stagingHeader() );
		const file = await downloadZip( page );
		const id = await customize( rest, 'template-parts', 'header', themeFile( 'parts/header.html' ).replace( 'Fresh bread every morning.', 'Local edit on this site.' ) );

		await upload( page, file );
		const row = importRow( page, 'parts/header' );
		await expect( row.locator( '.bto-badge--changed' ) ).toHaveText( 'Changed' );
		await expect( row.locator( '.bto-badge--overwrites' ) ).toHaveText( 'Overwrites a change made here' );
		await expect( row.locator( 'input[type="checkbox"]' ) ).not.toBeChecked();
		await row.locator( 'summary' ).click();
		await expect( row.locator( 'table.diff' ) ).toHaveCount( 2 );
		await expect( row.locator( '.diff-deletedline' ).first() ).toContainText( 'Local edit on this site.' );

		// Nothing ticked: the page says so and nothing is written.
		await page.getByRole( 'button', { name: 'Import selected items' } ).click();
		await expect( page.locator( '.notice-error.bto-notice' ) ).toContainText( 'Choose at least one item to import.' );
		expect( wp( 'post', 'get', String( id ), '--field=post_content' ) ).toContain( 'Local edit on this site.' );

		await upload( page, file );
		await importRow( page, 'parts/header' ).locator( 'input[type="checkbox"]' ).check();
		await page.getByRole( 'button', { name: 'Import selected items' } ).click();
		await page.locator( '.bto-confirm' ).getByRole( 'button' ).first().click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( '1 item imported (0 new, 1 changed).' );
		expect( wp( 'post', 'get', String( id ), '--field=post_content' ) ).toContain( 'and coffee' );

		wp( 'block-theme-ops', 'undo', JSON.parse( wp( 'block-theme-ops', 'backups', '--format=json' ) )[ 0 ].id.toString() );
		expect( wp( 'post', 'get', String( id ), '--field=post_content' ) ).toContain( 'Local edit on this site.' );
	} );

	test( 'an item someone changed again after the import is left alone by Undo', async ( { page, rest } ) => {
		await customize( rest, 'template-parts', 'header', stagingHeader() );
		const file = await downloadZip( page );
		resetState();

		await upload( page, file );
		await page.getByRole( 'button', { name: 'Import selected items' } ).click();
		await page.locator( '.bto-confirm' ).getByRole( 'button' ).first().click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( '1 item imported' );
		await customize( rest, 'template-parts', 'header', themeFile( 'parts/header.html' ).replace( 'Fresh bread every morning.', 'Edited after the import.' ) );

		await page.locator( '.bto-notice' ).getByRole( 'button', { name: 'Undo' } ).click();
		await expect( page.locator( '.notice-error.bto-notice' ) ).toContainText( 'every item was changed again since the import' );
		expect( wp( 'block-theme-ops', 'backups', '--format=count' ) ).toBe( '1' );
	} );

	test( 'a zip for another theme is refused, and files that are not templates are skipped and listed', async ( { page } ) => {
		const other = makeZip( { 'other-theme/parts/header.html': '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' } );
		await upload( page, other );
		await expect( page.locator( '.bto-theme-other' ) ).toContainText( 'nothing can be imported' );
		await expect( page.getByRole( 'button', { name: 'Import selected items' } ) ).toHaveCount( 0 );
		await expect( importRow( page, 'parts/header' ).locator( 'input[type="checkbox"]' ) ).toBeDisabled();
		await expect( importRow( page, 'parts/header' ).locator( '.bto-badge--other-theme' ) ).toHaveText( 'Not for this theme' );
		await page.getByRole( 'button', { name: 'Discard this upload' } ).click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( 'Upload discarded. Nothing was changed.' );

		// No folder at all: read as the active theme. Dangerous files are skipped, and nothing runs.
		const plain = makeZip( {
			'parts/footer.html': '<!-- wp:paragraph --><p>Footer</p><!-- /wp:paragraph -->',
			'templates/evil.html': '<?php file_put_contents( "/tmp/bto-pwned", "x" ); ?><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
			'functions.php': '<?php file_put_contents( "/tmp/bto-pwned", "x" );',
			'templates/loose.html': '<p>not a block</p>',
		} );
		await upload( page, plain );
		await expect( page.locator( '.bto-import-row' ) ).toHaveCount( 1 );
		await expect( page.locator( '.bto-note-rejected' ) ).toContainText( 'functions.php' );
		await expect( page.locator( '.bto-note-rejected' ) ).toContainText( 'templates/evil.html' );
		await expect( page.locator( '.bto-note-rejected' ) ).toContainText( 'PHP tag' );
		await expect( page.locator( '.bto-note-rejected' ) ).toContainText( 'outside of blocks' );
		await page.getByRole( 'button', { name: 'Import selected items' } ).click();
		await page.locator( '.bto-confirm' ).getByRole( 'button' ).first().click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( '1 item imported (1 new, 0 changed).' );
		expect( wpEval( "echo file_exists( '/tmp/bto-pwned' ) ? 'ran' : 'clean';" ) ).toBe( 'clean' );
	} );

	test( 'navigation menus are mapped by slug, the site address is rewritten and missing media is listed', async ( { page } ) => {
		const menu = wp( 'post', 'create', '--post_type=wp_navigation', '--post_status=publish', '--post_name=main-menu', '--post_title=Main menu', '--post_content=<!-- wp:page-list /-->', '--porcelain' );
		const markup = [
			'<!-- wp:navigation {"ref":12} /-->',
			'<!-- wp:navigation {"ref":13} /-->',
			'<!-- wp:image {"id":5} --><figure class="wp-block-image"><img src="https://staging.example/wp-content/uploads/2026/gone.png" class="wp-image-5"/></figure><!-- /wp:image -->',
			'<!-- wp:button {"url":"https:\\/\\/staging.example\\/shop"} --><div class="wp-block-button"><a class="wp-block-button__link" href="https://staging.example/shop">Shop</a></div><!-- /wp:button -->',
		].join( '\n' );
		const zip = makeZip( {
			'corner-bakery/parts/header.html': markup,
			'corner-bakery/block-theme-ops.json': JSON.stringify( {
				format: 1,
				theme: THEME,
				site_url: 'https://staging.example',
				navigation: { 12: 'main-menu', 13: 'sale-menu' },
				media: { 5: 'https://staging.example/wp-content/uploads/2026/gone.png' },
				areas: { header: 'header' },
			} ),
		} );

		await upload( page, zip );
		await expect( page.locator( '.bto-note' ).filter( { hasText: 'Site address' } ).locator( 'del' ) ).toContainText( 'https://staging.example' );
		await expect( page.locator( '.bto-note' ).filter( { hasText: 'Site address' } ).locator( 'ins' ) ).toContainText( 'http' );
		await expect( page.locator( '.bto-note-navigation' ) ).toContainText( 'sale-menu' );
		await expect( page.locator( '.bto-note-navigation' ) ).not.toContainText( 'main-menu' );
		await expect( page.locator( '.bto-note-media' ) ).toContainText( 'https://staging.example/wp-content/uploads/2026/gone.png' );

		await page.getByRole( 'button', { name: 'Import selected items' } ).click();
		await page.locator( '.bto-confirm' ).getByRole( 'button' ).first().click();
		await expect( page.locator( '.notice-warning.bto-notice' ) ).toContainText( '1 navigation menu(s) and 1 media file(s)' );

		const content = wpEval( `echo get_block_template( '${ THEME }//header', 'wp_template_part' )->content;` );
		expect( content ).toContain( `<!-- wp:navigation {"ref":${ menu }} /-->` );
		expect( content ).toContain( '<!-- wp:navigation /-->' );
		expect( content ).not.toContain( 'staging.example' );
		expect( content ).not.toContain( '"id":5' );
		expect( content ).toContain( 'shop' );
	} );

	test( 'WP-CLI import previews with --dry-run, takes --only, and undo brings it back', async ( { rest, page } ) => {
		await customize( rest, 'template-parts', 'header', stagingHeader() );
		await customize( rest, 'templates', 'index', stagingIndex() );
		const zip = putZip( await downloadZip( page ) );
		resetState();

		const dry = wp( 'block-theme-ops', 'import', zip, '--dry-run', '--format=json' );
		const preview = JSON.parse( dry.slice( dry.indexOf( '[' ), dry.lastIndexOf( ']' ) + 1 ) );
		expect( preview.sort( ( a, b ) => a.key.localeCompare( b.key ) ) ).toEqual( [
			{ key: 'parts/header', status: 'new', customized: 'no' },
			{ key: 'templates/index', status: 'new', customized: 'no' },
		] );
		expect( wp( 'post', 'list', '--post_type=wp_template_part,wp_template', '--format=count' ) ).toBe( '0' );

		const done = wp( 'block-theme-ops', 'import', zip, '--only=parts/header', '--yes' );
		expect( done ).toContain( 'Imported 1 item(s). Undo with: wp block-theme-ops undo' );
		expect( Object.keys( report() ) ).toEqual( [ 'parts/header' ] );
		const backups = JSON.parse( wp( 'block-theme-ops', 'backups', '--format=json' ) );
		expect( backups ).toHaveLength( 1 );
		expect( backups[ 0 ] ).toMatchObject( { key: 'import', kind: 'import' } );

		expect( wp( 'block-theme-ops', 'import', zip, '--only=parts/header', '--yes' ) ).toContain( 'Nothing to import' );

		wp( 'block-theme-ops', 'undo', String( backups[ 0 ].id ) );
		expect( report() ).toEqual( {} );
		expect( () => wp( 'block-theme-ops', 'import', '/tmp/does-not-exist.zip' ) ).toThrow( /not a zip file/ );
	} );

	test( 'the import abilities preview and apply a zip (WordPress 6.9+)', async ( { rest, page } ) => {
		test.skip( '1' !== wpEval( "echo function_exists( 'wp_register_ability' ) ? 1 : 0;" ), 'The Abilities API arrived in WordPress 6.9.' );
		await customize( rest, 'template-parts', 'header', stagingHeader() );
		const zip = fs.readFileSync( await downloadZip( page ) ).toString( 'base64' );
		resetState();

		const listed = await rest( 'GET', '/wp-abilities/v1/abilities?category=block-theme-ops' );
		const byName = Object.fromEntries( listed.body.map( ( ability ) => [ ability.name, ability ] ) );
		expect( byName[ 'block-theme-ops/preview-import' ].meta.annotations ).toMatchObject( { readonly: true } );
		expect( byName[ 'block-theme-ops/apply-import' ].meta.annotations ).toMatchObject( { readonly: false, destructive: true } );
		expect( byName[ 'block-theme-ops/undo-change' ].meta.annotations ).toMatchObject( { destructive: true } );

		const preview = await rest( 'GET', `/wp-abilities/v1/abilities/block-theme-ops/preview-import/run?input[zip]=${ encodeURIComponent( zip ) }` );
		expect( preview.status ).toBe( 200 );
		expect( preview.body ).toMatchObject( { theme: THEME, theme_status: 'match', items: [ { key: 'parts/header', status: 'new', customized: false } ] } );
		expect( report() ).toEqual( {} );

		const applied = await rest( 'POST', '/wp-abilities/v1/abilities/block-theme-ops/apply-import/run', { input: { zip } } );
		expect( applied.status ).toBe( 200 );
		expect( applied.body ).toMatchObject( { created: [ 'parts/header' ], updated: [] } );
		expect( Object.keys( report() ) ).toEqual( [ 'parts/header' ] );

		const undone = await rest( 'POST', '/wp-abilities/v1/abilities/block-theme-ops/undo-change/run', { input: { backup_id: applied.body.backup_id } } );
		expect( undone.body ).toMatchObject( { kind: 'import', undone: [ 'parts/header' ] } );
		expect( report() ).toEqual( {} );

		const bad = await rest( 'POST', '/wp-abilities/v1/abilities/block-theme-ops/apply-import/run', { input: { zip: 'AAAA' } } );
		expect( bad.status ).toBeGreaterThanOrEqual( 400 );
	} );

	test( 'a locked template cannot be saved from the REST API, with a message, and unlocking lets it through', async ( { page, rest } ) => {
		wp( 'block-theme-ops', 'lock', 'parts/header', `--environments=${ ALL_ENVIRONMENTS }` );
		await page.goto( LOCKS );
		await expect( page.locator( '.bto-lock-state' ) ).toContainText( 'Enforced here' );
		await expect( page.getByLabel( 'Lock Header' ) ).toBeChecked();
		await expect( page.getByLabel( 'Lock Footer' ) ).not.toBeChecked();

		const refused = await rest( 'POST', `/wp/v2/template-parts/${ THEME }//header`, { content: stagingHeader() } );
		expect( refused.status ).toBe( 403 );
		expect( refused.body ).toMatchObject( { code: 'block_theme_ops_locked', data: { status: 403, key: 'parts/header' } } );
		expect( refused.body.message ).toContain( 'managed in the theme files' );
		const created = await rest( 'POST', '/wp/v2/template-parts', { slug: 'header', theme: THEME, content: stagingHeader(), area: 'header' } );
		expect( created.status ).toBe( 403 );
		expect( wp( 'post', 'list', '--post_type=wp_template_part', '--format=count' ) ).toBe( '0' );

		// Other items are not locked.
		const open = await rest( 'POST', `/wp/v2/template-parts/${ THEME }//footer`, { content: themeFile( 'parts/footer.html' ).replace( 'Open Tuesday to Sunday', 'Open Monday to Sunday' ) } );
		expect( open.status ).toBe( 200 );

		// Unlock on the screen.
		await page.getByLabel( 'Lock Header' ).uncheck();
		await page.getByRole( 'button', { name: 'Save locks' } ).click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( 'Locks saved.' );
		expect( JSON.parse( wp( 'option', 'get', 'block_theme_ops_locks', '--format=json' ) ).items ).toEqual( [] );
		const allowed = await rest( 'POST', `/wp/v2/template-parts/${ THEME }//header`, { content: stagingHeader() } );
		expect( allowed.status ).toBe( 200 );
	} );

	test( 'everything from the theme can be locked, per environment, and the filtered message and link are used', async ( { page, rest } ) => {
		await page.goto( LOCKS );
		await page.getByLabel( 'Everything that comes from the theme' ).check();
		for ( const env of [ 'production', 'staging', 'development', 'local' ] ) {
			await page.getByLabel( env, { exact: true } ).check();
		}
		await page.getByRole( 'button', { name: 'Save locks' } ).click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( 'Locks saved.' );
		await expect( page.getByLabel( 'Lock Header' ) ).toBeDisabled();

		expect( ( await rest( 'POST', `/wp/v2/template-parts/${ THEME }//footer`, { content: stagingHeader() } ) ).status ).toBe( 403 );
		expect( ( await rest( 'POST', `/wp/v2/templates/${ THEME }//index`, { content: stagingIndex() } ) ).status ).toBe( 403 );
		const styles = Number( wpEval( 'wp_set_current_user( 1 ); echo WP_Theme_JSON_Resolver::get_user_global_styles_post_id();' ) );
		expect( ( await rest( 'POST', `/wp/v2/global-styles/${ styles }`, { styles: { color: { background: '#fff' } } } ) ).status ).toBe( 403 );
		// A template made only in the Site Editor has no theme file, so it stays open.
		expect( ( await rest( 'POST', '/wp/v2/templates', { slug: 'wp-custom-template-landing', title: 'Landing', content: '<!-- wp:paragraph --><p>Spring</p><!-- /wp:paragraph -->' } ) ).status ).toBe( 201 );

		// Only chosen environments: untick all but one that is not this site's.
		wp( 'block-theme-ops', 'lock', '--environments=staging' );
		const type = wpEval( 'echo wp_get_environment_type();' );
		expect( ( await rest( 'POST', `/wp/v2/template-parts/${ THEME }//footer`, { content: stagingHeader() } ) ).status ).toBe( 'staging' === type ? 403 : 200 );

		// The developer's own text and link.
		wp( 'block-theme-ops', 'lock', '--environments=' + ALL_ENVIRONMENTS );
		wpEval( `wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . '/bto-e2e.php', base64_decode( '${ Buffer.from( "<?php add_filter( 'block_theme_ops_lock_message', function () { return 'Ask the platform team.'; } ); add_filter( 'block_theme_ops_lock_link', function () { return 'https://git.example/team/theme'; } );" ).toString( 'base64' ) }' ) );` );
		const refused = await rest( 'POST', `/wp/v2/template-parts/${ THEME }//footer`, { content: stagingHeader() } );
		expect( refused.body ).toMatchObject( { message: 'Ask the platform team.', data: { link: 'https://git.example/team/theme' } } );
	} );

	test( 'reset, import and Undo still work on locked items', async ( { page, rest } ) => {
		const id = await customize( rest, 'template-parts', 'header', stagingHeader() );
		const zip = putZip( await downloadZip( page ) );
		wp( 'block-theme-ops', 'lock', 'parts/header', `--environments=${ ALL_ENVIRONMENTS }` );

		wp( 'block-theme-ops', 'reset', 'parts/header', '--yes' );
		expect( wp( 'post', 'list', '--post_type=wp_template_part', '--format=count' ) ).toBe( '0' );
		const backup = JSON.parse( wp( 'block-theme-ops', 'backups', '--format=json' ) )[ 0 ].id;
		wp( 'block-theme-ops', 'undo', String( backup ) );
		expect( wp( 'post', 'get', String( id ), '--field=post_content' ) ).toContain( 'and coffee' );

		wp( 'block-theme-ops', 'reset', 'parts/header', '--yes' );
		wp( 'block-theme-ops', 'import', zip, '--yes' );
		expect( report()[ 'parts/header' ].status ).toBe( 'customized' );

		// The Site Editor is refused, the screen is not.
		expect( ( await rest( 'POST', `/wp/v2/template-parts/${ THEME }//header`, { content: themeFile( 'parts/header.html' ) } ) ).status ).toBe( 403 );
		await page.goto( PAGE );
		await expect( page.locator( '.bto-badge--locked' ) ).toHaveText( 'Locked' );
		await page.locator( '.bto-row' ).first().hover();
		await page.locator( '.bto-row' ).first().getByRole( 'button', { name: 'Reset', exact: true } ).click();
		await page.locator( '.bto-confirm' ).getByRole( 'button' ).first().click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( '1 item reset to the theme file' );
	} );

	test( 'the Site Editor says an item is locked as soon as it opens', async ( { page } ) => {
		wp( 'block-theme-ops', 'lock', 'parts/header', `--environments=${ ALL_ENVIRONMENTS }` );
		await page.goto( `/wp-admin/site-editor.php?postType=wp_template_part&postId=${ encodeURIComponent( THEME + '//header' ) }&canvas=edit` );
		await expect( page.getByText( 'managed in the theme files', { exact: false } ).first() ).toBeVisible( { timeout: 60000 } );

		// The text goes through wp.hooks too.
		expect( await page.evaluate( () => 'function' === typeof window.wp.hooks.applyFilters ) ).toBe( true );
	} );

	test( 'Site Health says when a locked item still has a Site Editor copy', async ( { rest } ) => {
		await customize( rest, 'template-parts', 'header', stagingHeader() );
		wp( 'block-theme-ops', 'lock', 'parts/header', `--environments=${ ALL_ENVIRONMENTS }` );
		const result = JSON.parse( wpEval( "$h = new AryanX\\Axle\\BlockThemeOps\\Health\\SiteHealth( AryanX\\Axle\\BlockThemeOps\\Plugin::instance()->drift(), AryanX\\Axle\\BlockThemeOps\\Plugin::instance()->locks() ); echo wp_json_encode( $h->run_locks() );" ) );
		expect( result.status ).toBe( 'recommended' );
		expect( result.label ).toContain( '1 locked item still has a Site Editor copy' );
	} );

	test( 'every screen has a Help tab, and the first visit says what to do next', async ( { page } ) => {
		await page.goto( PAGE );
		await expect( page.locator( '.bto-empty' ) ).toContainText( 'Nothing in the database overrides the theme.' );
		await expect( page.getByRole( 'link', { name: 'Import changes from another site' } ) ).toBeVisible();
		await page.locator( '#contextual-help-link' ).click();
		await expect( page.locator( '#tab-link-block-theme-ops-overview' ) ).toBeVisible();
		await expect( page.locator( '#tab-link-block-theme-ops-statuses' ) ).toBeVisible();

		for ( const [ tab, help ] of [ [ 'import', 'import-preview' ], [ 'locks', 'locks-how' ], [ 'backups', 'backups-undo' ], [ 'settings', 'settings-github' ] ] ) {
			await page.goto( `${ PAGE }&tab=${ tab }` );
			await page.locator( '#contextual-help-link' ).click();
			await expect( page.locator( `#tab-link-block-theme-ops-${ help }` ) ).toBeVisible();
		}
		await page.goto( `${ PAGE }&tab=backups` );
		await expect( page.locator( '.bto-empty' ) ).toContainText( 'No resets or imports to undo.' );
	} );

	test( 'the in-place confirmation moves focus and goes away with Escape', async ( { page, rest } ) => {
		await customize( rest, 'template-parts', 'header', stagingHeader() );
		await page.goto( PAGE );
		await page.locator( '.bto-row' ).first().hover();
		const reset = page.locator( '.bto-row' ).first().getByRole( 'button', { name: 'Reset' } );
		await reset.click();
		const box = page.locator( '.bto-confirm' );
		await expect( box ).toContainText( 'A backup is kept, and you can undo this for 30 days' );
		await expect( box.locator( 'p' ) ).toBeFocused();
		await page.keyboard.press( 'Escape' );
		await expect( box ).toHaveCount( 0 );
		expect( wp( 'post', 'list', '--post_type=wp_template_part', '--format=count' ) ).toBe( '1' );

		// Relative times, with the exact time in the title.
		await expect( page.locator( '.bto-row time.axle-time' ).first() ).toHaveAttribute( 'title', /.+/ );
	} );
} );
