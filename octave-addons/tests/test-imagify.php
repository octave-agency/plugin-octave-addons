<?php

/*
IMAGIFY TESTS
-- A stand-in Imagify with the same settings lifecycle: its save handler
-- compares the stored values with the submitted ones and adds or removes
-- its marked .htaccess blocks, exactly as Imagify's rewrite subscribers do
---------------------------------------------------------- */

class Imagify_Options {

	protected static ?Imagify_Options $instance = null;

	public static function get_instance(): self {

		return self::$instance ??= new self();

	}

	public function get_all(): array {

		return array_merge( [
			'api_key'                => 'secret',
			'backup'                 => 1,
			'auto_optimize'          => 1,
			'display_nextgen'        => 0,
			'display_nextgen_method' => 'picture',
			'optimization_format'    => 'webp',
			'cdn_url'                => '',
		], (array) get_option( 'imagify_settings', [] ) );

	}

	public function set( $values ): void {

		update_option( 'imagify_settings', array_merge( $this->get_all(), (array) $values ) );

	}

}

function get_imagify_option( $key ) {

	return Imagify_Options::get_instance()->get_all()[ $key ] ?? null;

}

/*
FAKE REWRITE SUBSCRIBER
-- Mirrors Imagify\Webp\RewriteRules\Display::maybe_add_rewrite_rules()
---------------------------------------------------------- */

function oa_fake_imagify_on_save( $values ) {

	$GLOBALS['oa_imagify_seen'][] = [ 'stored' => get_imagify_option( 'display_nextgen_method' ), 'submitted' => $values['display_nextgen_method'] ?? '' ];

	$was = get_imagify_option( 'display_nextgen' ) && 'rewrite' === get_imagify_option( 'display_nextgen_method' );
	$is  = ! empty( $values['display_nextgen'] ) && 'rewrite' === ( $values['display_nextgen_method'] ?? '' );

	if ( $is && ! $was ) {

		oa_fake_imagify_add_rules();

	} elseif ( $was && ! $is ) {

		$file = Octave_Addons_Perf_Imagify::htaccess_path();

		file_put_contents( $file, preg_replace( '/# BEGIN Imagify: rewrite rules.*?# END Imagify: rewrite rules for \w+\n/s', '', (string) @file_get_contents( $file ) ) );

	}

	return $values;

}

function oa_fake_imagify_add_rules(): void {

	$file = Octave_Addons_Perf_Imagify::htaccess_path();

	if ( ! is_writable( file_exists( $file ) ? $file : dirname( $file ) ) ) {

		return;

	}

	$rules = '';

	foreach ( [ 'webp', 'avif' ] as $format ) {

		$rules .= "# BEGIN Imagify: rewrite rules for $format\nRewriteRule .* - [T=image/$format]\n# END Imagify: rewrite rules for $format\n";

	}

	file_put_contents( $file, $rules . (string) @file_get_contents( $file ) );

}

function oa_imagify( string $server = 'Apache/2.4' ): void {

	if ( ! defined( 'IMAGIFY_VERSION' ) ) {

		define( 'IMAGIFY_VERSION', '2.3.4' );

		// A namespaced stand-in class cannot be declared beside global code in one file; the string is a fixed literal.
		eval( 'namespace Imagify\Avif\RewriteRules; class Display {}' );

	}

	$_SERVER['SERVER_SOFTWARE'] = $server;
	$GLOBALS['oa_imagify_seen'] = [];

	@unlink( Octave_Addons_Perf_Imagify::htaccess_path() );
	file_put_contents( Octave_Addons_Perf_Imagify::htaccess_path(), "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n" );
	chmod( Octave_Addons_Perf_Imagify::htaccess_path(), 0644 );

	add_filter( 'imagify_settings_on_save', 'oa_fake_imagify_on_save' );
	add_action( 'imagify_activation', 'oa_fake_imagify_add_rules' );

	$GLOBALS['oa_http'] = static function () {

		return oa_http_response( 200, '<html></html>', 'text/html' );

	};

}

function oa_imagify_save( array $values ): void {

	$module   = oa_module( 'performance-imagify' );
	$old      = get_option( OCTAVE_ADDONS_OPTION_KEY, [] );
	$settings = oa_set_settings( 'performance-imagify', array_merge( [ 'enabled' => true ], $values ) );
	$new      = get_option( OCTAVE_ADDONS_OPTION_KEY, [] );

	$module->run( $settings );

	do_action( 'update_option_' . OCTAVE_ADDONS_OPTION_KEY, $old, $new );

}

/*
AVAILABILITY
-- Runs before any test defines IMAGIFY_VERSION
---------------------------------------------------------- */

function test_imagify_absent_hides_the_module_and_changes_nothing(): void {

	if ( defined( 'IMAGIFY_VERSION' ) ) {

		return;

	}

	oa_assert( ! oa_module( 'performance-imagify' )->show_in_admin(), 'hidden' );
	oa_assert_same( 'Imagify is not active.', Octave_Addons_Perf_Imagify::unavailable_reason() );

	$result = Octave_Addons_Perf_Imagify::apply( [ 'optimization_format' => 'avif' ] );

	oa_assert( ! $result['ok'] && ! $result['changed'] );
	oa_assert_same( false, get_option( 'imagify_settings' ), 'nothing written' );

}

function test_unsupported_imagify_version_or_api_is_refused(): void {

	oa_assert_contains( 'update it to 2.2.2 or newer', Octave_Addons_Perf_Imagify::api_problem( '2.1.9', true ) );
	oa_assert_contains( 'cannot be set up by Octave', Octave_Addons_Perf_Imagify::api_problem( '2.3.4', false ) );
	oa_assert_same( '', Octave_Addons_Perf_Imagify::api_problem( '2.3.4', true ) );

}

function test_imagify_active_shows_the_module_with_automatic_delivery(): void {

	oa_imagify();

	$module = oa_module( 'performance-imagify' );

	oa_assert( $module->show_in_admin(), 'shown' );
	oa_assert( Octave_Addons_Perf_Imagify::supports_avif() );
	oa_assert_same( [ 'display_nextgen_method' => 'rewrite' ], Octave_Addons_Perf_Imagify::desired( $module->get_defaults() ), 'Apache: Imagify\'s rules, format left to Imagify' );
	oa_assert_same( [ 'enabled' => false, 'format' => 'keep', 'delivery' => 'auto' ], $module->sanitize( [ 'format' => 'jpeg2000', 'delivery' => 'magic' ] ) );

	$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';
	oa_set_settings( 'performance-imagify', [ 'enabled' => true ] );

	oa_assert( Octave_Addons_Perf_Imagify::octave_delivery(), 'by default, Octave delivers where rules cannot' );

}

/*
SYNCHRONISATION
---------------------------------------------------------- */

function test_webp_with_picture_delivery_saves_only_octave_keys_through_the_lifecycle(): void {

	oa_imagify();
	update_option( 'imagify_settings', [ 'display_nextgen_method' => 'rewrite', 'api_key' => 'mine', 'backup' => 0 ] );

	oa_imagify_save( [ 'format' => 'webp', 'delivery' => 'picture' ] );

	$saved = get_option( 'imagify_settings' );

	oa_assert_same( 'webp', $saved['optimization_format'] );
	oa_assert_same( 1, $saved['display_nextgen'] );
	oa_assert_same( 'picture', $saved['display_nextgen_method'] );
	oa_assert_same( 'mine', $saved['api_key'], 'other settings preserved' );
	oa_assert_same( 0, $saved['backup'], 'other settings preserved' );
	oa_assert_same( [ [ 'stored' => 'rewrite', 'submitted' => 'picture' ] ], $GLOBALS['oa_imagify_seen'], 'save handlers saw the previous values' );
	oa_assert_not_contains( 'Imagify: rewrite rules', file_get_contents( Octave_Addons_Perf_Imagify::htaccess_path() ), 'picture delivery writes no rules' );
	oa_assert( Octave_Addons_Perf_Imagify::status()['sync']['ok'] );

}

function test_avif_with_rewrite_delivery_adds_imagify_markers_and_purges(): void {

	oa_imagify( 'LiteSpeed' );

	oa_imagify_save( [ 'format' => 'avif', 'delivery' => 'rewrite' ] );

	$saved = get_option( 'imagify_settings' );

	oa_assert_same( 'avif', $saved['optimization_format'] );
	oa_assert_same( 'rewrite', $saved['display_nextgen_method'] );
	oa_assert_same( [], Octave_Addons_Perf_Imagify::missing_markers( $saved ), 'Imagify wrote both blocks' );
	oa_assert_contains( '# BEGIN WordPress', file_get_contents( Octave_Addons_Perf_Imagify::htaccess_path() ), 'existing rules kept' );
	oa_assert_same( 1, did_action( 'octave_addons_perf_purged_all' ), 'caches purged after success' );
	oa_assert_same( 1, count( $GLOBALS['oa_http_log'] ), 'one health check' );
	oa_assert_same( false, get_option( Octave_Addons_Perf_Imagify::LOCK_OPTION ), 'lock released' );
	oa_assert_same( "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n", get_option( Octave_Addons_Perf_Imagify::BACKUP_OPTION )['htaccess'], 'previous file kept privately' );

}

function test_no_change_synchronisation_does_nothing(): void {

	oa_imagify();
	update_option( 'imagify_settings', [ 'optimization_format' => 'webp', 'display_nextgen' => '1', 'display_nextgen_method' => 'picture' ] );

	$result = Octave_Addons_Perf_Imagify::apply( [ 'optimization_format' => 'webp', 'display_nextgen' => 1, 'display_nextgen_method' => 'picture' ] );

	oa_assert( $result['ok'] && ! $result['changed'] );
	oa_assert_same( [], $GLOBALS['oa_imagify_seen'], 'lifecycle not run' );
	oa_assert_same( 0, did_action( 'octave_addons_perf_purged_all' ), 'nothing purged' );

}

function test_turning_next_gen_off_submits_the_switch_as_imagify_forms_do(): void {

	oa_imagify();
	update_option( 'imagify_settings', [ 'display_nextgen' => 1, 'display_nextgen_method' => 'rewrite' ] );
	oa_fake_imagify_add_rules();

	add_filter( 'imagify_settings_on_save', static function ( $values ) {

		$GLOBALS['oa_imagify_keys'] = array_key_exists( 'display_nextgen', $values );

		return $values;

	} );

	Octave_Addons_Perf_Imagify::apply( Octave_Addons_Perf_Imagify::desired( [ 'format' => 'off', 'delivery' => 'keep' ] ) );

	oa_assert_same( false, $GLOBALS['oa_imagify_keys'], 'unticked switch left out' );
	oa_assert_same( 0, get_option( 'imagify_settings' )['display_nextgen'] );
	oa_assert_same( 'off', get_option( 'imagify_settings' )['optimization_format'] );
	oa_assert_not_contains( 'Imagify: rewrite rules', file_get_contents( Octave_Addons_Perf_Imagify::htaccess_path() ), 'Imagify removed its own rules' );

}

function test_http_500_after_a_rewrite_restores_htaccess_and_settings(): void {

	oa_imagify();

	$before = file_get_contents( Octave_Addons_Perf_Imagify::htaccess_path() );

	$GLOBALS['oa_http'] = static function () {

		return oa_http_response( 500, 'Internal Server Error', 'text/html' );

	};

	$result = Octave_Addons_Perf_Imagify::apply( [ 'display_nextgen' => 1, 'display_nextgen_method' => 'rewrite' ] );

	oa_assert( ! $result['ok'] );
	oa_assert_contains( 'stopped working', $result['message'] );
	oa_assert_same( $before, file_get_contents( Octave_Addons_Perf_Imagify::htaccess_path() ), 'file restored' );
	oa_assert_same( 0, (int) get_option( 'imagify_settings' )['display_nextgen'], 'settings restored' );
	oa_assert_same( 0, did_action( 'octave_addons_perf_purged_all' ), 'no purge after a failure' );

}

/*
DELIVERY CHOICE
---------------------------------------------------------- */

function test_automatic_delivery_uses_rewrite_rules_only_when_safe(): void {

	oa_imagify( 'Apache/2.4.58' );

	oa_assert_same( 'rewrite', Octave_Addons_Perf_Imagify::choose_method()['method'], 'Apache with a writable .htaccess' );

	$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';

	oa_assert_same( 'rewrite', Octave_Addons_Perf_Imagify::choose_method()['method'], 'LiteSpeed reads .htaccess too' );

	$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';

	$choice = Octave_Addons_Perf_Imagify::choose_method();

	oa_assert_same( 'octave', $choice['method'], 'Octave\'s URL rewriting, never picture tags' );
	oa_assert_contains( 'Nginx', $choice['reasons'][0] );
	oa_assert_same( [], Octave_Addons_Perf_Imagify::expected_markers( [ 'display_nextgen' => 1, 'display_nextgen_method' => 'rewrite' ] ), 'no .htaccess markers expected on Nginx' );

}

function test_unwritable_htaccess_falls_back_to_octave_url_rewriting(): void {

	oa_imagify();
	chmod( Octave_Addons_Perf_Imagify::htaccess_path(), 0444 );

	$choice = Octave_Addons_Perf_Imagify::choose_method();

	oa_assert_same( 'octave', $choice['method'] );
	oa_assert_contains( 'cannot be changed', $choice['reasons'][0] );

	update_option( 'imagify_settings', [ 'display_nextgen' => 1, 'display_nextgen_method' => 'rewrite' ] );

	oa_assert_contains( 'cannot be changed', Octave_Addons_Perf_Imagify::repair()['message'] );

	chmod( Octave_Addons_Perf_Imagify::htaccess_path(), 0644 );

}

function test_cdn_or_cloudflare_rules_out_rewrite_delivery(): void {

	oa_imagify();
	update_option( 'imagify_settings', [ 'cdn_url' => 'https://cdn.example.net' ] );

	oa_assert_contains( 'cdn.example.net', implode( ' ', Octave_Addons_Perf_Imagify::choose_method()['reasons'] ) );

	update_option( 'imagify_settings', [] );
	update_option( Octave_Addons_Perf_Imagify::STATUS_OPTION, [ 'edge' => 'cloudflare' ] );

	$choice = Octave_Addons_Perf_Imagify::choose_method();

	oa_assert_same( 'octave', $choice['method'] );
	oa_assert_contains( 'Cloudflare', $choice['reasons'][0] );

	$desired = Octave_Addons_Perf_Imagify::desired( [ 'format' => 'webp', 'delivery' => 'auto' ] );

	oa_assert_same( 0, $desired['display_nextgen'], 'Imagify stops delivering so Octave can' );
	oa_assert( ! isset( $desired['display_nextgen_method'] ), 'no picture tags' );

}

/*
REPAIR
---------------------------------------------------------- */

function test_missing_markers_are_repaired_through_imagify(): void {

	oa_imagify();
	update_option( 'imagify_settings', [ 'display_nextgen' => 1, 'display_nextgen_method' => 'rewrite' ] );

	oa_assert_same( [ 'webp', 'avif' ], Octave_Addons_Perf_Imagify::missing_markers( Octave_Addons_Perf_Imagify::config() ) );

	$result = Octave_Addons_Perf_Imagify::repair();

	oa_assert( $result['ok'] && $result['changed'], $result['message'] );
	oa_assert_same( [], Octave_Addons_Perf_Imagify::missing_markers( Octave_Addons_Perf_Imagify::config() ) );
	oa_assert_same( 1, did_action( 'imagify_activation' ) );

	$again = Octave_Addons_Perf_Imagify::repair();

	oa_assert( $again['ok'] && ! $again['changed'], 'nothing to do once present' );
	oa_assert_same( 1, did_action( 'imagify_activation' ) );

}

function test_nginx_repair_reports_manual_configuration(): void {

	oa_imagify( 'nginx' );
	update_option( 'imagify_settings', [ 'display_nextgen' => 1, 'display_nextgen_method' => 'rewrite' ] );

	$result = Octave_Addons_Perf_Imagify::repair();

	oa_assert( ! $result['ok'] );
	oa_assert_contains( 'ask your host to add the rules', $result['message'] );
	oa_assert_same( 0, did_action( 'imagify_activation' ), '.htaccess never touched' );

}

function test_imagify_update_schedules_one_background_check(): void {

	oa_imagify();

	Octave_Addons_Perf_Imagify::maybe_schedule_verify();
	Octave_Addons_Perf_Imagify::maybe_schedule_verify();

	oa_assert_same( 1, count( $GLOBALS['oa_cron'] ) );
	oa_assert_same( '2.3.4', get_option( Octave_Addons_Perf_Imagify::VERSION_OPTION ) );

}

/*
DELIVERY TEST
---------------------------------------------------------- */

function test_delivery_test_needs_an_optimised_local_image(): void {

	oa_imagify();
	update_option( 'imagify_settings', [ 'display_nextgen' => 1, 'display_nextgen_method' => 'rewrite' ] );

	oa_assert_contains( 'No image with', Octave_Addons_Perf_Imagify::test_delivery()['message'] );

}

function test_delivery_test_checks_the_returned_format(): void {

	oa_imagify();
	update_option( 'imagify_settings', [ 'display_nextgen' => 1, 'display_nextgen_method' => 'rewrite', 'optimization_format' => 'avif' ] );

	@mkdir( WP_CONTENT_DIR . '/uploads', 0777, true );
	file_put_contents( WP_CONTENT_DIR . '/uploads/a.jpg', 'x' );
	file_put_contents( WP_CONTENT_DIR . '/uploads/a.jpg.avif', 'x' );

	$GLOBALS['oa_attachments'] = [ 7 => [ WP_CONTENT_DIR . '/uploads/a.jpg', 'https://example.com/wp-content/uploads/a.jpg' ] ];
	$GLOBALS['oa_http']        = static function ( $url, $args ) {

		$GLOBALS['oa_accept'] = $args['headers']['Accept'];

		return [ 'response' => [ 'code' => 200 ], 'body' => '', 'headers' => [ 'content-type' => 'image/avif', 'vary' => 'Accept', 'cf-ray' => 'abc' ] ];

	};

	$result = Octave_Addons_Perf_Imagify::test_delivery();

	oa_assert( $result['ok'], $result['message'] );
	oa_assert_contains( 'image/avif', $GLOBALS['oa_accept'] );
	oa_assert_contains( '/uploads/a.jpg?oa_nextgen_test=', $GLOBALS['oa_http_log'][0]['url'], 'original URL for rewrite delivery' );
	oa_assert_same( 'cloudflare', Octave_Addons_Perf_Imagify::status()['edge'], 'edge remembered' );

	$GLOBALS['oa_http'] = static function () {

		return oa_http_response( 200, '', 'image/jpeg' );

	};

	oa_assert_contains( 'instead of image/avif', Octave_Addons_Perf_Imagify::test_delivery()['message'] );

}
