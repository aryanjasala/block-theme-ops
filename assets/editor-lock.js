/**
 * Site Editor: tells people a template, part or the styles are locked as soon as they open it,
 * so nobody types for ten minutes before the save is refused.
 *
 * Hooks, through wp.hooks (read when the notice is made, so scripts loaded later can hook in):
 * - filter blockThemeOps.lockMessage ( message, key ): the text of the notice.
 *
 * The server sets window.blockThemeOpsLocks = { messages: { key: text }, link, linkText }.
 */
( function () {
	'use strict';

	var config = window.blockThemeOpsLocks;
	if ( ! config || ! config.messages || ! window.wp || ! window.wp.data ) {
		return;
	}

	var shown = {};

	function keyFor( type, id ) {
		var slug = String( id || '' ).split( '//' ).pop();
		if ( 'wp_template' === type ) {
			return 'templates/' + slug;
		}
		if ( 'wp_template_part' === type ) {
			return 'parts/' + slug;
		}
		return '';
	}

	function announce( key ) {
		if ( ! key || shown[ key ] || ! config.messages[ key ] ) {
			return;
		}
		shown[ key ] = true;

		var message = config.messages[ key ];
		if ( window.wp.hooks ) {
			message = window.wp.hooks.applyFilters( 'blockThemeOps.lockMessage', message, key );
		}

		var options = { id: 'block-theme-ops-lock-' + key.replace( /\W/g, '-' ), isDismissible: true, type: 'default' };
		if ( config.link ) {
			options.actions = [ { label: config.linkText, url: config.link } ];
		}
		window.wp.data.dispatch( 'core/notices' ).createNotice( 'warning', message, options );
	}

	function current() {
		var store = window.wp.data.select( 'core/edit-site' );
		if ( ! store || ! store.getEditedPostType ) {
			return;
		}
		announce( keyFor( store.getEditedPostType(), store.getEditedPostId() ) );
	}

	// Global styles are one record for the whole theme, so say it once at the start.
	announce( 'styles' );
	window.wp.data.subscribe( current );
	current();
}() );
