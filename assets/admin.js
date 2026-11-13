/**
 * Appearance > Theme Drift: asks in place before a reset, an import or a pull request, shows that
 * something is running, and reads results aloud.
 *
 * Hooks, through wp.hooks (read on each submit, so scripts loaded later can hook in):
 * - filter blockThemeOps.confirmMessage ( message, details ): the question to ask. Return '' to skip it.
 * - action blockThemeOps.beforeSubmit ( details ): a form is about to be sent.
 * details is { action, items }: the action name (reset, keep, export, pr, import_apply, ...) and the post IDs or item keys.
 */
( function () {
	'use strict';

	var strings = window.blockThemeOpsAdmin || { cancel: 'Cancel', working: 'Working. Please wait.' };
	var counter = 0;

	function hooks() {
		return window.wp && window.wp.hooks ? window.wp.hooks : null;
	}

	function speak( message ) {
		if ( message && window.wp && window.wp.a11y && window.wp.a11y.speak ) {
			window.wp.a11y.speak( message, 'polite' );
		}
	}

	function field( form, name ) {
		var input = form.elements ? form.elements[ name ] : null;
		if ( input && input.length && ! input.tagName ) {
			input = input[ 0 ];
		}
		return input ? input.value : '';
	}

	function describe( form, submitter ) {
		if ( submitter && 'row' === submitter.name ) {
			var parts = String( submitter.value ).split( ':' );
			return { action: parts[ 0 ], items: [ Number( parts[ 1 ] ) ] };
		}
		if ( submitter && 'task' === submitter.name ) {
			return { action: submitter.value, items: [] };
		}

		var task = field( form, 'task' );
		if ( 'bulk' === task ) {
			var boxes = document.querySelectorAll( 'input[name="ids[]"]:checked' );
			return {
				action: field( form, 'bulk_action' ) || ( document.getElementById( 'bto-bulk-action' ) || {} ).value || '',
				items: Array.prototype.map.call( boxes, function ( box ) {
					return Number( box.value );
				} ),
			};
		}
		if ( 'import_apply' === task ) {
			var keys = form.querySelectorAll( 'input[name="keys[]"]:checked' );
			return {
				action: task,
				items: Array.prototype.map.call( keys, function ( box ) {
					return box.value;
				} ),
			};
		}

		var id = field( form, 'id' );
		return { action: task, items: id ? [ Number( id ) ] : [] };
	}

	function label( form, submitter, details ) {
		if ( 'bulk' === field( form, 'task' ) && ! ( submitter && 'row' === submitter.name ) ) {
			var select = document.getElementById( 'bto-bulk-action' );
			if ( select && select.selectedIndex > 0 ) {
				return select.options[ select.selectedIndex ].text;
			}
		}
		if ( 'import_apply' === details.action ) {
			return submitter ? submitter.textContent.trim() : '';
		}
		return submitter ? submitter.textContent.trim() : '';
	}

	function slotFor( form ) {
		var slot = document.getElementById( 'bto-confirm-slot' );
		if ( ! slot ) {
			slot = document.createElement( 'div' );
			slot.id = 'bto-confirm-slot';
			form.parentNode.insertBefore( slot, form );
		}
		return slot;
	}

	function clearConfirm() {
		var slot = document.getElementById( 'bto-confirm-slot' );
		if ( slot ) {
			slot.innerHTML = '';
		}
	}

	/**
	 * Shows the question in place, with a button that goes ahead and one that cancels.
	 *
	 * @param {HTMLFormElement}  form      Form to send.
	 * @param {HTMLElement|null} submitter Button that was pressed.
	 * @param {string}           message   Question.
	 * @param {string}           verb      Label of the go-ahead button.
	 */
	function ask( form, submitter, message, verb ) {
		var slot = slotFor( form );
		var id = 'bto-confirm-text-' + ( ++counter );
		var box = document.createElement( 'div' );
		var text = document.createElement( 'p' );
		var actions = document.createElement( 'div' );
		var go = document.createElement( 'button' );
		var cancel = document.createElement( 'button' );

		box.className = 'axle-confirm axle-confirm--danger bto-confirm';
		box.setAttribute( 'role', 'group' );
		box.setAttribute( 'aria-labelledby', id );
		text.id = id;
		text.tabIndex = -1;
		text.textContent = message;
		actions.className = 'axle-actions';
		go.type = 'button';
		go.className = 'button button-primary bto-confirm-go';
		go.textContent = verb || 'OK';
		cancel.type = 'button';
		cancel.className = 'button bto-confirm-cancel';
		cancel.textContent = strings.cancel;
		actions.appendChild( go );
		actions.appendChild( cancel );
		box.appendChild( text );
		box.appendChild( actions );

		slot.innerHTML = '';
		slot.appendChild( box );
		text.focus();
		if ( box.scrollIntoView ) {
			box.scrollIntoView( { block: 'nearest' } );
		}

		go.addEventListener( 'click', function () {
			clearConfirm();
			form.setAttribute( 'data-bto-confirmed', '1' );
			if ( form.requestSubmit ) {
				form.requestSubmit( submitter || undefined );
			} else {
				form.submit();
			}
		} );
		cancel.addEventListener( 'click', function () {
			clearConfirm();
			if ( submitter && submitter.focus ) {
				submitter.focus();
			}
		} );
		box.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				cancel.click();
			}
		} );
	}

	function busy( form, submitter ) {
		form.setAttribute( 'aria-busy', 'true' );
		form.setAttribute( 'data-bto-busy-now', '1' );
		if ( submitter && submitter.parentNode && ! submitter.parentNode.querySelector( '.spinner.is-active' ) ) {
			var spinner = document.createElement( 'span' );
			spinner.className = 'spinner is-active bto-spinner';
			spinner.setAttribute( 'role', 'status' );
			spinner.style.float = 'none';
			submitter.parentNode.insertBefore( spinner, submitter.nextSibling );
		}
		speak( form.getAttribute( 'data-bto-busy' ) || strings.working );
	}

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form || ! form.classList || ! form.classList.contains( 'bto-form' ) ) {
			return;
		}
		if ( form.getAttribute( 'data-bto-busy-now' ) ) {
			event.preventDefault();
			return;
		}

		var submitter = event.submitter || null;
		var details = describe( form, submitter );
		var message = form.getAttribute( 'data-bto-confirm-' + details.action ) || '';
		var wpHooks = hooks();
		if ( wpHooks ) {
			message = wpHooks.applyFilters( 'blockThemeOps.confirmMessage', message, details );
		}
		if ( 'import_apply' === details.action && ! details.items.length ) {
			// Nothing chosen: nothing to ask about. The server says what to do.
			message = '';
		}
		message = String( message || '' ).replace( '{count}', String( details.items.length ) );

		if ( message && ! form.getAttribute( 'data-bto-confirmed' ) ) {
			event.preventDefault();
			ask( form, submitter, message, label( form, submitter, details ) );
			return;
		}
		form.removeAttribute( 'data-bto-confirmed' );

		if ( wpHooks ) {
			wpHooks.doAction( 'blockThemeOps.beforeSubmit', details );
		}
		// A download keeps the page open, so there is nothing to wait for.
		if ( 'export' !== details.action ) {
			busy( form, submitter );
		}
	} );

	// Back from the cache: forget the busy state.
	window.addEventListener( 'pageshow', function ( event ) {
		if ( ! event.persisted ) {
			return;
		}
		Array.prototype.forEach.call( document.querySelectorAll( '[data-bto-busy-now]' ), function ( form ) {
			form.removeAttribute( 'data-bto-busy-now' );
			form.removeAttribute( 'aria-busy' );
		} );
		Array.prototype.forEach.call( document.querySelectorAll( '.bto-spinner' ), function ( spinner ) {
			spinner.parentNode.removeChild( spinner );
		} );
	} );

	document.addEventListener( 'DOMContentLoaded', function () {
		var notice = document.querySelector( '.bto-notice[data-bto-speak]' );
		if ( notice ) {
			speak( notice.getAttribute( 'data-bto-speak' ) );
		}
	} );
}() );
