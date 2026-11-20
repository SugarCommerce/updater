<?php
/**
 * The plugin's row on the Plugins screen.
 *
 * @package   SugarCommerce\Updater
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Updater\Traits\Admin;

/**
 * The row under the plugin, and the Licence link beside Deactivate.
 */
trait Row {

	/**
	 * The row under the plugin on the Plugins screen.
	 *
	 * Without a `settings_url`, the Plugins screen is where the licence
	 * lives, and the row is the form. With one, the row only speaks up
	 * when there is no key or the licence has run out, and links there.
	 *
	 * @return void
	 */
	public function row(): void {
		$updater = $this->updater;

		if ( ! current_user_can( (string) $updater->config( 'capability' ) ) ) {
			return;
		}

		$state = $updater->state();

		if ( '' === $updater->config( 'settings_url' ) ) {
			$type    = match ( $state['status'] ) {
				'active'             => 'info',
				'expired', 'revoked' => 'error',
				default              => 'warning',
			};
			$content = $this->form( [ 'embedded' => true ] );
		} elseif ( 'expired' === $state['status'] ) {
			$type    = 'error';
			$content = sprintf(
				'<p>%s <a href="%s">%s</a></p>',
				esc_html( $updater->label( 'row_expired', null !== $state['expires_at'] ? $updater->date( $state['expires_at'] ) : '' ) ),
				esc_url( (string) $updater->config( 'renew_url' ) ),
				esc_html( $updater->label( 'renew' ) )
			);
		} elseif ( in_array( $state['status'], [ 'none', 'invalid', 'inactive' ], true ) ) {
			$type    = 'warning';
			$content = sprintf(
				'<p>%s <a href="%s">%s</a></p>',
				esc_html( $updater->label( 'row_none' ) ),
				esc_url( $updater->settings_url() ),
				esc_html( $updater->label( 'enter_key' ) )
			);
		} else {
			return;
		}

		$columns = 4;
		$table   = $GLOBALS['wp_list_table'] ?? null;

		if ( is_object( $table ) && method_exists( $table, 'get_column_count' ) ) {
			$columns = (int) $table->get_column_count();
		}

		printf(
			'<tr class="plugin-update-tr active %1$s"><td colspan="%2$d" class="plugin-update colspanchange"><div class="notice inline notice-%3$s notice-alt">%4$s</div></td></tr>',
			esc_attr( $updater->id() . '-row' ),
			(int) $columns,
			esc_attr( $type ),
			$content // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
		);
	}

	/**
	 * Add a Licence link beside Deactivate, when the licence lives on a
	 * page of its own.
	 *
	 * @param mixed $links The plugin's action links.
	 *
	 * @return array
	 */
	public function links( mixed $links ): array {
		$links = (array) $links;

		if ( '' === $this->updater->config( 'settings_url' ) || ! current_user_can( (string) $this->updater->config( 'capability' ) ) ) {
			return $links;
		}

		$links['licence'] = sprintf( '<a href="%s">%s</a>', esc_url( $this->updater->settings_url() ), esc_html( $this->updater->label( 'licence_link' ) ) );

		return $links;
	}
}
