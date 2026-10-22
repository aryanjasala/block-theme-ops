/**
 * Appearance > Theme Drift: asks before a reset or a pull request.
 *
 * Hooks, through wp.hooks (read on each submit, so scripts loaded later can hook in):
 * - filter blockThemeOps.confirmMessage ( message, details ): the question to ask. Return '' to skip it.
 * - action blockThemeOps.beforeSubmit ( details ): a form is about to be sent.
 * details is { action, items }: the action name (reset, keep, export, pr, ...) and the post IDs.
 */
( function () {
	'use strict';

	function hooks() {
		return window.wp && window.wp.hooks ? window.wp.hooks : null;
	}

	function field( form, name ) {
		var input = form.querySelector( '[name="' + name + '"]' );
		return input ? input.value : '';
	}

	function describe( form, submitter ) {
		if ( submitter && 'row' === submitter.name ) {
			var parts = String( submitter.value ).split( ':' );
			return { action: parts[ 0 ], items: [ Number( parts[ 1 ] ) ] };
		}

		var task = field( form, 'task' );
		if ( 'bulk' === task ) {
			var boxes = form.querySelectorAll( 'input[name="ids[]"]:checked' );
			return {
				action: field( form, 'bulk_action' ),
				items: Array.prototype.map.call( boxes, function ( box ) {
					return Number( box.value );
				} ),
			};
		}

		var id = field( form, 'id' );
		return { action: task, items: id ? [ Number( id ) ] : [] };
	}

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form || ! form.classList || ! form.classList.contains( 'bto-form' ) ) {
			return;
		}

		var details = describe( form, event.submitter );
		var message = form.getAttribute( 'data-bto-confirm-' + details.action ) || '';
		var wpHooks = hooks();
		if ( wpHooks ) {
			message = wpHooks.applyFilters( 'blockThemeOps.confirmMessage', message, details );
		}

		if ( message && ! window.confirm( message ) ) {
			event.preventDefault();
			return;
		}

		if ( wpHooks ) {
			wpHooks.doAction( 'blockThemeOps.beforeSubmit', details );
		}
	} );
} )();
