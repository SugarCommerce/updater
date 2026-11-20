<?php
/**
 * What a licence can be, as far as this site knows.
 *
 * @package   SugarCommerce\Updater
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Updater\Models;

/**
 * The six states. Updater::STATUSES lists their values.
 *
 * - `none`     No key has been entered.
 * - `active`   This site holds a seat on a licence that is good.
 * - `expired`  The licence ran out.
 * - `revoked`  The store took it away.
 * - `invalid`  The store does not know the key, for this product.
 * - `inactive` A good key without a seat here: deactivated, released
 *              from the account page, or refused one.
 */
enum Status: string {

	case None     = 'none';
	case Active   = 'active';
	case Expired  = 'expired';
	case Revoked  = 'revoked';
	case Invalid  = 'invalid';
	case Inactive = 'inactive';

	/**
	 * The state a word from the store puts the licence in.
	 *
	 * @param string $reason The store's `reason`.
	 *
	 * @return Status
	 */
	public static function for_reason( string $reason ): Status {
		return match ( $reason ) {
			'ok', 'activated' => self::Active,
			'expired'         => self::Expired,
			'revoked'         => self::Revoked,
			'not_found'       => self::Invalid,
			default           => self::Inactive,
		};
	}
}
