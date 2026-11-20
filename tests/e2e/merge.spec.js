// Merging a theme update into a customized template, the Site Editor drift notice, and locks on navigation menus.
const { test, expect, wp, wpEval, logInAs } = require( './utils' );
const { THEME, PAGE, installTheme, resetState, themeFile, editThemeFile, customize, report, setAxleEnvironment } = require( './helpers' );

const HEADER_EDIT = [ 'Fresh bread every morning.', 'Fresh bread and coffee every morning.' ];
const NAV_DEPLOY = [ '<!-- wp:site-title /-->', '<!-- wp:site-title /-->\n\n\t<!-- wp:navigation {"overlayMenu":"never"} /-->' ];

const editedHeader = () => themeFile( 'parts/header.html' ).replace( ...HEADER_EDIT );
const content = ( id ) => wp( 'post', 'get', String( id ), '--field=post_content' );

test.describe( 'Block Theme Ops merge', () => {
	test.beforeAll( installTheme );

	test.beforeEach( () => {
		resetState();
		installTheme();
	} );

	test.afterAll( () => {
		resetState();
		wp( 'theme', 'activate', process.env.WP_THEME || 'twentytwentyfive' );
	} );

	test( 'a theme change and a site change in different blocks merge without a question, with a preview and Undo', async ( { page, rest } ) => {
		const id = await customize( rest, 'template-parts', 'header', editedHeader() );
		editThemeFile( 'parts/header.html', ...NAV_DEPLOY );

		await page.goto( `${ PAGE }&item=${ id }` );
		await expect( page.locator( '.bto-merge-hint' ) ).toContainText( 'Merge keeps your changes' );
		await page.getByRole( 'link', { name: 'Merge theme changes' } ).click();

		await expect( page.locator( 'h1' ) ).toContainText( 'Merge theme changes into Header' );
		await expect( page.locator( '.bto-merge-applied' ) ).toContainText( 'Navigation' );
		await expect( page.locator( '.bto-conflict' ) ).toHaveCount( 0 );
		await expect( page.locator( '.bto-merge-frame' ) ).toHaveAttribute( 'sandbox', '' );
		await expect( page.locator( '#bto-diff-merge .diff-addedline' ) ).toContainText( 'wp:navigation' );
		await expect( page.locator( '#bto-merge-undo' ) ).toContainText( 'Makes one backup of this item first. Undo for 30 days.' );

		await page.getByRole( 'button', { name: 'Merge', exact: true } ).click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( 'Merged. 1 change from the theme was added and your changes were kept.' );
		expect( content( id ) ).toContain( 'and coffee' );
		expect( content( id ) ).toContain( 'wp:navigation' );
		expect( report()[ 'parts/header' ].status ).toBe( 'customized' );

		// The theme file now is the base for the next update.
		const snapshot = JSON.parse( wp( 'post', 'meta', 'get', String( id ), '_block_theme_ops_snapshot', '--format=json' ) );
		expect( snapshot.content ).toContain( 'wp:navigation' );

		await expect( page.locator( '.bto-backups tbody tr' ) ).toHaveCount( 0 ); // On the item screen, not the list.
		await page.goto( PAGE );
		await expect( page.locator( '.bto-backups tbody tr' ) ).toHaveCount( 1 );
		await page.getByRole( 'button', { name: 'Undo merge' } ).click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( 'Merge undone. Your earlier copy is back.' );
		expect( content( id ) ).not.toContain( 'wp:navigation' );
		expect( content( id ) ).toContain( 'and coffee' );
		expect( report()[ 'parts/header' ].status ).toBe( 'theme-changed' );
		expect( wp( 'block-theme-ops', 'backups', '--format=count' ) ).toBe( '0' );
	} );

	test( 'a block both sides changed needs a choice before Merge works', async ( { page, rest } ) => {
		const id = await customize( rest, 'template-parts', 'header', editedHeader() );
		editThemeFile( 'parts/header.html', 'Fresh bread every morning.', 'Fresh bread, baked at dawn.' );

		await page.goto( `${ PAGE }&item=${ id }&merge=1` );
		await expect( page.locator( '.bto-conflict' ) ).toHaveCount( 1 );
		await expect( page.locator( '.bto-conflict' ) ).toContainText( 'Both the theme and this site changed this block.' );
		await expect( page.locator( '.bto-conflict .axle-diff--labelled del' ) ).toContainText( 'and coffee' );
		await expect( page.locator( '.bto-conflict .axle-diff--labelled ins' ) ).toContainText( 'baked at dawn' );
		await expect( page.locator( '.bto-merge-go' ) ).toBeDisabled();
		await expect( page.locator( '#bto-merge-undo' ) ).toContainText( 'Choose a version for every conflict' );

		await page.getByLabel( 'Use the theme\'s version' ).check();
		await page.getByRole( 'button', { name: 'Update the preview' } ).click();
		await expect( page.locator( '.bto-merge-go' ) ).toBeEnabled();
		await page.getByRole( 'button', { name: 'Merge', exact: true } ).click();
		await expect( page.locator( '.bto-notice' ) ).toContainText( 'Merged.' );
		expect( content( id ) ).toContain( 'baked at dawn' );
		expect( content( id ) ).not.toContain( 'and coffee' );

		// Keeping this site's version works the same way, from the CLI.
		const other = await customize( rest, 'template-parts', 'footer', themeFile( 'parts/footer.html' ).replace( 'Open Tuesday to Sunday', 'Open every day' ) );
		editThemeFile( 'parts/footer.html', 'Open Tuesday to Sunday', 'Open Wednesday to Sunday' );
		const dry = wp( 'block-theme-ops', 'merge', 'parts/footer', '--dry-run' );
		expect( dry ).toContain( 'Conflict c' );
		expect( () => wp( 'block-theme-ops', 'merge', 'parts/footer', '--yes' ) ).toThrow( /Conflicts need a choice/ );
		wp( 'block-theme-ops', 'merge', 'parts/footer', '--all-ours', '--yes' );
		expect( content( other ) ).toContain( 'Open every day' );
	} );

	test( 'items with nothing to merge say why, and the merge route refuses people who cannot edit', async ( { page, rest } ) => {
		const id = await customize( rest, 'template-parts', 'header', editedHeader() );

		await page.goto( `${ PAGE }&item=${ id }&merge=1` );
		await expect( page.locator( '.bto-merge-blocked' ) ).toContainText( 'nothing to merge' );

		const preview = await rest( 'GET', '/wp-abilities/v1/abilities/block-theme-ops/preview-merge/run?input[item]=parts/header' );
		expect( preview.body ).toMatchObject( { can_merge: false } );

		editThemeFile( 'parts/header.html', ...NAV_DEPLOY );
		const planned = await rest( 'GET', '/wp-abilities/v1/abilities/block-theme-ops/preview-merge/run?input[item]=parts/header' );
		expect( planned.body ).toMatchObject( { can_merge: true, resolved: true, applied: [ { action: 'added', name: 'core/navigation' } ] } );
		const merged = await rest( 'POST', '/wp-abilities/v1/abilities/block-theme-ops/merge-theme-update/run', { input: { item: 'parts/header' } } );
		expect( merged.status ).toBe( 200 );
		expect( merged.body.backup_id ).toBeGreaterThan( 0 );
		const undone = await rest( 'POST', '/wp-abilities/v1/abilities/block-theme-ops/undo-change/run', { input: { backup_id: merged.body.backup_id } } );
		expect( undone.body ).toMatchObject( { kind: 'merge', undone: [ 'parts/header' ] } );
	} );

	test( 'the drift route answers editors with nothing, and admins with what changed', async ( { rest, browser } ) => {
		const id = await customize( rest, 'template-parts', 'header', editedHeader() );
		editThemeFile( 'parts/header.html', ...NAV_DEPLOY );

		const drift = await rest( 'GET', `/block-theme-ops/v1/drift?type=wp_template_part&slug=header` );
		expect( drift.status ).toBe( 200 );
		expect( drift.body ).toMatchObject( { drifted: true, canMerge: true, auto: 1, conflicts: 0, key: 'parts/header' } );
		expect( drift.body.diff ).toContain( '+' );
		expect( drift.body.mergeUrl ).toContain( `item=${ id }` );

		const none = await rest( 'GET', '/block-theme-ops/v1/drift?type=wp_template_part&slug=footer' );
		expect( none.body ).toEqual( { drifted: false } );

		wpEval( "if ( ! username_exists( 'bto_editor' ) ) { wp_insert_user( array( 'user_login' => 'bto_editor', 'user_pass' => 'bto-editor-pass', 'role' => 'editor' ) ); }" );
		const { context, page } = await logInAs( browser, 'bto_editor', 'bto-editor-pass' );
		const nonce = ( await ( await page.request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim();
		const refused = await page.request.get( '/index.php?rest_route=/block-theme-ops/v1/drift&type=wp_template_part&slug=header', { headers: { 'X-WP-Nonce': nonce } } );
		expect( refused.status() ).toBe( 403 );
		await context.close();
	} );

	test( 'the Site Editor says the theme changed, shows what, and links to Merge', async ( { page, rest } ) => {
		const id = await customize( rest, 'template-parts', 'header', editedHeader() );
		editThemeFile( 'parts/header.html', ...NAV_DEPLOY );

		await page.goto( `/wp-admin/site-editor.php?postType=wp_template_part&postId=${ THEME }%2F%2Fheader&canvas=edit` );
		const notice = page.locator( '.components-notice' ).filter( { hasText: 'The theme changed this since you saved your version' } );
		await expect( notice ).toBeVisible( { timeout: 30_000 } );
		await expect( notice ).toContainText( '1 change merges on its own, nothing needs your choice.' );
		const guide = page.getByRole( 'dialog', { name: /welcome to the site editor/i } );
		if ( await guide.isVisible().catch( () => false ) ) {
			await guide.getByRole( 'button', { name: 'Get started' } ).click();
		}
		await expect( notice.getByRole( 'link', { name: 'Merge' } ) ).toHaveAttribute( 'href', new RegExp( `item=${ id }&merge=1` ) );
		await expect( notice.getByRole( 'link', { name: 'Reset' } ) ).toBeVisible();

		await notice.getByRole( 'button', { name: 'See what the theme changed' } ).click();
		const dialog = page.getByRole( 'dialog', { name: 'What the theme changed' } );
		await expect( dialog ).toBeVisible();
		await expect( dialog.locator( '.bto-editor-diff__line.is-add' ).filter( { hasText: 'wp:navigation' } ) ).toHaveCount( 1 );
		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toHaveCount( 0 );
	} );

	test( 'a lock follows the Axle environment, so a copy Staging Guard marks as staging is free to edit', async ( { rest } ) => {
		wp( 'block-theme-ops', 'lock', 'parts/header' );
		const body = { content: editedHeader() };

		const refused = await rest( 'POST', `/wp/v2/template-parts/${ THEME }//header`, body );
		expect( refused.status ).toBe( 403 );
		expect( refused.body.code ).toBe( 'block_theme_ops_locked' );

		setAxleEnvironment( 'staging' );
		const allowed = await rest( 'POST', `/wp/v2/template-parts/${ THEME }//header`, body );
		expect( allowed.status ).toBe( 200 );
		expect( wp( 'block-theme-ops', 'locks' ) ).toContain( 'Enforced on this site (staging): no' );
	} );

	test( 'a navigation menu used by a locked part is locked with it', async ( { rest } ) => {
		const menu = await rest( 'POST', '/wp/v2/navigation', { title: 'Main menu', status: 'publish', content: '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->' } );
		expect( menu.status ).toBe( 201 );
		const other = await rest( 'POST', '/wp/v2/navigation', { title: 'Footer menu', status: 'publish', content: '<!-- wp:navigation-link {"label":"Contact","url":"/contact"} /-->' } );
		const header = themeFile( 'parts/header.html' ).replace( '<!-- wp:site-title /-->', `<!-- wp:site-title /-->\n<!-- wp:navigation {"ref":${ menu.body.id }} /-->` );
		await customize( rest, 'template-parts', 'header', header );

		wp( 'block-theme-ops', 'lock', 'parts/header' );
		const refused = await rest( 'POST', `/wp/v2/navigation/${ menu.body.id }`, { title: 'Renamed' } );
		expect( refused.status ).toBe( 403 );
		expect( refused.body ).toMatchObject( { code: 'block_theme_ops_locked', data: { key: 'parts/header' } } );
		const deleted = await rest( 'DELETE', `/wp/v2/navigation/${ menu.body.id }` );
		expect( deleted.status ).toBe( 403 );

		// A menu no locked item uses stays editable, and reading is never refused.
		expect( ( await rest( 'POST', `/wp/v2/navigation/${ other.body.id }`, { title: 'Links' } ) ).status ).toBe( 200 );
		expect( ( await rest( 'GET', `/wp/v2/navigation/${ menu.body.id }` ) ).status ).toBe( 200 );

		wp( 'block-theme-ops', 'unlock', 'parts/header' );
		expect( ( await rest( 'POST', `/wp/v2/navigation/${ menu.body.id }`, { title: 'Renamed' } ) ).status ).toBe( 200 );
	} );
} );
