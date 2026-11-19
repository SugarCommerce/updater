<?php
/**
 * New versions, told to WordPress.
 *
 * @package   SugarCommerce\Updater
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Updater;

use stdClass;

/**
 * What WordPress is told about the plugin: whether there is a new version,
 * where to download it, and what the "View details" window says.
 *
 * WordPress asks in one of two ways. A plugin whose `Update URI` header
 * names the store's host is asked about on `update_plugins_{host}`, and
 * wordpress.org is never asked about it. Any other is added to the update
 * list as it is saved (`pre_set_site_transient_update_plugins`) -- to
 * `response` when there is a newer version, to `no_update` otherwise,
 * which is what lets the auto-update toggle appear.
 *
 * Nothing is offered without a key, or when the store is down or says no.
 *
 * Part of Updater; not meant to be used on its own.
 */
final class Updates {

	/**
	 * The keys of the store's answer passed through to WordPress as they
	 * are, when the store sends them.
	 *
	 * @var string[]
	 */
	private const TEXT = [ 'requires', 'tested', 'requires_php', 'last_updated', 'upgrade_notice' ];

	/**
	 * Set up.
	 *
	 * @param Updater $updater The plugin.
	 */
	public function __construct( private Updater $updater ) {
	}

	/**
	 * Add the plugin to the update list as WordPress saves it.
	 *
	 * @param mixed $transient The `update_plugins` site transient.
	 *
	 * @return mixed
	 */
	public function transient( mixed $transient ): mixed {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$answer = $this->answer();
		$update = null === $answer ? null : $this->update( $answer );

		if ( null === $update ) {
			return $transient;
		}

		$basename = $this->updater->basename();

		if ( $this->newer( $answer ) ) {
			$transient->response[ $basename ] = $update;
			unset( $transient->no_update[ $basename ] );
		} else {
			$transient->no_update[ $basename ] = $update;
			unset( $transient->response[ $basename ] );
		}

		return $transient;
	}

	/**
	 * Answer `update_plugins_{host}` for this plugin.
	 *
	 * The filter runs once for every plugin whose `Update URI` names the
	 * host, so another plugin sold from the same store is left alone.
	 * WordPress compares the versions itself.
	 *
	 * @param mixed  $update      What another callback answered, or false.
	 * @param mixed  $plugin_data The plugin's headers.
	 * @param string $plugin_file The plugin's basename.
	 *
	 * @return mixed
	 */
	public function offer( mixed $update, mixed $plugin_data = [], string $plugin_file = '' ): mixed {
		if ( $plugin_file !== $this->updater->basename() ) {
			return $update;
		}

		$answer = $this->answer();
		$offer  = null === $answer ? null : $this->update( $answer );

		return null === $offer ? $update : $offer;
	}

	/**
	 * Fill the "View details" window.
	 *
	 * Only for `plugin_information` about this plugin's slug. Without an
	 * answer from the store the window still shows the plugin's own
	 * description, rather than wordpress.org's "Plugin not found".
	 *
	 * @param mixed  $result What another callback answered, or false.
	 * @param string $action The plugins_api() action.
	 * @param mixed  $args   Its arguments, with the slug.
	 *
	 * @return mixed
	 */
	public function info( mixed $result, string $action = '', mixed $args = null ): mixed {
		$slug = is_object( $args ) ? ( $args->slug ?? '' ) : ( is_array( $args ) ? ( $args['slug'] ?? '' ) : '' );

		if ( 'plugin_information' !== $action || $slug !== $this->updater->slug() ) {
			return $result;
		}

		$answer = $this->answer() ?? [];
		$author = $this->updater->header( 'Author' );
		$link   = $this->updater->header( 'AuthorURI' );

		$info = (object) [
			'name'          => $this->text( $answer, 'name', $this->updater->name() ),
			'slug'          => $this->updater->slug(),
			'version'       => $this->text( $answer, 'version', $this->updater->version() ),
			'author'        => '' !== $link && '' !== $author ? sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $author ) ) : esc_html( $author ),
			'homepage'      => esc_url_raw( $this->text( $answer, 'homepage', $this->homepage() ) ),
			'sections'      => $this->sections( $answer ),
			'download_link' => $this->package( $answer ),
			'requires'      => $this->text( $answer, 'requires', $this->updater->header( 'RequiresWP' ) ),
			'tested'        => $this->text( $answer, 'tested', '' ),
			'requires_php'  => $this->text( $answer, 'requires_php', $this->updater->header( 'RequiresPHP' ) ),
			'last_updated'  => $this->text( $answer, 'last_updated', '' ),
			'banners'       => $this->images( $answer['banners'] ?? [] ),
			'icons'         => $this->images( $answer['icons'] ?? [] ),
		];

		if ( '' !== $this->text( $answer, 'upgrade_notice', '' ) ) {
			$info->upgrade_notice = wp_kses_post( $this->text( $answer, 'upgrade_notice', '' ) );
		}

		/**
		 * Filter what the "View details" window shows.
		 *
		 * @param object  $info    The plugins_api() result.
		 * @param Updater $updater The plugin.
		 */
		return apply_filters( $this->updater->hook_name( 'info' ), $info, $this->updater );
	}

	/**
	 * Whether the store's version is newer than the one installed.
	 *
	 * The store's own word when it gave one -- it compares labels as well
	 * as numbers -- and the version numbers otherwise.
	 *
	 * @param array $answer The store's answer.
	 *
	 * @return bool
	 */
	public function newer( array $answer ): bool {
		if ( is_bool( $answer['update'] ?? null ) ) {
			return $answer['update'];
		}

		return version_compare( $this->text( $answer, 'version', '' ), $this->updater->version(), '>' );
	}

	/**
	 * What WordPress is told about the newest version, or null when there
	 * is nothing to tell.
	 *
	 * @param array $answer The store's answer.
	 *
	 * @return object|null
	 */
	public function update( array $answer ): ?object {
		$version = $this->text( $answer, 'version', '' );

		if ( '' === $version ) {
			return null;
		}

		$update = new stdClass();

		$update->id            = '' !== $this->updater->update_host() ? $this->updater->header( 'UpdateURI' ) : $this->updater->config( 'store' ) . '#' . $this->updater->slug();
		$update->slug          = $this->updater->slug();
		$update->plugin        = $this->updater->basename();
		$update->version       = $version;
		$update->new_version   = $version;
		$update->url           = esc_url_raw( $this->text( $answer, 'homepage', $this->homepage() ) );
		$update->package       = $this->package( $answer );
		$update->icons         = $this->images( $answer['icons'] ?? [] );
		$update->banners       = $this->images( $answer['banners'] ?? [] );
		$update->banners_rtl   = [];
		$update->translations  = [];
		$update->compatibility = new stdClass();

		foreach ( self::TEXT as $key ) {
			$update->{$key} = $this->text( $answer, $key, '' );
		}

		/**
		 * Filter what WordPress is told about a new version.
		 *
		 * Return false to offer nothing.
		 *
		 * @param object  $update  The update.
		 * @param Updater $updater The plugin.
		 */
		$update = apply_filters( $this->updater->hook_name( 'update' ), $update, $this->updater );

		return is_object( $update ) ? $update : null;
	}

	/**
	 * The store's answer, or null when there is none to use.
	 *
	 * @return array|null
	 */
	private function answer(): ?array {
		$answer = $this->updater->latest();

		return is_array( $answer ) ? $answer : null;
	}

	/**
	 * The download link: the store's signed one, or nothing.
	 *
	 * A licence that is good but has no file to give still hears there is
	 * a new version; WordPress says it cannot update it automatically.
	 *
	 * @param array $answer The store's answer.
	 *
	 * @return string
	 */
	private function package( array $answer ): string {
		return is_string( $answer['package'] ?? null ) ? esc_url_raw( $answer['package'] ) : '';
	}

	/**
	 * The tabs of the "View details" window.
	 *
	 * The description from the configuration or the plugin's header, and
	 * the store's changelog -- plain text, escaped and set in paragraphs.
	 * Any `sections` the store sends are HTML, and take the place of the
	 * ones here with the same name.
	 *
	 * @param array $answer The store's answer.
	 *
	 * @return array<string, string>
	 */
	private function sections( array $answer ): array {
		$sections    = [];
		$description = '' !== $this->updater->config( 'description' ) ? $this->updater->config( 'description' ) : $this->updater->header( 'Description' );

		if ( '' !== trim( $description ) ) {
			$sections['description'] = wpautop( wp_kses_post( $description ) );
		}

		foreach ( (array) ( $answer['sections'] ?? [] ) as $name => $html ) {
			$name = sanitize_key( (string) $name );

			if ( '' !== $name && is_string( $html ) && '' !== trim( $html ) ) {
				$sections[ $name ] = wp_kses_post( $html );
			}
		}

		$changelog = $this->text( $answer, 'changelog', '' );

		if ( ! isset( $sections['changelog'] ) && '' !== trim( $changelog ) ) {
			$sections['changelog'] = wpautop( esc_html( $changelog ) );
		}

		return $sections;
	}

	/**
	 * Banners or icons, by size, as addresses.
	 *
	 * @param mixed $images As the store sent them.
	 *
	 * @return array<string, string>
	 */
	private function images( mixed $images ): array {
		$clean = [];

		foreach ( (array) $images as $size => $url ) {
			$url = is_string( $url ) ? esc_url_raw( $url ) : '';

			if ( '' !== $url ) {
				$clean[ sanitize_key( (string) $size ) ] = $url;
			}
		}

		return $clean;
	}

	/**
	 * The plugin's home page: its `Plugin URI` header, or the store.
	 *
	 * @return string
	 */
	private function homepage(): string {
		$uri = $this->updater->header( 'PluginURI' );

		return '' !== $uri ? $uri : (string) $this->updater->config( 'store' );
	}

	/**
	 * One text value from the store's answer.
	 *
	 * @param array  $answer   The answer.
	 * @param string $key      The key.
	 * @param string $fallback When it is missing or empty.
	 *
	 * @return string
	 */
	private function text( array $answer, string $key, string $fallback ): string {
		$value = $answer[ $key ] ?? null;

		return is_scalar( $value ) && '' !== trim( (string) $value ) ? trim( (string) $value ) : $fallback;
	}
}
