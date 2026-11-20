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

use SugarCommerce\Plugin\Assets;
use SugarCommerce\Updater\Rest\Controller;

/**
 * Everything a person sees.
 *
 * The form goes wherever the plugin puts it -- its settings page, through
 * Updater::form() -- or, when the plugin has no `settings_url`, into a
 * row under the plugin on the Plugins screen. With JavaScript its buttons
 * ask the REST route (Rest\Controller) through wp.apiFetch and the box is
 * redrawn in place; without, it posts to admin-post.php, is checked for the
 * capability and a nonce, and comes back to the page it was on with the
 * outcome as a notice.
 *
 * The key is never printed: masked while active, and not put back in the
 * box otherwise -- an empty box with a key kept activates that key.
 *
 * Notices and the plugin row are in Traits\Admin\Notices and Row.
 *
 * Part of Updater; not meant to be used on its own.
 */
final class Admin {

	use Traits\Admin\Notices;
	use Traits\Admin\Row;

	/**
	 * How long the outcome of the form waits for the page after the
	 * redirect, in seconds.
	 */
	public const int OUTCOME_LIFETIME = 60;

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
			// The key kept is shown as its last four, as a placeholder:
			// left empty, the box activates it again.
			$fields .= sprintf(
				'<label class="screen-reader-text" for="%1$s">%2$s</label><input type="text" id="%1$s" name="%1$s" class="regular-text code" value="" placeholder="%3$s" autocomplete="off" spellcheck="false" data-licence-key /> ',
				esc_attr( $id . '_key' ),
				esc_html( $updater->label( 'license_key' ) ),
				esc_attr( '' !== $key ? self::mask( $key ) : $updater->label( 'placeholder' ) )
			);
			$fields .= $this->button( 'activate', 'button button-primary', $embedded );
		}

		// Run out, or running out: within the month the notices say so too.
		$expiring = 'active' === $state['status'] && null !== $state['expires_at'] && (int) $state['expires_at'] - time() < Updater::EXPIRING;

		if ( 'expired' === $state['status'] || $expiring ) {
			$fields .= sprintf(
				' <a class="button" href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( (string) $updater->config( 'renew_url' ) ),
				esc_html( $updater->label( 'renew' ) )
			);
		}

		$fields .= wp_nonce_field( $id . '_license', $id . '_nonce', true, false );
		$fields .= sprintf( '<p class="description %s">%s</p>', esc_attr( $id . '-status' ), esc_html( $this->status() ) );
		$fields .= sprintf( '<p class="%s" role="status" aria-live="polite"></p>', esc_attr( $id . '-said' ) );

		if ( ! $embedded ) {
			// admin-post.php for the form without JavaScript; the script
			// takes the buttons over and asks the REST route instead.
			$fields = sprintf(
				'<form method="post" action="%s"><input type="hidden" name="action" value="%s" />%s</form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				esc_attr( $id . '_license' ),
				$fields
			);
		}

		$this->enqueue();

		return sprintf(
			'<div class="%1$s" data-status="%2$s" data-licence="%3$s" data-embedded="%4$s" data-failed="%5$s">%6$s</div>',
			esc_attr( $id . '-licence' ),
			esc_attr( $state['status'] ),
			esc_attr( Controller::path( $updater ) ),
			$embedded ? '1' : '0',
			esc_attr( $updater->label( 'error_unreachable' ) ),
			$fields
		);
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

		set_transient( $this->outcome_key(), self::said( $this->updater, $do, $result ), self::OUTCOME_LIFETIME );

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
	 * What to tell the person after Activate or Deactivate.
	 *
	 * Always one of the labels: the store's own words are never shown.
	 *
	 * @param Updater        $updater The plugin.
	 * @param string         $action  `activate` or `deactivate`.
	 * @param true|\WP_Error $result  What it came to.
	 *
	 * @return array{type: string, message: string}
	 */
	public static function said( Updater $updater, string $action, true|\WP_Error $result ): array {
		if ( is_wp_error( $result ) ) {
			return [
				'type'    => 'error',
				'message' => $result->get_error_message(),
			];
		}

		return [
			'type'    => 'success',
			'message' => $updater->label( 'deactivate' === $action ? 'deactivated' : 'activated' ),
		];
	}

	/**
	 * The key with all but its last four characters hidden.
	 *
	 * @param string $key The key.
	 *
	 * @return string
	 */
	public static function mask( #[\SensitiveParameter] string $key ): string {
		$length = strlen( $key );

		if ( $length <= 4 ) {
			return str_repeat( '•', $length );
		}

		return str_repeat( '•', min( 12, $length - 4 ) ) . substr( $key, -4 );
	}

	/**
	 * Load the box's script: built from assets/js into assets/build, in the
	 * footer, with wp-api-fetch, which renews a nonce that ran out while
	 * the page was open.
	 *
	 * @return void
	 */
	private function enqueue(): void {
		Assets::enqueue_built_script( Controller::handle(), __FILE__, 'licence' );
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
			'<button type="submit" name="%1$s" value="%2$s" class="%3$s" data-licence-action="%2$s"%4$s>%5$s</button>',
			esc_attr( $this->updater->id() . '_do' ),
			esc_attr( $which ),
			esc_attr( $classes ),
			$embedded ? sprintf( ' formaction="%s" formmethod="post"', esc_url( admin_url( 'admin-post.php' ) ) ) : '',
			esc_html( $this->updater->label( $which ) )
		);
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
