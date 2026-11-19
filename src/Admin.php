<?php
/**
 * The licence form, its handler, the notices and the plugin row.
 *
 * @package   SugarCommerce\Updater
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Updater;

/**
 * Everything a person sees.
 *
 * The form goes wherever the plugin puts it -- its settings page, through
 * Updater::form() -- or, when the plugin has no `settings_url`, into a
 * row under the plugin on the Plugins screen. Either way it posts to
 * admin-post.php, is checked for the capability and a nonce, and comes
 * back to the page it was on with the outcome as a notice.
 *
 * Notices say what needs doing, once each, to people with the capability,
 * on the dashboard, the Plugins and Updates screens and the licence's own
 * page -- not on every screen of the admin.
 *
 * Part of Updater; not meant to be used on its own.
 */
final class Admin {

	/**
	 * How long the outcome of the form waits for the page after the
	 * redirect, in seconds.
	 */
	public const OUTCOME_LIFETIME = 60;

	/**
	 * Set up.
	 *
	 * @param Updater $updater The plugin.
	 */
	public function __construct( private Updater $updater ) {
	}

	/**
	 * The licence form. See Updater::form().
	 *
	 * @param array{embedded?: bool} $args Arguments.
	 *
	 * @return string
	 */
	public function form( array $args = [] ): string {
		$embedded = ! empty( $args['embedded'] );
		$updater  = $this->updater;
		$id       = $updater->id();
		$state    = $updater->state();
		$key      = $updater->key();
		$fields   = '';

		if ( 'active' === $state['status'] ) {
			$fields .= sprintf( '<code class="%s">%s</code> ', esc_attr( $id . '-key' ), esc_html( self::mask( $key ) ) );
			$fields .= $this->button( 'deactivate', 'button', $embedded );
		} else {
			$fields .= sprintf(
				'<label class="screen-reader-text" for="%1$s">%2$s</label><input type="text" id="%1$s" name="%1$s" class="regular-text code" value="%3$s" placeholder="%4$s" autocomplete="off" spellcheck="false" /> ',
				esc_attr( $id . '_key' ),
				esc_html( $updater->label( 'license_key' ) ),
				esc_attr( $key ),
				esc_attr( $updater->label( 'placeholder' ) )
			);
			$fields .= $this->button( 'activate', 'button button-primary', $embedded );
		}

		if ( 'expired' === $state['status'] ) {
			$fields .= sprintf(
				' <a class="button" href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( (string) $updater->config( 'renew_url' ) ),
				esc_html( $updater->label( 'renew' ) )
			);
		}

		$fields .= wp_nonce_field( $id . '_license', $id . '_nonce', true, false );
		$fields .= sprintf( '<p class="description %s">%s</p>', esc_attr( $id . '-status' ), esc_html( $this->status() ) );

		if ( ! $embedded ) {
			$fields = sprintf(
				'<form method="post" action="%s"><input type="hidden" name="action" value="%s" />%s</form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				esc_attr( $id . '_license' ),
				$fields
			);
		}

		return sprintf( '<div class="%1$s" data-status="%2$s">%3$s</div>', esc_attr( $id . '-licence' ), esc_attr( $state['status'] ), $fields );
	}

	/**
	 * Handle the form, and redirect back. See Updater::handle().
	 *
	 * The capability and the nonce are checked before anything is sent to
	 * the store; either failing stops the request where it is.
	 *
	 * @return string|null Where it redirected to, or null when the request
	 *                     was not this form's.
	 */
	public function handle(): ?string {
		$id = $this->updater->id();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only asks whether this form was sent; verified below.
		if ( ! isset( $_POST[ $id . '_do' ] ) ) {
			return null;
		}

		if ( ! current_user_can( (string) $this->updater->config( 'capability' ) ) ) {
			wp_die( esc_html( $this->updater->label( 'forbidden' ) ), '', [ 'response' => 403 ] );
		}

		$nonce = isset( $_POST[ $id . '_nonce' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $id . '_nonce' ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, $id . '_license' ) ) {
			wp_die( esc_html( $this->updater->label( 'link_expired' ) ), '', [ 'response' => 403 ] );
		}

		$do = sanitize_key( wp_unslash( $_POST[ $id . '_do' ] ) );

		if ( 'deactivate' === $do ) {
			$result = $this->updater->deactivate();
		} else {
			$key    = isset( $_POST[ $id . '_key' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $id . '_key' ] ) ) : '';
			$result = $this->updater->activate( $key );
		}

		$outcome = is_wp_error( $result )
			? [
				'type'    => 'error',
				'message' => $result->get_error_message(),
			]
			: [
				'type'    => 'success',
				'message' => $this->updater->label( 'deactivate' === $do ? 'deactivated' : 'activated' ),
			];

		set_transient( $this->outcome_key(), $outcome, self::OUTCOME_LIFETIME );

		$to = wp_get_referer();
		$to = is_string( $to ) && '' !== $to ? $to : $this->updater->settings_url();

		wp_safe_redirect( $to );

		return $to;
	}

	/**
	 * Handle the form, and stop. What admin-post.php and admin_init run.
	 *
	 * @return void
	 */
	public function listen(): void {
		if ( null !== $this->handle() ) {
			exit;
		}
	}

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

	/**
	 * The key with all but its last four characters hidden.
	 *
	 * @param string $key The key.
	 *
	 * @return string
	 */
	public static function mask( string $key ): string {
		$length = strlen( $key );

		if ( $length <= 4 ) {
			return str_repeat( '•', $length );
		}

		return str_repeat( '•', min( 12, $length - 4 ) ) . substr( $key, -4 );
	}

	/**
	 * One line saying where the licence stands.
	 *
	 * @return string
	 */
	private function status(): string {
		$updater = $this->updater;
		$state   = $updater->state();
		$expires = $state['expires_at'];

		return match ( $state['status'] ) {
			'active'   => null === $expires ? $updater->label( 'status_lifetime' ) : $updater->label( 'status_active', $updater->date( $expires ) ),
			'expired'  => $updater->label( 'status_expired', null !== $expires ? $updater->date( $expires ) : '' ),
			'revoked'  => $updater->label( 'status_revoked' ),
			'invalid'  => $updater->label( 'status_invalid' ),
			'inactive' => match ( $state['reason'] ) {
				'location_not_active' => $updater->label( 'status_released' ),
				'limit_reached'       => $updater->label( 'status_limit_reached' ),
				default               => $updater->label( 'status_inactive' ),
			},
			default    => $updater->label( 'status_none' ),
		};
	}

	/**
	 * One of the form's two buttons.
	 *
	 * Embedded in another form, it posts to admin-post.php by itself; the
	 * other form's own `action` field is harmless, because admin-post.php
	 * fires admin_init, which is listened on, before it reads that.
	 *
	 * @param string $which    `activate` or `deactivate`.
	 * @param string $classes  Its classes.
	 * @param bool   $embedded Whether it is inside another form.
	 *
	 * @return string
	 */
	private function button( string $which, string $classes, bool $embedded ): string {
		return sprintf(
			'<button type="submit" name="%1$s" value="%2$s" class="%3$s"%4$s>%5$s</button>',
			esc_attr( $this->updater->id() . '_do' ),
			esc_attr( $which ),
			esc_attr( $classes ),
			$embedded ? sprintf( ' formaction="%s" formmethod="post"', esc_url( admin_url( 'admin-post.php' ) ) ) : '',
			esc_html( $this->updater->label( $which ) )
		);
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

	/**
	 * Where the form's outcome waits for this user.
	 *
	 * @return string
	 */
	private function outcome_key(): string {
		return $this->updater->id() . '_outcome_' . get_current_user_id();
	}
}
