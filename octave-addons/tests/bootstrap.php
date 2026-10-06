<?php

/*
TEST BOOTSTRAP
-- Just enough of WordPress for the Performance modules to run under plain
-- PHP: hooks, options, transients, HTTP and cron are in-memory stand-ins,
-- while markup is handled by WordPress's real HTML API, loaded from
-- tests/.wp-core (run tests/fetch-wp-core.sh once) or from $OA_WP_CORE
-- Never shipped: the tests folder is export-ignored in .gitattributes
---------------------------------------------------------- */

error_reporting( E_ALL );

$oa_core = getenv( 'OA_WP_CORE' ) ?: __DIR__ . '/.wp-core';

if ( ! is_file( $oa_core . '/wp-includes/html-api/class-wp-html-tag-processor.php' ) ) {

	fwrite( STDERR, "WordPress HTML API not found. Run tests/fetch-wp-core.sh or set OA_WP_CORE.\n" );
	exit( 2 );

}

$oa_root = sys_get_temp_dir() . '/oa-tests-' . getmypid();

define( 'ABSPATH', $oa_root . '/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_CONTENT_DIR', $oa_root . '/wp-content' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'KB_IN_BYTES', 1024 );
define( 'MB_IN_BYTES', 1048576 );
define( 'OCTAVE_ADDONS_VERSION', 'test' );
define( 'OCTAVE_ADDONS_DIR', dirname( __DIR__ ) . '/' );
define( 'OCTAVE_ADDONS_URL', 'https://example.com/wp-content/plugins/octave-addons/' );
define( 'OCTAVE_ADDONS_MODULES_DIR', OCTAVE_ADDONS_DIR . 'modules/' );
define( 'OCTAVE_ADDONS_OPTION_KEY', 'octave_addons_settings' );
define( 'OCTAVE_ADDONS_SLUG', 'octave-addons' );

@mkdir( WP_CONTENT_DIR, 0777, true );

register_shutdown_function( static function () use ( $oa_root ): void {

	exec( 'rm -rf ' . escapeshellarg( $oa_root ) );

} );

/*
TEST STATE
-- Reset before every test
---------------------------------------------------------- */

function oa_test_reset(): void {

	$GLOBALS['oa_filters']   = [];
	$GLOBALS['oa_actions']   = [];
	$GLOBALS['oa_options']   = [];
	$GLOBALS['oa_autoload']  = [];
	$GLOBALS['oa_transients'] = [];
	$GLOBALS['oa_cron']      = [];
	$GLOBALS['oa_http']      = null;
	$GLOBALS['oa_http_log']  = [];
	$GLOBALS['oa_caps']      = [];
	$GLOBALS['oa_flags']     = [];
	$GLOBALS['oa_deregistered'] = [];
	$GLOBALS['oa_enqueued']  = [];
	$GLOBALS['oa_localized'] = [];
	$GLOBALS['pagenow']      = 'index.php';
	$_GET                    = [];
	$_POST                   = [];
	$_SERVER['REQUEST_METHOD'] = 'GET';

	if ( class_exists( 'Octave_Addons_Perf_Html' ) ) {

		Octave_Addons_Perf_Html::reset();

	}

	exec( 'rm -rf ' . escapeshellarg( WP_CONTENT_DIR . '/cache' ) );

}

function oa_flag( string $name ): bool {

	return ! empty( $GLOBALS['oa_flags'][ $name ] );

}

/*
HOOKS
---------------------------------------------------------- */

function add_filter( $hook, $callback, $priority = 10, $accepted = 1 ) {

	$GLOBALS['oa_filters'][ $hook ][ $priority ][] = [ $callback, $accepted ];

	return true;

}

function add_action( $hook, $callback, $priority = 10, $accepted = 1 ) {

	return add_filter( $hook, $callback, $priority, $accepted );

}

function has_filter( $hook, $callback = false ) {

	return ! empty( $GLOBALS['oa_filters'][ $hook ] );

}

function apply_filters( $hook, $value, ...$args ) {

	$callbacks = $GLOBALS['oa_filters'][ $hook ] ?? [];

	ksort( $callbacks );

	foreach ( $callbacks as $list ) {

		foreach ( $list as [ $callback, $accepted ] ) {

			$value = call_user_func_array( $callback, array_slice( array_merge( [ $value ], $args ), 0, max( 1, (int) $accepted ) ) );

		}

	}

	return $value;

}

function do_action( $hook, ...$args ) {

	$GLOBALS['oa_actions'][ $hook ] = ( $GLOBALS['oa_actions'][ $hook ] ?? 0 ) + 1;

	apply_filters( $hook, $args[0] ?? null, ...array_slice( $args, 1 ) );

}

function did_action( $hook ) {

	return $GLOBALS['oa_actions'][ $hook ] ?? 0;

}

function __return_null() {

	return null;

}

/*
OPTIONS, TRANSIENTS, CRON
---------------------------------------------------------- */

function get_option( $name, $default = false ) {

	return array_key_exists( $name, $GLOBALS['oa_options'] ) ? $GLOBALS['oa_options'][ $name ] : $default;

}

function update_option( $name, $value, $autoload = null ) {

	$old = get_option( $name, [] );

	$GLOBALS['oa_options'][ $name ]  = $value;
	$GLOBALS['oa_autoload'][ $name ] = $autoload;

	do_action( 'update_option_' . $name, $old, $value );

	return true;

}

function add_option( $name, $value ) {

	return update_option( $name, $value );

}

function delete_option( $name ) {

	unset( $GLOBALS['oa_options'][ $name ] );

	return true;

}

function get_transient( $name ) {

	return $GLOBALS['oa_transients'][ $name ] ?? false;

}

function set_transient( $name, $value, $ttl = 0 ) {

	$GLOBALS['oa_transients'][ $name ] = $value;

	return true;

}

function delete_transient( $name ) {

	unset( $GLOBALS['oa_transients'][ $name ] );

	return true;

}

function delete_site_transient( $name ) {

	return delete_transient( $name );

}

function wp_next_scheduled( $hook ) {

	foreach ( $GLOBALS['oa_cron'] as $event ) {

		if ( $event['hook'] === $hook ) {

			return $event['time'];

		}

	}

	return false;

}

function wp_schedule_event( $time, $recurrence, $hook ) {

	$GLOBALS['oa_cron'][] = [ 'time' => $time, 'recurrence' => $recurrence, 'hook' => $hook ];

	return true;

}

function wp_schedule_single_event( $time, $hook ) {

	return wp_schedule_event( $time, false, $hook );

}

function wp_clear_scheduled_hook( $hook ) {

	$GLOBALS['oa_cron'] = array_values( array_filter( $GLOBALS['oa_cron'], static function ( $event ) use ( $hook ) {

		return $event['hook'] !== $hook;

	} ) );

	return 0;

}

/*
REQUEST CONTEXT
---------------------------------------------------------- */

function is_admin() {

	return oa_flag( 'admin' );

}

function wp_doing_ajax() {

	return oa_flag( 'ajax' );

}

function wp_doing_cron() {

	return oa_flag( 'cron' );

}

function wp_is_json_request() {

	return oa_flag( 'rest' );

}

function is_feed() {

	return oa_flag( 'feed' );

}

function is_robots() {

	return false;

}

function is_trackback() {

	return false;

}

function is_favicon() {

	return false;

}

function is_embed() {

	return false;

}

function is_preview() {

	return false;

}

function is_customize_preview() {

	return false;

}

function is_cart() {

	return oa_flag( 'cart' );

}

function is_checkout() {

	return false;

}

function is_account_page() {

	return false;

}

function is_user_logged_in() {

	return oa_flag( 'logged_in' );

}

function current_user_can( $cap ) {

	return in_array( $cap, $GLOBALS['oa_caps'], true );

}

function get_current_user_id() {

	return 1;

}


/*
NONCES AND JSON RESPONSES
---------------------------------------------------------- */

class OA_Test_Json_Response extends Exception {

	public bool $success;
	public $data;
	public ?int $status;

	public function __construct( bool $success, $data, ?int $status ) {

		parent::__construct( 'json' );

		$this->success = $success;
		$this->data    = $data;
		$this->status  = $status;

	}

}

function wp_create_nonce( $action ) {

	return 'valid-' . $action;

}

function check_ajax_referer( $action, $arg = false, $die = true ) {

	$ok = ( $_POST[ $arg ] ?? $_GET[ $arg ] ?? '' ) === 'valid-' . $action;

	if ( ! $ok && $die ) {

		throw new OA_Test_Json_Response( false, 'bad-nonce', 403 );

	}

	return $ok ? 1 : false;

}

function wp_send_json_success( $data = null, $status = null ) {

	throw new OA_Test_Json_Response( true, $data, $status );

}

function wp_send_json_error( $data = null, $status = null ) {

	throw new OA_Test_Json_Response( false, $data, $status );

}

/*
FORMATTING
---------------------------------------------------------- */

function __( $text ) {

	return $text;

}

function _n( $single, $plural, $number ) {

	return 1 === (int) $number ? $single : $plural;

}

function esc_html__( $text ) {

	return $text;

}

function esc_attr__( $text ) {

	return $text;

}

function esc_html( $text ) {

	return htmlspecialchars( (string) $text, ENT_QUOTES );

}

function esc_attr( $text ) {

	return htmlspecialchars( (string) $text, ENT_QUOTES );

}

function esc_textarea( $text ) {

	return htmlspecialchars( (string) $text, ENT_QUOTES );

}

function esc_url( $url ) {

	return 0 === stripos( trim( (string) $url ), 'javascript:' ) ? '' : str_replace( '&', '&#038;', trim( (string) $url ) );

}

function esc_url_raw( $url, $protocols = null ) {

	$url = trim( (string) $url );

	if ( '' === $url || ( 0 !== strpos( $url, '/' ) && ! preg_match( '#^https?://#i', $url ) ) ) {

		return '';

	}

	return $url;

}

function sanitize_key( $key ) {

	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );

}

function sanitize_text_field( $text ) {

	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $text ) ) );

}

function sanitize_textarea_field( $text ) {

	return trim( strip_tags( (string) $text ) );

}

function sanitize_file_name( $name ) {

	return preg_replace( '/[^a-z0-9._-]/i', '', (string) $name );

}

function wp_strip_all_tags( $text ) {

	return trim( strip_tags( (string) $text ) );

}

function wp_unslash( $value ) {

	return is_string( $value ) ? stripslashes( $value ) : $value;

}

function absint( $value ) {

	return abs( (int) $value );

}

function wp_parse_url( $url, $component = -1 ) {

	return parse_url( (string) $url, $component );

}

function wp_parse_args( $args, $defaults = [] ) {

	return array_merge( $defaults, (array) $args );

}

function trailingslashit( $value ) {

	return rtrim( (string) $value, '/\\' ) . '/';

}

function untrailingslashit( $value ) {

	return rtrim( (string) $value, '/\\' );

}

function wp_json_encode( $value ) {

	return json_encode( $value );

}

function size_format( $bytes ) {

	return $bytes . ' B';

}

function human_time_diff( $from, $to ) {

	return ( $to - $from ) . ' seconds';

}

function wp_hash( $data ) {

	return hash_hmac( 'md5', (string) $data, 'test-salt' );

}

function wp_generate_password( $length = 12 ) {

	return substr( bin2hex( random_bytes( $length ) ), 0, $length );

}

function wp_mkdir_p( $dir ) {

	return is_dir( $dir ) || mkdir( $dir, 0777, true );

}

function add_query_arg( $key, $value = null, $url = null ) {

	$args = is_array( $key ) ? $key : [ $key => $value ];
	$url  = is_array( $key ) ? (string) $value : (string) $url;

	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );

}

function wp_list_pluck( $list, $field ) {

	return array_column( $list, $field );

}

function get_bloginfo( $show ) {

	return '7.1';

}

function home_url( $path = '' ) {

	return 'https://example.com' . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );

}

function site_url( $path = '' ) {

	return home_url( $path );

}

function content_url( $path = '' ) {

	return 'https://example.com/wp-content' . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );

}

function admin_url( $path = '' ) {

	return 'https://example.com/wp-admin/' . ltrim( $path, '/' );

}

function wp_deregister_script( $handle ) {

	$GLOBALS['oa_deregistered'][] = $handle;

}

function wp_enqueue_script( $handle ) {

	$GLOBALS['oa_enqueued'][] = $handle;

}

function wp_enqueue_style( $handle ) {

	$GLOBALS['oa_enqueued'][] = $handle;

}

function wp_script_is( $handle ) {

	return false;

}

function wp_style_is( $handle ) {

	return false;

}

function wp_script_add_data( $handle, $key, $value ) {

	return true;

}

function wp_localize_script( $handle, $name, $data ) {

	$GLOBALS['oa_localized'][ $name ] = $data;

}

function _doing_it_wrong( ...$args ) {}
function wp_has_noncharacters( $text ) {

	return false;

}

function wp_html_api_script_element_escaping_diagram_source() {

	return '';

}

function wp_kses_uri_attributes() {

	return [ 'action', 'cite', 'data', 'formaction', 'href', 'poster', 'src', 'srcset', 'xmlns' ];

}


/*
HTTP
-- $GLOBALS['oa_http'] is a callable( url, args ) returning a response array
-- or WP_Error; every request is logged
---------------------------------------------------------- */

class WP_Error {

	protected string $message;

	public function __construct( $code = '', $message = '' ) {

		$this->message = (string) $message;

	}

	public function get_error_message() {

		return $this->message;

	}

}

function is_wp_error( $value ) {

	return $value instanceof WP_Error;

}

function wp_remote_request( $url, $args = [] ) {

	$GLOBALS['oa_http_log'][] = [ 'url' => $url, 'args' => $args ];

	return is_callable( $GLOBALS['oa_http'] ) ? call_user_func( $GLOBALS['oa_http'], $url, $args ) : new WP_Error( 'none', 'No HTTP handler' );

}

function wp_safe_remote_get( $url, $args = [] ) {

	return wp_remote_request( $url, $args + [ 'method' => 'GET' ] );

}

function wp_remote_get( $url, $args = [] ) {

	return wp_safe_remote_get( $url, $args );

}

function wp_remote_retrieve_response_code( $response ) {

	return $response['response']['code'] ?? 0;

}

function wp_remote_retrieve_body( $response ) {

	return $response['body'] ?? '';

}

function wp_remote_retrieve_header( $response, $name ) {

	return $response['headers'][ strtolower( $name ) ] ?? '';

}

function oa_http_response( int $code, string $body, string $type = 'application/json' ): array {

	return [ 'response' => [ 'code' => $code ], 'body' => $body, 'headers' => [ 'content-type' => $type ] ];

}

/*
WORDPRESS HTML API
---------------------------------------------------------- */

foreach ( [
	'class-wp-token-map.php',
	'html-api/html5-named-character-references.php',
	'html-api/class-wp-html-attribute-token.php',
	'html-api/class-wp-html-span.php',
	'html-api/class-wp-html-text-replacement.php',
	'html-api/class-wp-html-decoder.php',
	'html-api/class-wp-html-doctype-info.php',
	'html-api/class-wp-html-tag-processor.php',
] as $oa_file ) {

	require_once $oa_core . '/wp-includes/' . $oa_file;

}

/*
PLUGIN
-- Real discovery through the module manager, so the grouping tests see
-- exactly what the admin would
---------------------------------------------------------- */

oa_test_reset();

require_once OCTAVE_ADDONS_DIR . 'includes/class-module.php';
require_once OCTAVE_ADDONS_DIR . 'includes/class-module-manager.php';

$GLOBALS['oa_manager'] = new Octave_Addons_Module_Manager();

function oa_module( string $id ): Octave_Addons_Module {

	$module = $GLOBALS['oa_manager']->get( $id );

	if ( ! $module ) {

		throw new RuntimeException( 'Module not discovered: ' . $id );

	}

	return $module;

}

/*
SETTINGS HELPER
-- Stores a module's settings, merged with its defaults, as a save would
---------------------------------------------------------- */

function oa_set_settings( string $id, array $values ): array {

	$module   = oa_module( $id );
	$settings = array_merge( $module->get_defaults(), $values );
	$all      = get_option( OCTAVE_ADDONS_OPTION_KEY, [] );

	$all[ $id ] = $settings;
	$GLOBALS['oa_options'][ OCTAVE_ADDONS_OPTION_KEY ] = $all;

	return $settings;

}
