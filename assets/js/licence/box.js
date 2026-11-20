/**
 * The box's two buttons.
 *
 * Activate sends the key typed -- or nothing, when the box is left empty,
 * and the route activates the key kept -- and Deactivate sends nothing.
 * Either way the box is swapped for the markup that comes back, and what
 * happened is said in its live region, so this never has to know how a box
 * is drawn, and a screen reader hears the outcome.
 *
 * Enter in the key's box presses Activate. Inside a settings page's form
 * it would otherwise send that form instead.
 *
 * Bound on the document rather than on each box, so a box drawn after the
 * page loaded -- or swapped in by this script -- works the same.
 */

/**
 * A box, by the attribute that names its route.
 */
export const BOX = '[data-licence]';

/**
 * The buttons, by the attribute that names what they do.
 */
const BUTTON = '[data-licence-action]';

/**
 * Answer every box's buttons.
 *
 * @param {Document} doc       Where to listen.
 * @param {Object}   deps      What the buttons need.
 * @param {Function} deps.post createPost()'s function.
 * @return {Function} Stops listening.
 */
export function bindLicences( doc, { post } ) {
	const view = doc.defaultView;

	const onClick = ( event ) => {
		const button =
			event.target instanceof view.Element
				? event.target.closest( BUTTON )
				: null;
		const box = button && button.closest( BOX );

		if ( ! box || ! box.dataset.licence ) {
			return;
		}

		event.preventDefault();
		send( box, button.dataset.licenceAction, post );
	};

	const onKey = ( event ) => {
		if (
			'Enter' !== event.key ||
			! ( event.target instanceof view.Element ) ||
			! event.target.matches( '[data-licence-key]' )
		) {
			return;
		}

		const box = event.target.closest( BOX );
		const button =
			box && box.querySelector( '[data-licence-action="activate"]' );

		if ( button ) {
			event.preventDefault();
			button.click();
		}
	};

	doc.addEventListener( 'click', onClick );
	doc.addEventListener( 'keydown', onKey );

	return () => {
		doc.removeEventListener( 'click', onClick );
		doc.removeEventListener( 'keydown', onKey );
	};
}

/**
 * Ask the route, and redraw the box.
 *
 * One request at a time: the box is marked busy, and its buttons do
 * nothing until the answer is in.
 *
 * @param {Element}  box    The box.
 * @param {string}   action `activate` or `deactivate`.
 * @param {Function} post   createPost()'s function.
 * @return {Promise<void>} Settles when the box is redrawn.
 */
export function send( box, action, post ) {
	if ( 'true' === box.getAttribute( 'aria-busy' ) ) {
		return Promise.resolve();
	}

	if ( 'activate' !== action && 'deactivate' !== action ) {
		return Promise.resolve();
	}

	const input = box.querySelector( '[data-licence-key]' );
	const data = { embedded: '1' === box.dataset.embedded };

	// Only the box without an active licence has a key box.
	if ( input ) {
		data.key = input.value.trim();
	}

	box.setAttribute( 'aria-busy', 'true' );
	box.querySelectorAll( BUTTON ).forEach( ( button ) => {
		button.disabled = true;
	} );

	return post( box.dataset.licence + '/' + action, data ).then(
		( answer ) => {
			const fresh = replace( box, answer && answer.html );

			say( fresh, answer && answer.message, answer && answer.ok );
			focus( fresh );
		},
		( error ) => {
			box.removeAttribute( 'aria-busy' );
			box.querySelectorAll( BUTTON ).forEach( ( button ) => {
				button.disabled = false;
			} );

			say( box, error.message || box.dataset.failed, false );
		}
	);
}

/**
 * Swap a box for new markup, and hand back the new box.
 *
 * The markup is the plugin's own, from its own route; anything else in it
 * than one box is ignored.
 *
 * @param {Element} box  The box.
 * @param {string}  html The new box.
 * @return {Element} The box now in the page.
 */
export function replace( box, html ) {
	const template = box.ownerDocument.createElement( 'template' );

	template.innerHTML = String( html || '' );

	const fresh = template.content.querySelector( BOX );

	if ( ! fresh ) {
		box.removeAttribute( 'aria-busy' );
		return box;
	}

	box.replaceWith( fresh );

	return fresh;
}

/**
 * Say what happened, in the box's live region.
 *
 * As text, never markup.
 *
 * @param {Element} box     The box.
 * @param {string}  message What to say.
 * @param {boolean} ok      Whether it worked.
 */
function say( box, message, ok ) {
	const region = box.querySelector( '[aria-live]' );

	if ( ! region || ! message ) {
		return;
	}

	region.textContent = String( message );
	region.classList.toggle( 'is-error', ! ok );
}

/**
 * Put the focus back in the box: on its key box, or its button.
 *
 * @param {Element} box The box.
 */
function focus( box ) {
	const target = box.querySelector( '[data-licence-key], ' + BUTTON );

	if ( target ) {
		target.focus();
	}
}
