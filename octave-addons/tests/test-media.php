<?php

/*
MEDIA TESTS
-- Native lazy loading, exclusions, hero protection and the existing video
-- loader
---------------------------------------------------------- */

function oa_media( array $values = [] ): Octave_Addons_Module_Performance_Media {

	$module = oa_module( 'performance-media' );

	$module->run( oa_set_settings( 'performance-media', array_merge( [ 'enabled' => true ], $values ) ) );

	return $module;

}

function oa_page( string $body ): string {

	return '<!doctype html><html><head></head><body>' . $body . '</body></html>';

}

function test_hero_stays_eager_and_other_images_are_lazy(): void {

	$html = oa_media()->transform( oa_page( '<img src="/logo.png"><img src="/hero.jpg" width="1600"><img src="/third.jpg" width="10" height="10">' ) );

	oa_assert_contains( '<img decoding="async" loading="lazy" src="/logo.png">', $html, 'browser decides for in-view images' );
	oa_assert_contains( '<img fetchpriority="high" src="/hero.jpg" width="1600">', $html, 'hero eager' );
	oa_assert_contains( 'src="/third.jpg"', $html );
	oa_assert_contains( 'width="10" height="10"', $html, 'dimensions kept' );

}

function test_explicit_opt_outs_are_respected(): void {

	$cases = [
		'<img src="/a.jpg" loading="eager">',
		'<img src="/a.jpg" fetchpriority="high">',
		'<img src="/a.jpg" data-no-lazy>',
		'<img src="/a.jpg" data-skip-lazy="1">',
		'<img src="/a.jpg" data-oa-no-lazy>',
		'<img src="/a.jpg" class="hero skip-lazy">',
		'<img data-src="/a.jpg" src="placeholder.gif" class="lazyload">',
		'<img src="data:image/gif;base64,R0lGOD">',
	];

	$module = oa_media();

	foreach ( $cases as $case ) {

		oa_assert_contains( $case, $module->transform( oa_page( $case ) ), $case );

	}

}

function test_user_exclusions_cover_classes_attributes_and_urls(): void {

	$module = oa_media( [
		'exclude_classes'    => ".keep-me",
		'exclude_attributes' => "data-hero\ndata-role=banner",
		'exclude_urls'       => '/uploads/critical/',
	] );

	$images = [
		'<img src="/a.jpg" class="x keep-me">',
		'<img src="/a.jpg" data-hero>',
		'<img src="/a.jpg" data-role="banner">',
		'<img src="/wp-content/uploads/critical/a.jpg">',
	];

	foreach ( $images as $image ) {

		oa_assert_contains( $image, $module->transform( oa_page( $image ) ), $image );

	}

	oa_assert_contains( 'loading="lazy"', $module->transform( oa_page( '<img src="/a.jpg" data-role="other">' ) ), 'non-matching value is lazy' );

}

function test_picture_srcset_and_modern_formats_are_preserved(): void {

	$picture = '<picture><source type="image/avif" srcset="/a.avif 1x, /a@2x.avif 2x"><source type="image/webp" srcset="/a.webp"><img src="/a.jpg" srcset="/a.jpg 1x, /a@2x.jpg 2x" sizes="100vw" width="800" height="600" alt="A"></picture>';
	$html    = oa_media()->transform( oa_page( '<img src="/hero.jpg" width="1600">' . $picture ) );

	oa_assert_contains( '<source type="image/avif" srcset="/a.avif 1x, /a@2x.avif 2x">', $html );
	oa_assert_contains( '<source type="image/webp" srcset="/a.webp">', $html );
	oa_assert_contains( 'srcset="/a.jpg 1x, /a@2x.jpg 2x" sizes="100vw" width="800" height="600" alt="A"', $html );
	oa_assert_contains( 'loading="lazy"', $html );

}

function test_iframes_are_lazy_and_can_be_switched_off(): void {

	$iframe = '<iframe src="https://www.google.com/maps/embed?pb=1"></iframe>';

	oa_assert_contains( 'loading="lazy"', oa_media()->transform( oa_page( $iframe ) ) );

	oa_test_reset();

	oa_assert_not_contains( 'loading="lazy"', oa_media( [ 'iframes' => false ] )->transform( oa_page( $iframe ) ) );

}

function test_media_steps_aside_when_wp_rocket_lazy_loads(): void {

	define( 'WP_ROCKET_VERSION', '3.0' );
	update_option( 'wp_rocket_settings', [ 'lazyload' => 1, 'lazyload_iframes' => 0 ] );

	$html = oa_media()->transform( oa_page( '<img src="/a.jpg"><iframe src="https://x.test/"></iframe>' ) );

	oa_assert_contains( '<img src="/a.jpg">', $html, 'images left to WP Rocket' );
	oa_assert_contains( '<iframe loading="lazy" src="https://x.test/">', $html, 'iframes still handled' );

}

/*
VIDEOS
-- The always-on Breakdance module keeps its behaviour, takes the Media
-- module's exclusions and can be switched off by it
---------------------------------------------------------- */

function test_existing_video_lazy_loading_still_works(): void {

	$html = Octave_Addons_Module_Breakdance_Lazy_Load::filter_video_markup( '<video src="/v.mp4" autoplay muted playsinline poster="/p.jpg"></video>' );

	oa_assert_contains( 'preload="none"', $html );
	oa_assert_contains( 'data-oa-autoplay', $html );
	oa_assert_not_contains( ' autoplay ', $html );
	oa_assert_contains( 'poster="/p.jpg"', $html );
	oa_assert_contains( 'playsinline', $html );

}

function test_video_handling_honours_media_settings_and_opt_outs(): void {

	$video = '<video src="/v.mp4" autoplay></video>';

	oa_assert_same( '<video src="/v.mp4" autoplay data-oa-no-lazy></video>', Octave_Addons_Module_Breakdance_Lazy_Load::filter_video_markup( '<video src="/v.mp4" autoplay data-oa-no-lazy></video>' ) );

	oa_media( [ 'exclude_urls' => '/v.mp4' ] );

	oa_assert_same( $video, Octave_Addons_Module_Breakdance_Lazy_Load::filter_video_markup( $video ), 'excluded by URL' );

	oa_test_reset();
	oa_media( [ 'videos' => false ] );

	oa_assert_same( $video, Octave_Addons_Module_Breakdance_Lazy_Load::filter_video_markup( $video ), 'video lazy loading off' );

	oa_test_reset();
	$_GET['oa_no_optimize'] = Octave_Addons_Perf_Context::bypass_key();

	oa_assert_same( $video, Octave_Addons_Module_Breakdance_Lazy_Load::filter_video_markup( $video ), 'bypass argument' );

}

/*
MAIN IMAGE PRIORITY
---------------------------------------------------------- */

function test_first_wide_eager_image_gets_high_priority_once(): void {

	$html = oa_media()->transform( oa_page( '<img src="/logo.png" width="180"><img src="/hero.jpg" width="1600"><img src="/second.jpg" width="1600"><img src="/late.jpg" width="1600">' ) );

	oa_assert_not_contains( 'fetchpriority="high" src="/logo.png"', $html, 'narrow logo passed over' );
	oa_assert_contains( 'fetchpriority="high" src="/hero.jpg"', $html );
	oa_assert_same( 1, substr_count( $html, 'fetchpriority' ), 'only one image prioritised' );

}

function test_existing_high_priority_image_is_not_competed_with(): void {

	$page = oa_page( '<img src="/hero.jpg" width="1600"><img src="/lcp.jpg" fetchpriority="high" loading="lazy" width="1600">' );

	oa_assert_same( 1, substr_count( oa_media()->transform( $page ), 'fetchpriority' ) );
	oa_assert_not_contains( 'fetchpriority', oa_media()->transform( oa_page( '<img src="/a.jpg"><img src="/b.jpg"><img src="/c.jpg"><img src="/far.jpg" width="1600">' ) ), 'only near the top' );

}

/*
BUILDER FIXES
---------------------------------------------------------- */

function oa_upload( string $name, int $width, int $height ): string {

	$path = WP_CONTENT_DIR . '/uploads/2026/10/' . $name;

	@mkdir( dirname( $path ), 0777, true );

	$image = imagecreatetruecolor( $width, $height );

	imagejpeg( $image, $path );

	return 'https://example.com/wp-content/uploads/2026/10/' . $name;

}

function test_header_images_load_eagerly(): void {

	$html = oa_media()->transform( oa_page( '<header class="site"><div><img src="/logo.png" loading="lazy" width="80" height="20"></div></header><img src="/a.png" loading="lazy"><header><img src="/b.png" loading="lazy"></header>' ) );

	oa_assert_contains( '<img src="/logo.png"  width="80" height="20">', $html );
	oa_assert_contains( '<img src="/a.png" loading="lazy">', $html, 'outside the header' );
	oa_assert_contains( '<img src="/b.png" loading="lazy">', $html, 'only the first header' );

}

function test_missing_dimensions_come_from_the_file(): void {

	$full = oa_upload( 'photo.jpg', 120, 60 );
	$html = oa_media()->transform( oa_page( '<img src="https://example.com/wp-content/uploads/2026/10/photo-300x150.jpg"><img src="" srcset="' . $full . ' 120w"><img src="/remote.png"><img src="' . $full . '" width="10">' ) );

	oa_assert_contains( 'height="150" loading="lazy" width="300" src="https://example.com/wp-content/uploads/2026/10/photo-300x150.jpg"', $html );
	oa_assert_contains( '<img height="60" width="120" src="" srcset="' . $full . ' 120w">', $html );
	oa_assert_contains( '<img decoding="async" loading="lazy" src="/remote.png">', $html, 'unknown file untouched' );
	oa_assert_contains( 'src="' . $full . '" width="10">', $html, 'partial dimensions untouched' );

}

function test_video_posters_use_a_smaller_generated_size(): void {

	$poster = oa_upload( 'clip-poster.jpg', 1280, 720 );

	oa_upload( 'clip-poster-768x432.jpg', 768, 432 );
	oa_upload( 'clip-poster-1024x576.jpg', 1024, 576 );
	oa_upload( 'clip-poster-150x150.jpg', 150, 150 );

	$page = oa_page( '<video poster="' . $poster . '" src="/clip.mp4"></video>' );

	oa_assert_contains( 'poster="https://example.com/wp-content/uploads/2026/10/clip-poster-768x432.jpg"', oa_media()->transform( $page ) );
	oa_assert_contains( 'clip-poster-1024x576.jpg"', oa_media( [ 'poster_width' => 1024 ] )->transform( $page ) );
	oa_assert_same( $page, oa_media( [ 'poster_width' => 0, 'images' => false, 'iframes' => false, 'dimensions' => false, 'header_eager' => false ] )->transform( $page ), 'off' );

}

function test_posters_below_the_hero_wait_for_the_viewport(): void {

	$poster = oa_upload( 'later-poster-640x360.jpg', 640, 360 );
	$video  = '<video data-oa-lazy-video preload="none" src="/v.mp4" poster="%s"%s></video>';
	$page   = oa_page(
		'<header><video data-oa-lazy-video src="/h.mp4" poster="/head.jpg"></video></header>' .
		sprintf( $video, '/hero.jpg', '' ) .
		sprintf( $video, $poster, '' ) .
		sprintf( $video, $poster, ' width="640" height="360"' ) .
		'<video src="/plain.mp4" poster="/plain.jpg"></video>'
	);
	$html   = oa_media()->transform( $page );

	oa_assert_contains( 'poster="/head.jpg"', $html, 'site header keeps its poster' );
	oa_assert_contains( 'poster="/hero.jpg"', $html, 'first video keeps its poster' );
	oa_assert_contains( 'data-oa-poster="' . $poster . '" style="aspect-ratio: 640 / 360;"', $html, 'later poster parked, shape held' );
	oa_assert_same( 1, substr_count( $html, 'aspect-ratio' ), 'sized video needs no aspect ratio' );
	oa_assert_same( 2, substr_count( $html, 'data-oa-poster=' ) );
	oa_assert_contains( 'poster="/plain.jpg"', $html, 'videos the loader does not handle keep their poster' );

	$hero = oa_media()->transform( oa_page( '<img src="/hero.jpg" width="1600">' . sprintf( $video, '/first.jpg', '' ) ) );

	oa_assert_contains( 'data-oa-poster="/first.jpg"', $hero, 'a hero image before it means the first video is below' );
	oa_assert_not_contains( 'data-oa-poster=', oa_media( [ 'lazy_posters' => false ] )->transform( $page ), 'off' );
	oa_assert_not_contains( 'data-oa-poster=', oa_media( [ 'videos' => false ] )->transform( $page ), 'needs lazy videos' );

}
