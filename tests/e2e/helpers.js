// Helpers for the Block Theme Ops end-to-end tests: a small block theme, its
// files, Site Editor changes made through the REST API, and a clean slate.
const { wp, wpEval } = require( './utils' );

const THEME = 'corner-bakery';
const PAGE = '/wp-admin/themes.php?page=block-theme-ops';
const MOCK_URL = 'http://wp/wp-content/plugins/block-theme-ops/tests/e2e/fixtures/github-mock.php';
const OPTIONS = [ 'block_theme_ops_settings', 'block_theme_ops_alerts', 'bto_e2e_mail' ];

/**
 * Copies the fixture theme into wp-content/themes, fresh, and activates it.
 * The plugin folder is mounted read-only, so the theme gets its own copy.
 */
function installTheme() {
	wpEval( `
		$src = WP_PLUGIN_DIR . '/block-theme-ops/tests/e2e/fixtures/${ THEME }';
		$dst = get_theme_root() . '/${ THEME }';
		if ( is_dir( $dst ) ) {
			$old = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dst, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $old as $file ) {
				$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
			rmdir( $dst );
		}
		$new = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
		mkdir( $dst );
		foreach ( $new as $file ) {
			$target = $dst . '/' . $new->getSubPathName();
			$file->isDir() ? mkdir( $target ) : copy( $file->getPathname(), $target );
		}
	` );
	wp( 'theme', 'activate', THEME );
}

/**
 * Removes every Site Editor change, backup, setting and test log.
 */
function resetState() {
	wpEval( `
		foreach ( get_posts( array( 'post_type' => array( 'wp_template', 'wp_template_part', 'wp_global_styles', 'bto_backup' ), 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( ${ JSON.stringify( OPTIONS ) } as $option ) {
			delete_option( $option );
		}
		delete_transient( 'block_theme_ops_notice_1' );
		wp_clear_scheduled_hook( 'block_theme_ops_check' );
		@unlink( WP_CONTENT_DIR . '/bto-github-mock.json' );
		@unlink( WP_CONTENT_DIR . '/mu-plugins/bto-e2e.php' );
		@unlink( WP_CONTENT_DIR . '/mu-plugins/bto-e2e-rtl.php' );
	` );
}

/**
 * Reads a file of the fixture theme as installed.
 *
 * @param {string} file Path inside the theme.
 * @return {string} Contents.
 */
function themeFile( file ) {
	return wpEval( `echo file_get_contents( get_theme_root() . '/${ THEME }/${ file }' );` );
}

/**
 * Writes a file of the installed theme, like a deploy would.
 *
 * @param {string} file     Path inside the theme.
 * @param {string} contents New contents.
 */
function writeThemeFile( file, contents ) {
	const encoded = Buffer.from( contents ).toString( 'base64' );
	wpEval( `file_put_contents( get_theme_root() . '/${ THEME }/${ file }', base64_decode( '${ encoded }' ) ); clearstatcache();` );
}

/**
 * Changes one line of a theme file.
 *
 * @param {string} file Path inside the theme.
 * @param {string} from Text to replace.
 * @param {string} to   Replacement.
 */
function editThemeFile( file, from, to ) {
	const before = themeFile( file );
	if ( ! before.includes( from ) ) {
		throw new Error( `${ file } does not contain ${ from }` );
	}
	writeThemeFile( file, before.replace( from, to ) );
}

/**
 * Saves a template or template part through the REST API, as the Site Editor does.
 *
 * @param {Function} rest    The rest() fixture.
 * @param {string}   kind    templates or template-parts.
 * @param {string}   slug    Slug.
 * @param {string}   content Block markup.
 * @return {Promise<number>} Post ID of the database copy.
 */
async function customize( rest, kind, slug, content ) {
	const response = await rest( 'POST', `/wp/v2/${ kind }/${ THEME }//${ slug }`, { content } );
	if ( 200 !== response.status ) {
		throw new Error( `Saving ${ kind }/${ slug } failed: ${ JSON.stringify( response.body ) }` );
	}
	return response.body.wp_id;
}

/**
 * Saves global styles changes through the REST API, as the Styles panel does.
 *
 * @param {Function} rest   The rest() fixture.
 * @param {Object}   styles Styles to set.
 * @return {Promise<number>} Post ID of the global styles post.
 */
async function customizeStyles( rest, styles ) {
	const id = Number( wpEval( 'wp_set_current_user( 1 ); echo WP_Theme_JSON_Resolver::get_user_global_styles_post_id();' ) );
	const response = await rest( 'POST', `/wp/v2/global-styles/${ id }`, { styles, settings: {} } );
	if ( 200 !== response.status ) {
		throw new Error( `Saving global styles failed: ${ JSON.stringify( response.body ) }` );
	}
	return id;
}

/**
 * The drift report from WP-CLI, keyed by item key.
 *
 * @param {...string} args Extra arguments, e.g. '--kept'.
 * @return {Object} Items by key.
 */
function report( ...args ) {
	const items = JSON.parse( wp( 'block-theme-ops', 'status', '--format=json', ...args ) || '[]' );
	return Object.fromEntries( items.map( ( item ) => [ item.key, item ] ) );
}

/**
 * Installs a must-use plugin that points GitHub calls at the mock and records email.
 */
function installMocks() {
	const php = `<?php
add_filter( 'block_theme_ops_github_api_url', function () { return '${ MOCK_URL }'; } );
add_filter( 'pre_wp_mail', function ( $short, $atts ) {
	$log   = get_option( 'bto_e2e_mail', array() );
	$log[] = $atts;
	update_option( 'bto_e2e_mail', $log );
	return true;
}, 10, 2 );
`;
	const encoded = Buffer.from( php ).toString( 'base64' );
	wpEval( `wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . '/bto-e2e.php', base64_decode( '${ encoded }' ) );` );
}

/**
 * Turns wp-admin right to left, as a Hebrew or Arabic site has it, without a language pack.
 */
function installRtl() {
	const php = "<?php\nadd_action( 'init', function () { $GLOBALS['wp_locale']->text_direction = 'rtl'; } );\n";
	wpEval( `wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . '/bto-e2e-rtl.php', base64_decode( '${ Buffer.from( php ).toString( 'base64' ) }' ) );` );
}

/**
 * Requests the GitHub mock received, oldest first.
 *
 * @return {Object[]} method, path, auth and body of each.
 */
function githubLog() {
	const raw = wpEval( "echo is_readable( WP_CONTENT_DIR . '/bto-github-mock.json' ) ? file_get_contents( WP_CONTENT_DIR . '/bto-github-mock.json' ) : '[]';" );
	return JSON.parse( raw || '[]' );
}

/**
 * A realistic mix for the screenshots and the demo: a header edited before a
 * theme deploy changed it, a customized footer and styles, an identical page
 * template, and a landing template that only exists in the database.
 *
 * @param {Function} rest The rest() fixture.
 * @return {Promise<Object>} Post IDs by key.
 */
async function seedDemo( rest ) {
	wp( 'user', 'update', 'admin', '--display_name=Maya Patel' );
	const ids = {};
	ids.header = await customize( rest, 'template-parts', 'header', themeFile( 'parts/header.html' ).replace( 'Fresh bread every morning.', 'Fresh bread and coffee every morning.' ) );
	ids.footer = await customize( rest, 'template-parts', 'footer', themeFile( 'parts/footer.html' ).replace( 'Open Tuesday to Sunday, 7 am to 3 pm.', 'Open every day, 7 am to 4 pm.' ) );
	ids.page = await customize( rest, 'templates', 'page', themeFile( 'templates/page.html' ) );
	const landing = await rest( 'POST', '/wp/v2/templates', { slug: 'wp-custom-template-spring-sale', title: 'Spring sale', content: '<!-- wp:paragraph --><p>Spring sale: 20% off sourdough.</p><!-- /wp:paragraph -->' } );
	ids.landing = landing.body.wp_id;
	ids.styles = await customizeStyles( rest, { color: { background: '#f4ecdf' } } );
	// The theme deploy that came after: a navigation block in the header.
	editThemeFile( 'parts/header.html', '<!-- wp:site-title /-->', '<!-- wp:site-title /-->\n\n\t<!-- wp:navigation {"overlayMenu":"never"} /-->' );
	return ids;
}

/**
 * Undoes seedDemo()'s change to the admin's name.
 */
function unseedDemo() {
	wp( 'user', 'update', 'admin', '--display_name=admin' );
}

module.exports = { THEME, PAGE, installTheme, resetState, themeFile, writeThemeFile, editThemeFile, customize, customizeStyles, report, installMocks, installRtl, githubLog, seedDemo, unseedDemo };
