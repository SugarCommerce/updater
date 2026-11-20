<?php
/**
 * PHPUnit bootstrap.
 *
 * This is a library, not a plugin: there is no WordPress to load. The
 * functions the updater calls are stubbed here, as close to core's
 * behaviour as the tests depend on: hooks that really run, options and
 * transients in globals, a site at https://site.test, a capability check and
 * nonces the test controls, a cron that remembers what was scheduled, and
 * an HTTP API that asks `pre_http_request` first, as core's does -- which
 * is how each test plays the store.
 *
 * @package SugarCommerce\Updater
 */

declare( strict_types=1 );

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WP_PLUGIN_DIR', __DIR__ . '/fixtures/plugins' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

// Checked out as on the workbench: wp-content/plugins/libraries/sugarcommerce/updater.
define( 'WP_CONTENT_DIR', dirname( __DIR__, 5 ) );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

/**
 * Put every stubbed global back as a fresh site has it.
 */
function updater_test_reset(): void {
	$GLOBALS['up_hooks']       = [];
	$GLOBALS['up_options']     = [];
	$GLOBALS['up_autoload']    = [];
	$GLOBALS['up_transients']  = [];
	$GLOBALS['up_site']        = [ 'update_plugins' => (object) [ 'response' => [] ] ];
	$GLOBALS['up_requests']    = [];
	$GLOBALS['up_cron']        = [];
	$GLOBALS['up_deactivate']  = [];
	$GLOBALS['up_can']         = [ 'activate_plugins' => true ];
	$GLOBALS['up_user']        = 1;
	$GLOBALS['up_admin']       = true;
	$GLOBALS['up_environment'] = 'production';
	$GLOBALS['up_redirect']    = null;
	$GLOBALS['up_screen']      = null;
	$GLOBALS['up_logged_in']   = true;
	$GLOBALS['up_scripts']     = [];
	$GLOBALS['up_routes']      = [];
	$GLOBALS['up_dns']         = [
		'store.example' => [ '93.184.216.34' ],
		'site.test'     => [ '127.0.0.1' ],
	];
	$GLOBALS['pagenow']        = 'index.php';

	$_GET    = [];
	$_POST   = [];
	$_SERVER = array_diff_key( $_SERVER, [ 'HTTP_REFERER' => true ] );

	unset( $GLOBALS['wp_list_table'] );

	SugarCommerce\Updater\Updater::reset();
}

/**
 * DNS in tests: the table in up_dns; an IP literal is itself.
 *
 * @param string $host A host.
 *
 * @return string[]
 */
function updater_test_resolve( string $host ): array {
	return $GLOBALS['up_dns'][ strtolower( $host ) ] ?? ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ? [ $host ] : [] );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public mixed $data = '' ) {}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

/**
 * What wp_die() was called with, thrown so the handler stops where core's would.
 */
final class Updater_Died extends RuntimeException {

	public function __construct( public string $body, public int $status ) {
		parent::__construct( $body );
	}
}

// Hooks.

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['up_hooks'][ $hook ][ (int) $priority ][] = [ $callback, (int) $accepted_args ];
		ksort( $GLOBALS['up_hooks'][ $hook ] );

		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $hook, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( $hook, $callback, $priority = 10 ) {
		foreach ( $GLOBALS['up_hooks'][ $hook ][ (int) $priority ] ?? [] as $index => [ $registered ] ) {
			if ( $registered === $callback ) {
				unset( $GLOBALS['up_hooks'][ $hook ][ (int) $priority ][ $index ] );
			}
		}

		return true;
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook ) {
		return ! empty( $GLOBALS['up_hooks'][ $hook ] );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		foreach ( $GLOBALS['up_hooks'][ $hook ] ?? [] as $callbacks ) {
			foreach ( $callbacks as [ $callback, $accepted ] ) {
				$value = $callback( ...array_slice( array_merge( [ $value ], $args ), 0, max( 1, $accepted ) ) );
			}
		}

		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		foreach ( $GLOBALS['up_hooks'][ $hook ] ?? [] as $callbacks ) {
			foreach ( $callbacks as [ $callback, $accepted ] ) {
				$callback( ...array_slice( $args, 0, $accepted ) );
			}
		}
	}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( $file, $callback ) {
		$GLOBALS['up_deactivate'][ plugin_basename( $file ) ][] = $callback;
	}
}

// Options, transients, cron.

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		if ( 'date_format' === $key ) {
			return 'j F Y';
		}

		return $GLOBALS['up_options'][ $key ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['up_options'][ $key ]  = $value;
		$GLOBALS['up_autoload'][ $key ] = $autoload;

		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['up_transients'][ $key ][0] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['up_transients'][ $key ] = [ $value, $expiration ];

		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['up_transients'][ $key ] );

		return true;
	}
}

if ( ! function_exists( 'delete_site_transient' ) ) {
	function delete_site_transient( $key ) {
		unset( $GLOBALS['up_site'][ $key ] );

		return true;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = [] ) {
		return $GLOBALS['up_cron'][ $hook . serialize( $args ) ]['time'] ?? false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = [] ) {
		$GLOBALS['up_cron'][ $hook . serialize( $args ) ] = [
			'time'       => $timestamp,
			'recurrence' => $recurrence,
			'hook'       => $hook,
			'args'       => $args,
		];

		return true;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook, $args = [] ) {
		unset( $GLOBALS['up_cron'][ $hook . serialize( $args ) ] );

		return 1;
	}
}

// HTTP: core's order. pre_http_request is asked first, and a test that
// does not answer it gets a refusal, never a real request.

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = [] ) {
		$GLOBALS['up_requests'][] = [ 'url' => $url, 'args' => $args ];

		$pre = apply_filters( 'pre_http_request', false, $args, $url );

		return false !== $pre ? $pre : new WP_Error( 'http_request_failed', 'No store in tests.' );
	}
}

// What network's SafeUrl calls: core's own safe variant, here the same as the plain
// one, with the arguments it was given remembered.
if ( ! function_exists( 'wp_safe_remote_post' ) ) {
	function wp_safe_remote_post( $url, $args = [] ) {
		$args['reject_unsafe_urls'] = true;

		return wp_remote_post( $url, $args );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( $response, $header ) {
		return is_array( $response ) ? (string) ( array_change_key_case( (array) ( $response['headers'] ?? [] ) )[ strtolower( $header ) ] ?? '' ) : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}
}

// The site and who is using it.

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://site.test' . ( '' !== $path ? '/' . ltrim( (string) $path, '/' ) : '' );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'https://site.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return (bool) $GLOBALS['up_admin'];
	}
}

if ( ! function_exists( 'wp_get_environment_type' ) ) {
	function wp_get_environment_type() {
		return $GLOBALS['up_environment'];
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return (int) $GLOBALS['up_user'];
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability, ...$args ) {
		return ! empty( $GLOBALS['up_can'][ $capability ] );
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return (bool) $GLOBALS['up_logged_in'];
	}
}

if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen() {
		return null === $GLOBALS['up_screen'] ? null : (object) [ 'id' => $GLOBALS['up_screen'] ];
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) {
		return 'nonce-' . $action;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action = -1 ) {
		return 'nonce-' . $action === $nonce ? 1 : false;
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
		$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . wp_create_nonce( $action ) . '" />';

		if ( $referer ) {
			$field .= '<input type="hidden" name="_wp_http_referer" value="/wp-admin/plugins.php" />';
		}

		return $field;
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = [] ) {
		throw new Updater_Died( (string) $message, (int) ( $args['response'] ?? 500 ) );
	}
}

if ( ! function_exists( 'wp_get_referer' ) ) {
	function wp_get_referer() {
		$referer = $_POST['_wp_http_referer'] ?? $_SERVER['HTTP_REFERER'] ?? '';

		return '' !== $referer ? 'https://site.test' . $referer : false;
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( $location, $status = 302 ) {
		$GLOBALS['up_redirect'] = $location;

		return true;
	}
}

// Plugins.

if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $path ) {
		return (string) preg_replace( '|(?<=.)/+|', '/', str_replace( '\\', '/', (string) $path ) );
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		$file = wp_normalize_path( $file );
		$dir  = wp_normalize_path( WP_PLUGIN_DIR );

		return trim( (string) preg_replace( '#^' . preg_quote( $dir, '#' ) . '/#', '', $file ), '/' );
	}
}

if ( ! function_exists( '_cleanup_header_comment' ) ) {
	function _cleanup_header_comment( $str ) {
		return trim( (string) preg_replace( '/\s*(?:\*\/|\?>).*/', '', $str ) );
	}
}

if ( ! function_exists( 'get_file_data' ) ) {
	function get_file_data( $file, $default_headers, $context = '' ) {
		$file_data = str_replace( "\r", "\n", (string) file_get_contents( $file, false, null, 0, 8192 ) );
		$headers   = [];

		foreach ( $default_headers as $field => $regex ) {
			if ( preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $regex, '/' ) . ':(.*)$/mi', $file_data, $match ) && $match[1] ) {
				$headers[ $field ] = _cleanup_header_comment( $match[1] );
			} else {
				$headers[ $field ] = '';
			}
		}

		return $headers;
	}
}

// Text, URLs and escaping.

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url, $protocols = null ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );

		return in_array( $scheme, $protocols ?? [ 'http', 'https' ], true ) ? $url : '';
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return str_replace( '&', '&#038;', esc_url_raw( $url ) );
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $text ) {
		return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $text ) ) );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $text ) {
		return strip_tags( (string) $text, '<div><p><a><strong><em><code><br><ul><ol><li><h3><h4><tr><td><input><button><label><form><span>' );
	}
}

if ( ! function_exists( 'wpautop' ) ) {
	function wpautop( $text, $br = true ) {
		$paragraphs = preg_split( '/\n\s*\n/', trim( (string) $text ) );

		return implode( "\n", array_map( static fn( $p ) => '<p>' . str_replace( "\n", "<br />\n", $p ) . '</p>', $paragraphs ) ) . "\n";
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $timestamp = null ) {
		return gmdate( $format, $timestamp ?? time() );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $name ) {
		return (string) preg_replace( '/[^A-Za-z0-9._-]/', '', str_replace( ' ', '-', (string) $name ) );
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = [] ) {
		return array_merge( $defaults, is_array( $args ) ? $args : [] );
	}
}

/*
 * Core's admin notice functions, as WordPress 7.1 has them (wp-includes/functions.php),
 * trimmed of the type check that only warns, so a notice is tested against the
 * markup core really prints.
 */
if ( ! function_exists( 'wp_get_admin_notice' ) ) {
	function wp_get_admin_notice( $message, $args = [] ) {
		$args = wp_parse_args(
			$args,
			[
				'type'               => '',
				'dismissible'        => false,
				'id'                 => '',
				'additional_classes' => [],
				'attributes'         => [],
				'paragraph_wrap'     => true,
			]
		);

		$args       = apply_filters( 'wp_admin_notice_args', $args, $message );
		$classes    = 'notice';
		$attributes = '';

		if ( is_string( $args['type'] ) && '' !== trim( $args['type'] ) ) {
			$classes .= ' notice-' . trim( $args['type'] );
		}

		if ( true === $args['dismissible'] ) {
			$classes .= ' is-dismissible';
		}

		if ( is_array( $args['additional_classes'] ) && ! empty( $args['additional_classes'] ) ) {
			$classes .= ' ' . implode( ' ', $args['additional_classes'] );
		}

		foreach ( (array) $args['attributes'] as $attr => $val ) {
			if ( is_bool( $val ) ) {
				$attributes .= $val ? ' ' . $attr : '';
			} elseif ( is_int( $attr ) ) {
				$attributes .= ' ' . esc_attr( trim( $val ) );
			} elseif ( $val ) {
				$attributes .= ' ' . $attr . '="' . esc_attr( trim( $val ) ) . '"';
			}
		}

		if ( false !== $args['paragraph_wrap'] ) {
			$message = "<p>$message</p>";
		}

		return apply_filters( 'wp_admin_notice_markup', sprintf( '<div class="%1$s"%2$s>%3$s</div>', $classes, $attributes, $message ), $message, $args );
	}

	function wp_admin_notice( $message, $args = [] ) {
		do_action( 'wp_admin_notice', $message, $args );

		echo wp_kses_post( wp_get_admin_notice( $message, $args ) );
	}
}

// Scripts.

if ( ! function_exists( 'content_url' ) ) {
	function content_url( $path = '' ) {
		return 'https://site.test/wp-content' . ( '' !== $path ? '/' . ltrim( (string) $path, '/' ) : '' );
	}
}

if ( ! function_exists( 'site_url' ) ) {
	function site_url( $path = '' ) {
		return 'https://site.test' . ( '' !== $path ? '/' . ltrim( (string) $path, '/' ) : '' );
	}
}

if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( $handle, $src, $deps = [], $ver = false, $args = [] ) {
		$GLOBALS['up_scripts'][ $handle ] = [
			'src'      => $src,
			'deps'     => $deps,
			'enqueued' => false,
		];

		return true;
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle ) {
		$GLOBALS['up_scripts'][ $handle ]['enqueued'] = true;
	}
}

if ( ! function_exists( 'wp_set_script_translations' ) ) {
	function wp_set_script_translations( $handle, $domain = 'default', $path = '' ) {
		return true;
	}
}

// REST: enough of core's classes for the controller's callbacks.

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $route_namespace, $route, $args = [], $override = false ) {
		$GLOBALS['up_routes'][ '/' . $route_namespace . $route ] = $args;

		return true;
	}
}

if ( ! function_exists( 'rest_authorization_required_code' ) ) {
	function rest_authorization_required_code() {
		return is_user_logged_in() ? 403 : 401;
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		const READABLE  = 'GET';
		const CREATABLE = 'POST';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		public function __construct( private array $params = [] ) {}
		public function get_param( $key ) { return $this->params[ $key ] ?? null; }
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public array $headers = [];
		public function __construct( public mixed $data = null, public int $status = 200 ) {}
		public function get_data() { return $this->data; }
		public function get_status() { return $this->status; }
		public function header( $key, $value ) { $this->headers[ $key ] = $value; }
	}
}

updater_test_reset();
