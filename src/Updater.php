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
use WP_Error;

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
 */
final class Updater {

	/**
	 * What a licence can be, as far as this site knows.
	 *
	 * - `none`     No key has been entered.
	 * - `active`   This site holds a seat on a licence that is good.
	 * - `expired`  The licence ran out.
	 * - `revoked`  The store took it away.
	 * - `invalid`  The store does not know the key, for this product.
	 * - `inactive` A good key without a seat here: deactivated, released
	 *              from the account page, or refused one.
	 *
	 * @var string[]
	 */
	public const STATUSES = [ 'none', 'active', 'expired', 'revoked', 'invalid', 'inactive' ];

	/**
	 * The answers that say something about the licence, and so are kept.
	 *
	 * Everything else -- `busy`, `too_many_requests`, a refusal for a
	 * missing field, a store that cannot be reached -- is about the
	 * request, and leaves what is known as it was.
	 *
	 * @var string[]
	 */
	public const ANSWERS = [ 'ok', 'activated', 'deactivated', 'not_found', 'revoked', 'expired', 'location_not_active', 'limit_reached', 'not_active' ];

	/**
	 * How long the store's answer about updates is kept, in seconds.
	 *
	 * WordPress checks twice a day; keeping the answer for six hours means
	 * every one of those checks is a fresh one, and a Plugins screen
	 * opened in between costs the store nothing.
	 */
	public const CACHE = 21600;

	/**
	 * The longest the answer may be kept, in seconds.
	 *
	 * The download link in it is signed for a day. WordPress keeps the
	 * update it was given for up to twelve hours more, so an answer kept
	 * longer than this could hand WordPress a link that has run out.
	 */
	public const CACHE_LIMIT = 43200;

	/**
	 * How long a store that could not be reached is left alone, in seconds.
	 */
	public const RETRY_AFTER = 3600;

	/**
	 * How close to its end a licence starts saying so, in seconds.
	 */
	public const EXPIRING = 2592000;

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
	 * The configuration, normalised.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * The plugin's headers, read when first asked for.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $headers = null;

	/**
	 * Every word, translated when first asked for.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $labels = null;

	/**
	 * Whether a check forced from the Updates screen has already been made
	 * on this request.
	 *
	 * WordPress saves the update list more than once while it checks, and
	 * each save asks again; one fresh answer a request is enough.
	 *
	 * @var bool
	 */
	private bool $fresh = false;

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
	 *                                  `product` is missing. A plugin that
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
	 * The licence key, as entered. Empty when there is none.
	 *
	 * @return string
	 */
	public function key(): string {
		return (string) $this->stored()['key'];
	}

	/**
	 * What is known about the licence.
	 *
	 * What the store said the last time it was asked; nothing here asks
	 * it. check() does.
	 *
	 * @return array{status: string, reason: string, expires_at: int|null, activations: int|null, max: int|null, checked_at: int|null}
	 */
	public function state(): array {
		$state = array_merge(
			[
				'status'      => 'none',
				'reason'      => '',
				'expires_at'  => null,
				'activations' => null,
				'max'         => null,
				'checked_at'  => null,
			],
			(array) $this->stored()['state']
		);

		// No key is no licence, whatever was said about the last one.
		if ( '' === $this->key() || ! in_array( $state['status'], self::STATUSES, true ) ) {
			$state['status'] = 'none';
		}

		return $state;
	}

	/**
	 * Claim this site's seat with a key.
	 *
	 * Whatever the store says about the key is kept, so a key it does not
	 * know stays in the box to be corrected and the notices say why. A
	 * store that cannot be reached, or asks to be asked again, changes
	 * nothing.
	 *
	 * @param string $key The key.
	 *
	 * @return true|WP_Error
	 */
	public function activate( string $key ): true|WP_Error {
		$key = trim( sanitize_text_field( $key ) );

		if ( '' === $key ) {
			return $this->error( 'key_required' );
		}

		$answer = $this->client->send(
			'activate',
			[
				'key'         => $key,
				'product'     => $this->config['product'],
				'location'    => $this->location(),
				'environment' => self::environment(),
				'version'     => $this->config['version'],
			]
		);

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		if ( ! in_array( $answer['reason'], self::ANSWERS, true ) ) {
			return $this->error( (string) $answer['reason'], $answer );
		}

		$this->record( $key, $answer );
		$this->forget();

		if ( empty( $answer['ok'] ) ) {
			return $this->error( (string) $answer['reason'], $answer );
		}

		$this->schedule();

		return true;
	}

	/**
	 * Release this site's seat.
	 *
	 * The key is kept, so activating again is one click. A seat the store
	 * says this site does not hold, or a key it no longer knows, is
	 * already released, and that is what was asked for.
	 *
	 * @return true|WP_Error
	 */
	public function deactivate(): true|WP_Error {
		$key = $this->key();

		if ( '' === $key ) {
			return $this->error( 'no_key' );
		}

		$answer = $this->client->send(
			'deactivate',
			[
				'key'      => $key,
				'product'  => $this->config['product'],
				'location' => $this->location(),
			]
		);

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		if ( empty( $answer['ok'] ) && ! in_array( $answer['reason'], [ 'location_not_active', 'not_found' ], true ) ) {
			return $this->error( (string) $answer['reason'], $answer );
		}

		$this->record(
			$key,
			[
				'reason'  => 'deactivated',
				'license' => $answer['license'] ?? null,
			]
		);

		$this->forget();
		$this->unschedule();

		return true;
	}

	/**
	 * Ask the store whether the key is still good, and keep the answer.
	 *
	 * What the weekly check runs. Finds out about a licence that ran out,
	 * was taken away, or had this site's seat released from the account
	 * page -- without waiting for an update to be refused.
	 *
	 * @return array The state afterwards. See state().
	 */
	public function check(): array {
		$key = $this->key();

		if ( '' === $key ) {
			return $this->state();
		}

		$answer = $this->client->send(
			'validate',
			[
				'key'      => $key,
				'product'  => $this->config['product'],
				'location' => $this->location(),
			]
		);

		if ( ! is_wp_error( $answer ) && in_array( $answer['reason'], self::ANSWERS, true ) ) {
			$this->record( $key, $answer );
		}

		return $this->state();
	}

	/**
	 * The newest version, from the store, kept for `cache` seconds.
	 *
	 * The store's answer as it came: `version`, `update`, `changelog`,
	 * `package` and the rest. A licence the store will not update -- run
	 * out, taken away, not active here -- is the error saying so, and is
	 * kept as the licence's state too.
	 *
	 * @param bool $force Ask the store even when an answer is kept.
	 *
	 * @return array|WP_Error
	 */
	public function latest( bool $force = false ): array|WP_Error {
		$key = $this->key();

		if ( '' === $key ) {
			return $this->error( 'no_key' );
		}

		$stored = $this->stored();
		$force  = $force || ( ! $this->fresh && self::forced() );

		if ( ! $force ) {
			$kept = $stored['latest'];

			if ( is_array( $kept ) && (int) $kept['at'] + $this->config['cache'] > time() && is_array( $kept['answer'] ) ) {
				return $this->outcome( $kept['answer'] );
			}

			// The store was down a moment ago. Asking again on every page
			// load until it is back makes every page load slow, and the
			// store's recovery slower.
			if ( (int) $stored['retry_at'] > time() ) {
				return $this->error( 'unreachable' );
			}
		}

		$this->fresh = true;

		$answer = $this->client->send(
			'latest',
			[
				'key'      => $key,
				'product'  => $this->config['product'],
				'current'  => $this->config['version'],
				'location' => $this->location(),
			]
		);

		if ( is_wp_error( $answer ) || ! in_array( $answer['reason'], self::ANSWERS, true ) ) {
			$stored['retry_at'] = time() + self::RETRY_AFTER;
			$this->save( $stored );

			return is_wp_error( $answer ) ? $answer : $this->error( (string) $answer['reason'], $answer );
		}

		$this->record( $key, $answer );

		$stored             = $this->stored();
		$stored['latest']   = [
			'at'     => time(),
			'answer' => $answer,
		];
		$stored['retry_at'] = 0;
		$this->save( $stored );

		return $this->outcome( $answer );
	}

	/**
	 * The licence form.
	 *
	 * A masked key and Deactivate while the licence is active here; a key
	 * box and Activate otherwise; one line saying where the licence stands;
	 * a Renew link when it has run out or ends within 30 days. It posts to
	 * admin-post.php and comes back to the page it was on, with the outcome
	 * as a notice.
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

	/**
	 * The plugin's slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return $this->config['slug'];
	}

	/**
	 * The plugin's basename, `folder/file.php`.
	 *
	 * @return string
	 */
	public function basename(): string {
		return $this->config['basename'];
	}

	/**
	 * The plugin's name: as configured, or its `Plugin Name` header.
	 *
	 * @return string
	 */
	public function name(): string {
		if ( '' !== $this->config['name'] ) {
			return $this->config['name'];
		}

		return '' !== $this->header( 'Name' ) ? $this->header( 'Name' ) : $this->config['slug'];
	}

	/**
	 * The version installed.
	 *
	 * @return string
	 */
	public function version(): string {
		return $this->config['version'];
	}

	/**
	 * One configuration value, normalised.
	 *
	 * @param string $key The key.
	 *
	 * @return mixed
	 */
	public function config( string $key ): mixed {
		return $this->config[ $key ] ?? null;
	}

	/**
	 * One of the plugin's own headers.
	 *
	 * `Name`, `PluginURI`, `Description`, `Author`, `AuthorURI`,
	 * `RequiresWP`, `RequiresPHP` or `UpdateURI`.
	 *
	 * @param string $key The header.
	 *
	 * @return string
	 */
	public function header( string $key ): string {
		if ( null === $this->headers ) {
			$this->headers = array_map(
				'strval',
				get_file_data(
					$this->config['file'],
					[
						'Name'        => 'Plugin Name',
						'PluginURI'   => 'Plugin URI',
						'Description' => 'Description',
						'Author'      => 'Author',
						'AuthorURI'   => 'Author URI',
						'RequiresWP'  => 'Requires at least',
						'RequiresPHP' => 'Requires PHP',
						'UpdateURI'   => 'Update URI',
					],
					'plugin'
				)
			);
		}

		return $this->headers[ $key ] ?? '';
	}

	/**
	 * Where the licence form lives.
	 *
	 * @return string
	 */
	public function settings_url(): string {
		return '' !== $this->config['settings_url'] ? $this->config['settings_url'] : admin_url( 'plugins.php' );
	}

	/**
	 * The host whose `update_plugins_{host}` filter answers for this
	 * plugin, or an empty string when updates come through the update
	 * list instead.
	 *
	 * Only when the plugin's `Update URI` header names the store's own
	 * host. Then WordPress does not ask wordpress.org about the plugin at
	 * all -- so a free plugin there with the same folder name can never
	 * be offered over it -- and asks this instead.
	 *
	 * @return string
	 */
	public function update_host(): string {
		$uri = trim( $this->header( 'UpdateURI' ) );

		if ( '' === $uri ) {
			return '';
		}

		// As core reads it: a URI without a scheme is given one.
		$host  = (string) wp_parse_url( str_contains( $uri, '://' ) ? $uri : 'http://' . $uri, PHP_URL_HOST );
		$store = (string) wp_parse_url( $this->config['store'], PHP_URL_HOST );

		return '' !== $host && strtolower( $host ) === strtolower( $store ) ? $host : '';
	}

	/**
	 * The address of one of the store's licence routes.
	 *
	 * @param string $action `activate`, `validate`, `deactivate` or `latest`.
	 *
	 * @return string
	 */
	public function endpoint( string $action ): string {
		return sprintf( '%s/wp-json/%s/licenses/%s', $this->config['store'], $this->config['namespace'], $action );
	}

	/**
	 * Where this site is, to the store: its home URL.
	 *
	 * @return string
	 */
	public function location(): string {
		return home_url();
	}

	/**
	 * The name everything this plugin keeps or listens for is built from:
	 * `{prefix}_{slug}`, with the slug's hyphens as underscores.
	 *
	 * The option is called this, the form's fields start with it, and
	 * admin-post.php's action is it plus `_license`.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->config['prefix'] . '_' . str_replace( '-', '_', $this->config['slug'] );
	}

	/**
	 * One hook's name: `{prefix}_{name}`.
	 *
	 * @param string $name The hook, without the prefix.
	 *
	 * @return string
	 */
	public function hook_name( string $name ): string {
		return $this->config['prefix'] . '_' . $name;
	}

	/**
	 * One word or sentence, as configured or translated.
	 *
	 * @param string $key       The label.
	 * @param mixed  ...$values Anything it has placeholders for.
	 *
	 * @return string
	 */
	public function label( string $key, mixed ...$values ): string {
		if ( null === $this->labels ) {
			$this->labels = array_merge( Labels::defaults(), array_map( 'strval', (array) $this->config['labels'] ) );
		}

		$label = $this->labels[ $key ] ?? $key;

		return [] === $values ? $label : vsprintf( $label, array_map( 'strval', $values ) );
	}

	/**
	 * The error for a reason, in plain English.
	 *
	 * The code is the reason as the store said it; the message is what a
	 * person is shown. The store's answer, when there was one, is the
	 * error's data.
	 *
	 * @param string     $reason The reason.
	 * @param array|null $answer The store's answer.
	 *
	 * @return WP_Error
	 */
	public function error( string $reason, ?array $answer = null ): WP_Error {
		$key = 'error_' . $reason;

		$message = $this->label( $key );

		if ( $message === $key ) {
			$message = $this->label( 'error_unknown' );
		}

		return new WP_Error( '' !== $reason ? $reason : 'unknown', $message, null === $answer ? null : [ 'answer' => $answer ] );
	}

	/**
	 * A date as the site writes them.
	 *
	 * @param int $timestamp The moment.
	 *
	 * @return string
	 */
	public function date( int $timestamp ): string {
		$format = (string) get_option( 'date_format' );

		return (string) wp_date( '' !== $format ? $format : 'j F Y', $timestamp );
	}

	/**
	 * Keep the weekly check scheduled while the licence is active. What
	 * runs on `admin_init`.
	 *
	 * @return void
	 */
	public function keep_scheduled(): void {
		// The schedule is loaded on every request anyway; the licence's
		// option is not, so it is only read when there is something to fix.
		if ( false !== wp_next_scheduled( $this->hook_name( 'check' ), [ $this->config['slug'] ] ) ) {
			return;
		}

		if ( 'active' === $this->state()['status'] ) {
			$this->schedule();
		}
	}

	/**
	 * Stop the weekly check. What runs when the plugin is deactivated.
	 *
	 * @return void
	 */
	public function unschedule(): void {
		wp_clear_scheduled_hook( $this->hook_name( 'check' ), [ $this->config['slug'] ] );
	}

	/**
	 * Start the weekly check, unless it is already scheduled.
	 *
	 * @return void
	 */
	private function schedule(): void {
		$hook = $this->hook_name( 'check' );
		$args = [ $this->config['slug'] ];

		if ( false === wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', $hook, $args );
		}
	}

	/**
	 * Attach the hooks.
	 *
	 * @return void
	 */
	private function hook(): void {
		$host     = $this->update_host();
		$basename = $this->config['basename'];

		if ( '' !== $host ) {
			add_filter( 'update_plugins_' . $host, [ $this->updates, 'offer' ], 10, 3 );
		} else {
			add_filter( 'pre_set_site_transient_update_plugins', [ $this->updates, 'transient' ] );
		}

		add_filter( 'plugins_api', [ $this->updates, 'info' ], 20, 3 );

		// admin-post.php fires admin_init before its own action, so the
		// form is handled there either way; listening on admin_init too is
		// what lets an embedded form post from inside a settings page's.
		add_action( 'admin_post_' . $this->id() . '_license', [ $this->admin, 'listen' ] );
		add_action( 'admin_init', [ $this->admin, 'listen' ] );
		add_action( 'admin_init', [ $this, 'keep_scheduled' ] );

		add_action( 'admin_notices', [ $this->admin, 'outcome' ] );

		if ( $this->config['notices'] ) {
			add_action( 'admin_notices', [ $this->admin, 'notices' ] );
		}

		add_action( 'after_plugin_row_' . $basename, [ $this->admin, 'row' ], 10, 0 );
		add_filter( 'plugin_action_links_' . $basename, [ $this->admin, 'links' ] );

		register_deactivation_hook( $this->config['file'], [ $this, 'unschedule' ] );

		if ( ! isset( self::$listening[ $this->config['prefix'] ] ) ) {
			self::$listening[ $this->config['prefix'] ] = true;

			add_action( $this->hook_name( 'check' ), [ self::class, 'scheduled' ] );
		}
	}

	/**
	 * Keep what the store said about a key.
	 *
	 * @param string $key    The key it was about.
	 * @param array  $answer The store's answer.
	 *
	 * @return void
	 */
	private function record( string $key, array $answer ): void {
		$previous = $this->state();
		$stored   = $this->stored();
		$license  = is_array( $answer['license'] ?? null ) ? $answer['license'] : [];
		$reason   = (string) $answer['reason'];

		$stored['key']   = $key;
		$stored['state'] = [
			'status'      => match ( $reason ) {
				'ok', 'activated' => 'active',
				'expired'         => 'expired',
				'revoked'         => 'revoked',
				'not_found'       => 'invalid',
				default           => 'inactive',
			},
			'reason'      => $reason,
			'expires_at'  => isset( $license['expires_at'] ) ? (int) $license['expires_at'] : null,
			'activations' => isset( $license['activations'] ) ? (int) $license['activations'] : null,
			'max'         => isset( $license['max'] ) ? (int) $license['max'] : null,
			'checked_at'  => time(),
		];

		$this->save( $stored );

		$state = $this->state();

		if ( $state['status'] !== $previous['status'] || $state['reason'] !== $previous['reason'] ) {
			/**
			 * Fires when what is known about a licence changes.
			 *
			 * @param array   $state    The state now. See Updater::state().
			 * @param array   $previous The state before.
			 * @param Updater $updater  The plugin it is about.
			 */
			do_action( $this->hook_name( 'state_changed' ), $state, $previous, $this );
		}
	}

	/**
	 * Forget the kept update answer, here and in WordPress's update list,
	 * so the next look at the Plugins screen asks again.
	 *
	 * @return void
	 */
	private function forget(): void {
		$stored             = $this->stored();
		$stored['latest']   = null;
		$stored['retry_at'] = 0;

		$this->save( $stored );

		delete_site_transient( 'update_plugins' );
	}

	/**
	 * The store's answer, or the error it amounts to.
	 *
	 * @param array $answer The answer.
	 *
	 * @return array|WP_Error
	 */
	private function outcome( array $answer ): array|WP_Error {
		return empty( $answer['ok'] ) ? $this->error( (string) ( $answer['reason'] ?? '' ), $answer ) : $answer;
	}

	/**
	 * Everything kept for this plugin.
	 *
	 * @return array{key: string, state: array, latest: array|null, retry_at: int}
	 */
	private function stored(): array {
		$stored = get_option( $this->id(), [] );

		return array_merge(
			[
				'key'      => '',
				'state'    => [],
				'latest'   => null,
				'retry_at' => 0,
			],
			is_array( $stored ) ? $stored : []
		);
	}

	/**
	 * Keep it, in one option that is not loaded on every page.
	 *
	 * @param array $stored Everything kept.
	 *
	 * @return void
	 */
	private function save( array $stored ): void {
		update_option( $this->id(), $stored, false );
	}

	/**
	 * Whether this is the Updates screen's "Check again".
	 *
	 * @return bool
	 */
	private static function forced(): bool {
		global $pagenow;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only skips the cache; core checks the request.
		return is_admin() && 'update-core.php' === $pagenow && ! empty( $_GET['force-check'] );
	}

	/**
	 * The kind of site this is, in the store's words.
	 *
	 * @return string `production`, `staging` or `local`.
	 */
	private static function environment(): string {
		return match ( wp_get_environment_type() ) {
			'staging'              => 'staging',
			'development', 'local' => 'local',
			default                => 'production',
		};
	}

	/**
	 * Check and complete a configuration.
	 *
	 * @param array<string, mixed> $config As given.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws InvalidArgumentException When a required key is missing.
	 */
	private static function normalise( array $config ): array {
		foreach ( [ 'file', 'version', 'store', 'product' ] as $required ) {
			if ( ! isset( $config[ $required ] ) || ! is_scalar( $config[ $required ] ) || '' === trim( (string) $config[ $required ] ) ) {
				throw new InvalidArgumentException( esc_html( sprintf( 'Updater::register() needs "%s".', $required ) ) );
			}
		}

		$store = untrailingslashit( esc_url_raw( trim( (string) $config['store'] ) ) );

		if ( '' === $store ) {
			throw new InvalidArgumentException( 'Updater::register() needs "store" to be the address of the store.' );
		}

		$file     = (string) $config['file'];
		$basename = plugin_basename( $file );
		$folder   = dirname( $basename );
		$slug     = sanitize_key( (string) ( $config['slug'] ?? ( '.' !== $folder ? $folder : basename( $basename, '.php' ) ) ) );
		$prefix   = sanitize_key( (string) ( $config['prefix'] ?? '' ) );
		$cache    = (int) ( $config['cache'] ?? self::CACHE );

		return [
			'file'         => $file,
			'basename'     => $basename,
			'slug'         => $slug,
			'version'      => trim( (string) $config['version'] ),
			'store'        => $store,
			'product'      => trim( (string) $config['product'] ),
			'name'         => (string) ( $config['name'] ?? '' ),
			'description'  => (string) ( $config['description'] ?? '' ),
			'namespace'    => trim( (string) ( $config['namespace'] ?? 'sugarcart/v1' ), '/' ),
			'prefix'       => '' !== $prefix ? $prefix : 'sugarcommerce_updater',
			'capability'   => (string) ( $config['capability'] ?? 'manage_options' ),
			'renew_url'    => esc_url_raw( (string) ( $config['renew_url'] ?? $store ) ),
			'account_url'  => esc_url_raw( (string) ( $config['account_url'] ?? $store ) ),
			'settings_url' => esc_url_raw( (string) ( $config['settings_url'] ?? '' ) ),
			'cache'        => max( MINUTE_IN_SECONDS, min( self::CACHE_LIMIT, $cache ) ),
			'labels'       => (array) ( $config['labels'] ?? [] ),
			'notices'      => (bool) ( $config['notices'] ?? true ),
		];
	}
}
