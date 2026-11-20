<?php
/**
 * The licence box's REST routes.
 *
 * @package SugarCommerce\Updater
 */

declare( strict_types=1 );

namespace SugarCommerce\Updater\Tests;

use SugarCommerce\Updater\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * What the script asks, who may ask it, and what comes back.
 */
final class RestTest extends TestCase {

	/**
	 * The routes, as rest_api_init registers them.
	 *
	 * @return array<string, array>
	 */
	private function routes(): array {
		do_action( 'rest_api_init' );

		return $GLOBALS['up_routes'];
	}

	/**
	 * A request to one of the routes.
	 *
	 * @param array<string, mixed> $params Its parameters.
	 *
	 * @return WP_REST_Request
	 */
	private static function request( array $params = [] ): WP_REST_Request {
		return new WP_REST_Request( $params + [ 'slug' => 'seller' ] );
	}

	/**
	 * Three routes, named for the build, each needing permission, the key
	 * typed as a short string.
	 */
	public function test_the_routes(): void {
		$this->register();

		$routes = $this->routes();
		$base   = '/sugarcommerce-updater/v1/licences/(?P<slug>[a-z0-9_-]{1,100})';

		$this->assertSame( [ $base, $base . '/activate', $base . '/deactivate' ], array_keys( $routes ) );

		foreach ( $routes as $route ) {
			$this->assertSame( [ Controller::class, 'permitted' ], $route['permission_callback'] );
		}

		$this->assertSame( 'POST', $routes[ $base . '/activate' ]['methods'] );
		$this->assertSame( 'string', $routes[ $base . '/activate' ]['args']['key']['type'] );
		$this->assertSame( 200, $routes[ $base . '/activate' ]['args']['key']['maxLength'] );
		$this->assertSame( 'boolean', $routes[ $base . '/deactivate' ]['args']['embedded']['type'] );
	}

	/**
	 * A prefixed build answers on its own namespace.
	 */
	public function test_the_namespace_is_named_for_the_build(): void {
		$this->assertSame( 'sugarcommerce-updater', Controller::prefix() );
		$this->assertSame( 'myshop-sugarcommerce-updater', Controller::prefix( 'MyShop\\Vendor\\SugarCommerce\\Updater\\Rest' ) );
		$this->assertSame( 'arraypress-pro-sugarcommerce-updater', Controller::prefix( 'ArrayPress\\Pro\\Lib\\Updater\\Rest' ) );
	}

	/**
	 * Signed in, a plugin that is registered, and its capability.
	 */
	public function test_permission(): void {
		$this->register();

		$this->assertTrue( Controller::permitted( self::request() ) );

		$GLOBALS['up_logged_in'] = false;
		$this->assertSame( 401, Controller::permitted( self::request() )->get_error_data()['status'] );

		$GLOBALS['up_logged_in'] = true;
		$this->assertSame( 404, Controller::permitted( self::request( [ 'slug' => 'other' ] ) )->get_error_data()['status'] );

		$GLOBALS['up_can'] = [ 'manage_options' => true ];
		$this->assertSame( 403, Controller::permitted( self::request() )->get_error_data()['status'] );
	}

	/**
	 * The configured capability is the one asked for.
	 */
	public function test_the_configured_capability(): void {
		$this->register( [ 'capability' => 'update_plugins' ] );

		$this->assertInstanceOf( WP_Error::class, Controller::permitted( self::request() ) );

		$GLOBALS['up_can']['update_plugins'] = true;
		$this->assertTrue( Controller::permitted( self::request() ) );
	}

	/**
	 * Activating answers with what to say, the licence and the new box --
	 * and never the key beyond its last four.
	 */
	public function test_activating(): void {
		$this->register();
		$this->store( [ 'activate' => self::answer( true, 'activated' ) ] );

		$response = Controller::activate( self::request( [ 'key' => 'KEY-1234-ABCD' ] ) );
		$data     = $response->get_data();

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertTrue( $data['ok'] );
		$this->assertSame( 'success', $data['type'] );
		$this->assertSame( 'Licence activated. Updates will now arrive here.', $data['message'] );
		$this->assertSame( 'active', $data['licence']['status'] );
		$this->assertSame( 'ABCD', $data['licence']['key'] );
		$this->assertStringContainsString( 'data-status="active"', $data['html'] );
		$this->assertStringNotContainsString( 'KEY-1234', wp_json_encode_for_test( $data ) );
		$this->assertSame( 'no-store', $response->headers['Cache-Control'] );
	}

	/**
	 * A refusal is said in the plugin's words, with the box redrawn; the
	 * store's answer is not passed on.
	 */
	public function test_a_refusal(): void {
		$this->register();
		$this->store( [ 'activate' => self::answer( false, 'limit_reached', [], [ 'location' => [ 'url' => 'https://site.test', 'active' => false ] ] ) ] );

		$data = Controller::activate( self::request( [ 'key' => 'KEY-1234-ABCD' ] ) )->get_data();

		$this->assertFalse( $data['ok'] );
		$this->assertSame( 'limit_reached', $data['code'] );
		$this->assertSame( 'error', $data['type'] );
		$this->assertStringContainsString( 'as many sites as it allows', $data['message'] );
		$this->assertSame( [ 'ok', 'code', 'type', 'message', 'licence', 'html' ], array_keys( $data ) );
		$this->assertStringNotContainsString( 'KEY-1234', wp_json_encode_for_test( $data ) );
	}

	/**
	 * Too many tries is a 429.
	 */
	public function test_too_many_tries(): void {
		$updater = $this->register();

		$GLOBALS['up_transients'][ $updater->id() . '_attempts' ] = [
			[
				'count' => 10,
				'until' => time() + 60,
			],
			60,
		];

		$error = Controller::activate( self::request( [ 'key' => 'KEY' ] ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 429, $error->get_error_data()['status'] );
		$this->assertSame( [], $this->asked() );
	}

	/**
	 * Deactivating, and the box it leaves, embedded when it was.
	 */
	public function test_deactivating(): void {
		$updater = $this->register();

		$this->activated( $updater );
		$this->store( [ 'deactivate' => self::answer( true, 'deactivated' ) ] );

		$data = Controller::deactivate( self::request( [ 'embedded' => true ] ) )->get_data();

		$this->assertTrue( $data['ok'] );
		$this->assertSame( 'Licence deactivated on this site.', $data['message'] );
		$this->assertSame( 'inactive', $data['licence']['status'] );
		$this->assertStringContainsString( 'data-embedded="1"', $data['html'] );
		$this->assertStringNotContainsString( '<form', $data['html'] );
	}

	/**
	 * Reading where it stands asks the store nothing.
	 */
	public function test_reading(): void {
		$updater = $this->register();

		$this->activated( $updater );

		$data = Controller::read( self::request() )->get_data();

		$this->assertSame( [ 'licence', 'html' ], array_keys( $data ) );
		$this->assertSame( 'ABCD', $data['licence']['key'] );
		$this->assertSame( [], $this->asked() );
	}

	/**
	 * The box names its route and loads its script.
	 */
	public function test_the_box_names_its_route_and_loads_the_script(): void {
		$form = $this->register()->form();

		$this->assertStringContainsString( 'data-licence="/sugarcommerce-updater/v1/licences/seller"', $form );
		$this->assertStringContainsString( 'data-licence-action="activate"', $form );
		$this->assertTrue( $GLOBALS['up_scripts']['sugarcommerce-updater-licence']['enqueued'] );
		$this->assertContains( 'wp-api-fetch', $GLOBALS['up_scripts']['sugarcommerce-updater-licence']['deps'] );
	}
}

/**
 * JSON, as the REST server would send it.
 *
 * @param mixed $data The data.
 *
 * @return string
 */
function wp_json_encode_for_test( mixed $data ): string {
	return (string) json_encode( $data, JSON_UNESCAPED_UNICODE );
}
