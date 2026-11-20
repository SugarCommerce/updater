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

use SugarCommerce\Updater\Models\Release;
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
 * What is offered is the store's answer as Store\Reader let it through: a
 * version that is a version, and a download link only when it is https on
 * the store's own host.
 *
 * Part of Updater; not meant to be used on its own.
 */
final class Updates {

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

		$release = $this->answer();
		$update  = null === $release ? null : $this->update( $release );

		if ( null === $update ) {
			return $transient;
		}

		$basename = $this->updater->basename();

		if ( $this->newer( $release ) ) {
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

		$release = $this->answer();
		$offer   = null === $release ? null : $this->update( $release );

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

		$release = $this->answer() ?? new Release();
		$author  = $this->updater->header( 'Author' );
		$link    = $this->updater->header( 'AuthorURI' );

		$info = (object) [
			'name'          => $this->either( $release->name, $this->updater->name() ),
			'slug'          => $this->updater->slug(),
			'version'       => $this->either( $release->version, $this->updater->version() ),
			'author'        => '' !== $link && '' !== $author ? sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $author ) ) : esc_html( $author ),
			'homepage'      => esc_url_raw( $this->either( $release->homepage, $this->homepage() ) ),
			'sections'      => $this->sections( $release ),
			'download_link' => $release->package,
			'requires'      => $this->either( $release->requires, $this->updater->header( 'RequiresWP' ) ),
			'tested'        => $release->tested,
			'requires_php'  => $this->either( $release->requires_php, $this->updater->header( 'RequiresPHP' ) ),
			'last_updated'  => $release->last_updated,
			'banners'       => $release->banners,
			'icons'         => $release->icons,
		];

		if ( '' !== $release->upgrade_notice ) {
			$info->upgrade_notice = wp_kses_post( $release->upgrade_notice );
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
	 * @param Release $release The newest version.
	 *
	 * @return bool
	 */
	public function newer( Release $release ): bool {
		if ( null !== $release->update ) {
			return $release->update;
		}

		return version_compare( $release->version, $this->updater->version(), '>' );
	}

	/**
	 * What WordPress is told about the newest version, or null when there
	 * is nothing to tell.
	 *
	 * @param Release $release The newest version.
	 *
	 * @return object|null
	 */
	public function update( Release $release ): ?object {
		if ( '' === $release->version ) {
			return null;
		}

		$update = new stdClass();

		$update->id             = '' !== $this->updater->update_host() ? $this->updater->header( 'UpdateURI' ) : $this->updater->config( 'store' ) . '#' . $this->updater->slug();
		$update->slug           = $this->updater->slug();
		$update->plugin         = $this->updater->basename();
		$update->version        = $release->version;
		$update->new_version    = $release->version;
		$update->url            = esc_url_raw( $this->either( $release->homepage, $this->homepage() ) );
		$update->package        = $release->package;
		$update->icons          = $release->icons;
		$update->banners        = $release->banners;
		$update->banners_rtl    = [];
		$update->translations   = [];
		$update->compatibility  = new stdClass();
		$update->requires       = $release->requires;
		$update->tested         = $release->tested;
		$update->requires_php   = $release->requires_php;
		$update->last_updated   = $release->last_updated;
		$update->upgrade_notice = '' !== $release->upgrade_notice ? wp_kses_post( $release->upgrade_notice ) : '';

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
	 * The newest version, or null when there is none to use.
	 *
	 * @return Release|null
	 */
	private function answer(): ?Release {
		$answer = $this->updater->release();

		return is_wp_error( $answer ) ? null : $answer->release;
	}

	/**
	 * The tabs of the "View details" window.
	 *
	 * The description from the configuration or the plugin's header, and
	 * the store's changelog -- plain text, escaped and set in paragraphs.
	 * Any `sections` the store sends are HTML, and take the place of the
	 * ones here with the same name.
	 *
	 * @param Release $release The newest version.
	 *
	 * @return array<string, string>
	 */
	private function sections( Release $release ): array {
		$sections    = [];
		$description = '' !== $this->updater->config( 'description' ) ? $this->updater->config( 'description' ) : $this->updater->header( 'Description' );

		if ( '' !== trim( $description ) ) {
			$sections['description'] = wpautop( wp_kses_post( $description ) );
		}

		foreach ( $release->sections as $name => $html ) {
			$sections[ $name ] = wp_kses_post( $html );
		}

		if ( ! isset( $sections['changelog'] ) && '' !== $release->changelog ) {
			$sections['changelog'] = wpautop( esc_html( $release->changelog ) );
		}

		return $sections;
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
	 * A value from the store, or what to use without one.
	 *
	 * @param string $value    The store's.
	 * @param string $fallback When it is empty.
	 *
	 * @return string
	 */
	private function either( string $value, string $fallback ): string {
		return '' !== $value ? $value : $fallback;
	}
}
