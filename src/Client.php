<?php
/**
 * The store's licence API, asked.
 *
 * @package   SugarCommerce\Updater
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Updater;

use WP_Error;

/**
 * One request to the store, and its answer read.
 *
 * The store answers every well-formed request 200, with `ok`, a `reason`
 * to branch on and the licence when there is one -- so "expired" is a
 * word in the answer, never a guess from a status code. What comes back
 * from here is that answer, or an error when there was nothing to read:
 * the store could not be reached, did not answer in its own shape, or
 * refused the request itself (too many, or a field missing).
 *
 * Part of Updater; not meant to be used on its own.
 */
final class Client {

	/**
	 * How long to wait for the store, in seconds.
	 *
	 * Long enough for a store on shared hosting, short enough that the
	 * Plugins screen does not hang on one that is down.
	 */
	public const TIMEOUT = 15;

	/**
	 * Set up.
	 *
	 * @param Updater $updater The plugin asking.
	 */
	public function __construct( private Updater $updater ) {
	}

	/**
	 * Ask the store.
	 *
	 * @param string               $action `activate`, `validate`, `deactivate` or `latest`.
	 * @param array<string, mixed> $body   What to send.
	 *
	 * @return array|WP_Error The answer, with `ok` and `reason` always there.
	 */
	public function send( string $action, array $body ): array|WP_Error {
		$args = [
			'timeout' => self::TIMEOUT,
			'headers' => [ 'Accept' => 'application/json' ],
			'body'    => $body,
		];

		/**
		 * Filter a request to the store before it is sent.
		 *
		 * @param array   $args    wp_remote_post() arguments.
		 * @param string  $action  `activate`, `validate`, `deactivate` or `latest`.
		 * @param Updater $updater The plugin asking.
		 */
		$args = (array) apply_filters( $this->updater->hook_name( 'request' ), $args, $action, $this->updater );

		$answer = $this->read( wp_remote_post( $this->updater->endpoint( $action ), $args ) );

		/**
		 * Filter the store's answer, or the error it amounted to.
		 *
		 * @param array|WP_Error $answer  The answer.
		 * @param string         $action  `activate`, `validate`, `deactivate` or `latest`.
		 * @param Updater        $updater The plugin asking.
		 */
		$answer = apply_filters( $this->updater->hook_name( 'response' ), $answer, $action, $this->updater );

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		return is_array( $answer ) && is_string( $answer['reason'] ?? null ) ? $answer : $this->updater->error( 'unreachable' );
	}

	/**
	 * Read an HTTP response as the store's answer.
	 *
	 * @param array|WP_Error $response From wp_remote_post().
	 *
	 * @return array|WP_Error
	 */
	private function read( array|WP_Error $response ): array|WP_Error {
		if ( is_wp_error( $response ) ) {
			return $this->updater->error( 'unreachable' );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$answer = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		// Not the store's shape: a page that is not the API, a host's error
		// page, a site that moved. All the same to the person waiting.
		if ( ! is_array( $answer ) || ! isset( $answer['reason'] ) || ! is_string( $answer['reason'] ) ) {
			return $this->updater->error( 'unreachable' );
		}

		$answer['ok'] = ! empty( $answer['ok'] );

		// A refusal of the request rather than an answer about the key:
		// too many requests, or a field the store needed.
		if ( 200 !== $status ) {
			return $this->updater->error( $answer['reason'], $answer );
		}

		return $answer;
	}
}
