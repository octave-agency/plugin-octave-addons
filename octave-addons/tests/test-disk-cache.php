<?php

/*
OCTAVE PAGE CACHE TESTS
-- Which requests and responses may be cached, atomic writes, purges,
-- expiry, and never stacking on another page cache or drop-in
---------------------------------------------------------- */

require_once OCTAVE_ADDONS_DIR . 'modules/performance/services/page-cache-serve.php';

/*
HELPERS
---------------------------------------------------------- */

function oa_disk_cache( array $values = [] ): void {

	oa_set_settings( 'performance-page-cache', array_merge( [ 'enabled' => true ], $values ) );
	Octave_Addons_Perf_Disk_Cache::boot();

}

function oa_request( string $uri, array $server = [] ): array {

	return array_merge( [ 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'HTTP_HOST' => 'example.com', 'HTTPS' => 'on' ], $server );

}

function oa_cache_key( string $uri, array $server = [], array $cookies = [] ): string {

	return octave_addons_page_cache_key( Octave_Addons_Perf_Disk_Cache::config(), oa_request( $uri, $server ), $cookies );

}

function oa_store_page( string $uri, string $html = '' ): string {

	$_SERVER = array_merge( $_SERVER, oa_request( $uri ) );
	$_COOKIE = [];

	return Octave_Addons_Perf_Disk_Cache::store( '' !== $html ? $html : '<!doctype html><html><head></head><body>Page</body></html>' );

}

function oa_cached_file( string $uri ): string {

	return Octave_Addons_Perf_Store::dir( Octave_Addons_Perf_Disk_Cache::DIR ) . oa_cache_key( $uri );

}

/*
REQUESTS
---------------------------------------------------------- */

function test_anonymous_page_requests_have_a_cache_file(): void {

	oa_assert_same( 'example.com/index-https.html', oa_cache_key( '/' ) );
	oa_assert_same( 'example.com/about/index-https.html', oa_cache_key( '/about/' ) );
	oa_assert_same( 'example.com/about/index.html', oa_cache_key( '/about/', [ 'HTTPS' => '' ] ), 'http and https apart' );
	oa_assert_same( 'example.com/about/index-noslash-https.html', oa_cache_key( '/about' ), 'a URL WordPress may redirect is its own file' );
	oa_assert_same( 'example.com/about/index-https.html', oa_cache_key( '/about/?utm_source=mail&gclid=1' ), 'marketing arguments ignored' );
	oa_assert_same( 'example.com/caf%C3%A9/index-https.html', oa_cache_key( '/caf%C3%A9/' ), 'encoded as requested' );

}

function test_private_or_dynamic_requests_are_never_cached(): void {

	$bypass = [
		'POST'             => [ '/', [ 'REQUEST_METHOD' => 'POST' ], [] ],
		'PUT'              => [ '/', [ 'REQUEST_METHOD' => 'PUT' ], [] ],
		'DELETE'           => [ '/', [ 'REQUEST_METHOD' => 'DELETE' ], [] ],
		'logged in'        => [ '/', [], [ 'wordpress_logged_in_abc' => 'x' ] ],
		'password'         => [ '/', [], [ 'wp-postpass_abc' => 'x' ] ],
		'commenter'        => [ '/', [], [ 'comment_author_abc' => 'x' ] ],
		'cart cookie'      => [ '/', [], [ 'woocommerce_items_in_cart' => '1' ] ],
		'Woo session'      => [ '/', [], [ 'wp_woocommerce_session_abc' => 'x' ] ],
		'authorization'    => [ '/', [ 'HTTP_AUTHORIZATION' => 'Basic x' ], [] ],
		'query string'     => [ '/?s=hello', [], [] ],
		'nonce URL'        => [ '/page/?_wpnonce=abc&action=x', [], [] ],
		'preview'          => [ '/?p=3&preview=true', [], [] ],
		'admin'            => [ '/wp-admin/', [], [] ],
		'login'            => [ '/wp-login.php', [], [] ],
		'REST'             => [ '/wp-json/wp/v2/posts', [], [] ],
		'AJAX'             => [ '/wp-admin/admin-ajax.php', [], [] ],
		'feed'             => [ '/feed/', [], [] ],
		'cart'             => [ '/cart/', [], [] ],
		'checkout'         => [ '/checkout/', [], [] ],
		'account'          => [ '/my-account/', [], [] ],
		'file'             => [ '/sitemap.xml', [], [] ],
		'traversal'        => [ '/a/../b/', [], [] ],
		'other host'       => [ '/', [ 'HTTP_HOST' => 'evil.test' ], [] ],
	];

	foreach ( $bypass as $label => [ $uri, $server, $cookies ] ) {

		oa_assert_same( '', oa_cache_key( $uri, $server, $cookies ), $label );

	}

}

/*
RESPONSES
---------------------------------------------------------- */

function test_pages_are_written_atomically(): void {

	oa_disk_cache();

	oa_assert_same( '', oa_store_page( '/about/' ) );

	$file = oa_cached_file( '/about/' );

	oa_assert( is_file( $file ), 'stored' );
	oa_assert_contains( 'Octave page cache', (string) file_get_contents( $file ) );
	oa_assert_same( [], glob( dirname( $file ) . '/*.tmp' ), 'no temporary file left behind' );

}

function test_unsafe_responses_are_never_stored(): void {

	oa_disk_cache();

	oa_assert_same( 'cookie', Octave_Addons_Perf_Disk_Cache::headers_bypass( [ 'Set-Cookie: PHPSESSID=1; path=/' ] ) );
	oa_assert_same( 'cache-control', Octave_Addons_Perf_Disk_Cache::headers_bypass( [ 'Cache-Control: no-store, no-cache, must-revalidate' ] ) );
	oa_assert_same( 'cache-control', Octave_Addons_Perf_Disk_Cache::headers_bypass( [ 'Cache-Control: private' ] ) );
	oa_assert_same( 'redirect', Octave_Addons_Perf_Disk_Cache::headers_bypass( [ 'Location: https://example.com/' ] ) );
	oa_assert_same( 'non-html', Octave_Addons_Perf_Disk_Cache::headers_bypass( [ 'Content-Type: application/json' ] ) );
	oa_assert_same( '', Octave_Addons_Perf_Disk_Cache::headers_bypass( [ 'Content-Type: text/html; charset=UTF-8', 'Cache-Control: public, max-age=600' ] ) );

	$GLOBALS['oa_flags']['404'] = true;
	oa_assert_same( 'status', oa_store_page( '/missing/' ), 'error pages' );

	$GLOBALS['oa_flags'] = [ 'logged_in' => true ];
	oa_assert_same( 'logged-in', oa_store_page( '/about/' ) );

	$GLOBALS['oa_flags'] = [ 'singular' => true, 'password' => true ];
	oa_assert_same( 'password', oa_store_page( '/private/' ) );

	$GLOBALS['oa_flags'] = [];
	oa_assert_same( 'non-html', oa_store_page( '/feed-ish/', '{"json":true}' ) );
	oa_assert_same( 'non-html', oa_store_page( '/cut/', '<!doctype html><html><body>half' ), 'a page cut short' );

	Octave_Addons_Perf_Disk_Cache::skip( 'incomplete' );
	oa_assert_same( 'incomplete', oa_store_page( '/about/' ), 'optimised files not ready yet' );

	oa_assert( ! is_dir( Octave_Addons_Perf_Store::dir( Octave_Addons_Perf_Disk_Cache::DIR ) ), 'nothing written' );

}

function test_requests_octave_never_optimises_bypass_the_cache(): void {

	oa_disk_cache();

	$_SERVER = array_merge( $_SERVER, oa_request( '/about/' ) );
	$_COOKIE = [];

	oa_assert_same( '', Octave_Addons_Perf_Disk_Cache::request_bypass() );

	$GLOBALS['oa_flags']['logged_in'] = true;
	oa_assert_same( 'logged-in', Octave_Addons_Perf_Disk_Cache::request_bypass() );

	$GLOBALS['oa_flags'] = [ 'feed' => true ];
	do_action( 'parse_query' );
	oa_assert_same( 'feed', Octave_Addons_Perf_Disk_Cache::request_bypass() );

	$GLOBALS['oa_flags'] = [ 'cart' => true ];
	oa_assert_same( 'woocommerce', Octave_Addons_Perf_Disk_Cache::request_bypass(), 'cart page' );

	$GLOBALS['oa_flags'] = [ 'ajax' => true ];
	oa_assert_same( 'ajax', Octave_Addons_Perf_Disk_Cache::request_bypass() );

	$GLOBALS['oa_flags'] = [];
	$_SERVER['REQUEST_METHOD'] = 'POST';
	oa_assert_same( 'method', Octave_Addons_Perf_Disk_Cache::request_bypass() );

}

/*
PURGES AND EXPIRY
---------------------------------------------------------- */

function test_purges_remove_stored_pages(): void {

	oa_disk_cache();
	oa_store_page( '/about/' );
	oa_store_page( '/team/' );

	$report = Octave_Addons_Perf_Cache::purge_urls( [ 'https://example.com/about/' ], 'content' );

	oa_assert_same( 'success', $report['octave-page-cache']['status'] );
	oa_assert( ! is_file( oa_cached_file( '/about/' ) ), 'targeted' );
	oa_assert( is_file( oa_cached_file( '/team/' ) ), 'others kept' );

	Octave_Addons_Perf_Cache::purge_all( 'all', 'manual' );

	oa_assert( ! is_file( oa_cached_file( '/team/' ) ), 'full' );

	oa_store_page( '/team/' );
	Octave_Addons_Perf_Cache::purge_all( 'files', 'manual' );

	oa_assert( ! is_file( oa_cached_file( '/team/' ) ), 'pages pointing at deleted minified files go too' );

}

function test_expired_pages_are_removed_by_cron_only(): void {

	oa_disk_cache( [ 'lifespan' => 2 ] );
	oa_store_page( '/old/' );
	oa_store_page( '/new/' );

	touch( oa_cached_file( '/old/' ), time() - 3 * HOUR_IN_SECONDS );

	oa_assert( is_file( oa_cached_file( '/old/' ) ), 'storing never scans for expired pages' );
	oa_assert_same( 1, Octave_Addons_Perf_Disk_Cache::collect_garbage() );
	oa_assert( ! is_file( oa_cached_file( '/old/' ) ) );
	oa_assert( is_file( oa_cached_file( '/new/' ) ) );
	oa_assert_same( Octave_Addons_Perf_Disk_Cache::GC_HOOK, $GLOBALS['oa_cron'][0]['hook'], 'hourly job booked' );

}

/*
OWNERSHIP
---------------------------------------------------------- */

function test_octave_never_stacks_on_another_page_cache(): void {

	oa_set_settings( 'performance-page-cache', [ 'enabled' => true ] );

	oa_assert( Octave_Addons_Perf_Disk_Cache::is_active(), 'nothing else caches pages' );
	oa_assert_same( 'Octave page cache', Octave_Addons_Perf_Page_Cache::active_label() );

	foreach ( [ 'WP Rocket', 'LiteSpeed Cache', 'Breeze', 'FlyingPress', 'Cloudways Varnish' ] as $owner ) {

		oa_test_reset();
		oa_set_settings( 'performance-page-cache', [ 'enabled' => true ] );

		add_filter( 'octave_addons_perf_handled_elsewhere', static function ( $current, $feature ) use ( $owner ) {

			return 'page_cache' === $feature ? $owner : $current;

		}, 10, 2 );

		oa_assert( ! Octave_Addons_Perf_Disk_Cache::is_active(), $owner );
		oa_assert_same( 'deferred', Octave_Addons_Perf_Disk_Cache::status()['code'], $owner );

	}

}

function test_another_plugins_drop_in_is_never_touched(): void {

	$path = Octave_Addons_Perf_Disk_Cache::drop_in_path();

	file_put_contents( $path, "<?php\n// Another cache plugin\n" );

	oa_set_settings( 'performance-page-cache', [ 'enabled' => true ] );

	oa_assert( ! Octave_Addons_Perf_Disk_Cache::is_active(), 'another drop-in means another cache' );
	oa_assert_same( 'conflict', Octave_Addons_Perf_Disk_Cache::status()['code'] );
	oa_assert( ! Octave_Addons_Perf_Disk_Cache::install(), 'refused' );

	Octave_Addons_Perf_Disk_Cache::uninstall();

	oa_assert_same( "<?php\n// Another cache plugin\n", file_get_contents( $path ), 'left exactly as it was' );

	unlink( $path );

}

function test_own_drop_in_is_installed_and_removed_cleanly(): void {

	oa_set_settings( 'performance-page-cache', [ 'enabled' => true ] );

	oa_assert( Octave_Addons_Perf_Disk_Cache::install() );

	$path = Octave_Addons_Perf_Disk_Cache::drop_in_path();

	oa_assert( Octave_Addons_Perf_Disk_Cache::own_drop_in() );
	oa_assert_contains( Octave_Addons_Perf_Disk_Cache::MARKER, (string) file_get_contents( $path ) );
	oa_assert( false !== exec( 'php -l ' . escapeshellarg( $path ) . ' 2>&1', $lint, $code ) && 0 === $code, 'valid PHP' );
	oa_assert( ! Octave_Addons_Perf_Disk_Cache::foreign_drop_in() );

	oa_store_page( '/about/' );
	Octave_Addons_Perf_Disk_Cache::uninstall();

	oa_assert( ! file_exists( $path ), 'drop-in removed' );
	oa_assert( ! is_dir( Octave_Addons_Perf_Store::dir( Octave_Addons_Perf_Disk_Cache::DIR ) ), 'pages removed' );

}

function test_wp_config_is_never_edited(): void {

	oa_set_settings( 'performance-page-cache', [ 'enabled' => true ] );

	$status = Octave_Addons_Perf_Disk_Cache::status();

	oa_assert_same( 'plugin', $status['code'], 'still caches without WP_CACHE' );
	oa_assert_contains( "define( 'WP_CACHE', true );", $status['message'], 'tells the administrator what to add' );
	oa_assert( ! file_exists( ABSPATH . 'wp-config.php' ), 'nothing written there' );

}
