<?php
/**
 * Updates for a paid plugin, from the store that sold it.
 *
 * @package   SugarCommerce\Updater
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Updater;

use InvalidArgumentException;
use SugarCommerce\Updater\Rest\Controller;
use SugarCommerce\Updater\Store\Client;

/**
 * One paid plugin's licence and updates.
 *
 * A plugin sold from a store does not come from wordpress.org, so nothing
 * tells WordPress there is a new version, and nothing should hand the new
 * version to a site whose licence has run out. This asks the store that
 * sold it -- its licence API, `{store}/wp-json/{namespace}/licenses/*` --
 * with the key the customer typed in:
 *
 * - **The key.** Activate and Deactivate claim and release this site's
 *   seat. What the store answered is kept, in one option, so the form, the
 *   notices and the plugin row all say the same thing.
 *
 * - **Updates.** When WordPress checks for updates, the store is asked for
 *   the newest version and a link to it, and its answer is kept for a few
 *   hours. A store that is down, or a key it does not accept, offers
 *   nothing -- and is not asked again for an hour.
 *
 * - **Saying so.** No key yet, a licence that has run out or is about to,
 *   one taken away, a seat released from the account page: each is one
 *   notice, to the people who can do something about it, on the screens
 *   where it matters.
 *
 * Everything this keeps or fires is named from the `prefix` and the slug,
 * so two plugins that each bundle the library -- or one plugin with three
 * paid add-ons -- never share a key, a cache or a hook.
 *
 * The jobs are in traits: Traits\Updater\Licence (the key and the seat),
 * Releases (the newest version), Plugin (what the plugin is and is called)
 * and Setup (configuration, hooks, the weekly event).
 */
final class Updater {

	use Traits\Updater\Licence;
	use Traits\Updater\Plugin;
	use Traits\Updater\Releases;
	use Traits\Updater\Setup;

	/**
	 * What a licence can be, as far as this site knows: Models\Status's
	 * values.
	 *
	 * @var string[]
	 */
	public const array STATUSES = [ 'none', 'active', 'expired', 'revoked', 'invalid', 'inactive' ];

	/**
	 * The answers that say something about the licence, and so are kept.
	 *
	 * Everything else -- `busy`, `too_many_requests`, a refusal for a
	 * missing field, a store that cannot be reached -- is about the
	 * request, and leaves what is known as it was.
	 *
	 * @var string[]
	 */
	public const array ANSWERS = [ 'ok', 'activated', 'deactivated', 'not_found', 'revoked', 'expired', 'location_not_active', 'limit_reached', 'not_active' ];

	/**
	 * How long the store's answer about updates is kept, in seconds.
	 *
	 * WordPress checks twice a day; keeping the answer for six hours means
	 * every one of those checks is a fresh one, and a Plugins screen
	 * opened in between costs the store nothing.
	 */
	public const int CACHE = 21600;

	/**
	 * The longest the answer may be kept, in seconds.
	 *
	 * The download link in it is signed for a day. WordPress keeps the
	 * update it was given for up to twelve hours more, so an answer kept
	 * longer than this could hand WordPress a link that has run out.
	 */
	public const int CACHE_LIMIT = 43200;

	/**
	 * How long a store that could not be reached is left alone, in seconds.
	 */
	public const int RETRY_AFTER = 3600;

	/**
	 * How close to its end a licence starts saying so, in seconds.
	 */
	public const int EXPIRING = 2592000;

	/**
	 * Activations one plugin may try in ATTEMPT_WINDOW.
	 *
	 * Each is a request to the store with a key someone typed. Ten in a
	 * quarter of an hour is plenty for a person correcting a typo, and
	 * nothing like enough to guess keys through this site.
	 */
	public const int ATTEMPTS = 10;

	/**
	 * The window ATTEMPTS counts in, in seconds.
	 */
	public const int ATTEMPT_WINDOW = 900;

	/**
	 * Registered plugins, by slug.
	 *
	 * @var array<string, Updater>
	 */
	private static array $registry = [];

	/**
	 * Prefixes whose scheduled check has been attached.
	 *
	 * @var array<string, bool>
	 */
	private static array $listening = [];

	/**
	 * What talks to the store.
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * What tells WordPress about new versions.
	 *
	 * @var Updates
	 */
	private Updates $updates;

	/**
	 * The form, its handler, the notices and the plugin row.
	 *
	 * @var Admin
	 */
	private Admin $admin;

	/**
	 * Give a plugin a licence key and updates from its store.
	 *
	 *     Updater::register( [
	 *         'file'    => __FILE__,
	 *         'version' => '1.4.0',
	 *         'store'   => 'https://store.example',
	 *         'product' => 'my-plugin',
	 *     ] );
	 *
	 * Call it from the plugin's main file, or on `plugins_loaded`.
	 * Registering a slug a second time hands back the first.
	 *
	 * @param array<string, mixed> $config See the README for every key.
	 *
	 * @return Updater
	 *
	 * @throws InvalidArgumentException When `file`, `version`, `store` or
	 *                                  `product` is missing, or `store` is
	 *                                  not an https address. A plugin that
	 *                                  forgot one should find out on its
	 *                                  author's screen, not a customer's.
	 */
	public static function register( array $config ): Updater {
		$config = self::normalise( $config );

		if ( isset( self::$registry[ $config['slug'] ] ) ) {
			return self::$registry[ $config['slug'] ];
		}

		$updater = new self( $config );

		self::$registry[ $config['slug'] ] = $updater;

		$updater->hook();

		return $updater;
	}

	/**
	 * A registered plugin, by slug.
	 *
	 * @param string $slug The slug.
	 *
	 * @return Updater|null
	 */
	public static function get( string $slug ): ?Updater {
		return self::$registry[ sanitize_key( $slug ) ] ?? null;
	}

	/**
	 * Every registered plugin, by slug.
	 *
	 * @return array<string, Updater>
	 */
	public static function all(): array {
		return self::$registry;
	}

	/**
	 * Forget every registered plugin. For tests.
	 *
	 * Hooks already attached stay attached.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$registry  = [];
		self::$listening = [];

		Controller::reset();
	}

	/**
	 * The weekly check. What the scheduled event runs.
	 *
	 * @param mixed $slug The plugin it is for.
	 *
	 * @return void
	 */
	public static function scheduled( mixed $slug ): void {
		self::get( (string) $slug )?->check();
	}

	/**
	 * Set up.
	 *
	 * @param array<string, mixed> $config The configuration, normalised.
	 */
	private function __construct( array $config ) {
		$this->config  = $config;
		$this->client  = new Client( $this );
		$this->updates = new Updates( $this );
		$this->admin   = new Admin( $this );
	}

	/**
	 * The licence form.
	 *
	 * A masked key and Deactivate while the licence is active here; a key
	 * box and Activate otherwise; one line saying where the licence stands;
	 * a Renew link when it has run out or ends within 30 days. With
	 * JavaScript the buttons ask the REST route and the box redraws in
	 * place; without, it posts to admin-post.php and comes back to the page
	 * it was on, with the outcome as a notice.
	 *
	 * Inside another form -- a settings page -- pass `embedded`: the
	 * fields are printed without a form of their own, and the buttons post
	 * to admin-post.php by themselves.
	 *
	 * @param array{embedded?: bool} $args Arguments.
	 *
	 * @return string
	 */
	public function form( array $args = [] ): string {
		return $this->admin->form( $args );
	}

	/**
	 * Handle the form. What admin-post.php runs.
	 *
	 * @return string|null Where it redirected to, or null when the request
	 *                     was not this form's.
	 */
	public function handle(): ?string {
		return $this->admin->handle();
	}

	/**
	 * The notices that apply now, by key, before the screen and the
	 * `{prefix}_notices` filter are considered.
	 *
	 * @return array<string, array{type: string, message: string}>
	 */
	public function notices(): array {
		return $this->admin->due();
	}
}
