<?php
/**
 * What the plugin is, and what everything is named.
 *
 * @package   SugarCommerce\Updater
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Updater\Traits\Updater;

use SugarCommerce\Updater\Labels;
use WP_Error;

/**
 * The plugin's names, headers and addresses, its words, and its errors.
 */
trait Plugin {

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
		$this->headers ??= array_map(
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
		return sprintf( '%s/wp-json/%s/licenses/%s', $this->config['store'], $this->config['namespace'], rawurlencode( $action ) );
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
		$this->labels ??= array_merge( Labels::defaults(), array_map( 'strval', (array) $this->config['labels'] ) );

		$label = $this->labels[ $key ] ?? $key;

		return [] === $values ? $label : vsprintf( $label, array_map( 'strval', $values ) );
	}

	/**
	 * The error for a reason, in plain English.
	 *
	 * The code is the reason as the store said it; the message is what a
	 * person is shown -- always one of the labels, never the store's own
	 * words. The store's answer, when there was one, is the error's data.
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
}
