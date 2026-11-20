<?php
/**
 * Asking the store, and nowhere else.
 *
 * @package   SugarCommerce\Updater
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Updater\Store;

use SugarCommerce\Network\IP;
use WP_Error;

/**
 * The licence key goes to the store's address, and the server has network
 * reach nobody else has: `169.254.169.254` (the cloud's instance
 * credentials), `10.x` (the database), `127.0.0.1`. A store address that
 * resolves there -- set by a filter, or a store whose DNS was taken over --
 * would make every licence check a request inside the network, carrying
 * the key. So before each request:
 *
 * - **https only**, and no username or password in the address.
 * - **A plain host**: letters, digits, dots, hyphens, or an IP literal --
 *   nothing two parsers read differently.
 * - **Every address the host resolves to must be public**
 *   ({@see IP::is_public()}, A and AAAA). The site's own host is the one
 *   exception, as core's wp_http_validate_url() makes it: a store selling
 *   to itself -- a test site -- is asking itself. So is a host
 *   `http_request_host_is_external` says is fine.
 * - **The connection is pinned** to the address that was checked, when curl
 *   is the transport, so a second DNS answer cannot move it.
 * - **No redirects**, which would be a second address nobody checked.
 *
 * The same rules as sugarcommerce/webhooks' SafeUrl, which belongs in
 * sugarcommerce/network; until it moves there, this keeps its own copy.
 */
final class Guard {

	/**
	 * How a host is resolved, for tests: fn( string $host ): string[].
	 *
	 * @var callable|null
	 */
	private static $resolver = null;

	/**
	 * Resolve hosts with this instead of DNS. For tests; null for DNS.
	 *
	 * @param callable|null $resolver fn( string $host ): string[].
	 *
	 * @return void
	 */
	public static function resolve_with( ?callable $resolver ): void {
		self::$resolver = $resolver;
	}

	/**
	 * Why an address may not be asked, or null when it may.
	 *
	 * @param string $url The address.
	 *
	 * @return string|null A reason, for a developer -- never shown to the
	 *                     person at the form, whom it would help to probe.
	 */
	public static function refuse( string $url ): ?string {
		return self::check( $url )[0];
	}

	/**
	 * Post to the store.
	 *
	 * @param string               $url  The address.
	 * @param array<string, mixed> $args As for wp_remote_post(); `redirection` is set to nought.
	 *
	 * @return array|WP_Error
	 */
	public static function post( string $url, array $args ): array|WP_Error {
		[ $reason, $ip ] = self::check( $url );

		if ( null !== $reason ) {
			return new WP_Error( 'http_request_not_executed', $reason );
		}

		$args['redirection'] = 0;

		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$port = (int) wp_parse_url( $url, PHP_URL_PORT );
		$port = $port > 0 ? $port : 443;

		$pin = static function ( $handle ) use ( $host, $port, $ip ): void {
			if ( defined( 'CURLOPT_RESOLVE' ) && $handle instanceof \CurlHandle ) {
				$address = str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip;

				curl_setopt( $handle, CURLOPT_RESOLVE, [ trim( $host, '[]' ) . ':' . $port . ':' . $address ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- the only way to pin the connection; this is WordPress's own curl handle.
			}
		};

		add_action( 'http_api_curl', $pin, 10, 1 );

		try {
			return wp_safe_remote_post( $url, $args );
		} finally {
			remove_action( 'http_api_curl', $pin, 10 );
		}
	}

	/**
	 * Whether an address is one a store may have: https, a plain host, no
	 * credentials. What register() asks of `store`; no DNS.
	 *
	 * @param string $url The address.
	 *
	 * @return bool
	 */
	public static function is_store_address( string $url ): bool {
		if ( 1 === preg_match( '/[\x00-\x20\x7F]/', $url ) ) {
			return false;
		}

		$parts = wp_parse_url( $url );

		return is_array( $parts )
			&& 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& ! isset( $parts['user'] ) && ! isset( $parts['pass'] )
			&& ! isset( $parts['query'] ) && ! isset( $parts['fragment'] )
			&& self::is_plain_host( (string) ( $parts['host'] ?? '' ) );
	}

	/**
	 * Every address a name resolves to, A and AAAA. An IP literal is itself.
	 *
	 * @param string $host A name or an IP literal.
	 *
	 * @return string[] Empty when it does not resolve.
	 */
	public static function resolve( string $host ): array {
		$host = trim( $host, '[]' );

		if ( null !== self::$resolver ) {
			return array_values( array_map( 'strval', (array) ( self::$resolver )( $host ) ) );
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return [ $host ];
		}

		$ips = [];
		$a   = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a name that does not resolve is an answer, not a warning.

		if ( is_array( $a ) ) {
			$ips = $a;
		}

		$aaaa = function_exists( 'dns_get_record' ) ? @dns_get_record( $host, DNS_AAAA ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.

		foreach ( is_array( $aaaa ) ? $aaaa : [] as $record ) {
			if ( is_string( $record['ipv6'] ?? null ) ) {
				$ips[] = $record['ipv6'];
			}
		}

		return array_values( array_unique( $ips ) );
	}

	/**
	 * Check an address: why not, and the address to connect to.
	 *
	 * @param string $url The address.
	 *
	 * @return array{0: string|null, 1: string}
	 */
	private static function check( string $url ): array {
		if ( ! self::is_store_address( $url ) ) {
			return [ 'The store address must be https, with a plain host and no username or password.', '' ];
		}

		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$ips  = array_values( array_filter( self::resolve( $host ) ) );

		if ( [] === $ips ) {
			return [ 'The store host does not resolve.', '' ];
		}

		$own = strtolower( trim( $host, '[]' ) ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		if ( ! $own && ! apply_filters( 'http_request_host_is_external', false, trim( $host, '[]' ), $url ) ) {
			foreach ( $ips as $ip ) {
				// Every answer must pass: one private address among public
				// ones is a rebinding attempt, not a misconfiguration.
				if ( ! IP::is_public( $ip ) ) {
					return [ 'The store host resolves to a private or reserved address.', '' ];
				}
			}
		}

		return [ null, $ips[0] ];
	}

	/**
	 * Whether a host is spelled the way a resolver and curl agree on.
	 *
	 * @param string $host As wp_parse_url() gave it.
	 *
	 * @return bool
	 */
	private static function is_plain_host( string $host ): bool {
		if ( str_starts_with( $host, '[' ) ) {
			return 1 === preg_match( '/^\[[0-9a-f:.]+\]$/iD', $host )
				&& false !== filter_var( substr( $host, 1, -1 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );
		}

		return 1 === preg_match( '/^[a-z0-9_.-]{1,253}$/iD', $host ) && ! str_contains( $host, '..' );
	}
}
