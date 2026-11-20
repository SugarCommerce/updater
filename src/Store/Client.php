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

namespace SugarCommerce\Updater\Store;

use SugarCommerce\Network\Remote\SafeUrl;
use SugarCommerce\Updater\Models\Answer;
use SugarCommerce\Updater\Updater;
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
 * The request goes through sugarcommerce/network's SafeUrl (https, every
 * address the host resolves to public -- the site's own host excepted, as
 * core's own check makes it -- pinned, no redirects, never a metadata
 * service); the answer through the Reader (only keys it can check).
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
	public const int TIMEOUT = 15;

	/**
	 * The most of an answer read, in bytes. A licence answer is a few
	 * hundred; a `latest` with a long changelog a few dozen thousand.
	 */
	public const int MAX_BYTES = 1048576;

	/**
	 * Reads the store's answers.
	 *
	 * @var Reader
	 */
	private Reader $reader;

	/**
	 * Guards every request to the store.
	 *
	 * @var SafeUrl
	 */
	private SafeUrl $guard;

	/**
	 * Set up.
	 *
	 * @param Updater $updater The plugin asking.
	 */
	public function __construct( private Updater $updater ) {
		$this->reader = new Reader( (string) $updater->config( 'store' ) );
		$this->guard  = new SafeUrl( resolver: $updater->config( 'resolver' ), https_only: true, own_host: true );
	}

	/**
	 * The guard every request to this plugin's store goes through.
	 *
	 * @return SafeUrl
	 */
	public function guard(): SafeUrl {
		return $this->guard;
	}

	/**
	 * The Reader for this plugin's store.
	 *
	 * @return Reader
	 */
	public function reader(): Reader {
		return $this->reader;
	}

	/**
	 * Ask the store.
	 *
	 * @param string               $action `activate`, `validate`, `deactivate` or `latest`.
	 * @param array<string, mixed> $body   What to send.
	 *
	 * @return Answer|WP_Error
	 */
	public function send( string $action, array $body ): Answer|WP_Error {
		$args = [
			'timeout'             => self::TIMEOUT,
			'headers'             => [ 'Accept' => 'application/json' ],
			'body'                => $body,
			'limit_response_size' => self::MAX_BYTES,
		];

		/**
		 * Filter a request to the store before it is sent.
		 *
		 * The body carries the licence key: log the action, not the body.
		 *
		 * @param array   $args    wp_remote_post() arguments.
		 * @param string  $action  `activate`, `validate`, `deactivate` or `latest`.
		 * @param Updater $updater The plugin asking.
		 */
		$args = (array) apply_filters( $this->updater->hook_name( 'request' ), $args, $action, $this->updater );

		$answer = $this->read( $this->guard->post( $this->updater->endpoint( $action ), $args ) );

		/**
		 * Filter the store's answer, or the error it amounted to.
		 *
		 * @param array|WP_Error $answer  The answer, as an array.
		 * @param string         $action  `activate`, `validate`, `deactivate` or `latest`.
		 * @param Updater        $updater The plugin asking.
		 */
		$answer = apply_filters( $this->updater->hook_name( 'response' ), $answer, $action, $this->updater );

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		return $this->reader->answer( $answer ) ?? $this->updater->error( 'unreachable' );
	}

	/**
	 * Read an HTTP response as the store's answer.
	 *
	 * @param array|WP_Error $response From the guard.
	 *
	 * @return array|WP_Error The answer, checked, as an array.
	 */
	private function read( array|WP_Error $response ): array|WP_Error {
		if ( is_wp_error( $response ) ) {
			return $this->updater->error( 'unreachable' );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		// Not the store's shape: a page that is not the API, a host's error
		// page, a site that moved. All the same to the person waiting.
		$answer = json_validate( $body, 32 ) ? $this->reader->answer( json_decode( $body, true, 32 ) ) : null;

		if ( null === $answer ) {
			return $this->updater->error( 'unreachable' );
		}

		// A refusal of the request rather than an answer about the key:
		// too many requests, or a field the store needed.
		if ( 200 !== $status ) {
			return $this->updater->error( $answer->reason, $answer->to_array() );
		}

		return $answer->to_array();
	}
}
