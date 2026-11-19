<?php
/**
 * Licence tests.
 *
 * @package SugarCommerce\Updater
 */

declare( strict_types=1 );

namespace SugarCommerce\Updater\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use SugarCommerce\Updater\Updater;
use WP_Error;

/**
 * The key, what the store says about it, and what is kept.
 */
final class LicenceTest extends TestCase {

	/**
	 * A plugin that forgot a required key finds out on its author's screen.
	 *
	 * @param string $missing The key left out.
	 */
	#[DataProvider( 'required' )]
	public function test_a_required_key_is_required( string $missing ): void {
		$config = [
			'file'    => self::FILE,
			'version' => '1.0.0',
			'store'   => 'https://store.example',
			'product' => 'seller-pro',
		];

		unset( $config[ $missing ] );

		$this->expectException( InvalidArgumentException::class );

		Updater::register( $config );
	}

	/**
	 * The four keys nothing works without.
	 *
	 * @return array<string, array{string}>
	 */
	public static function required(): array {
		return [
			'file'    => [ 'file' ],
			'version' => [ 'version' ],
			'store'   => [ 'store' ],
			'product' => [ 'product' ],
		];
	}

	/**
	 * Everything else has a default, read from the plugin where it can be.
	 */
	public function test_the_defaults_come_from_the_plugin(): void {
		$updater = $this->register( [ 'store' => 'https://store.example/' ] );

		$this->assertSame( 'seller', $updater->slug() );
		$this->assertSame( 'seller/seller.php', $updater->basename() );
		$this->assertSame( 'Seller Pro', $updater->name() );
		$this->assertSame( 'https://store.example', $updater->config( 'store' ) );
		$this->assertSame( 'sugarcart/v1', $updater->config( 'namespace' ) );
		$this->assertSame( 'sugarcommerce_updater', $updater->config( 'prefix' ) );
		$this->assertSame( 'manage_options', $updater->config( 'capability' ) );
		$this->assertSame( 'https://store.example', $updater->config( 'renew_url' ) );
		$this->assertSame( 'https://site.test/wp-admin/plugins.php', $updater->settings_url() );
		$this->assertSame( Updater::CACHE, $updater->config( 'cache' ) );
		$this->assertSame( 'https://store.example/wp-json/sugarcart/v1/licenses/activate', $updater->endpoint( 'activate' ) );
	}

	/**
	 * The update answer is never kept long enough for its link to run out.
	 */
	public function test_the_cache_is_capped(): void {
		$this->assertSame( Updater::CACHE_LIMIT, $this->register( [ 'cache' => 7 * DAY_IN_SECONDS ] )->config( 'cache' ) );
	}

	/**
	 * Registered plugins are found by slug, and the first registration wins.
	 */
	public function test_the_registry(): void {
		$updater = $this->register();

		$this->assertSame( $updater, Updater::get( 'seller' ) );
		$this->assertSame( $updater, $this->register( [ 'version' => '9.9.9' ] ) );
		$this->assertSame( [ 'seller' => $updater ], Updater::all() );
		$this->assertNull( Updater::get( 'other' ) );
	}

	/**
	 * Without a key there is no licence, and nothing to ask the store.
	 */
	public function test_no_key_is_no_licence(): void {
		$updater = $this->register();

		$this->assertSame( '', $updater->key() );
		$this->assertSame( 'none', $updater->state()['status'] );
		$this->assertSame( 'none', $updater->check()['status'] );
		$this->assertSame( 'no_key', $updater->latest()->get_error_code() );
		$this->assertSame( 'no_key', $updater->deactivate()->get_error_code() );
		$this->assertSame( [], $this->asked() );
	}

	/**
	 * Activating sends what the store needs, and keeps what it said.
	 */
	public function test_activating_sends_the_site_and_keeps_the_answer(): void {
		$updater = $this->register();
		$sent    = [];

		$GLOBALS['up_environment'] = 'development';

		$this->store(
			[
				'activate' => static function ( array $body ) use ( &$sent ) {
					$sent = $body;

					return self::answer( true, 'activated', [ 'expires_at' => 1893456000, 'activations' => 2, 'max' => 5 ] );
				},
			]
		);

		$this->assertTrue( $updater->activate( '  KEY-1234-ABCD ' ) );

		$this->assertSame(
			[
				'key'         => 'KEY-1234-ABCD',
				'product'     => 'seller-pro',
				'location'    => 'https://site.test',
				'environment' => 'local',
				'version'     => '1.0.0',
			],
			$sent
		);

		$state = $updater->state();

		$this->assertSame( 'KEY-1234-ABCD', $updater->key() );
		$this->assertSame( 'active', $state['status'] );
		$this->assertSame( 'activated', $state['reason'] );
		$this->assertSame( 1893456000, $state['expires_at'] );
		$this->assertSame( 2, $state['activations'] );
		$this->assertSame( 5, $state['max'] );
		$this->assertIsInt( $state['checked_at'] );
	}

	/**
	 * Kept in one option, named from the prefix and slug, never autoloaded.
	 */
	public function test_it_is_kept_in_one_option_that_is_not_autoloaded(): void {
		$updater = $this->register( [ 'prefix' => 'myshop' ] );

		$this->activated( $updater );

		$this->assertSame( [ 'myshop_seller' ], array_keys( $GLOBALS['up_options'] ) );
		$this->assertFalse( $GLOBALS['up_autoload']['myshop_seller'] );
	}

	/**
	 * Each environment WordPress knows becomes one the store knows.
	 *
	 * @param string $type     wp_get_environment_type().
	 * @param string $expected What the store is told.
	 */
	#[DataProvider( 'environments' )]
	public function test_the_environment_is_mapped( string $type, string $expected ): void {
		$updater = $this->register();
		$sent    = '';

		$GLOBALS['up_environment'] = $type;

		$this->store(
			[
				'activate' => static function ( array $body ) use ( &$sent ) {
					$sent = $body['environment'];

					return self::answer( true, 'activated' );
				},
			]
		);

		$updater->activate( 'KEY' );

		$this->assertSame( $expected, $sent );
	}

	/**
	 * WordPress's four environments.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function environments(): array {
		return [
			'production'  => [ 'production', 'production' ],
			'staging'     => [ 'staging', 'staging' ],
			'development' => [ 'development', 'local' ],
			'local'       => [ 'local', 'local' ],
		];
	}

	/**
	 * Every answer about a key becomes a state and a sentence.
	 *
	 * @param string $reason The store's reason.
	 * @param string $status The state it is kept as.
	 */
	#[DataProvider( 'refusals' )]
	public function test_each_refusal_becomes_a_state_and_a_message( string $reason, string $status ): void {
		$updater = $this->register();

		$this->store( [ 'activate' => self::answer( false, $reason, 'not_found' === $reason ? null : [] ) ] );

		$result = $updater->activate( 'KEY-1234-ABCD' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $reason, $result->get_error_code() );
		$this->assertSame( $updater->label( 'error_' . $reason ), $result->get_error_message() );
		$this->assertStringNotContainsString( '_', $result->get_error_message(), 'A code reached a person.' );
		$this->assertSame( $status, $updater->state()['status'] );
		$this->assertSame( $reason, $updater->state()['reason'] );

		// Kept, so it can be corrected in the box.
		$this->assertSame( 'KEY-1234-ABCD', $updater->key() );
	}

	/**
	 * The answers that say something about the key.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function refusals(): array {
		return [
			'not_found'           => [ 'not_found', 'invalid' ],
			'revoked'             => [ 'revoked', 'revoked' ],
			'expired'             => [ 'expired', 'expired' ],
			'location_not_active' => [ 'location_not_active', 'inactive' ],
			'limit_reached'       => [ 'limit_reached', 'inactive' ],
			'not_active'          => [ 'not_active', 'inactive' ],
		];
	}

	/**
	 * Answers about the request, not the key, change nothing.
	 *
	 * @param mixed  $answer What the store sent.
	 * @param string $code   The error.
	 */
	#[DataProvider( 'interruptions' )]
	public function test_an_answer_about_the_request_changes_nothing( mixed $answer, string $code ): void {
		$updater = $this->register();

		$this->activated( $updater );
		$before = $updater->state();

		$this->store( [ 'activate' => $answer ] );

		$result = $updater->activate( 'OTHER-KEY' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $updater->label( 'error_' . $code ), $result->get_error_message() );
		$this->assertSame( 'KEY-1234-ABCD', $updater->key() );
		$this->assertSame( $before, $updater->state() );
	}

	/**
	 * Busy, refused, down, or not the store at all.
	 *
	 * @return array<string, array{mixed, string}>
	 */
	public static function interruptions(): array {
		return [
			'busy'              => [ self::answer( false, 'busy' ), 'busy' ],
			'too_many_requests' => [ [ 'status' => 429, 'body' => self::answer( false, 'too_many_requests', null ) ], 'too_many_requests' ],
			'key_required'      => [ [ 'status' => 422, 'body' => self::answer( false, 'key_required', null ) ], 'key_required' ],
			'product_required'  => [ [ 'status' => 422, 'body' => self::answer( false, 'product_required', null ) ], 'product_required' ],
			'location_required' => [ [ 'status' => 422, 'body' => self::answer( false, 'location_required', null ) ], 'location_required' ],
			'down'              => [ new WP_Error( 'http_request_failed', 'cURL error 28' ), 'unreachable' ],
			'not the api'       => [ [ 'status' => 404, 'body' => '<html>Not found</html>' ], 'unreachable' ],
			'server error'      => [ [ 'status' => 500, 'body' => '' ], 'unreachable' ],
		];
	}

	/**
	 * An empty key is not sent.
	 */
	public function test_an_empty_key_is_not_sent(): void {
		$this->assertSame( 'key_required', $this->register()->activate( '   ' )->get_error_code() );
		$this->assertSame( [], $this->asked() );
	}

	/**
	 * A reason nobody wrote a sentence for still reads as one.
	 */
	public function test_an_unknown_reason_has_a_message(): void {
		$updater = $this->register();

		$this->store( [ 'activate' => self::answer( false, 'something_new' ) ] );

		$result = $updater->activate( 'KEY' );

		$this->assertSame( 'something_new', $result->get_error_code() );
		$this->assertSame( $updater->label( 'error_unknown' ), $result->get_error_message() );
		$this->assertSame( 'none', $updater->state()['status'] );
	}

	/**
	 * Labels replace the default words, messages included.
	 */
	public function test_labels_replace_the_words(): void {
		$updater = $this->register( [ 'labels' => [ 'error_expired' => 'Out of date.' ] ] );

		$this->store( [ 'activate' => self::answer( false, 'expired' ) ] );

		$this->assertSame( 'Out of date.', $updater->activate( 'KEY' )->get_error_message() );
	}

	/**
	 * Activating forgets the update answer, here and in WordPress, and
	 * starts the weekly check.
	 */
	public function test_activating_clears_the_cache_and_schedules_the_check(): void {
		$updater = $this->register();

		$this->activated( $updater );
		$this->store( [ 'latest' => self::latest() ] );
		$updater->latest();

		$GLOBALS['up_site']['update_plugins'] = (object) [];

		$this->store( [ 'activate' => self::answer( true, 'activated' ) ] );
		$updater->activate( 'KEY-1234-ABCD' );

		$this->assertNull( $GLOBALS['up_options']['sugarcommerce_updater_seller']['latest'] );
		$this->assertArrayNotHasKey( 'update_plugins', $GLOBALS['up_site'] );
		$this->assertNotFalse( wp_next_scheduled( 'sugarcommerce_updater_check', [ 'seller' ] ) );
		$this->assertSame( 'weekly', $GLOBALS['up_cron'][ 'sugarcommerce_updater_check' . serialize( [ 'seller' ] ) ]['recurrence'] );
	}

	/**
	 * A refused activation is not scheduled.
	 */
	public function test_a_refused_activation_is_not_scheduled(): void {
		$updater = $this->register();

		$this->store( [ 'activate' => self::answer( false, 'expired' ) ] );
		$updater->activate( 'KEY' );

		$this->assertSame( [], $GLOBALS['up_cron'] );
	}

	/**
	 * Deactivating releases the seat, keeps the key, forgets the cache and
	 * stops the weekly check.
	 */
	public function test_deactivating(): void {
		$updater = $this->register();
		$sent    = [];

		$this->activated( $updater );
		$this->store( [ 'latest' => self::latest() ] );
		$updater->latest();

		$this->store(
			[
				'deactivate' => static function ( array $body ) use ( &$sent ) {
					$sent = $body;

					return self::answer( true, 'deactivated', [ 'activations' => 0 ] );
				},
			]
		);

		$GLOBALS['up_site']['update_plugins'] = (object) [];

		$this->assertTrue( $updater->deactivate() );

		$this->assertSame(
			[
				'key'      => 'KEY-1234-ABCD',
				'product'  => 'seller-pro',
				'location' => 'https://site.test',
			],
			$sent
		);
		$this->assertSame( 'KEY-1234-ABCD', $updater->key() );
		$this->assertSame( 'inactive', $updater->state()['status'] );
		$this->assertSame( 'deactivated', $updater->state()['reason'] );
		$this->assertNull( $GLOBALS['up_options']['sugarcommerce_updater_seller']['latest'] );
		$this->assertArrayNotHasKey( 'update_plugins', $GLOBALS['up_site'] );
		$this->assertFalse( wp_next_scheduled( 'sugarcommerce_updater_check', [ 'seller' ] ) );
	}

	/**
	 * A seat already gone is released: that is what was asked for.
	 */
	public function test_deactivating_a_seat_already_gone_succeeds(): void {
		$updater = $this->register();

		$this->activated( $updater );
		$this->store( [ 'deactivate' => self::answer( false, 'location_not_active' ) ] );

		$this->assertTrue( $updater->deactivate() );
		$this->assertSame( 'inactive', $updater->state()['status'] );
	}

	/**
	 * A store that cannot be reached leaves the licence active.
	 */
	public function test_deactivating_against_a_store_that_is_down_changes_nothing(): void {
		$updater = $this->register();

		$this->activated( $updater );

		$this->assertSame( 'unreachable', $updater->deactivate()->get_error_code() );
		$this->assertSame( 'active', $updater->state()['status'] );
	}

	/**
	 * The weekly check finds out what changed, and says so once.
	 */
	public function test_checking_keeps_what_the_store_says_and_fires_on_a_change(): void {
		$updater = $this->register();
		$changes = [];

		$this->activated( $updater );

		add_action(
			'sugarcommerce_updater_state_changed',
			static function ( $state, $previous, $who ) use ( &$changes, $updater ) {
				$changes[] = [ $previous['status'], $state['status'], $who === $updater ];
			},
			10,
			3
		);

		$this->store( [ 'validate' => self::answer( false, 'location_not_active' ) ] );

		$this->assertSame( 'inactive', $updater->check()['status'] );
		$this->assertSame( 'location_not_active', $updater->state()['reason'] );
		$this->assertSame( [ 'validate' ], $this->asked() );

		$updater->check();

		$this->assertSame( [ [ 'active', 'inactive', true ] ], $changes );
	}

	/**
	 * A check against a store that is down keeps what was known.
	 */
	public function test_checking_against_a_store_that_is_down_changes_nothing(): void {
		$updater = $this->register();

		$this->activated( $updater );

		$this->assertSame( 'active', $updater->check()['status'] );
	}

	/**
	 * The update answer is kept, and asked for again when it has aged or
	 * when told to.
	 */
	public function test_latest_is_cached(): void {
		$updater = $this->register();

		$this->activated( $updater );
		$this->store( [ 'latest' => self::latest() ] );

		$this->assertSame( '1.2.0', $updater->latest()['version'] );
		$this->assertSame( '1.2.0', $updater->latest()['version'] );
		$this->assertSame( [ 'latest' ], $this->asked() );

		$updater->latest( true );
		$this->assertSame( [ 'latest', 'latest' ], $this->asked() );

		$GLOBALS['up_options']['sugarcommerce_updater_seller']['latest']['at'] = time() - Updater::CACHE - 1;

		$updater->latest();
		$this->assertSame( [ 'latest', 'latest', 'latest' ], $this->asked() );
	}

	/**
	 * `latest` sends the version installed and this site.
	 */
	public function test_latest_sends_the_version_installed(): void {
		$updater = $this->register();
		$sent    = [];

		$this->activated( $updater );
		$this->store(
			[
				'latest' => static function ( array $body ) use ( &$sent ) {
					$sent = $body;

					return self::latest();
				},
			]
		);

		$updater->latest();

		$this->assertSame(
			[
				'key'      => 'KEY-1234-ABCD',
				'product'  => 'seller-pro',
				'current'  => '1.0.0',
				'location' => 'https://site.test',
			],
			$sent
		);
	}

	/**
	 * The Updates screen's Check Again asks the store once, not once for
	 * every time WordPress saves the list.
	 */
	public function test_the_updates_screen_force_check_skips_the_cache_once(): void {
		$updater = $this->register();

		$this->activated( $updater );
		$this->store( [ 'latest' => self::latest() ] );
		$updater->latest();

		// The next request: the Updates screen, Check Again.
		Updater::reset();
		$updater = $this->register();

		$GLOBALS['pagenow']   = 'update-core.php';
		$_GET['force-check'] = '1';

		$updater->latest();
		$updater->latest();

		$this->assertSame( [ 'latest', 'latest' ], $this->asked() );
	}

	/**
	 * A store that is down is left alone for an hour.
	 */
	public function test_a_store_that_is_down_is_not_asked_again_for_an_hour(): void {
		$updater = $this->register();

		$this->activated( $updater );

		$this->assertSame( 'unreachable', $updater->latest()->get_error_code() );
		$this->assertSame( 'unreachable', $updater->latest()->get_error_code() );
		$this->assertSame( [ 'latest' ], $this->asked() );

		$GLOBALS['up_options']['sugarcommerce_updater_seller']['retry_at'] = time() - 1;

		$this->store( [ 'latest' => self::latest() ] );

		$this->assertSame( '1.2.0', $updater->latest()['version'] );
	}

	/**
	 * A licence the store will not update is the error saying so, and
	 * becomes the licence's state.
	 */
	public function test_a_refused_update_is_kept_as_the_state(): void {
		$updater = $this->register();

		$this->activated( $updater );
		$this->store( [ 'latest' => self::answer( false, 'expired', [ 'expires_at' => time() - DAY_IN_SECONDS ] ) ] );

		$this->assertSame( 'expired', $updater->latest()->get_error_code() );
		$this->assertSame( 'expired', $updater->state()['status'] );

		// Kept like any other answer: no second request.
		$this->assertSame( 'expired', $updater->latest()->get_error_code() );
		$this->assertSame( [ 'latest' ], $this->asked() );
	}

	/**
	 * The request and the answer pass through filters named from the prefix.
	 */
	public function test_the_request_and_response_filters(): void {
		$updater = $this->register( [ 'prefix' => 'myshop' ] );
		$seen    = [];

		add_filter(
			'myshop_request',
			static function ( $args, $action, $who ) use ( &$seen ) {
				$seen[]          = [ $action, $who->slug() ];
				$args['timeout'] = 3;

				return $args;
			},
			10,
			3
		);

		add_filter(
			'myshop_response',
			static fn( $answer, $action ) => 'activate' === $action ? self::answer( true, 'activated' ) : $answer,
			10,
			2
		);

		$this->assertTrue( $updater->activate( 'KEY' ) );
		$this->assertSame( [ [ 'activate', 'seller' ] ], $seen );
		$this->assertSame( 3, $GLOBALS['up_requests'][0]['args']['timeout'] );
	}

	/**
	 * The namespace is the client's to change.
	 */
	public function test_the_namespace_is_configurable(): void {
		$updater = $this->register( [ 'namespace' => '/shop/v2/' ] );

		$updater->activate( 'KEY' );

		$this->assertSame( 'https://store.example/wp-json/shop/v2/licenses/activate', $GLOBALS['up_requests'][0]['url'] );
	}

	/**
	 * The weekly check runs the check for the plugin it was scheduled for.
	 */
	public function test_the_scheduled_event_checks_its_plugin(): void {
		$updater = $this->register();

		$this->activated( $updater );
		$this->store( [ 'validate' => self::answer( false, 'revoked' ) ] );

		do_action( 'sugarcommerce_updater_check', 'seller' );

		$this->assertSame( 'revoked', $updater->state()['status'] );

		do_action( 'sugarcommerce_updater_check', 'someone-else' );
		$this->assertSame( [ 'validate' ], $this->asked() );
	}

	/**
	 * Deactivating the plugin stops its weekly check.
	 */
	public function test_deactivating_the_plugin_unschedules_the_check(): void {
		$updater = $this->register();

		$this->activated( $updater );
		$this->assertNotFalse( wp_next_scheduled( 'sugarcommerce_updater_check', [ 'seller' ] ) );

		foreach ( $GLOBALS['up_deactivate']['seller/seller.php'] as $callback ) {
			$callback();
		}

		$this->assertFalse( wp_next_scheduled( 'sugarcommerce_updater_check', [ 'seller' ] ) );
	}

	/**
	 * The check is put back on admin_init while the licence is active, and
	 * not while there is none.
	 */
	public function test_the_check_is_kept_scheduled_while_active(): void {
		$updater = $this->register();

		do_action( 'admin_init' );
		$this->assertSame( [], $GLOBALS['up_cron'] );

		$this->activated( $updater );
		$GLOBALS['up_cron'] = [];

		do_action( 'admin_init' );
		$this->assertNotFalse( wp_next_scheduled( 'sugarcommerce_updater_check', [ 'seller' ] ) );
	}
}
