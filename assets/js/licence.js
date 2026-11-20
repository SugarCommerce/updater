/**
 * The licence box.
 *
 * Activate and Deactivate through the library's REST route, by
 * wp.apiFetch, which renews an expired nonce and tries again, so a page
 * left open for a day still works. The box is redrawn in place from the
 * markup the route sends back. Without this script the box is a form that
 * posts to admin-post.php, and works the same, with a page load.
 *
 * Loaded by the box itself (Admin::form()), under the copy's handle, in the
 * footer. Bound on the document at once: nothing waits for the page, since
 * a click cannot come before it.
 */

import apiFetch from '@wordpress/api-fetch';

import { bindLicences } from './licence/box';
import { createPost } from './licence/request';

bindLicences( document, { post: createPost( apiFetch ) } );
