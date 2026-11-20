<?php
/**
 * The key, and this site's seat.
 *
 * @package   SugarCommerce\Updater
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Updater\Traits\Updater;

use SugarCommerce\Updater\Models\Answer;
use SugarCommerce\Updater\Models\Licence as State;
use SugarCommerce\Updater\Models\Status;
use SugarCommerce\Updater\Updater;
use WP_Error;

/**
 * Activate, Deactivate, the weekly check, and what is kept about them.
 */
trait Licence {

	/**
	 * The licence key, as entered. Empty when there is none.
	 *
	 * Never printed: the form shows it masked, the REST route its last four.
	 *
	 * @return string
	 */
	public function key(): string {
		return (string) $this->stored()['key'];
	}

	/**
	 * What is known about the licence.
	 *
	 * What the store said the last time it was asked; nothing here asks
	 * it. check() does.
	 *
	 * @return array{status: string, reason: string, expires_at: int|null, activations: int|null, max: int|null, checked_at: int|null}
	 */
	public function state(): array {
		return $this->licence()->to_array();
	}

	/**
	 * Claim this site's seat with a key.
	 *
	 * Whatever the store says about the key is kept, so a key it does not
	 * know stays to be corrected and the notices say why. A store that
	 * cannot be reached, or asks to be asked again, changes nothing.
	 *
	 * @param string $key The key. Empty: the one kept here, so activating
	 *                    again after Deactivate is one click.
	 *
	 * @return true|WP_Error
	 */
	public function activate( #[\SensitiveParameter] string $key = '' ): true|WP_Error {
		$key = trim( sanitize_text_field( $key ) );
		$key = '' !== $key ? $key : $this->key();

		if ( '' === $key ) {
			return $this->error( 'key_required' );
		}

		if ( ! $this->attempt() ) {
			return $this->error( 'too_many_attempts' );
		}

		$answer = $this->client->send(
			'activate',
			[
				'key'         => $key,
				'product'     => $this->config['product'],
				'location'    => $this->location(),
				'environment' => self::environment(),
				'version'     => $this->config['version'],
			]
		);

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		if ( ! in_array( $answer->reason, Updater::ANSWERS, true ) ) {
			return $this->error( $answer->reason, $answer->to_array() );
		}

		$this->record( $key, $answer );
		$this->forget();

		if ( ! $answer->ok ) {
			return $this->error( $answer->reason, $answer->to_array() );
		}

		$this->schedule();

		return true;
	}

	/**
	 * Release this site's seat.
	 *
	 * The key is kept, so activating again is one click. A seat the store
	 * says this site does not hold, or a key it no longer knows, is
	 * already released, and that is what was asked for.
	 *
	 * @return true|WP_Error
	 */
	public function deactivate(): true|WP_Error {
		$key = $this->key();

		if ( '' === $key ) {
			return $this->error( 'no_key' );
		}

		$answer = $this->client->send(
			'deactivate',
			[
				'key'      => $key,
				'product'  => $this->config['product'],
				'location' => $this->location(),
			]
		);

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		if ( ! $answer->ok && ! in_array( $answer->reason, [ 'location_not_active', 'not_found' ], true ) ) {
			return $this->error( $answer->reason, $answer->to_array() );
		}

		$this->record( $key, new Answer( false, 'deactivated', $answer->license ) );

		$this->forget();
		$this->unschedule();

		return true;
	}

	/**
	 * Ask the store whether the key is still good, and keep the answer.
	 *
	 * What the weekly check runs. Finds out about a licence that ran out,
	 * was taken away, or had this site's seat released from the account
	 * page -- without waiting for an update to be refused.
	 *
	 * @return array The state afterwards. See state().
	 */
	public function check(): array {
		$key = $this->key();

		if ( '' === $key ) {
			return $this->state();
		}

		$answer = $this->client->send(
			'validate',
			[
				'key'      => $key,
				'product'  => $this->config['product'],
				'location' => $this->location(),
			]
		);

		if ( ! is_wp_error( $answer ) && in_array( $answer->reason, Updater::ANSWERS, true ) ) {
			$this->record( $key, $answer );
		}

		return $this->state();
	}

	/**
	 * The state, as its model.
	 *
	 * @return State
	 */
	private function licence(): State {
		$kept   = (array) $this->stored()['state'];
		$status = Status::tryFrom( (string) ( $kept['status'] ?? '' ) ) ?? Status::None;
		$int    = static fn( mixed $value ): ?int => is_numeric( $value ) ? (int) $value : null;

		// No key is no licence, whatever was said about the last one.
		if ( '' === $this->key() ) {
			$status = Status::None;
		}

		return new State(
			$status,
			(string) ( $kept['reason'] ?? '' ),
			$int( $kept['expires_at'] ?? null ),
			$int( $kept['activations'] ?? null ),
			$int( $kept['max'] ?? null ),
			$int( $kept['checked_at'] ?? null )
		);
	}

	/**
	 * Count one activation, unless there have been too many lately.
	 *
	 * @return bool False when this one is over the limit.
	 */
	private function attempt(): bool {
		$name  = $this->id() . '_attempts';
		$kept  = get_transient( $name );
		$now   = time();
		$count = is_array( $kept ) && (int) ( $kept['until'] ?? 0 ) > $now ? (int) ( $kept['count'] ?? 0 ) : 0;
		$until = 0 === $count ? $now + Updater::ATTEMPT_WINDOW : (int) $kept['until'];

		if ( $count >= Updater::ATTEMPTS ) {
			return false;
		}

		set_transient(
			$name,
			[
				'count' => $count + 1,
				'until' => $until,
			],
			max( 1, $until - $now )
		);

		return true;
	}

	/**
	 * Keep what the store said about a key.
	 *
	 * @param string $key    The key it was about.
	 * @param Answer $answer The store's answer.
	 *
	 * @return void
	 */
	private function record( #[\SensitiveParameter] string $key, Answer $answer ): void {
		$previous = $this->state();
		$stored   = $this->stored();
		$license  = $answer->license ?? [];

		$stored['key']   = $key;
		$stored['state'] = ( new State(
			Status::for_reason( $answer->reason ),
			$answer->reason,
			$license['expires_at'] ?? null,
			$license['activations'] ?? null,
			$license['max'] ?? null,
			time()
		) )->to_array();

		$this->save( $stored );

		$state = $this->state();

		if ( $state['status'] !== $previous['status'] || $state['reason'] !== $previous['reason'] ) {
			/**
			 * Fires when what is known about a licence changes.
			 *
			 * @param array   $state    The state now. See Updater::state().
			 * @param array   $previous The state before.
			 * @param Updater $updater  The plugin it is about.
			 */
			do_action( $this->hook_name( 'state_changed' ), $state, $previous, $this );
		}
	}

	/**
	 * Everything kept for this plugin.
	 *
	 * @return array{key: string, state: array, latest: array|null, retry_at: int}
	 */
	private function stored(): array {
		$stored = get_option( $this->id(), [] );

		return array_merge(
			[
				'key'      => '',
				'state'    => [],
				'latest'   => null,
				'retry_at' => 0,
			],
			is_array( $stored ) ? $stored : []
		);
	}

	/**
	 * Keep it, in one option that is not loaded on every page.
	 *
	 * @param array $stored Everything kept.
	 *
	 * @return void
	 */
	private function save( array $stored ): void {
		update_option( $this->id(), $stored, false );
	}
}
