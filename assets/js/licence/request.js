/**
 * The box's requests.
 *
 * Through wp.apiFetch with a `path`, so core's middleware adds the REST
 * root and the nonce -- and, when the nonce has run out while the page was
 * open, fetches a fresh one and sends the request again.
 */

/**
 * A function that posts to one of a box's routes.
 *
 * @param {Function} apiFetch wp.apiFetch, or a stand-in.
 * @return {Function} ( path, data ) => Promise of the decoded answer. It
 *                    rejects with an Error whose message is the server's
 *                    reason for a refusal, or '' when there is none.
 */
export function createPost( apiFetch ) {
	return ( path, data ) =>
		apiFetch( { path, method: 'POST', data } ).catch( ( error ) => {
			throw new Error( String( ( error && error.message ) || '' ) );
		} );
}
