<?php
/**
 * What every updater test starts from.
 *
 * @package SugarCommerce\Updater
 */

declare( strict_types=1 );

namespace SugarCommerce\Updater\Tests;

use PHPUnit\Framework\TestCase as Base;
use SugarCommerce\Updater\Updater;
use WP_Error;

/**
 * A fresh site, a plugin to register, and a store each test plays.
 */
abstract class TestCase extends Base {

	/**
	 * The fixture plugin's main file.
	 */
	protected const FILE = WP_PLUGIN_DIR . '/seller/seller.php';

	/**
	 * Forget the last test's site.
	 */
	protected function setUp(): void {
		updater_test_reset();
	}

	/**
	 * Register the fixture plugin.
	 *
	 * @param array<string, mixed> $config Anything to change.
	 *
	 * @return Updater
	 */
	protected function register( array $config = [] ): Updater {
		return Updater::register(
			array_merge(
				[
					'file'    => self::FILE,
					'version' => '1.0.0',
					'store'    => 'https://store.example',
					'product'  => 'seller-pro',
					'resolver' => 'updater_test_resolve',
				],
				$config
			)
		);
	}

	/**
	 * Play the store.
	 *
	 * Each action answers with a body (an array, sent as JSON with a 200),
	 * `[ 'status' => …, 'body' => … ]` for another status or a raw body, a
	 * WP_Error for a store that cannot be reached, or a callable given the
	 * request body. An action not listed is not answered.
	 *
	 * @param array<string, mixed> $answers By action.
	 *
	 * @return void
	 */
	protected function store( array $answers ): void {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $answers ) {
				$action = basename( (string) $url );

				if ( ! array_key_exists( $action, $answers ) ) {
					return $pre;
				}

				$answer = $answers[ $action ];

				if ( is_callable( $answer ) ) {
					$answer = $answer( $args['body'] ?? [] );
				}

				if ( $answer instanceof WP_Error ) {
					return $answer;
				}

				$status = 200;

				if ( isset( $answer['status'] ) && array_key_exists( 'body', $answer ) ) {
					$status = (int) $answer['status'];
					$answer = $answer['body'];
				}

				return [
					'response' => [ 'code' => $status ],
					'body'     => is_string( $answer ) ? $answer : json_encode( $answer ),
				];
			},
			10,
			3
		);
	}

	/**
	 * The store's answer, as it shapes every one.
	 *
	 * @param bool                 $ok      Whether it did what was asked.
	 * @param string               $reason  The reason.
	 * @param array<string, mixed> $license The licence's fields, or null for none.
	 * @param array<string, mixed> $extra   More keys.
	 *
	 * @return array<string, mixed>
	 */
	protected static function answer( bool $ok, string $reason, ?array $license = [], array $extra = [] ): array {
		return array_merge(
			[
				'ok'      => $ok,
				'reason'  => $reason,
				'license' => null === $license ? null : array_merge(
					[
						'status'      => 'active',
						'expires_at'  => time() + 365 * DAY_IN_SECONDS,
						'activations' => 1,
						'max'         => 3,
						'product_id'  => 12,
						'test'        => false,
					],
					$license
				),
			],
			$extra
		);
	}

	/**
	 * A `latest` answer with a version on offer.
	 *
	 * @param string               $version The version.
	 * @param array<string, mixed> $extra   More keys.
	 *
	 * @return array<string, mixed>
	 */
	protected static function latest( string $version = '1.2.0', array $extra = [] ): array {
		return self::answer(
			true,
			'ok',
			[],
			array_merge(
				[
					'version'   => $version,
					'update'    => version_compare( $version, '1.0.0', '>' ),
					'changelog' => "= 1.2.0 =\nFaster <checkout>.",
					'files'     => [
						[
							'name'       => 'seller.zip',
							'url'        => 'https://store.example/download/seller.zip?sig=abc',
							'expires_at' => time() + DAY_IN_SECONDS,
						],
					],
					'package'   => 'https://store.example/download/seller.zip?sig=abc',
				],
				$extra
			)
		);
	}

	/**
	 * Give the fixture plugin an active key, as an activation would.
	 *
	 * @param Updater $updater The plugin.
	 * @param array   $license The licence's fields.
	 *
	 * @return void
	 */
	protected function activated( Updater $updater, array $license = [] ): void {
		$this->store( [ 'activate' => self::answer( true, 'activated', $license ) ] );
		$this->assertTrue( $updater->activate( 'KEY-1234-ABCD' ) );

		$GLOBALS['up_requests'] = [];
		$GLOBALS['up_hooks']['pre_http_request'] = [];
	}

	/**
	 * The actions the store was asked, in order.
	 *
	 * @return string[]
	 */
	protected function asked(): array {
		return array_map( static fn( array $request ): string => basename( $request['url'] ), $GLOBALS['up_requests'] );
	}

	/**
	 * Whatever a callable prints.
	 *
	 * @param callable $print The callable.
	 *
	 * @return string
	 */
	protected function printed( callable $print ): string {
		ob_start();

		try {
			$print();
		} finally {
			$output = (string) ob_get_clean();
		}

		return $output;
	}
}
