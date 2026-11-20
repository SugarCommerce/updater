<?php
/**
 * What is known about a licence.
 *
 * @package   SugarCommerce\Updater
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Updater\Models;

/**
 * The licence's state: what the store said the last time it was asked.
 *
 * Updater::state() hands it out as an array, `to_array()`'s shape.
 */
final readonly class Licence {

	/**
	 * Set up.
	 *
	 * @param Status   $status      Where it stands.
	 * @param string   $reason      The store's word for it.
	 * @param int|null $expires_at  When it ends, or null for never.
	 * @param int|null $activations Sites it is active on.
	 * @param int|null $max         Sites it allows, or null for any number.
	 * @param int|null $checked_at  When the store was last asked.
	 */
	public function __construct(
		public Status $status = Status::None,
		public string $reason = '',
		public ?int $expires_at = null,
		public ?int $activations = null,
		public ?int $max = null,
		public ?int $checked_at = null,
	) {
	}

	/**
	 * As Updater::state() gives it.
	 *
	 * @return array{status: string, reason: string, expires_at: int|null, activations: int|null, max: int|null, checked_at: int|null}
	 */
	public function to_array(): array {
		return [
			'status'      => $this->status->value,
			'reason'      => $this->reason,
			'expires_at'  => $this->expires_at,
			'activations' => $this->activations,
			'max'         => $this->max,
			'checked_at'  => $this->checked_at,
		];
	}
}
