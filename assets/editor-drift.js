/**
 * Site Editor: when the template or part someone opens has a theme file that changed after it was saved,
 * say so, show what the theme changed, and link to the merge.
 *
 * Hooks, through wp.hooks (read when the notice is made):
 * - filter blockThemeOps.driftMessage ( message, drift ): the text of the notice.
 *
 * The server sets window.blockThemeOpsDrift = { path }.
 */
( function () {
	'use strict';

	var config = window.blockThemeOpsDrift;
	var wp = window.wp;
	if ( ! config || ! wp || ! wp.data || ! wp.apiFetch || ! wp.element || ! wp.components ) {
		return;
	}

	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var _n = wp.i18n._n;
	var el = wp.element.createElement;
	var asked = {};
	var modalRoot = null;

	function slugOf( id ) {
		return String( id || '' ).split( '//' ).pop();
	}

	function closeModal() {
		if ( modalRoot ) {
			modalRoot.unmount();
			modalRoot = null;
		}
		var host = document.getElementById( 'block-theme-ops-drift-modal' );
		if ( host ) {
			host.remove();
		}
	}

	function diffLines( diff ) {
		return String( diff || '' ).split( '\n' ).map( function ( line, index ) {
			var kind = 'same';
			if ( 0 === line.indexOf( '+++' ) || 0 === line.indexOf( '---' ) ) {
				kind = 'head';
			} else if ( 0 === line.indexOf( '+' ) ) {
				kind = 'add';
			} else if ( 0 === line.indexOf( '-' ) ) {
				kind = 'del';
			}
			return el( 'div', { key: index, className: 'bto-editor-diff__line is-' + kind }, line || ' ' );
		} );
	}

	function openModal( drift ) {
		closeModal();
		var host = document.createElement( 'div' );
		host.id = 'block-theme-ops-drift-modal';
		document.body.appendChild( host );

		var Modal = wp.components.Modal;
		var Button = wp.components.Button;
		var body = drift.diff
			? el( 'pre', { className: 'bto-editor-diff', dir: 'ltr', tabIndex: 0 }, diffLines( drift.diff ) )
			: el( 'p', null, __( 'There is no copy of the theme file from the time of the edit, so what changed cannot be shown.', 'block-theme-ops' ) );

		var tree = el(
			Modal,
			{ title: __( 'What the theme changed', 'block-theme-ops' ), onRequestClose: closeModal, className: 'bto-editor-modal' },
			el( 'p', null, __( 'Lines starting with + are new in the theme file, lines starting with - are gone. This site keeps showing your saved version until you merge or reset.', 'block-theme-ops' ) ),
			body,
			el(
				'div',
				{ className: 'bto-editor-modal__actions' },
				drift.canMerge ? el( Button, { variant: 'primary', href: drift.mergeUrl }, __( 'Merge theme changes', 'block-theme-ops' ) ) : null,
				el( Button, { variant: 'secondary', onClick: closeModal }, __( 'Close', 'block-theme-ops' ) )
			)
		);

		if ( wp.element.createRoot ) {
			modalRoot = wp.element.createRoot( host );
			modalRoot.render( tree );
		} else {
			wp.element.render( tree, host );
			modalRoot = { unmount: function () { wp.element.unmountComponentAtNode( host ); } };
		}
	}

	function announce( drift ) {
		var message = __( 'The theme changed this since you saved your version, and this site still shows your version.', 'block-theme-ops' );
		if ( drift.canMerge ) {
			var changes = sprintf(
				/* translators: 1: number of changes that merge on their own. */
				_n( '%d change merges on its own', '%d changes merge on their own', drift.auto, 'block-theme-ops' ),
				drift.auto
			);
			var conflicts = drift.conflicts
				? sprintf(
					/* translators: 1: number of conflicts to choose. */
					_n( '%d needs your choice', '%d need your choice', drift.conflicts, 'block-theme-ops' ),
					drift.conflicts
				)
				: __( 'nothing needs your choice', 'block-theme-ops' );
			message += ' ' + changes + ', ' + conflicts + '.';
		}
		if ( wp.hooks ) {
			message = wp.hooks.applyFilters( 'blockThemeOps.driftMessage', message, drift );
		}

		var actions = [
			{ label: __( 'See what the theme changed', 'block-theme-ops' ), onClick: function () { openModal( drift ); } }
		];
		if ( drift.canMerge ) {
			actions.push( { label: __( 'Merge', 'block-theme-ops' ), url: drift.mergeUrl } );
		}
		actions.push( { label: __( 'Reset', 'block-theme-ops' ), url: drift.itemUrl } );

		wp.data.dispatch( 'core/notices' ).createNotice( 'warning', message, {
			id: 'block-theme-ops-drift-' + drift.key.replace( /\W/g, '-' ),
			isDismissible: true,
			type: 'default',
			actions: actions
		} );
	}

	function check() {
		var store = wp.data.select( 'core/edit-site' );
		if ( ! store || ! store.getEditedPostType ) {
			return;
		}
		var type = store.getEditedPostType();
		if ( 'wp_template' !== type && 'wp_template_part' !== type ) {
			return;
		}
		var slug = slugOf( store.getEditedPostId() );
		var key = type + ':' + slug;
		if ( ! slug || asked[ key ] ) {
			return;
		}
		asked[ key ] = true;

		wp.apiFetch( { path: config.path + '?type=' + encodeURIComponent( type ) + '&slug=' + encodeURIComponent( slug ) } )
			.then( function ( drift ) {
				if ( drift && drift.drifted ) {
					announce( drift );
				}
			} )
			.catch( function () {
				// A refused or failed check shows nothing: the notice is a convenience.
			} );
	}

	wp.data.subscribe( check );
	check();
}() );
