<?php

/*
MEDIA TESTS
-- Native lazy loading, exclusions, hero protection, the existing video
-- loader and YouTube/Vimeo facades
---------------------------------------------------------- */

function oa_media( array $values = [] ): Octave_Addons_Module_Performance_Media {

	$module = oa_module( 'performance-media' );

	$module->run( oa_set_settings( 'performance-media', array_merge( [ 'enabled' => true ], $values ) ) );

	return $module;

}

function oa_page( string $body ): string {

	return '<!doctype html><html><head></head><body>' . $body . '</body></html>';

}

function test_first_images_stay_eager_and_later_ones_lazy(): void {

	$html = oa_media( [ 'skip_first' => 2 ] )->transform( oa_page( '<img src="/logo.png"><img src="/hero.jpg"><img src="/third.jpg" width="10" height="10">' ) );

	oa_assert_contains( '<img src="/logo.png">', $html, 'logo untouched' );
	oa_assert_contains( '<img src="/hero.jpg">', $html, 'hero untouched' );
	oa_assert_contains( 'src="/third.jpg"', $html );
	oa_assert_contains( 'loading="lazy"', $html );
	oa_assert_contains( 'decoding="async"', $html );
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

	$module = oa_media( [ 'skip_first' => 0 ] );

	foreach ( $cases as $case ) {

		oa_assert_contains( $case, $module->transform( oa_page( $case ) ), $case );

	}

}

function test_user_exclusions_cover_classes_attributes_and_urls(): void {

	$module = oa_media( [
		'skip_first'         => 0,
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
	$html    = oa_media( [ 'skip_first' => 0 ] )->transform( oa_page( $picture ) );

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

	$html = oa_media( [ 'skip_first' => 0 ] )->transform( oa_page( '<img src="/a.jpg"><iframe src="https://x.test/"></iframe>' ) );

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
FACADES
---------------------------------------------------------- */

function test_youtube_embed_becomes_accessible_facade_with_fallbacks(): void {

	$embed  = '<figure><iframe title="Launch film" width="640" height="360" src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?cc_load_policy=1" allowfullscreen></iframe></figure>';
	$html   = oa_media( [ 'facades' => true ] )->filter_embed_facade( $embed );

	oa_assert_contains( '<figure><div class="oa-video-facade oa-video-facade--youtube" data-oa-facade style="aspect-ratio: 640 / 360;">', $html );
	oa_assert_contains( 'aria-label="Play video: Launch film"', $html );
	oa_assert_contains( 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $html );
	oa_assert_contains( '<template><iframe title="Launch film"', $html, 'player kept for the script' );
	oa_assert_contains( '<noscript><iframe title="Launch film"', $html, 'player kept without JavaScript' );
	oa_assert_contains( 'cc_load_policy=1', $html, 'captions parameter kept' );
	oa_assert_contains( '</div></figure>', $html );

}

function test_facade_skips_autoplay_unknown_and_opted_out_embeds(): void {

	$module = oa_media( [ 'facades' => true ] );

	foreach ( [
		'<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ?autoplay=1"></iframe>',
		'<iframe src="https://example.com/embed/abc"></iframe>',
		'<iframe data-oa-no-lazy src="https://player.vimeo.com/video/123"></iframe>',
	] as $embed ) {

		oa_assert_same( $embed, $module->filter_embed_facade( $embed ), $embed );

	}

	oa_assert_same( [ 'provider' => 'vimeo', 'id' => '76979871' ], Octave_Addons_Module_Performance_Media::video_from_url( 'https://player.vimeo.com/video/76979871' ) );
	oa_assert_same( [], Octave_Addons_Module_Performance_Media::video_from_url( 'https://www.youtube.com.evil.test/embed/dQw4w9WgXcQ' ) );

}

/*
MAIN IMAGE PRIORITY
---------------------------------------------------------- */

function test_first_wide_eager_image_gets_high_priority_once(): void {

	$html = oa_media()->transform( oa_page( '<img src="/logo.png" width="180"><img src="/hero.jpg" width="1600"><img src="/second.jpg" width="1600"><img src="/late.jpg" width="1600">' ) );

	oa_assert_contains( '<img src="/logo.png" width="180">', $html, 'narrow logo passed over' );
	oa_assert_contains( 'fetchpriority="high" src="/hero.jpg"', $html );
	oa_assert_same( 1, substr_count( $html, 'fetchpriority' ), 'only one image prioritised' );

}

function test_existing_high_priority_image_is_not_competed_with(): void {

	$page = oa_page( '<img src="/hero.jpg" width="1600"><img src="/lcp.jpg" fetchpriority="high" loading="lazy" width="1600">' );

	oa_assert_same( 1, substr_count( oa_media()->transform( $page ), 'fetchpriority' ) );
	oa_assert_not_contains( 'fetchpriority', oa_media( [ 'priority' => false ] )->transform( oa_page( '<img src="/hero.jpg" width="1600">' ) ), 'switch respected' );

}
