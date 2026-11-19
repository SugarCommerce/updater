<?php
/**
 * Every word the updater shows.
 *
 * @package   SugarCommerce\Updater
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Updater;

/**
 * The default words, every one replaceable through the `labels` setting.
 *
 * Written for the person who bought the plugin, not the person who wrote
 * it: no status codes, no "API", and what to do next where there is
 * something to do.
 */
final class Labels {

	/**
	 * The defaults, by key.
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return [
			// The form.
			'license_key'               => __( 'Licence Key', 'sugarcommerce' ),
			'placeholder'               => __( 'Paste your licence key', 'sugarcommerce' ),
			'activate'                  => __( 'Activate Licence', 'sugarcommerce' ),
			'deactivate'                => __( 'Deactivate Licence', 'sugarcommerce' ),
			'renew'                     => __( 'Renew Licence', 'sugarcommerce' ),
			'enter_key'                 => __( 'Enter Licence Key', 'sugarcommerce' ),
			'account'                   => __( 'View Your Account', 'sugarcommerce' ),
			'licence_link'              => __( 'Licence', 'sugarcommerce' ),

			// One line saying where the licence stands.
			'status_none'               => __( 'Enter your licence key to get updates.', 'sugarcommerce' ),
			/* translators: %s: the date the licence ends. */
			'status_active'             => __( 'Active until %s.', 'sugarcommerce' ),
			'status_lifetime'           => __( 'Active, with no end date.', 'sugarcommerce' ),
			/* translators: %s: the date the licence ended. */
			'status_expired'            => __( 'Expired on %s. Renew it to get updates again.', 'sugarcommerce' ),
			'status_revoked'            => __( 'This licence key has been revoked, so it can no longer get updates.', 'sugarcommerce' ),
			'status_invalid'            => __( 'That licence key was not recognised. Check it against the one in your purchase email.', 'sugarcommerce' ),
			'status_inactive'           => __( 'Not active on this site. Activate it to get updates.', 'sugarcommerce' ),
			'status_released'           => __( 'This site was removed from the licence. Activate it again to get updates.', 'sugarcommerce' ),
			'status_limit_reached'      => __( 'The licence is already in use on as many sites as it allows.', 'sugarcommerce' ),

			// After the form is sent.
			'activated'                 => __( 'Licence activated. Updates will now arrive here.', 'sugarcommerce' ),
			'deactivated'               => __( 'Licence deactivated on this site.', 'sugarcommerce' ),
			'forbidden'                 => __( 'Sorry, you are not allowed to change this licence.', 'sugarcommerce' ),
			'link_expired'              => __( 'This page has been open too long. Go back, reload it and try again.', 'sugarcommerce' ),

			// Notices. The first placeholder is always the plugin's name.
			/* translators: %s: the plugin's name. */
			'notice_none'               => __( 'Enter your licence key for %s to get updates and support.', 'sugarcommerce' ),
			/* translators: 1: the plugin's name, 2: the date the licence ended. */
			'notice_expired'            => __( 'Your licence for %1$s expired on %2$s. Renew it to keep getting updates.', 'sugarcommerce' ),
			/* translators: 1: the plugin's name, 2: the date the licence ends. */
			'notice_expiring'           => __( 'Your licence for %1$s ends on %2$s. Renew it to keep getting updates.', 'sugarcommerce' ),
			/* translators: %s: the plugin's name. */
			'notice_revoked'            => __( 'Your licence key for %s has been revoked, so it can no longer get updates.', 'sugarcommerce' ),
			/* translators: %s: the plugin's name. */
			'notice_released'           => __( 'This site was removed from your licence for %s, so it will not get updates until it is activated again.', 'sugarcommerce' ),

			// The line under the plugin on the Plugins screen.
			'row_none'                  => __( 'Enter your licence key to get updates for this plugin.', 'sugarcommerce' ),
			/* translators: %s: the date the licence ended. */
			'row_expired'               => __( 'Your licence expired on %s. Renew it to get updates.', 'sugarcommerce' ),

			// What went wrong, one sentence for each thing the store can say.
			'error_not_found'           => __( 'That licence key was not recognised. Check it against the one in your purchase email.', 'sugarcommerce' ),
			'error_revoked'             => __( 'This licence key has been revoked. Contact the store if you think that is a mistake.', 'sugarcommerce' ),
			'error_expired'             => __( 'This licence has expired. Renew it to activate it and keep getting updates.', 'sugarcommerce' ),
			'error_location_not_active' => __( 'This site is not activated on that licence. Activate it again to get updates.', 'sugarcommerce' ),
			'error_limit_reached'       => __( 'This licence is already in use on as many sites as it allows. Deactivate it on another site, or upgrade it, then try again.', 'sugarcommerce' ),
			'error_busy'                => __( 'The store is busy with another activation of this licence. Try again in a moment.', 'sugarcommerce' ),
			'error_not_active'          => __( 'The licence could not be activated on this site. Try again, or contact the store.', 'sugarcommerce' ),
			'error_too_many_requests'   => __( 'The store has had too many requests from this server. Try again in a minute.', 'sugarcommerce' ),
			'error_key_required'        => __( 'Enter a licence key first.', 'sugarcommerce' ),
			'error_product_required'    => __( 'The store could not tell which product this licence is for. Contact the plugin\'s author.', 'sugarcommerce' ),
			'error_location_required'   => __( 'The store could not tell which site this is. Contact the plugin\'s author.', 'sugarcommerce' ),
			'error_no_key'              => __( 'There is no licence key on this site yet.', 'sugarcommerce' ),
			'error_unreachable'         => __( 'The store could not be reached. Try again in a few minutes.', 'sugarcommerce' ),
			'error_unknown'             => __( 'The store did not accept the licence key. Try again, or contact the store.', 'sugarcommerce' ),
		];
	}
}
