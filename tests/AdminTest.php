<?php
/**
 * Admin tests.
 *
 * @package SugarCommerce\Updater
 */

declare( strict_types=1 );

namespace SugarCommerce\Updater\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SugarCommerce\Updater\Admin;
use SugarCommerce\Updater\Updater;
use Updater_Died;

/**
 * The form, its handler, the notices and the plugin row.
 */
final class AdminTest extends TestCase {

	/**
	 * Keep a state as if the store had said it.
	 *
	 * @param Updater $updater The plugin.
	 * @param string  $status  The status.
	 * @param array   $more    Anything else.
	 *
	 * @return void
	 */
	private function state( Updater $updater, string $status, array $more = [] ): void {
		$GLOBALS['up_options'][ $updater->id() ] = [
			'key'   => 'none' === $status ? '' : 'KEY-1234-ABCD',
			'state' => array_merge(
				[
					'status'     => $status,
					'reason'     => '',
					'expires_at' => time() + 365 * DAY_IN_SECONDS,
				],
				$more
			),
		];
	}

	/**
	 * Post the form, as the browser would.
	 *
	 * @param string $do    `activate` or `deactivate`.
	 * @param array  $extra More fields, or ones to replace.
	 *
	 * @return void
	 */
	private function post( string $do, array $extra = [] ): void {
		$_POST = array_merge(
			[
				'sugarcommerce_updater_seller_do'    => $do,
				'sugarcommerce_updater_seller_key'   => 'KEY-1234-ABCD',
				'sugarcommerce_updater_seller_nonce' => 'nonce-sugarcommerce_updater_seller_license',
				'_wp_http_referer'                   => '/wp-admin/admin.php?page=shop-settings',
			],
			$extra
		);
	}

	/**
	 * Without a licence: a box for the key and Activate.
	 */
	public function test_the_form_without_a_licence(): void {
		$form = $this->register()->form();

		$this->assertStringContainsString( '<form method="post" action="https://site.test/wp-admin/admin-post.php">', $form );
		$this->assertStringContainsString( 'name="action" value="sugarcommerce_updater_seller_license"', $form );
		$this->assertStringContainsString( 'name="sugarcommerce_updater_seller_key"', $form );
		$this->assertStringContainsString( 'value="activate"', $form );
		$this->assertStringContainsString( '>Activate Licence</button>', $form );
		$this->assertStringContainsString( 'name="sugarcommerce_updater_seller_nonce" value="nonce-sugarcommerce_updater_seller_license"', $form );
		$this->assertStringContainsString( 'Enter your licence key to get updates.', $form );
		$this->assertStringNotContainsString( 'Deactivate', $form );
	}

	/**
	 * Active: the key masked, and Deactivate.
	 */
	public function test_the_form_while_active(): void {
		$updater = $this->register();

		$this->state( $updater, 'active', [ 'expires_at' => 1893456000 ] );

		$form = $updater->form();

		$this->assertStringContainsString( '•••••••••ABCD', $form );
		$this->assertStringNotContainsString( 'KEY-1234-ABCD', $form );
		$this->assertStringContainsString( 'value="deactivate"', $form );
		$this->assertStringContainsString( '>Deactivate Licence</button>', $form );
		$this->assertStringContainsString( 'Active until 1 January 2030.', $form );
		$this->assertStringNotContainsString( '_key"', $form );
	}

	/**
	 * A licence with no end says so.
	 */
	public function test_the_form_for_a_licence_with_no_end(): void {
		$updater = $this->register();

		$this->state( $updater, 'active', [ 'expires_at' => null ] );

		$this->assertStringContainsString( 'Active, with no end date.', $updater->form() );
	}

	/**
	 * Expired: the key in the box to activate again, and a Renew link.
	 */
	public function test_the_form_when_expired(): void {
		$updater = $this->register( [ 'renew_url' => 'https://store.example/renew' ] );

		$this->state( $updater, 'expired', [ 'expires_at' => 1735689600 ] );

		$form = $updater->form();

		$this->assertStringContainsString( 'value="KEY-1234-ABCD"', $form );
		$this->assertStringContainsString( 'href="https://store.example/renew"', $form );
		$this->assertStringContainsString( '>Renew Licence</a>', $form );
		$this->assertStringContainsString( 'Expired on 1 January 2025.', $form );
	}

	/**
	 * Ending within the month: still active, and Renew beside Deactivate;
	 * further off, no Renew.
	 */
	public function test_the_form_when_ending_soon(): void {
		$updater = $this->register( [ 'renew_url' => 'https://store.example/renew' ] );

		$this->state( $updater, 'active', [ 'expires_at' => time() + 10 * DAY_IN_SECONDS ] );

		$form = $updater->form();

		$this->assertStringContainsString( '>Deactivate Licence</button>', $form );
		$this->assertStringContainsString( '>Renew Licence</a>', $form );

		$this->state( $updater, 'active', [ 'expires_at' => time() + 60 * DAY_IN_SECONDS ] );

		$this->assertStringNotContainsString( 'Renew Licence', $updater->form() );
	}

	/**
	 * Each state has one line, in words.
	 *
	 * @param string $status The status.
	 * @param string $reason The reason.
	 * @param string $line   What the form says.
	 */
	#[DataProvider( 'lines' )]
	public function test_the_status_line( string $status, string $reason, string $line ): void {
		$updater = $this->register();

		$this->state( $updater, $status, [ 'reason' => $reason ] );

		$this->assertStringContainsString( $line, $updater->form() );
	}

	/**
	 * The lines.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function lines(): array {
		return [
			'revoked'  => [ 'revoked', 'revoked', 'has been revoked' ],
			'invalid'  => [ 'invalid', 'not_found', 'was not recognised' ],
			'released' => [ 'inactive', 'location_not_active', 'This site was removed from the licence.' ],
			'limit'    => [ 'inactive', 'limit_reached', 'as many sites as it allows' ],
			'inactive' => [ 'inactive', 'deactivated', 'Not active on this site.' ],
		];
	}

	/**
	 * Inside another form: no form of its own, buttons that post themselves.
	 */
	public function test_the_embedded_form(): void {
		$form = $this->register()->form( [ 'embedded' => true ] );

		$this->assertStringNotContainsString( '<form', $form );
		$this->assertStringNotContainsString( 'name="action"', $form );
		$this->assertStringContainsString( 'formaction="https://site.test/wp-admin/admin-post.php" formmethod="post"', $form );
	}

	/**
	 * A request that is not this form's is not handled.
	 */
	public function test_another_request_is_not_handled(): void {
		$this->assertNull( $this->register()->handle() );
		$this->assertNull( $GLOBALS['up_redirect'] );
	}

	/**
	 * Somebody without the capability is stopped before the store is asked.
	 */
	public function test_the_handler_checks_the_capability(): void {
		$updater = $this->register();

		$GLOBALS['up_can'] = [];
		$this->post( 'activate' );

		try {
			$updater->handle();
			$this->fail( 'It went on.' );
		} catch ( Updater_Died $died ) {
			$this->assertSame( 403, $died->status );
		}

		$this->assertSame( [], $this->asked() );
		$this->assertSame( '', $updater->key() );
	}

	/**
	 * A configured capability is the one checked.
	 */
	public function test_the_handler_checks_the_configured_capability(): void {
		$updater = $this->register( [ 'capability' => 'install_plugins' ] );

		$this->post( 'activate' );
		$this->expectException( Updater_Died::class );

		$updater->handle();
	}

	/**
	 * A request without the nonce is stopped before the store is asked.
	 */
	public function test_the_handler_checks_the_nonce(): void {
		$updater = $this->register();

		$this->post( 'activate', [ 'sugarcommerce_updater_seller_nonce' => 'forged' ] );

		try {
			$updater->handle();
			$this->fail( 'It went on.' );
		} catch ( Updater_Died $died ) {
			$this->assertSame( 403, $died->status );
		}

		$this->assertSame( [], $this->asked() );
	}

	/**
	 * Another plugin's nonce is not this one's.
	 */
	public function test_another_plugins_nonce_is_refused(): void {
		$updater = $this->register();

		$this->post( 'activate', [ 'sugarcommerce_updater_seller_nonce' => 'nonce-sugarcommerce_updater_other_license' ] );
		$this->expectException( Updater_Died::class );

		$updater->handle();
	}

	/**
	 * Activating from the form: the key is sent, the outcome waits for the
	 * page it goes back to, and is shown there once.
	 */
	public function test_activating_from_the_form(): void {
		$updater = $this->register();

		$this->store( [ 'activate' => self::answer( true, 'activated' ) ] );
		$this->post( 'activate' );

		$this->assertSame( 'https://site.test/wp-admin/admin.php?page=shop-settings', $updater->handle() );
		$this->assertSame( 'https://site.test/wp-admin/admin.php?page=shop-settings', $GLOBALS['up_redirect'] );
		$this->assertSame( 'active', $updater->state()['status'] );

		$notice = $this->printed( static fn() => do_action( 'admin_notices' ) );

		$this->assertStringContainsString( 'notice-success', $notice );
		$this->assertStringContainsString( 'Licence activated.', $notice );
		$this->assertStringNotContainsString( 'Licence activated.', $this->printed( static fn() => do_action( 'admin_notices' ) ) );
	}

	/**
	 * A refusal comes back as the reason, in words.
	 */
	public function test_a_refusal_from_the_form(): void {
		$updater = $this->register();

		$this->store( [ 'activate' => self::answer( false, 'limit_reached' ) ] );
		$this->post( 'activate' );
		$updater->handle();

		$notice = $this->printed( static fn() => do_action( 'admin_notices' ) );

		$this->assertStringContainsString( 'notice-error', $notice );
		$this->assertStringContainsString( 'as many sites as it allows', $notice );
	}

	/**
	 * Deactivating from the form.
	 */
	public function test_deactivating_from_the_form(): void {
		$updater = $this->register();

		$this->activated( $updater );
		$this->store( [ 'deactivate' => self::answer( true, 'deactivated' ) ] );
		$this->post( 'deactivate' );
		$updater->handle();

		$this->assertSame( 'inactive', $updater->state()['status'] );
		$this->assertStringContainsString( 'Licence deactivated on this site.', $this->printed( static fn() => do_action( 'admin_notices' ) ) );
	}

	/**
	 * With no referer, back to where the licence lives.
	 */
	public function test_without_a_referer_it_goes_to_the_settings(): void {
		$updater = $this->register( [ 'settings_url' => 'https://site.test/wp-admin/admin.php?page=shop-licence' ] );

		$this->store( [ 'activate' => self::answer( true, 'activated' ) ] );
		$this->post( 'activate' );
		unset( $_POST['_wp_http_referer'] );

		$this->assertSame( 'https://site.test/wp-admin/admin.php?page=shop-licence', $updater->handle() );
	}

	/**
	 * The handler listens on admin_post_{id}_license.
	 */
	public function test_the_handler_is_hooked_to_admin_post(): void {
		$this->register();

		$this->assertTrue( has_filter( 'admin_post_sugarcommerce_updater_seller_license' ) );
	}

	/**
	 * Each state has its notice, and an active licence far from its end
	 * has none.
	 *
	 * @param string   $status  The status.
	 * @param array    $more    More of the state.
	 * @param string   $key     The notice expected, or '' for none.
	 * @param string   $type    Its type.
	 * @param string[] $words   What it says.
	 */
	#[DataProvider( 'notices' )]
	public function test_each_state_has_its_notice( string $status, array $more, string $key, string $type, array $words ): void {
		$updater = $this->register(
			[
				'renew_url'    => 'https://store.example/renew',
				'account_url'  => 'https://store.example/account',
				'settings_url' => 'https://site.test/wp-admin/admin.php?page=shop-licence',
			]
		);

		$this->state( $updater, $status, $more );

		$due = $updater->notices();

		if ( '' === $key ) {
			$this->assertSame( [], $due );

			return;
		}

		$this->assertSame( [ $key ], array_keys( $due ) );
		$this->assertSame( $type, $due[ $key ]['type'] );

		foreach ( $words as $word ) {
			$this->assertStringContainsString( $word, $due[ $key ]['message'] );
		}
	}

	/**
	 * The states and their notices.
	 *
	 * @return array<string, array{string, array, string, string, string[]}>
	 */
	public static function notices(): array {
		return [
			'no key'         => [ 'none', [], 'missing', 'warning', [ 'Enter your licence key for Seller Pro', 'href="https://site.test/wp-admin/admin.php?page=shop-licence"', 'Enter Licence Key' ] ],
			'unknown key'    => [ 'invalid', [ 'reason' => 'not_found' ], 'missing', 'warning', [ 'Enter your licence key' ] ],
			'deactivated'    => [ 'inactive', [ 'reason' => 'deactivated' ], 'missing', 'warning', [ 'Enter your licence key' ] ],
			'expired'        => [ 'expired', [ 'expires_at' => 1735689600 ], 'expired', 'error', [ 'expired on 1 January 2025', 'href="https://store.example/renew"', 'Renew Licence' ] ],
			'past its date'  => [ 'active', [ 'expires_at' => time() - 60 ], 'expired', 'error', [ 'expired on' ] ],
			'expiring'       => [ 'active', [ 'expires_at' => time() + 10 * DAY_IN_SECONDS ], 'expiring', 'warning', [ 'ends on', 'Renew Licence' ] ],
			'thirty days'    => [ 'active', [ 'expires_at' => time() + 30 * DAY_IN_SECONDS ], 'expiring', 'warning', [ 'ends on' ] ],
			'thirty-one'     => [ 'active', [ 'expires_at' => time() + 31 * DAY_IN_SECONDS ], '', '', [] ],
			'no end'         => [ 'active', [ 'expires_at' => null ], '', '', [] ],
			'revoked'        => [ 'revoked', [ 'reason' => 'revoked' ], 'revoked', 'error', [ 'has been revoked', 'href="https://store.example/account"', 'View Your Account' ] ],
			'seat taken'     => [ 'inactive', [ 'reason' => 'location_not_active' ], 'released', 'warning', [ 'removed from your licence', 'Activate Licence' ] ],
		];
	}

	/**
	 * Printed on the screens they belong on, and not elsewhere.
	 */
	public function test_notices_are_shown_on_their_screens_only(): void {
		$this->register( [ 'settings_url' => 'https://site.test/wp-admin/admin.php?page=shop-licence' ] );

		$GLOBALS['up_screen'] = 'edit-post';
		$this->assertSame( '', $this->printed( static fn() => do_action( 'admin_notices' ) ) );

		foreach ( [ 'dashboard', 'plugins', 'update-core' ] as $screen ) {
			$GLOBALS['up_screen'] = $screen;
			$this->assertStringContainsString( 'data-notice="missing"', $this->printed( static fn() => do_action( 'admin_notices' ) ), $screen );
		}

		$GLOBALS['up_screen'] = 'toplevel_page_shop';
		$GLOBALS['pagenow']   = 'admin.php';
		$_GET['page']         = 'shop-licence';
		$this->assertStringContainsString( 'data-notice="missing"', $this->printed( static fn() => do_action( 'admin_notices' ) ) );

		$_GET['page'] = 'shop-orders';
		$this->assertSame( '', $this->printed( static fn() => do_action( 'admin_notices' ) ) );
	}

	/**
	 * Only to people who can do something about it.
	 */
	public function test_notices_need_the_capability(): void {
		$this->register();

		$GLOBALS['up_screen'] = 'dashboard';
		$GLOBALS['up_can']    = [];

		$this->assertSame( '', $this->printed( static fn() => do_action( 'admin_notices' ) ) );
	}

	/**
	 * The notices filter can take them away; the setting can switch them off.
	 */
	public function test_notices_can_be_filtered_or_switched_off(): void {
		$this->register( [ 'prefix' => 'myshop' ] );

		$GLOBALS['up_screen'] = 'dashboard';

		add_filter( 'myshop_notices', static fn( $notices ) => array_diff_key( $notices, [ 'missing' => true ] ) );
		$this->assertSame( '', $this->printed( static fn() => do_action( 'admin_notices' ) ) );

		updater_test_reset();
		$this->register( [ 'notices' => false ] );

		$GLOBALS['up_screen'] = 'dashboard';
		$this->assertSame( '', $this->printed( static fn() => do_action( 'admin_notices' ) ) );
	}

	/**
	 * The notice screens are filterable.
	 */
	public function test_the_notice_screens_filter(): void {
		$this->register();

		add_filter( 'sugarcommerce_updater_notice_screens', static fn( $screens ) => [ 'edit-post' ] );

		$GLOBALS['up_screen'] = 'edit-post';
		$this->assertStringContainsString( 'data-notice="missing"', $this->printed( static fn() => do_action( 'admin_notices' ) ) );
	}

	/**
	 * Without a settings page, the plugin row is the form.
	 */
	public function test_the_row_is_the_form_without_a_settings_page(): void {
		$updater = $this->register();

		$GLOBALS['wp_list_table'] = new class() {
			/**
			 * The table's columns.
			 *
			 * @return int
			 */
			public function get_column_count(): int {
				return 3;
			}
		};

		$row = $this->printed( static fn() => do_action( 'after_plugin_row_seller/seller.php' ) );

		$this->assertStringContainsString( '<tr class="plugin-update-tr active sugarcommerce_updater_seller-row">', $row );
		$this->assertStringContainsString( 'colspan="3"', $row );
		$this->assertStringContainsString( 'formaction=', $row );
		$this->assertStringContainsString( 'name="sugarcommerce_updater_seller_key"', $row );
		$this->assertStringNotContainsString( '<form', $row, 'The Plugins screen is already a form.' );

		$this->state( $updater, 'active' );
		$this->assertStringContainsString( 'Deactivate Licence', $this->printed( static fn() => do_action( 'after_plugin_row_seller/seller.php' ) ) );
	}

	/**
	 * With a settings page, the row only speaks for no key or an expired
	 * licence, and links there.
	 */
	public function test_the_row_with_a_settings_page(): void {
		$updater = $this->register( [ 'settings_url' => 'https://site.test/wp-admin/admin.php?page=shop-licence' ] );

		$row = $this->printed( static fn() => do_action( 'after_plugin_row_seller/seller.php' ) );

		$this->assertStringContainsString( 'Enter your licence key to get updates for this plugin.', $row );
		$this->assertStringContainsString( 'href="https://site.test/wp-admin/admin.php?page=shop-licence"', $row );
		$this->assertStringContainsString( 'colspan="4"', $row );

		$this->state( $updater, 'expired', [ 'expires_at' => 1735689600 ] );
		$this->assertStringContainsString( 'Your licence expired on 1 January 2025.', $this->printed( static fn() => do_action( 'after_plugin_row_seller/seller.php' ) ) );

		$this->state( $updater, 'active' );
		$this->assertSame( '', $this->printed( static fn() => do_action( 'after_plugin_row_seller/seller.php' ) ) );
	}

	/**
	 * No row for somebody without the capability.
	 */
	public function test_the_row_needs_the_capability(): void {
		$this->register();

		$GLOBALS['up_can'] = [];

		$this->assertSame( '', $this->printed( static fn() => do_action( 'after_plugin_row_seller/seller.php' ) ) );
	}

	/**
	 * A Licence link beside Deactivate, when the licence has its own page.
	 */
	public function test_the_licence_link(): void {
		$this->register( [ 'settings_url' => 'https://site.test/wp-admin/admin.php?page=shop-licence' ] );

		$links = apply_filters( 'plugin_action_links_seller/seller.php', [ 'deactivate' => '<a>Deactivate</a>' ] );

		$this->assertSame( '<a href="https://site.test/wp-admin/admin.php?page=shop-licence">Licence</a>', $links['licence'] );

		updater_test_reset();
		$this->register();

		$this->assertSame( [ 'deactivate' => 'x' ], apply_filters( 'plugin_action_links_seller/seller.php', [ 'deactivate' => 'x' ] ) );
	}

	/**
	 * A key is shown by its last four characters.
	 */
	public function test_the_mask(): void {
		$this->assertSame( '••••••••••••ABCD', Admin::mask( 'KEY-1234-ABCD-EFGH-ABCD' ) );
		$this->assertSame( '•••••6789', Admin::mask( '123456789' ) );
		$this->assertSame( '•••', Admin::mask( 'abc' ) );
	}
}
