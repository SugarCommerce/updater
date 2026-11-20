<?php
/**
 * The store's answer, checked before anything uses it.
 *
 * @package   SugarCommerce\Updater
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Updater\Store;

use SugarCommerce\Updater\Models\Answer;
use SugarCommerce\Updater\Models\Release;

/**
 * What the store sends is read as untrusted.
 *
 * The store is the plugin's own -- but its answer crosses the internet,
 * may come from a store that was taken over, and ends up in the update
 * list, where WordPress downloads and installs whatever `package` says.
 * So only the keys this library knows are kept, each to its own rule:
 *
 * - `reason` is a lower-case word; anything else is no answer at all.
 * - A version is a version: letters, digits, dots, `+`, `-`, `_`, at most
 *   64 characters -- never markup, never a path.
 * - The download link is https, on the store's own host, with no
 *   username or password in it. Any other link is dropped, so nothing is
 *   offered for one-click install from anywhere but the store.
 * - Links to show (home page, icons, banners) are http(s) only.
 * - Text is plain and capped; `sections` stay HTML and are kses'd where
 *   they are shown.
 */
final class Reader {

	/**
	 * What a `reason` may be.
	 */
	public const string REASON = '/^[a-z][a-z0-9_]{0,63}$/D';

	/**
	 * What a version may be.
	 */
	public const string VERSION = '/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}$/D';

	/**
	 * The longest text kept, in bytes. A changelog is long; not this long.
	 */
	public const int TEXT_LIMIT = 65536;

	/**
	 * The keys a `latest` answer adds. Any of them makes the answer carry a
	 * Release.
	 *
	 * @var string[]
	 */
	private const array RELEASE_KEYS = [ 'version', 'update', 'package', 'changelog', 'files', 'name', 'homepage', 'requires', 'tested', 'requires_php', 'last_updated', 'upgrade_notice', 'sections', 'icons', 'banners' ];

	/**
	 * Set up.
	 *
	 * @param string $store The store's address; its host is the only one a download may come from.
	 */
	public function __construct( private readonly string $store ) {
	}

	/**
	 * The answer, or null when it is not the store's shape.
	 *
	 * @param mixed $raw The decoded body, or what a filter returned.
	 *
	 * @return Answer|null
	 */
	public function answer( mixed $raw ): ?Answer {
		if ( ! is_array( $raw ) || ! is_string( $raw['reason'] ?? null ) || 1 !== preg_match( self::REASON, $raw['reason'] ) ) {
			return null;
		}

		$release = null;

		if ( [] !== array_intersect_key( $raw, array_flip( self::RELEASE_KEYS ) ) ) {
			$release = $this->release( $raw );
		}

		return new Answer(
			! empty( $raw['ok'] ),
			$raw['reason'],
			is_array( $raw['license'] ?? null ) ? $this->license( $raw['license'] ) : null,
			is_array( $raw['location'] ?? null ) ? $this->location( $raw['location'] ) : null,
			$release
		);
	}

	/**
	 * Whether a download link may be handed to WordPress: https, on the
	 * store's host, no credentials.
	 *
	 * @param mixed $url The link.
	 *
	 * @return string The link, or '' when it may not.
	 */
	public function package( mixed $url ): string {
		if ( ! is_string( $url ) || '' === $url || strlen( $url ) > 2048 || 1 === preg_match( '/[\x00-\x20\x7F"\'<>\\\\]/', $url ) ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		$store = wp_parse_url( $this->store );

		if ( ! is_array( $parts ) || ! is_array( $store ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}

		$same_host = strtolower( (string) ( $parts['host'] ?? '' ) ) === strtolower( (string) ( $store['host'] ?? '' ) );
		$same_port = (int) ( $parts['port'] ?? 443 ) === (int) ( $store['port'] ?? 443 );

		if ( 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || '' === (string) ( $parts['host'] ?? '' ) || ! $same_host || ! $same_port ) {
			return '';
		}

		return esc_url_raw( $url );
	}

	/**
	 * A version, or '' when it is not one.
	 *
	 * @param mixed $value As sent.
	 *
	 * @return string
	 */
	public static function version( mixed $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		return 1 === preg_match( self::VERSION, $value ) ? $value : '';
	}

	/**
	 * Plain text, capped, or '' when it is not text.
	 *
	 * @param mixed $value As sent.
	 *
	 * @return string
	 */
	public static function text( mixed $value ): string {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return '';
		}

		$value = (string) $value;

		if ( strlen( $value ) > self::TEXT_LIMIT ) {
			$value = substr( $value, 0, self::TEXT_LIMIT );
		}

		// Invalid UTF-8 is nothing to print.
		return 1 === preg_match( '//u', $value ) ? trim( $value ) : '';
	}

	/**
	 * One line of plain text: no markup, no line breaks.
	 *
	 * @param mixed $value As sent.
	 *
	 * @return string
	 */
	public static function line( mixed $value ): string {
		return sanitize_text_field( self::text( $value ) );
	}

	/**
	 * An http(s) address to show, or ''.
	 *
	 * @param mixed $value As sent.
	 *
	 * @return string
	 */
	public static function link( mixed $value ): string {
		return is_string( $value ) && strlen( $value ) <= 2048 ? esc_url_raw( trim( $value ), [ 'http', 'https' ] ) : '';
	}

	/**
	 * The newest version, from a `latest` answer.
	 *
	 * @param array<string, mixed> $raw The answer.
	 *
	 * @return Release
	 */
	private function release( array $raw ): Release {
		$sections = [];

		foreach ( is_array( $raw['sections'] ?? null ) ? $raw['sections'] : [] as $name => $html ) {
			$name = sanitize_key( (string) $name );
			$html = self::text( $html );

			if ( '' !== $name && '' !== $html ) {
				$sections[ $name ] = $html;
			}
		}

		$files = [];

		foreach ( is_array( $raw['files'] ?? null ) ? $raw['files'] : [] as $file ) {
			$url = is_array( $file ) ? $this->package( $file['url'] ?? null ) : '';

			if ( '' !== $url ) {
				$files[] = [
					'name'       => sanitize_file_name( self::line( $file['name'] ?? '' ) ),
					'url'        => $url,
					'expires_at' => is_numeric( $file['expires_at'] ?? null ) ? (int) $file['expires_at'] : null,
				];
			}
		}

		return new Release(
			version: self::version( $raw['version'] ?? null ),
			update: is_bool( $raw['update'] ?? null ) ? $raw['update'] : null,
			package: $this->package( $raw['package'] ?? null ),
			changelog: self::text( $raw['changelog'] ?? '' ),
			name: self::line( $raw['name'] ?? '' ),
			homepage: self::link( $raw['homepage'] ?? '' ),
			requires: self::version( $raw['requires'] ?? '' ),
			tested: self::version( $raw['tested'] ?? '' ),
			requires_php: self::version( $raw['requires_php'] ?? '' ),
			last_updated: self::line( $raw['last_updated'] ?? '' ),
			upgrade_notice: self::text( $raw['upgrade_notice'] ?? '' ),
			sections: $sections,
			icons: $this->images( $raw['icons'] ?? [] ),
			banners: $this->images( $raw['banners'] ?? [] ),
			files: $files,
		);
	}

	/**
	 * The licence's fields, as numbers and words.
	 *
	 * @param array<string, mixed> $license As sent.
	 *
	 * @return array<string, mixed>
	 */
	private function license( array $license ): array {
		$int = static fn( mixed $value ): ?int => is_numeric( $value ) ? (int) $value : null;

		return [
			'status'      => sanitize_key( is_string( $license['status'] ?? null ) ? $license['status'] : '' ),
			'expires_at'  => $int( $license['expires_at'] ?? null ),
			'activations' => $int( $license['activations'] ?? null ),
			'max'         => $int( $license['max'] ?? null ),
			'product_id'  => $int( $license['product_id'] ?? null ),
			'test'        => ! empty( $license['test'] ),
		];
	}

	/**
	 * The location the store answered about.
	 *
	 * @param array<string, mixed> $location As sent.
	 *
	 * @return array{url: string, active: bool}
	 */
	private function location( array $location ): array {
		return [
			'url'    => self::link( $location['url'] ?? '' ),
			'active' => ! empty( $location['active'] ),
		];
	}

	/**
	 * Banners or icons, by size, as addresses.
	 *
	 * @param mixed $images As sent.
	 *
	 * @return array<string, string>
	 */
	private function images( mixed $images ): array {
		$clean = [];

		foreach ( is_array( $images ) ? $images : [] as $size => $url ) {
			$size = sanitize_key( (string) $size );
			$url  = self::link( $url );

			if ( '' !== $size && '' !== $url ) {
				$clean[ $size ] = $url;
			}
		}

		return $clean;
	}
}
