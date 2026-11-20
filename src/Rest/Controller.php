<?php
/**
 * The licence box's REST routes.
 *
 * @package   SugarCommerce\Updater
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Updater\Rest;

use SugarCommerce\Plugin\Build;
use SugarCommerce\Updater\Admin;
use SugarCommerce\Updater\Updater;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * What the licence box's script asks.
 *
 *     GET  /{namespace}/licences/{slug}             where it stands
 *     POST /{namespace}/licences/{slug}/activate    { key?, embedded? }
 *     POST /{namespace}/licences/{slug}/deactivate  { embedded? }
 *
 * Activate and Deactivate answer with what to say and the box's new
 * markup, so the script swaps one element and never has to know how a box
 * is drawn. Every route needs a signed-in user with the plugin's
 * capability (`activate_plugins` unless it set another) and a REST nonce,
 * which wp.apiFetch sends and renews.
 *
 * The key never comes back: the licence's last four characters at most.
 * Nor do the store's own words or its answer -- only the plugin's labels.
 *
 * The namespace is named for the build (SugarCommerce\Plugin\Build), so
 * two plugins bundling a copy each answer for their own plugins.
 */
final class Controller {

	/**
	 * This library's own name, used unprefixed.
	 */
	private const string LIBRARY = 'sugarcommerce-updater';

	/**
	 * How many segments this class's namespace has unprefixed --
	 * SugarCommerce\Updater\Rest. Counted rather than written out, because
	 * Strauss rewrites a namespace it finds in a string.
	 */
	private const int OWN_SEGMENTS = 3;

	/**
	 * A slug, as the routes match it.
	 */
	private const string SLUG = '(?P<slug>[a-z0-9_-]{1,100})';

	/**
	 * Whether the routes are hooked.
	 *
	 * @var bool
	 */
	private static bool $listening = false;

	/**
	 * Register the routes on rest_api_init, once.
	 *
	 * @return void
	 */
	public static function listen(): void {
		if ( self::$listening ) {
			return;
		}

		self::$listening = true;

		add_action( 'rest_api_init', [ self::class, 'register' ] );
	}

	/**
	 * Forget that the routes were hooked. For tests.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$listening = false;
	}

	/**
	 * This copy's name: "sugarcommerce-updater", or
	 * "{build}-sugarcommerce-updater" in a prefixed build.
	 *
	 * @param string $under The namespace to name it from; this class's own when left out.
	 *
	 * @return string
	 */
	public static function prefix( string $under = __NAMESPACE__ ): string {
		$build = Build::name( $under, self::OWN_SEGMENTS );

		return '' === $build ? self::LIBRARY : $build . '-' . self::LIBRARY;
	}

	/**
	 * The REST namespace: "{prefix}/v1".
	 *
	 * @return string
	 */
	public static function namespace(): string {
		return self::prefix() . '/v1';
	}

	/**
	 * The script's handle.
	 *
	 * @return string
	 */
	public static function handle(): string {
		return self::prefix() . '-licence';
	}

	/**
	 * A plugin's route, for apiFetch's `path`.
	 *
	 * @param Updater $updater The plugin.
	 *
	 * @return string
	 */
	public static function path( Updater $updater ): string {
		return '/' . self::namespace() . '/licences/' . rawurlencode( $updater->slug() );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function register(): void {
		$embedded = [
			'type'    => 'boolean',
			'default' => false,
		];

		register_rest_route(
			self::namespace(),
			'/licences/' . self::SLUG,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ self::class, 'read' ],
				'permission_callback' => [ self::class, 'permitted' ],
				'args'                => [ 'embedded' => $embedded ],
			]
		);

		register_rest_route(
			self::namespace(),
			'/licences/' . self::SLUG . '/activate',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'activate' ],
				'permission_callback' => [ self::class, 'permitted' ],
				'args'                => [
					'key'      => [
						'type'      => 'string',
						'default'   => '',
						'maxLength' => 200,
					],
					'embedded' => $embedded,
				],
			]
		);

		register_rest_route(
			self::namespace(),
			'/licences/' . self::SLUG . '/deactivate',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'deactivate' ],
				'permission_callback' => [ self::class, 'permitted' ],
				'args'                => [ 'embedded' => $embedded ],
			]
		);
	}

	/**
	 * Whether the request may touch the licence it names.
	 *
	 * Signed in first, so a stranger cannot learn which plugins are here.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return true|WP_Error
	 */
	public static function permitted( WP_REST_Request $request ): true|WP_Error {
		if ( ! is_user_logged_in() ) {
			return self::refusal( 'rest_forbidden', __( 'Sorry, you are not allowed to change this licence.', 'sugarcommerce' ), rest_authorization_required_code() );
		}

		$updater = Updater::get( (string) $request->get_param( 'slug' ) );

		if ( null === $updater ) {
			return self::refusal( 'rest_not_found', __( 'There is no such licence.', 'sugarcommerce' ), 404 );
		}

		if ( ! current_user_can( (string) $updater->config( 'capability' ) ) ) {
			return self::refusal( 'rest_forbidden', $updater->label( 'forbidden' ), rest_authorization_required_code() );
		}

		return true;
	}

	/**
	 * Where the licence stands.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public static function read( WP_REST_Request $request ): WP_REST_Response {
		return self::answer( $request, null );
	}

	/**
	 * Activate: the key typed, or the one kept when none was.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function activate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = self::updater( $request )->activate( (string) $request->get_param( 'key' ) );

		if ( is_wp_error( $result ) && 'too_many_attempts' === $result->get_error_code() ) {
			return self::refusal( 'too_many_attempts', $result->get_error_message(), 429 );
		}

		return self::answer( $request, [ 'activate', $result ] );
	}

	/**
	 * Deactivate.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public static function deactivate( WP_REST_Request $request ): WP_REST_Response {
		return self::answer( $request, [ 'deactivate', self::updater( $request )->deactivate() ] );
	}

	/**
	 * The answer: where the licence stands, the box, and what to say.
	 *
	 * @param WP_REST_Request                         $request The request.
	 * @param array{0: string, 1: true|WP_Error}|null $done    What was done, and what it came to.
	 *
	 * @return WP_REST_Response
	 */
	private static function answer( WP_REST_Request $request, ?array $done ): WP_REST_Response {
		$updater = self::updater( $request );
		$key     = $updater->key();
		$body    = [
			'licence' => $updater->state() + [ 'key' => '' !== $key ? substr( $key, -4 ) : '' ],
			'html'    => $updater->form( [ 'embedded' => (bool) $request->get_param( 'embedded' ) ] ),
		];

		if ( null !== $done ) {
			[ $do, $result ] = $done;

			$body = [
				'ok'   => ! is_wp_error( $result ),
				'code' => is_wp_error( $result ) ? (string) $result->get_error_code() : '',
			] + Admin::said( $updater, $do, $result ) + $body;
		}

		$response = new WP_REST_Response( $body, 200 );

		// About one site's licence, for one person: nothing to keep.
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * The plugin a request names. permitted() has checked there is one.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return Updater
	 */
	private static function updater( WP_REST_Request $request ): Updater {
		return Updater::get( (string) $request->get_param( 'slug' ) ) ?? throw new \LogicException( 'No such plugin.' );
	}

	/**
	 * A refusal, with only a status: never the store's answer.
	 *
	 * @param string $code    The code.
	 * @param string $message The message.
	 * @param int    $status  The HTTP status.
	 *
	 * @return WP_Error
	 */
	private static function refusal( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
