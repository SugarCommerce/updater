<?php
/**
 * Configuration, hooks and the weekly event.
 *
 * @package   SugarCommerce\Updater
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Updater\Traits\Updater;

use InvalidArgumentException;
use SugarCommerce\Network\Remote\SafeUrl;
use SugarCommerce\Updater\Rest\Controller;
use SugarCommerce\Updater\Updater;

/**
 * Checking what register() was given, attaching what it needs, and keeping
 * the weekly check scheduled while the licence is active.
 */
trait Setup {

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

		// The no-JavaScript form posts to admin-post.php, which fires
		// admin_init before its own action, so the form is handled there
		// either way; listening on admin_init too is what lets an embedded
		// form post from inside a settings page's. The script uses REST.
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

		Controller::listen();

		if ( ! isset( self::$listening[ $this->config['prefix'] ] ) ) {
			self::$listening[ $this->config['prefix'] ] = true;

			add_action( $this->hook_name( 'check' ), [ self::class, 'scheduled' ] );
		}
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
	 * @throws InvalidArgumentException When a required key is missing, or
	 *                                  the store or its namespace is not
	 *                                  one to send a licence key to.
	 */
	private static function normalise( array $config ): array {
		foreach ( [ 'file', 'version', 'store', 'product' ] as $required ) {
			if ( ! isset( $config[ $required ] ) || ! is_scalar( $config[ $required ] ) || '' === trim( (string) $config[ $required ] ) ) {
				throw new InvalidArgumentException( esc_html( sprintf( 'Updater::register() needs "%s".', $required ) ) );
			}
		}

		$store = untrailingslashit( trim( (string) $config['store'] ) );

		// The key is sent there: over https, to a plain host, nowhere else.
		if ( ! self::is_store_address( $store ) ) {
			throw new InvalidArgumentException( 'Updater::register() needs "store" to be the https address of the store.' );
		}

		$namespace = trim( (string) ( $config['namespace'] ?? 'sugarcart/v1' ), '/' );

		if ( 1 !== preg_match( '#^[A-Za-z0-9_-]+(/[A-Za-z0-9_.-]+)*$#D', $namespace ) || str_contains( $namespace, '..' ) ) {
			throw new InvalidArgumentException( 'Updater::register() needs "namespace" to be a REST namespace, like "sugarcart/v1".' );
		}

		$file     = (string) $config['file'];
		$basename = plugin_basename( $file );
		$folder   = dirname( $basename );
		$slug     = sanitize_key( (string) ( $config['slug'] ?? ( '.' !== $folder ? $folder : basename( $basename, '.php' ) ) ) );
		$prefix   = sanitize_key( (string) ( $config['prefix'] ?? '' ) );
		$cache    = (int) ( $config['cache'] ?? Updater::CACHE );

		return [
			'file'         => $file,
			'basename'     => $basename,
			'slug'         => $slug,
			'version'      => trim( (string) $config['version'] ),
			'store'        => $store,
			'product'      => trim( (string) $config['product'] ),
			'name'         => (string) ( $config['name'] ?? '' ),
			'description'  => (string) ( $config['description'] ?? '' ),
			'namespace'    => $namespace,
			'prefix'       => '' !== $prefix ? $prefix : 'sugarcommerce_updater',
			'capability'   => (string) ( $config['capability'] ?? 'activate_plugins' ),
			'renew_url'    => esc_url_raw( (string) ( $config['renew_url'] ?? $store ) ),
			'account_url'  => esc_url_raw( (string) ( $config['account_url'] ?? $store ) ),
			'settings_url' => esc_url_raw( (string) ( $config['settings_url'] ?? '' ) ),
			'cache'        => max( MINUTE_IN_SECONDS, min( Updater::CACHE_LIMIT, $cache ) ),
			'labels'       => (array) ( $config['labels'] ?? [] ),
			'notices'      => (bool) ( $config['notices'] ?? true ),
			'resolver'     => is_callable( $config['resolver'] ?? null ) ? $config['resolver'] : null,
		];
	}

	/**
	 * Whether an address is one a store may have: https, a plain host that
	 * is not a metadata service, no credentials, no query or fragment. No
	 * DNS: what register() can say before anything is asked.
	 *
	 * @param string $url The address.
	 *
	 * @return bool
	 */
	private static function is_store_address( string $url ): bool {
		if ( null !== ( new SafeUrl( https_only: true ) )->malformed( $url ) ) {
			return false;
		}

		$parts = (array) wp_parse_url( $url );

		return ! isset( $parts['query'] ) && ! isset( $parts['fragment'] );
	}
}
