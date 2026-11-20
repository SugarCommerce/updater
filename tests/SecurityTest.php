<?php
/**
 * The updater, read as an attacker would.
 *
 * @package SugarCommerce\Updater
 */

declare( strict_types=1 );

namespace SugarCommerce\Updater\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SugarCommerce\Updater\Store\Guard;
use SugarCommerce\Updater\Updater;

/**
 * The key stays secret, the key goes only to a public https store, and
 * what the store sends back is checked before WordPress installs anything.
 */
final class SecurityTest extends TestCase {

	/**
	 * Whatever the licence's state, a key kept is never printed in full.
	 *
	 * @param string $status The status.
	 */
	#[DataProvider( 'kept_states' )]
	public function test_the_key_is_never_printed( string $status ): void {
		$updater = $this->register();

		$GLOBALS['up_options'][ $updater->id() ] = [
			'key'   => 'SECRET-KEY-1234-WXYZ',
			'state' => [
				'status' => $status,
				'reason' => 'inactive' === $status ? 'location_not_active' : '',
			],
		];

		$printed = $updater->form() . $updater->form( [ 'embedded' => true ] ) . $this->printed( static fn() => do_action( 'after_plugin_row_seller/seller.php' ) );

		$this->assertStringNotContainsString( 'SECRET-KEY', $printed );
		$this->assertStringContainsString( 'WXYZ', $printed );
	}

	/**
	 * Every state a key can be kept in.
	 *
	 * @return array<string, array{string}>
	 */
	public static function kept_states(): array {
		return array_combine( [ 'active', 'expired', 'revoked', 'invalid', 'inactive' ], array_map( static fn( $s ) => [ $s ], [ 'active', 'expired', 'revoked', 'invalid', 'inactive' ] ) );
	}

	/**
	 * An empty box with a key kept activates that key: it never has to be
	 * printed back into the box for "activate again" to be one click.
	 */
	public function test_an_empty_box_activates_the_key_kept(): void {
		$updater = $this->register();
		$sent    = [];

		$GLOBALS['up_options'][ $updater->id() ] = [
			'key'   => 'KEY-KEPT-9999',
			'state' => [ 'status' => 'inactive' ],
		];

		$this->store(
			[
				'activate' => static function ( $body ) use ( &$sent ) {
					$sent = $body;

					return self::answer( true, 'activated' );
				},
			]
		);

		$_POST = [
			'sugarcommerce_updater_seller_do'    => 'activate',
			'sugarcommerce_updater_seller_key'   => '',
			'sugarcommerce_updater_seller_nonce' => 'nonce-sugarcommerce_updater_seller_license',
		];

		$updater->handle();

		$this->assertSame( 'KEY-KEPT-9999', $sent['key'] );
		$this->assertSame( 'active', $updater->state()['status'] );
	}

	/**
	 * The key is only ever sent over https.
	 *
	 * @param string $store The store's address.
	 */
	#[DataProvider( 'unsafe_stores' )]
	public function test_the_store_must_be_an_https_address( string $store ): void {
		$this->expectException( InvalidArgumentException::class );

		$this->register( [ 'store' => $store ] );
	}

	/**
	 * Store addresses a key may not go to.
	 *
	 * @return array<string, array{string}>
	 */
	public static function unsafe_stores(): array {
		return [
			'plain http'     => [ 'http://store.example' ],
			'no scheme'      => [ 'store.example' ],
			'credentials'    => [ 'https://me:pw@store.example' ],
			'a query'        => [ 'https://store.example/?x=1' ],
			'an odd host'    => [ 'https://store%2eexample' ],
			'another scheme' => [ 'ftp://store.example' ],
		];
	}

	/**
	 * The same rule holds when each request is made, whatever a filter did
	 * to the address since.
	 */
	public function test_the_guard_refuses_what_is_not_a_store_address(): void {
		$this->assertNotNull( Guard::refuse( 'http://store.example/wp-json/sugarcart/v1/licenses/activate' ) );
		$this->assertNotNull( Guard::refuse( 'https://me@store.example/wp-json/sugarcart/v1/licenses/activate' ) );
		$this->assertNull( Guard::refuse( 'https://store.example/wp-json/sugarcart/v1/licenses/activate' ) );
	}

	/**
	 * The store's REST namespace goes into the address: it cannot climb out.
	 */
	public function test_the_namespace_must_be_one(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->register( [ 'namespace' => '../../wp-admin/admin-ajax.php?action=x' ] );
	}

	/**
	 * A store whose host resolves inside the network is never asked, so the
	 * key never goes there.
	 *
	 * @param string[] $addresses What the host resolves to.
	 */
	#[DataProvider( 'private_addresses' )]
	public function test_a_store_inside_the_network_is_not_asked( array $addresses ): void {
		$GLOBALS['up_dns']['store.example'] = $addresses;

		$this->store( [ 'activate' => self::answer( true, 'activated' ) ] );

		$result = $this->register()->activate( 'KEY-1234' );

		$this->assertSame( 'unreachable', $result->get_error_code() );
		$this->assertSame( [], $this->asked() );
	}

	/**
	 * Addresses inside the network, or spelled to look as if they are not.
	 *
	 * @return array<string, array{string[]}>
	 */
	public static function private_addresses(): array {
		return [
			'cloud metadata'      => [ [ '169.254.169.254' ] ],
			'loopback'            => [ [ '127.0.0.1' ] ],
			'private'             => [ [ '10.0.0.5' ] ],
			'one private of two'  => [ [ '93.184.216.34', '192.168.1.1' ] ],
			'IPv6 loopback'       => [ [ '::1' ] ],
			'NAT64 of loopback'   => [ [ '64:ff9b::7f00:1' ] ],
			'carrier-grade NAT'   => [ [ '100.64.0.1' ] ],
			'does not resolve'    => [ [] ],
		];
	}

	/**
	 * A store on the site's own host -- a test site selling to itself --
	 * is asked, as core's own check allows.
	 */
	public function test_a_store_on_the_sites_own_host_is_asked(): void {
		$this->store( [ 'activate' => self::answer( true, 'activated' ) ] );

		$this->assertTrue( $this->register( [ 'store' => 'https://site.test' ] )->activate( 'KEY-1234' ) );
		$this->assertSame( [ 'activate' ], $this->asked() );
	}

	/**
	 * The request follows no redirect, uses core's safe transport, and
	 * reads a capped answer.
	 */
	public function test_the_request_is_held_to_the_store(): void {
		$this->store( [ 'activate' => self::answer( true, 'activated' ) ] );

		$this->register()->activate( 'KEY-1234' );

		$args = $GLOBALS['up_requests'][0]['args'];

		$this->assertSame( 0, $args['redirection'] );
		$this->assertTrue( $args['reject_unsafe_urls'] );
		$this->assertSame( 1048576, $args['limit_response_size'] );
	}

	/**
	 * Activations are limited, so the form cannot be used to guess keys.
	 */
	public function test_activations_are_limited(): void {
		$updater = $this->register();

		$this->store( [ 'activate' => self::answer( false, 'not_found', null ) ] );

		for ( $i = 0; $i < Updater::ATTEMPTS; $i++ ) {
			$this->assertSame( 'not_found', $updater->activate( 'GUESS-' . $i )->get_error_code() );
		}

		$this->assertSame( 'too_many_attempts', $updater->activate( 'GUESS-X' )->get_error_code() );
		$this->assertCount( Updater::ATTEMPTS, $this->asked() );

		// Each plugin counts its own.
		$this->assertNotSame( 'too_many_attempts', $this->register( [ 'file' => WP_PLUGIN_DIR . '/seller-uri/seller-uri.php' ] )->activate( 'KEY' )->get_error_code() );
	}

	/**
	 * The window passes, and activating works again.
	 */
	public function test_the_limit_lifts(): void {
		$updater = $this->register();

		$GLOBALS['up_transients'][ $updater->id() . '_attempts' ] = [
			[
				'count' => Updater::ATTEMPTS,
				'until' => time() - 1,
			],
			0,
		];

		$this->store( [ 'activate' => self::answer( true, 'activated' ) ] );

		$this->assertTrue( $updater->activate( 'KEY' ) );
	}

	/**
	 * A download link anywhere but https on the store's host is never handed
	 * to WordPress to install.
	 *
	 * @param string $package The link the store sent.
	 */
	#[DataProvider( 'foreign_packages' )]
	public function test_a_download_from_elsewhere_is_not_offered( string $package ): void {
		$updater = $this->register();

		$this->activated( $updater );
		$this->store( [ 'latest' => self::latest( '1.2.0', [ 'package' => $package ] ) ] );

		$list = apply_filters( 'pre_set_site_transient_update_plugins', (object) [ 'response' => [] ] );

		$this->assertSame( '', $list->response['seller/seller.php']->package );
		$this->assertArrayNotHasKey( 'package', $updater->latest() );
	}

	/**
	 * Links a store taken over might send.
	 *
	 * @return array<string, array{string}>
	 */
	public static function foreign_packages(): array {
		return [
			'another host'        => [ 'https://evil.example/seller.zip' ],
			'a lookalike host'    => [ 'https://store.example.evil.example/seller.zip' ],
			'plain http'          => [ 'http://store.example/seller.zip' ],
			'credentials'         => [ 'https://store.example@evil.example/seller.zip' ],
			'another port'        => [ 'https://store.example:8443/seller.zip' ],
			'a local file'        => [ 'file:///etc/passwd' ],
			'protocol-relative'   => [ '//evil.example/seller.zip' ],
			'a backslash trick'   => [ 'https://evil.example\\@store.example/seller.zip' ],
			'a username'          => [ 'https://me:pw@store.example/seller.zip' ],
			'a quote'             => [ 'https://store.example/seller.zip"><script>' ],
			'a space'             => [ 'https://store.example/seller 2.zip' ],
		];
	}

	/**
	 * The store's own link is offered.
	 */
	public function test_a_download_from_the_store_is_offered(): void {
		$updater = $this->register();

		$this->activated( $updater );
		$this->store( [ 'latest' => self::latest( '1.2.0', [ 'package' => 'https://STORE.example/download/seller.zip?sig=abc' ] ) ] );

		$list = apply_filters( 'pre_set_site_transient_update_plugins', (object) [ 'response' => [] ] );

		$this->assertSame( 'https://STORE.example/download/seller.zip?sig=abc', $list->response['seller/seller.php']->package );
	}

	/**
	 * A version that is not one offers nothing.
	 *
	 * @param mixed $version What the store sent.
	 */
	#[DataProvider( 'bad_versions' )]
	public function test_a_version_that_is_not_one_is_not_offered( mixed $version ): void {
		$updater = $this->register();

		$this->activated( $updater );
		$this->store( [ 'latest' => self::latest( '1.2.0', [ 'version' => $version ] ) ] );

		$list = apply_filters( 'pre_set_site_transient_update_plugins', (object) [ 'response' => [] ] );

		$this->assertArrayNotHasKey( 'seller/seller.php', $list->response );
	}

	/**
	 * Versions a store taken over might send.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function bad_versions(): array {
		return [
			'markup'     => [ '1.2.0<script>alert(1)</script>' ],
			'a path'     => [ '../../1.2.0' ],
			'a space'    => [ '1.2.0 beta' ],
			'too long'   => [ str_repeat( '1', 65 ) ],
			'a list'     => [ [ '1.2.0' ] ],
			'a quote'    => [ '1.2.0"' ],
		];
	}

	/**
	 * Only the keys the library knows survive; anything else the store
	 * sends -- whatever it is -- is dropped before it is kept or used.
	 */
	public function test_unknown_keys_are_dropped(): void {
		$updater = $this->register();

		$this->activated( $updater );
		$this->store(
			[
				'latest' => self::latest(
					'1.2.0',
					[
						'evil'     => 'O:8:"stdClass":0:{}',
						'name'     => '<img src=x onerror=alert(1)>Seller',
						'homepage' => 'javascript:alert(1)',
						'icons'    => [ '1x' => 'javascript:alert(1)' ],
					]
				),
			]
		);

		$latest = $updater->latest();

		$this->assertArrayNotHasKey( 'evil', $latest );
		$this->assertArrayNotHasKey( 'evil', $GLOBALS['up_options'][ $updater->id() ]['latest']['answer'] );
		$this->assertSame( 'Seller', $latest['name'] );
		$this->assertArrayNotHasKey( 'homepage', $latest );
		$this->assertArrayNotHasKey( 'icons', $latest );
	}

	/**
	 * A reason that is not a word is no answer: it never becomes an error
	 * code or a label's name.
	 */
	public function test_a_reason_that_is_not_a_word_is_no_answer(): void {
		$this->store( [ 'activate' => self::answer( false, '<b>error_unknown</b>' ) ] );

		$this->assertSame( 'unreachable', $this->register()->activate( 'KEY' )->get_error_code() );
	}

	/**
	 * Nothing the library ships unserializes anything.
	 */
	public function test_nothing_is_unserialized(): void {
		$src = dirname( __DIR__ ) . '/src';

		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, RecursiveDirectoryIterator::SKIP_DOTS ) ) as $file ) {
			$this->assertDoesNotMatchRegularExpression( '/\b(maybe_)?unserialize\s*\(/', (string) file_get_contents( $file->getPathname() ), $file->getFilename() );
		}
	}
}
