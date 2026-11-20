/**
 * The licence box: each button posts to its route through apiFetch, the
 * box is swapped for the answer, the outcome is said as text, and a
 * failure leaves the box as it was.
 */

import { afterEach, beforeEach, describe, expect, test } from 'vitest';

import { bindLicences, replace, send } from '../../assets/js/licence/box';
import { createPost } from '../../assets/js/licence/request';

const PATH = '/sugarcommerce-updater/v1/licences/seller';

/**
 * A box, as Admin::form() draws it.
 *
 * @param {Object} [options]          How.
 * @param {string} [options.status]   The licence's status.
 * @param {string} [options.embedded] '1' inside another form.
 * @return {string} The markup.
 */
const box = ( { status = 'none', embedded = '0' } = {} ) =>
	`<div class="x-licence" data-status="${ status }" data-licence="${ PATH }" data-embedded="${ embedded }" data-failed="The store could not be reached.">` +
	( 'active' === status
		? '<code>••••ABCD</code> <button type="submit" data-licence-action="deactivate">Deactivate</button>'
		: '<input type="text" value="" data-licence-key /> <button type="submit" data-licence-action="activate">Activate</button>' ) +
	'<p class="x-said" role="status" aria-live="polite"></p></div>';

/**
 * A stand-in for apiFetch that remembers what it was asked.
 *
 * @param {Function} answer ( options ) => the answer, or throws.
 * @return {Function} The stand-in, with `.calls`.
 */
function fakeApi( answer ) {
	const api = ( options ) => {
		api.calls.push( options );

		try {
			return Promise.resolve( answer( options ) );
		} catch ( error ) {
			return Promise.reject( error );
		}
	};

	api.calls = [];

	return api;
}

const settle = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

const click = ( selector ) => {
	const event = new window.MouseEvent( 'click', {
		bubbles: true,
		cancelable: true,
	} );

	document.querySelector( selector ).dispatchEvent( event );

	return event;
};

let unbind = () => {};

beforeEach( () => {
	document.body.innerHTML = '';
} );

afterEach( () => {
	unbind();
} );

describe( 'the buttons', () => {
	test( 'Activate posts the key typed and swaps the box', async () => {
		document.body.innerHTML = `<form>${ box() }</form>`;

		const api = fakeApi( () => ( {
			ok: true,
			message: 'Licence activated.',
			html: box( { status: 'active' } ),
		} ) );

		unbind = bindLicences( document, { post: createPost( api ) } );

		document.querySelector( '[data-licence-key]' ).value = '  KEY-1  ';

		const event = click( '[data-licence-action="activate"]' );

		await settle();

		expect( event.defaultPrevented ).toBe( true );
		expect( api.calls ).toEqual( [
			{
				path: PATH + '/activate',
				method: 'POST',
				data: { embedded: false, key: 'KEY-1' },
			},
		] );
		expect(
			document.querySelector( '[data-licence]' ).dataset.status
		).toBe( 'active' );
		expect( document.querySelector( '[aria-live]' ).textContent ).toBe(
			'Licence activated.'
		);
		expect( document.activeElement.dataset.licenceAction ).toBe(
			'deactivate'
		);
	} );

	test( 'Deactivate sends no key, and says so when embedded', async () => {
		document.body.innerHTML = box( { status: 'active', embedded: '1' } );

		const api = fakeApi( () => ( {
			ok: true,
			message: 'Deactivated.',
			html: box(),
		} ) );

		unbind = bindLicences( document, { post: createPost( api ) } );
		click( '[data-licence-action="deactivate"]' );

		await settle();

		expect( api.calls[ 0 ].path ).toBe( PATH + '/deactivate' );
		expect( api.calls[ 0 ].data ).toEqual( { embedded: true } );
	} );

	test( 'a refusal is said as text and is marked as one', async () => {
		document.body.innerHTML = box();

		const api = fakeApi( () => ( {
			ok: false,
			message: '<img src=x onerror=alert(1)>Not recognised.',
			html: box(),
		} ) );

		unbind = bindLicences( document, { post: createPost( api ) } );
		click( '[data-licence-action="activate"]' );

		await settle();

		const region = document.querySelector( '[aria-live]' );

		expect( region.textContent ).toBe(
			'<img src=x onerror=alert(1)>Not recognised.'
		);
		expect( region.querySelector( 'img' ) ).toBeNull();
		expect( region.classList.contains( 'is-error' ) ).toBe( true );
	} );

	test( 'a request that fails leaves the box, and says why', async () => {
		document.body.innerHTML = box();

		const api = fakeApi( () => {
			throw { message: 'Too many tries.' };
		} );

		unbind = bindLicences( document, { post: createPost( api ) } );
		click( '[data-licence-action="activate"]' );

		await settle();

		const button = document.querySelector( '[data-licence-action]' );

		expect( button.disabled ).toBe( false );
		expect(
			document
				.querySelector( '[data-licence]' )
				.hasAttribute( 'aria-busy' )
		).toBe( false );
		expect( document.querySelector( '[aria-live]' ).textContent ).toBe(
			'Too many tries.'
		);
	} );

	test( 'with no reason given, the box says its own', async () => {
		document.body.innerHTML = box();

		const api = fakeApi( () => {
			throw {};
		} );

		unbind = bindLicences( document, { post: createPost( api ) } );
		click( '[data-licence-action="activate"]' );

		await settle();

		expect( document.querySelector( '[aria-live]' ).textContent ).toBe(
			'The store could not be reached.'
		);
	} );

	test( 'one request at a time', async () => {
		document.body.innerHTML = box();

		const api = fakeApi( () => new Promise( () => {} ) );

		unbind = bindLicences( document, { post: createPost( api ) } );
		click( '[data-licence-action="activate"]' );
		click( '[data-licence-action="activate"]' );

		await settle();

		expect( api.calls ).toHaveLength( 1 );
		expect(
			document.querySelector( '[data-licence-action]' ).disabled
		).toBe( true );
	} );

	test( 'a button outside a box is left to the browser', () => {
		document.body.innerHTML =
			'<button data-licence-action="activate">Elsewhere</button>';

		const api = fakeApi( () => ( {} ) );

		unbind = bindLicences( document, { post: createPost( api ) } );

		expect( click( 'button' ).defaultPrevented ).toBe( false );
		expect( api.calls ).toHaveLength( 0 );
	} );

	test( 'an action that is not one is not sent', async () => {
		document.body.innerHTML = box();

		const api = fakeApi( () => ( {} ) );

		await send(
			document.querySelector( '[data-licence]' ),
			'../../wp/v2/users',
			createPost( api )
		);

		expect( api.calls ).toHaveLength( 0 );
	} );
} );

describe( 'the keyboard', () => {
	test( 'Enter in the key box presses Activate, not the outer form', async () => {
		document.body.innerHTML = `<form>${ box() }</form>`;

		const api = fakeApi( () => ( {
			ok: true,
			message: 'Ok',
			html: box(),
		} ) );

		unbind = bindLicences( document, { post: createPost( api ) } );

		const event = new window.KeyboardEvent( 'keydown', {
			key: 'Enter',
			bubbles: true,
			cancelable: true,
		} );

		document.querySelector( '[data-licence-key]' ).dispatchEvent( event );

		await settle();

		expect( event.defaultPrevented ).toBe( true );
		expect( api.calls[ 0 ].path ).toBe( PATH + '/activate' );
	} );
} );

describe( 'replace', () => {
	test( 'markup with no box leaves the box', () => {
		document.body.innerHTML = box();

		const old = document.querySelector( '[data-licence]' );

		old.setAttribute( 'aria-busy', 'true' );

		expect( replace( old, '<p>nothing</p>' ) ).toBe( old );
		expect( old.hasAttribute( 'aria-busy' ) ).toBe( false );
	} );
} );
