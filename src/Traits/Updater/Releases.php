<?php
/**
 * The newest version, from the store.
 *
 * @package   SugarCommerce\Updater
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Updater\Traits\Updater;

use SugarCommerce\Updater\Models\Answer;
use SugarCommerce\Updater\Updater;
use WP_Error;

/**
 * The store's `latest` answer: asked for, kept, forgotten.
 */
trait Releases {

	/**
	 * Whether a check forced from the Updates screen has already been made
	 * on this request.
	 *
	 * WordPress saves the update list more than once while it checks, and
	 * each save asks again; one fresh answer a request is enough.
	 *
	 * @var bool
	 */
	private bool $fresh = false;

	/**
	 * The newest version, from the store, kept for `cache` seconds.
	 *
	 * The store's answer, as the Reader let it through: `version`,
	 * `update`, `changelog`, `package` and the rest -- the download link
	 * only when it is https on the store's own host. A licence the store
	 * will not update -- run out, taken away, not active here -- is the
	 * error saying so, and is kept as the licence's state too. Deactivated
	 * here, the store is not asked.
	 *
	 * @param bool $force Ask the store even when an answer is kept.
	 *
	 * @return array|WP_Error
	 */
	public function latest( bool $force = false ): array|WP_Error {
		$answer = $this->release( $force );

		return $answer instanceof Answer ? $answer->to_array() : $answer;
	}

	/**
	 * The newest version, as the store's answer. What latest() and the
	 * update hooks read.
	 *
	 * @param bool $force Ask the store even when an answer is kept.
	 *
	 * @return Answer|WP_Error
	 */
	public function release( bool $force = false ): Answer|WP_Error {
		$key = $this->key();

		if ( '' === $key ) {
			return $this->error( 'no_key' );
		}

		// Deactivated here on purpose: there is no seat to update, and the
		// store would only say so -- which, kept, would read as the seat
		// having been taken away elsewhere.
		if ( 'deactivated' === $this->state()['reason'] ) {
			return $this->error( 'location_not_active' );
		}

		$stored = $this->stored();
		$force  = $force || ( ! $this->fresh && self::forced() );

		if ( ! $force ) {
			$kept   = $stored['latest'];
			$answer = is_array( $kept ) && (int) ( $kept['at'] ?? 0 ) + $this->config['cache'] > time()
				? $this->client->reader()->answer( $kept['answer'] ?? null )
				: null;

			if ( null !== $answer ) {
				return $this->outcome( $answer );
			}

			// The store was down a moment ago. Asking again on every page
			// load until it is back makes every page load slow, and the
			// store's recovery slower.
			if ( (int) $stored['retry_at'] > time() ) {
				return $this->error( 'unreachable' );
			}
		}

		$this->fresh = true;

		$answer = $this->client->send(
			'latest',
			[
				'key'      => $key,
				'product'  => $this->config['product'],
				'current'  => $this->config['version'],
				'location' => $this->location(),
			]
		);

		if ( is_wp_error( $answer ) || ! in_array( $answer->reason, Updater::ANSWERS, true ) ) {
			$stored['retry_at'] = time() + Updater::RETRY_AFTER;
			$this->save( $stored );

			return is_wp_error( $answer ) ? $answer : $this->error( $answer->reason, $answer->to_array() );
		}

		$this->record( $key, $answer );

		$stored             = $this->stored();
		$stored['latest']   = [
			'at'     => time(),
			'answer' => $answer->to_array(),
		];
		$stored['retry_at'] = 0;
		$this->save( $stored );

		return $this->outcome( $answer );
	}

	/**
	 * Forget the kept update answer, here and in WordPress's update list,
	 * so the next look at the Plugins screen asks again.
	 *
	 * @return void
	 */
	private function forget(): void {
		$stored             = $this->stored();
		$stored['latest']   = null;
		$stored['retry_at'] = 0;

		$this->save( $stored );

		delete_site_transient( 'update_plugins' );
	}

	/**
	 * The store's answer, or the error it amounts to.
	 *
	 * @param Answer $answer The answer.
	 *
	 * @return Answer|WP_Error
	 */
	private function outcome( Answer $answer ): Answer|WP_Error {
		return $answer->ok ? $answer : $this->error( $answer->reason, $answer->to_array() );
	}

	/**
	 * Whether this is the Updates screen's "Check again".
	 *
	 * @return bool
	 */
	private static function forced(): bool {
		global $pagenow;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only skips the cache; core checks the request.
		return is_admin() && 'update-core.php' === $pagenow && ! empty( $_GET['force-check'] );
	}
}
