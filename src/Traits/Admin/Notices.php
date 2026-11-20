<?php
/**
 * The licence notices.
 *
 * @package   SugarCommerce\Updater
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Updater\Traits\Admin;

use SugarCommerce\Updater\Updater;

/**
 * What the form did, and what needs doing: once each, to people with the
 * capability, on the dashboard, the Plugins and Updates screens and the
 * licence's own page -- not on every screen of the admin.
 */
trait Notices {

	/**
	 * Show what the form did, on the page it came back to.
	 *
	 * @return void
	 */
	public function outcome(): void {
		$key     = $this->outcome_key();
		$outcome = get_transient( $key );

		if ( ! is_array( $outcome ) || ! isset( $outcome['type'], $outcome['message'] ) ) {
			return;
		}

		delete_transient( $key );

		wp_admin_notice(
			esc_html( (string) $outcome['message'] ),
			[
				'type'        => 'error' === $outcome['type'] ? 'error' : 'success',
				'dismissible' => true,
			]
		);
	}

	/**
	 * Print the notices that apply, on the screens they belong on.
	 *
	 * @return void
	 */
	public function notices(): void {
		if ( ! current_user_can( (string) $this->updater->config( 'capability' ) ) || ! $this->on_notice_screen() ) {
			return;
		}

		/**
		 * Filter the licence notices about to be shown.
		 *
		 * Keyed `missing`, `released`, `expired`, `expiring` or `revoked`,
		 * each `[ 'type' => …, 'message' => HTML ]`. Return an empty array
		 * to show none.
		 *
		 * @param array   $notices The notices.
		 * @param Updater $updater The plugin.
		 */
		$notices = apply_filters( $this->updater->hook_name( 'notices' ), $this->due(), $this->updater );

		foreach ( (array) $notices as $key => $notice ) {
			if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
				continue;
			}

			wp_admin_notice(
				(string) $notice['message'],
				[
					'type'               => (string) ( $notice['type'] ?? 'warning' ),
					'additional_classes' => [ $this->updater->id() . '-notice' ],
					'attributes'         => [ 'data-notice' => (string) $key ],
				]
			);
		}
	}

	/**
	 * The notice the licence's state calls for, by key. One at most.
	 *
	 * @return array<string, array{type: string, message: string}>
	 */
	public function due(): array {
		$updater = $this->updater;
		$state   = $updater->state();
		$name    = $updater->name();
		$expires = $state['expires_at'];
		$status  = $state['status'];

		// Active by what was last said, but the date has passed since.
		if ( 'active' === $status && null !== $expires && $expires <= time() ) {
			$status = 'expired';
		}

		$notice = match ( true ) {
			'expired' === $status => [
				'expired',
				'error',
				$updater->label( 'notice_expired', $name, null !== $expires ? $updater->date( $expires ) : '' ),
				(string) $updater->config( 'renew_url' ),
				$updater->label( 'renew' ),
			],
			'revoked' === $status => [
				'revoked',
				'error',
				$updater->label( 'notice_revoked', $name ),
				(string) $updater->config( 'account_url' ),
				$updater->label( 'account' ),
			],
			'inactive' === $status && 'location_not_active' === $state['reason'] => [
				'released',
				'warning',
				$updater->label( 'notice_released', $name ),
				$updater->settings_url(),
				$updater->label( 'activate' ),
			],
			'active' === $status && null !== $expires && $expires - time() <= Updater::EXPIRING => [
				'expiring',
				'warning',
				$updater->label( 'notice_expiring', $name, $updater->date( $expires ) ),
				(string) $updater->config( 'renew_url' ),
				$updater->label( 'renew' ),
			],
			'active' === $status => null,
			default => [
				'missing',
				'warning',
				$updater->label( 'notice_none', $name ),
				$updater->settings_url(),
				$updater->label( 'enter_key' ),
			],
		};

		if ( null === $notice ) {
			return [];
		}

		[ $key, $type, $message, $url, $link ] = $notice;

		return [
			$key => [
				'type'    => $type,
				'message' => esc_html( $message ) . ' ' . sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $link ) ),
			],
		];
	}

	/**
	 * Whether this screen is one the notices belong on.
	 *
	 * @return bool
	 */
	private function on_notice_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		/**
		 * Filter the screens the licence notices are shown on, besides the
		 * licence's own page.
		 *
		 * @param string[] $screens Screen ids.
		 * @param Updater  $updater The plugin.
		 */
		$screens = (array) apply_filters(
			$this->updater->hook_name( 'notice_screens' ),
			[ 'dashboard', 'plugins', 'update-core', 'dashboard-network', 'plugins-network', 'update-core-network' ],
			$this->updater
		);

		if ( is_object( $screen ) && in_array( $screen->id ?? '', $screens, true ) ) {
			return true;
		}

		return $this->on_settings_screen();
	}

	/**
	 * Whether this is the page the licence form lives on.
	 *
	 * The same admin file, and the same `page` when the address has one.
	 *
	 * @return bool
	 */
	private function on_settings_screen(): bool {
		global $pagenow;

		$url = (string) $this->updater->config( 'settings_url' );

		if ( '' === $url ) {
			return false;
		}

		$file = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		if ( '' === $file || $file !== $pagenow ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only compares which page this is.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return ! isset( $query['page'] ) || sanitize_key( (string) $query['page'] ) === $page;
	}
}
