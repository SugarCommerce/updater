# Updater

Updates for a paid plugin, from the store that sold it.

## What it does

A plugin sold from your own store does not come from wordpress.org, so
nothing tells WordPress there is a new version -- and nothing should hand
the new version to a site whose licence has run out.

One call gives the plugin what a paid plugin needs: a box for the licence
key with Activate and Deactivate, new versions on the Plugins and Updates
screens with the "View details" window filled in, one-click and automatic
updates through the store's signed download link, and a word to the site's
owner when the licence is missing, running out, or gone. It talks to a
SugarCart store's licence API, so any plugin sold from any SugarCart store
can use it.

## Features

* Add a licence key box, with Activate and Deactivate, to any settings page -- or under the plugin on the Plugins screen -- that works in place with JavaScript and as a plain form without
* Offer new versions from your store, with the download link only a good licence gets
* Fill the "View details" window: description, changelog, requirements, banners and icons
* Keep wordpress.org out of it with an `Update URI` header on your store's host
* Turn on the auto-update toggle for your plugin, like any other
* Say plainly when there is no key, the licence has expired or ends within 30 days, was revoked, or this site was removed from it
* Check the licence once a week, so a seat released from the account page is noticed
* Leave a store that is down alone for an hour, rather than slowing every page
* Change every word, and every request, answer and update, through hooks

## Installation

```bash
composer require sugarcommerce/updater
```

## Quick start

In the plugin's main file:

```php
use SugarCommerce\Updater\Updater;

Updater::register( [
	'file'    => __FILE__,
	'version' => '1.4.0',
	'store'   => 'https://store.example',
	'product' => 'my-plugin',
] );
```

That is all a plugin needs. With no settings page named, the licence key box
appears in a row under the plugin on the Plugins screen. To put it on your
own settings page instead:

```php
$updater = Updater::register( [
	'file'         => __FILE__,
	'version'      => MY_PLUGIN_VERSION,
	'store'        => 'https://store.example',
	'product'      => 'my-plugin',
	'settings_url' => admin_url( 'admin.php?page=my-plugin-settings&tab=licence' ),
] );

// Wherever the box belongs:
echo $updater->form();
```

## The plugin header

Add an `Update URI` naming your store:

```php
/**
 * Plugin Name: My Plugin Pro
 * Version:     1.4.0
 * Update URI:  https://store.example
 */
```

When its host is the store's host, WordPress never asks wordpress.org about
the plugin -- so a free plugin there that happens to share your folder name
can never be offered over yours -- and asks this library instead, on
`update_plugins_store.example`. Without the header, or with one on another
host, updates are added to WordPress's update list as it is saved
(`pre_set_site_transient_update_plugins`), which works too, but leaves
wordpress.org free to answer for the same folder name.

## Configuration

| Key | Default | |
|---|---|---|
| `file` | (required) | The plugin's main file, `__FILE__` |
| `version` | (required) | The version installed |
| `store` | (required) | The store's https address |
| `product` | (required) | The store's product id or slug |
| `slug` | the plugin's folder | What the plugin is known by; the registry key |
| `name` | the `Plugin Name` header | Used in notices and the "View details" window |
| `description` | the `Description` header | The "View details" window's Description tab |
| `namespace` | `sugarcart/v1` | The store's REST namespace, like `sugarcart/v1` |
| `prefix` | `sugarcommerce_updater` | What hooks and the option are named from |
| `capability` | `activate_plugins` | Who sees the form, the notices and the row, and may use them (and the REST routes) |
| `renew_url` | the store | Where Renew Licence goes |
| `account_url` | the store | Where View Your Account goes, for a revoked key |
| `settings_url` | the Plugins screen | Where the licence box lives |
| `cache` | `21600` (six hours) | How long the store's update answer is kept, at most twelve hours |
| `labels` | | Words to replace; see [Words](#words) |
| `notices` | `true` | Whether to show the licence notices |
| `resolver` | DNS | `fn( string $host ): string[]`, every address a host resolves to. For tests |

`register()` throws an `InvalidArgumentException` when a required key is
missing, `store` is not an https address, or `namespace` is not a REST
namespace, so a plugin that got one wrong finds out on its author's screen. A
slug registered twice is the first registration.

```php
Updater::get( 'my-plugin' );   // the registered plugin, or null
Updater::all();                // every one, by slug
```

## The licence

```php
$updater->key();                  // the key as entered, '' when none
$updater->state();                // what the store last said
$updater->activate( $key );       // true, or a WP_Error saying why not
$updater->activate();             // ... with the key kept here, after Deactivate
$updater->deactivate();           // true, or a WP_Error
$updater->check();                // ask the store now, keep and return the state
$updater->latest();               // the newest version, kept for `cache` seconds
$updater->latest( true );         // ... asked for again
```

`state()` is:

| Key | |
|---|---|
| `status` | `none`, `active`, `expired`, `revoked`, `invalid` (a key the store does not know) or `inactive` (a good key without a seat here) |
| `reason` | The store's word: `activated`, `ok`, `deactivated`, `location_not_active`, `limit_reached`… |
| `expires_at` | When the licence ends, as a timestamp, or null for never |
| `activations` | Sites it is active on |
| `max` | Sites it allows, or null for any number |
| `checked_at` | When the store was last asked |

Everything is kept in one option that is not loaded on every page,
`{prefix}_{slug}` with the slug's hyphens as underscores. Whatever the store
says about a key is kept -- a key it does not know stays, shown as its last
four characters, to be corrected -- while an answer about the request (`busy`, too many requests,
a store that cannot be reached) changes nothing. Deactivating keeps the key,
so activating again is one click, and the store is not asked about updates
until it is -- so a site deactivated on purpose is never told its seat was
released elsewhere.

Every error is a `WP_Error` whose code is the store's reason and whose
message is a sentence for the person in front of it:

| Code | Message |
|---|---|
| `not_found` | That licence key was not recognised. Check it against the one in your purchase email. |
| `revoked` | This licence key has been revoked. Contact the store if you think that is a mistake. |
| `expired` | This licence has expired. Renew it to activate it and keep getting updates. |
| `location_not_active` | This site is not activated on that licence. Activate it again to get updates. |
| `limit_reached` | This licence is already in use on as many sites as it allows. Deactivate it on another site, or upgrade it, then try again. |
| `busy` | The store is busy with another activation of this licence. Try again in a moment. |
| `not_active` | The licence could not be activated on this site. Try again, or contact the store. |
| `too_many_requests` | The store has had too many requests from this server. Try again in a minute. |
| `too_many_attempts` | That is a lot of tries at activating this licence. Wait a few minutes, then try again. |
| `key_required` | Enter a licence key first. |
| `product_required`, `location_required` | The store could not tell which product / site this is. Contact the plugin's author. |
| `no_key` | There is no licence key on this site yet. |
| `unreachable` | The store could not be reached. Try again in a few minutes. |
| anything else | The store did not accept the licence key. Try again, or contact the store. |

## The form

`$updater->form()` returns the licence box: the key masked to its last four
characters with Deactivate Licence while it is active here; a key box and
Activate Licence otherwise (empty, with the key kept as its last four for a
placeholder -- left empty, Activate uses that key); one line saying where the
licence stands; and Renew Licence when it has expired or ends within 30 days.

With JavaScript the buttons ask the REST routes below through `wp.apiFetch`
(which renews a nonce that ran out while the page was open), the box is
redrawn in place and the outcome is said in it. Without, the box is a form
that posts to `admin-post.php`, checks the capability and a nonce before
anything reaches the store, and goes back to the page it came from, where the
outcome is shown as a notice.

Inside another form -- a settings page posting to `options.php` -- pass
`embedded`, so it prints no form of its own and its buttons post themselves:

```php
echo $updater->form( [ 'embedded' => true ] );
```

The fields are named from `$updater->id()` (`{prefix}_{slug}`): `{id}_key`,
`{id}_do` and `{id}_nonce`, and admin-post.php's action is `{id}_license`.

### The REST routes

The box's script uses these; so can anything else signed in with the
`capability`. The namespace is named for the build (`SugarCommerce\Plugin\Build`):
`sugarcommerce-updater/v1` unprefixed, `{build}-sugarcommerce-updater/v1` in a
plugin's Strauss copy, so two plugins bundling the library never answer for
each other.

| Route | Sent | Answer |
|---|---|---|
| `GET /licences/{slug}` | `embedded?` | `licence` (the state, and `key` as its last four), `html` (the box) |
| `POST /licences/{slug}/activate` | `key?` (empty: the key kept), `embedded?` | `ok`, `code`, `type`, `message`, `licence`, `html`; 429 after too many tries |
| `POST /licences/{slug}/deactivate` | `embedded?` | the same |

## Updates

When WordPress checks for updates, the store's `latest` route is asked with
the key, the version installed and this site's address. A newer version goes
in the update list's `response`, with the store's signed download link as its
package; the same version goes in `no_update`, which is what shows the
auto-update toggle. The store's own `update` answer decides which, when it
gives one; the version numbers otherwise.

Nothing is offered without a key, or when the store answers no -- an expired
or revoked licence, a seat released -- and the reason is kept as the
licence's state. A store that cannot be reached offers nothing and is not
asked again for an hour. The answer is kept for `cache` seconds; activating
or deactivating forgets it, and the Updates screen's Check Again asks afresh.

The "View details" window (`plugins_api`, for this slug only) shows the
plugin's description, the store's changelog -- plain text, escaped and set in
paragraphs -- and whatever optional details the store sends: `name`,
`homepage`, `requires`, `tested`, `requires_php`, `last_updated`, `icons`,
`banners`, `upgrade_notice`, and `sections` (HTML, replacing the tab of the
same name). Without an answer it still describes the plugin.

## Notices and the plugin row

One notice at most for each plugin, to people with the capability, on the
dashboard, the Plugins and Updates screens and the `settings_url` page:

| Key | When | Links to |
|---|---|---|
| `missing` | No key, a key the store does not know, or deactivated | the licence box |
| `expired` | The licence has run out | Renew Licence |
| `expiring` | It ends within 30 days | Renew Licence |
| `revoked` | The store took it away | View Your Account |
| `released` | This site's seat was released, from the account page or elsewhere | the licence box |

Under the plugin on the Plugins screen: with no `settings_url`, a row that
is the licence box; with one, a row for no key or an expired licence,
linking there, and a Licence link beside Deactivate.

## The weekly check

While the licence is active, a weekly event (`{prefix}_check`, with the slug
as its argument) asks the store whether the key is still good, so a licence
that ran out, was revoked or had this site released is noticed before an
update is refused. It is scheduled on activation, kept scheduled while the
licence is active, and removed on deactivation of the licence or the plugin.

## Hooks

Every hook is named from the `prefix` (`sugarcommerce_updater` unless you
set one), and is given the `Updater` it is about.

### `{prefix}_request`

Filter a request before it is sent: `( array $args, string $action, Updater $updater )`.
`$action` is `activate`, `validate`, `deactivate` or `latest`. The body carries
the licence key: log the action, never the body. Redirects stay off whatever
the filter returns.

```php
add_filter( 'myplugin_request', function ( array $args, string $action ) {
	$args['timeout'] = 30; // a slow store

	return $args;
}, 10, 2 );
```

### `{prefix}_response`

Filter the store's answer, or the `WP_Error` it amounted to:
`( array|WP_Error $answer, string $action, Updater $updater )`.

```php
add_filter( 'myplugin_response', function ( $answer, string $action ) {
	if ( is_wp_error( $answer ) ) {
		error_log( "Licence {$action}: " . $answer->get_error_code() );
	}

	return $answer;
}, 10, 2 );
```

### `{prefix}_update`

Filter what WordPress is told about a new version, or return false to offer
nothing: `( object $update, Updater $updater )`.

```php
add_filter( 'myplugin_update', function ( $update ) {
	// Hold back a major version until the site is on PHP 8.4.
	return version_compare( PHP_VERSION, '8.4', '<' ) && str_starts_with( $update->new_version, '3.' ) ? false : $update;
} );
```

### `{prefix}_info`

Filter the "View details" window: `( object $info, Updater $updater )`.

```php
add_filter( 'myplugin_info', function ( $info ) {
	$info->sections['support'] = '<p>Email <a href="mailto:help@store.example">help@store.example</a>.</p>';

	return $info;
} );
```

### `{prefix}_state_changed`

Fires when what is known about the licence changes:
`( array $state, array $previous, Updater $updater )`.

```php
add_action( 'myplugin_state_changed', function ( array $state, array $previous ) {
	if ( 'active' !== $state['status'] ) {
		delete_option( 'myplugin_pro_features' );
	}
}, 10, 2 );
```

### `{prefix}_notices`

Filter the licence notices about to be shown, keyed as in the table above,
each `[ 'type' => …, 'message' => HTML ]`: `( array $notices, Updater $updater )`.

```php
add_filter( 'myplugin_notices', function ( array $notices ) {
	unset( $notices['expiring'] ); // the store emails about this already

	return $notices;
} );
```

### `{prefix}_notice_screens`

Filter the screen ids the notices appear on, besides the licence's own page:
`( array $screens, Updater $updater )`. The default is the dashboard, Plugins
and Updates screens.

```php
add_filter( 'myplugin_notice_screens', fn( array $screens ) => [ 'plugins' ] );
```

### `{prefix}_check`

The weekly event. Run it yourself with `do_action( 'myplugin_check', 'my-plugin' )`.

## Words

Every word is in `labels`, and any of them can be replaced -- the buttons,
the status line, the notices and each error message. The keys are in
`src/Labels.php`; placeholders are `%s` (or `%1$s`, `%2$s`: the plugin's name
first in the notices, then the date).

```php
Updater::register( [
	// ...
	'labels' => [
		'activate'        => __( 'Unlock Updates', 'my-plugin' ),
		'error_not_found' => __( 'We could not find that key. It is in your receipt.', 'my-plugin' ),
	],
] );
```

## What the store answers

Four public POST routes under `{store}/wp-json/{namespace}`, authenticated by
the key alone, sent as a form:

| Route | Sent |
|---|---|
| `/licenses/activate` | `key`, `product`, `location` (`home_url()`), `environment` (`production`, `staging` or `local`), `version` |
| `/licenses/validate` | `key`, `product`, `location` |
| `/licenses/deactivate` | `key`, `product`, `location` |
| `/licenses/latest` | `key`, `product`, `current`, `location` |

Every well-formed request is answered 200 with
`{ ok, reason, license: { status, expires_at, activations, max, product_id, test } }`,
and `latest` adds `version`, `update`, `changelog`, `files` and `package`. A
SugarCart store answers exactly this; any other store can, too. Keys beyond
these are dropped (see [Security](#security)).

## Two plugins on one site

Everything is named from the prefix and the slug: the option, the form's
fields and admin-post action, the outcome waiting after a redirect, and the
weekly event's argument. Two plugins bundling this library -- or one plugin
with three paid add-ons, each registered -- never share a key, a cache or a
notice. Give each plugin its own `prefix` when its hooks should be its own.

## Security

What each rule defends against:

* **The key is never printed in full** -- masked to its last four in the box, the placeholder and the REST answer -- so other admins, page caches and screenshots never see it.
* **The key goes only to an https store** with a plain host and no credentials in the address (`register()` refuses anything else), so it never crosses the wire in the clear.
* **Every address the store's host resolves to must be public** (`IP::is_public()`, A and AAAA, NAT64/6to4 judged by the IPv4 inside), the connection is pinned to the checked address and redirects are not followed, so a store address cannot be turned on the cloud metadata service or the internal network. The guard is `sugarcommerce/network`'s `Remote\SafeUrl` (https only, the site's own host allowed as core allows it, never a metadata or link-local address).
* **The store's answer is untrusted**: JSON checked with `json_validate()` and decoded to arrays only, capped at 1 MB, `reason` a lower-case word, versions version-shaped, display links http(s), text plain -- and every key the library does not know dropped before it is kept or used.
* **Downloads come only over https from the store's own host and port**, with no credentials in the link, so a store taken over cannot point one-click and automatic updates at a package elsewhere.
* **Activation is limited** to ten tries a quarter of an hour per plugin, so the box cannot be used to guess keys through the site.
* **Every write is checked**: the form for the capability and its nonce, the REST routes for a signed-in user, the capability and the REST nonce; the store's own words never reach the page, only the plugin's labels.
* **Nothing is unserialized**, and no secret is compared by hand -- nonces are WordPress's (`wp_verify_nonce()`, which is timing-safe).

## Layout

| Folder | |
|---|---|
| `src/Updater.php` | The one class a plugin uses; its jobs are traits in `src/Traits/Updater/` |
| `src/Admin.php`, `src/Updates.php` | The box, notices and row (traits in `src/Traits/Admin/`); what WordPress is told about new versions |
| `src/Rest/` | The box's REST routes |
| `src/Store/` | Asking the store (`Client`, through network's `SafeUrl`) and reading its answer (`Reader`) |
| `src/Models/` | Shapes only: `Answer`, `Release`, `Licence`, `Status` |
| `assets/js/`, `assets/build/` | The box's script, and its committed build |

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later
* A store answering the licence API -- SugarCart, or anything that speaks it

## License

GPL-2.0-or-later
