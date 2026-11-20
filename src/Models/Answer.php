<?php
/**
 * One answer from the store.
 *
 * @package   SugarCommerce\Updater
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Updater\Models;

/**
 * What the store said, in its own shape -- `ok`, a `reason` to branch on,
 * the licence when there is one -- after Store\Reader has kept only what it
 * could check. Nothing the store sent beyond these keys survives.
 */
final readonly class Answer {

	/**
	 * Set up.
	 *
	 * @param bool                      $ok       Whether it did what was asked.
	 * @param string                    $reason   Its word: lower-case letters, digits and underscores.
	 * @param array<string, mixed>|null $license  `status`, `expires_at`, `activations`, `max`, `product_id`, `test`.
	 * @param array<string, mixed>|null $location `url` and `active`, when a location was asked about.
	 * @param Release|null              $release  The newest version, on a `latest` answer.
	 */
	public function __construct(
		public bool $ok,
		public string $reason,
		public ?array $license = null,
		public ?array $location = null,
		public ?Release $release = null,
	) {
	}

	/**
	 * The answer as an array: what the `{prefix}_response` filter, latest()
	 * and the kept update answer have.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$answer = [
			'ok'      => $this->ok,
			'reason'  => $this->reason,
			'license' => $this->license,
		];

		if ( null !== $this->location ) {
			$answer['location'] = $this->location;
		}

		return null === $this->release ? $answer : $answer + $this->release->to_array();
	}
}
