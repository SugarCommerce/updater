<?php
/**
 * Update tests.
 *
 * @package SugarCommerce\Updater
 */

declare( strict_types=1 );

namespace SugarCommerce\Updater\Tests;

use stdClass;

/**
 * What WordPress is told: a new version, its download, and its details.
 */
final class UpdatesTest extends TestCase {

	/**
	 * Run the update list through the filter, as WordPress saves it.
	 *
	 * @param object|null $transient The list. A fresh one by default.
	 *
	 * @return object
	 */
	private function saved( ?object $transient = null ): object {
		return apply_filters( 'pre_set_site_transient_update_plugins', $transient ?? new stdClass() );
	}

	/**
	 * A newer version is offered, with the store's signed download link.
	 */
	public function test_a_newer_version_is_offered(): void {
		$updater = $this->register();

		$this->activated( $updater );
		$this->store( [ 'latest' => self::latest( '1.2.0', [ 'tested' => '7.1', 'requires_php' => '8.3', 'icons' => [ '2x' => 'https://store.example/icon.png', 'bad' => 'javascript:alert(1)' ] ] ) ] );

		$transient = $this->saved();
		$update    = $transient->response['seller/seller.php'];

		$this->assertSame( '1.2.0', $update->new_version );
		$this->assertSame( 'seller', $update->slug );
		$this->assertSame( 'seller/seller.php', $update->plugin );
		$this->assertSame( 'https://store.example/download/seller.zip?sig=abc', $update->package );
		$this->assertSame( 'https://store.example/seller-pro', $update->url );
		$this->assertSame( '7.1', $update->tested );
		$this->assertSame( '8.3', $update->requires_php );
		$this->assertSame( [ '2x' => 'https://store.example/icon.png' ], $update->icons );
		$this->assertArrayNotHasKey( 'seller/seller.php', (array) ( $transient->no_update ?? [] ) );
	}

	/**
	 * The same version goes in `no_update`, so the auto-update toggle shows.
	 */
	public function test_the_same_version_is_no_update(): void {
		$updater = $this->register();

		$this->activated( $updater );
		$this->store( [ 'latest' => self::latest( '1.0.0' ) ] );

		$old            = new stdClass();
		$old->response  = [ 'seller/seller.php' => (object) [ 'new_version' => '0.9' ] ];
		$transient      = $this->saved( $old );

		$this->assertSame( '1.0.0', $transient->no_update['seller/seller.php']->new_version );
		$this->assertArrayNotHasKey( 'seller/seller.php', $transient->response );
	}

	/**
	 * The store's own word decides, when it gives one.
	 */
	public function test_the_stores_word_decides(): void {
		$updater = $this->register();

		$this->activated( $updater );
		$this->store( [ 'latest' => self::latest( '2.0.0', [ 'update' => false ] ) ] );

		$this->assertArrayNotHasKey( 'seller/seller.php', (array) ( $this->saved()->response ?? [] ) );
	}

	/**
	 * Without a word from the store, the version numbers decide.
	 */
	public function test_without_the_stores_word_the_versions_decide(): void {
		$updater = $this->register();

		$this->activated( $updater );
		$this->store( [ 'latest' => self::latest( '1.10.0', [ 'update' => null ] ) ] );

		$this->assertArrayHasKey( 'seller/seller.php', $this->saved()->response );
	}

	/**
	 * Without a key nothing is offered, and the store is not asked.
	 */
	public function test_nothing_without_a_key(): void {
		$this->register();
		$this->store( [ 'latest' => self::latest() ] );

		$transient = $this->saved();

		$this->assertSame( [], (array) $transient );
		$this->assertSame( [], $this->asked() );
	}

	/**
	 * A store that is down, or says no, offers nothing and breaks nothing.
	 *
	 * @param mixed $answer The store's answer.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'refusals' )]
	public function test_nothing_when_the_store_says_no( mixed $answer ): void {
		$updater = $this->register();

		$this->activated( $updater );

		if ( null !== $answer ) {
			$this->store( [ 'latest' => $answer ] );
		}

		$old           = new stdClass();
		$old->response = [ 'other/other.php' => (object) [] ];

		$this->assertEquals( $old, $this->saved( clone $old ) );
	}

	/**
	 * Down, expired, revoked, no seat, no version.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function refusals(): array {
		return [
			'down'       => [ null ],
			'expired'    => [ self::answer( false, 'expired' ) ],
			'revoked'    => [ self::answer( false, 'revoked' ) ],
			'no seat'    => [ self::answer( false, 'location_not_active' ) ],
			'no version' => [ self::latest( '' ) ],
			'garbage'    => [ [ 'status' => 200, 'body' => 'Fatal error' ] ],
		];
	}

	/**
	 * Not an object is left alone.
	 */
	public function test_a_list_that_is_not_an_object_is_left_alone(): void {
		$updater = $this->register();

		$this->activated( $updater );

		$this->assertFalse( apply_filters( 'pre_set_site_transient_update_plugins', false ) );
	}

	/**
	 * A good licence with no file still hears of the version, with no link.
	 */
	public function test_no_file_means_no_package(): void {
		$updater = $this->register();

		$this->activated( $updater );
		$this->store( [ 'latest' => self::latest( '1.2.0', [ 'package' => null, 'files' => [] ] ) ] );

		$this->assertSame( '', $this->saved()->response['seller/seller.php']->package );
	}

	/**
	 * The update filter can change the update, or offer nothing.
	 */
	public function test_the_update_filter(): void {
		$updater = $this->register();

		$this->activated( $updater );
		$this->store( [ 'latest' => self::latest() ] );

		add_filter( 'sugarcommerce_updater_update', static fn() => false );

		$this->assertSame( [], (array) $this->saved() );
	}

	/**
	 * An Update URI on the store's host is answered on update_plugins_{host},
	 * and the update list is left alone.
	 */
	public function test_an_update_uri_on_the_stores_host_uses_its_filter(): void {
		$updater = $this->register( [ 'file' => WP_PLUGIN_DIR . '/seller-uri/seller-uri.php' ] );

		$this->assertSame( 'store.example', $updater->update_host() );
		$this->assertTrue( has_filter( 'update_plugins_store.example' ) );
		$this->assertFalse( has_filter( 'pre_set_site_transient_update_plugins' ) );

		$this->activated( $updater );
		$this->store( [ 'latest' => self::latest() ] );

		$update = apply_filters( 'update_plugins_store.example', false, [ 'Version' => '1.0.0' ], 'seller-uri/seller-uri.php', [] );

		$this->assertSame( '1.2.0', $update->version );
		$this->assertSame( 'https://store.example/seller-uri', $update->id );
		$this->assertSame( 'https://store.example/download/seller.zip?sig=abc', $update->package );
	}

	/**
	 * Another plugin with an Update URI on the same host is not answered for.
	 */
	public function test_another_plugin_on_the_same_host_is_left_alone(): void {
		$updater = $this->register( [ 'file' => WP_PLUGIN_DIR . '/seller-uri/seller-uri.php' ] );

		$this->activated( $updater );
		$this->store( [ 'latest' => self::latest() ] );

		$this->assertFalse( apply_filters( 'update_plugins_store.example', false, [], 'other/other.php', [] ) );
		$this->assertSame( [], $this->asked() );
	}

	/**
	 * Without a key, the host's filter answers nothing either.
	 */
	public function test_the_hosts_filter_needs_a_key(): void {
		$this->register( [ 'file' => WP_PLUGIN_DIR . '/seller-uri/seller-uri.php' ] );

		$this->assertFalse( apply_filters( 'update_plugins_store.example', false, [], 'seller-uri/seller-uri.php', [] ) );
	}

	/**
	 * An Update URI on another host leaves updates to the update list.
	 */
	public function test_an_update_uri_elsewhere_uses_the_update_list(): void {
		$updater = $this->register( [ 'file' => WP_PLUGIN_DIR . '/seller-elsewhere/seller-elsewhere.php' ] );

		$this->assertSame( '', $updater->update_host() );
		$this->assertFalse( has_filter( 'update_plugins_updates.elsewhere.example' ) );
		$this->assertTrue( has_filter( 'pre_set_site_transient_update_plugins' ) );
	}

	/**
	 * The details window is filled for this plugin's slug only.
	 */
	public function test_the_details_window_is_only_for_this_slug(): void {
		$updater = $this->register();

		$this->activated( $updater );
		$this->store( [ 'latest' => self::latest() ] );

		$this->assertFalse( apply_filters( 'plugins_api', false, 'plugin_information', (object) [ 'slug' => 'akismet' ] ) );
		$this->assertFalse( apply_filters( 'plugins_api', false, 'query_plugins', (object) [ 'slug' => 'seller' ] ) );
		$this->assertSame( [], $this->asked() );

		$info = apply_filters( 'plugins_api', false, 'plugin_information', (object) [ 'slug' => 'seller' ] );

		$this->assertSame( 'Seller Pro', $info->name );
		$this->assertSame( 'seller', $info->slug );
		$this->assertSame( '1.2.0', $info->version );
		$this->assertSame( '<a href="https://example.com">Example Ltd</a>', $info->author );
		$this->assertSame( 'https://store.example/seller-pro', $info->homepage );
		$this->assertSame( 'https://store.example/download/seller.zip?sig=abc', $info->download_link );
		$this->assertSame( '7.1', $info->requires );
		$this->assertSame( '8.3', $info->requires_php );
	}

	/**
	 * The changelog is the store's plain text, escaped and in paragraphs;
	 * the description is the plugin's own.
	 */
	public function test_the_changelog_is_escaped(): void {
		$updater = $this->register();

		$this->activated( $updater );
		$this->store( [ 'latest' => self::latest() ] );

		$info = apply_filters( 'plugins_api', false, 'plugin_information', (object) [ 'slug' => 'seller' ] );

		$this->assertSame( "<p>= 1.2.0 =<br />\nFaster &lt;checkout&gt;.</p>\n", $info->sections['changelog'] );
		$this->assertStringContainsString( 'A paid plugin, sold from a store.', $info->sections['description'] );
	}

	/**
	 * The optional keys the store may send are passed through, and its
	 * sections are HTML.
	 */
	public function test_the_stores_details_are_passed_through(): void {
		$updater = $this->register( [ 'description' => 'From the configuration.' ] );

		$this->activated( $updater );
		$this->store(
			[
				'latest' => self::latest(
					'1.2.0',
					[
						'name'           => 'Seller Pro (Store)',
						'homepage'       => 'https://store.example/products/seller',
						'tested'         => '7.2',
						'last_updated'   => '2026-11-01',
						'banners'        => [ 'low' => 'https://store.example/b.png' ],
						'sections'       => [
							'installation' => '<p>Upload <strong>it</strong>.</p><script>x</script>',
							'changelog'    => '<h4>1.2.0</h4>',
						],
						'upgrade_notice' => 'Back up first.',
					]
				),
			]
		);

		$info = apply_filters( 'plugins_api', false, 'plugin_information', (object) [ 'slug' => 'seller' ] );

		$this->assertSame( 'Seller Pro (Store)', $info->name );
		$this->assertSame( 'https://store.example/products/seller', $info->homepage );
		$this->assertSame( '7.2', $info->tested );
		$this->assertSame( '2026-11-01', $info->last_updated );
		$this->assertSame( [ 'low' => 'https://store.example/b.png' ], $info->banners );
		$this->assertSame( '<p>Upload <strong>it</strong>.</p>x', $info->sections['installation'] );
		$this->assertSame( '<h4>1.2.0</h4>', $info->sections['changelog'] );
		$this->assertSame( "<p>From the configuration.</p>\n", $info->sections['description'] );
		$this->assertSame( [ 'description', 'installation', 'changelog' ], array_keys( $info->sections ) );
		$this->assertSame( 'Back up first.', $info->upgrade_notice );
	}

	/**
	 * With no answer from the store the window still describes the plugin.
	 */
	public function test_the_details_window_without_a_key(): void {
		$this->register();

		$info = apply_filters( 'plugins_api', false, 'plugin_information', (object) [ 'slug' => 'seller' ] );

		$this->assertSame( 'Seller Pro', $info->name );
		$this->assertSame( '1.0.0', $info->version );
		$this->assertSame( '', $info->download_link );
		$this->assertArrayNotHasKey( 'changelog', $info->sections );
	}

	/**
	 * The details filter, named from the prefix.
	 */
	public function test_the_info_filter(): void {
		$this->register( [ 'prefix' => 'myshop' ] );

		add_filter(
			'myshop_info',
			static function ( $info ) {
				$info->name = 'Renamed';

				return $info;
			}
		);

		$this->assertSame( 'Renamed', apply_filters( 'plugins_api', false, 'plugin_information', (object) [ 'slug' => 'seller' ] )->name );
	}
}
