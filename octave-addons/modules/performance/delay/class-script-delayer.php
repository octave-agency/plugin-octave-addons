<?php

/*
SCRIPT DELAYER
-- Decides, script by script, whether a page's <script> waits for the first
-- interaction, and rewrites the ones that do so the browser leaves them
-- alone: the type becomes text/plain-like and the src moves to a data
-- attribute. Every other attribute (async, defer, integrity, crossorigin,
-- nomodule, referrerpolicy, id) stays where it is for the loader to copy
-- Only scripts matching a selected service or an administrator's include
-- pattern are delayed. Protected scripts — Breakdance, Octave, WordPress
-- core, jQuery, consent, payment, form and CAPTCHA code — are never delayed,
-- whatever the patterns say. Same-origin files are only delayed when an
-- administrator includes them by pattern
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Script_Delayer {

	/** Placeholder type that no browser executes. */
	public const TYPE = 'text/oa-delayed';

	/** Script types that execute; anything else (JSON, templates, import maps) is ignored. */
	protected const EXECUTABLE = [ '', 'text/javascript', 'application/javascript', 'module', 'text/ecmascript', 'application/ecmascript' ];

	/** Attributes that mark a script as critical. */
	protected const CRITICAL_ATTRIBUTES = [ 'data-oa-no-delay', 'data-oa-critical', 'data-no-optimize', 'data-oa-delay' ];

	/** Patterns that are never delayed, matched against src, id and inline code. */
	protected const PROTECTED = [
		// Builder, plugin and WordPress core.
		'breakdance', 'octave', '/wp-includes/', '/wp-admin/', 'jquery', 'wp-emoji', 'wp.i18n', 'wp-hooks',
		// Menus and headers outside Breakdance.
		'responsive-menu', 'mega-menu', 'navigation.js', 'menu.js',
		// Consent management.
		'consent', 'cookiebot', 'onetrust', 'cookielaw', 'cookieyes', 'complianz', 'cmplz', 'iubenda', 'usercentrics', 'termly', 'cookie-law-info', 'borlabs', 'didomi', 'osano', 'quantcast', 'cookie-notice',
		// Checkout and payments.
		'woocommerce', 'wc-', 'wcpay', 'stripe', 'paypal', 'braintree', 'squareup', 'klarna', 'afterpay', 'clearpay', 'checkout', 'adyen', 'authorize.net', 'pay.google', 'apple-pay', 'payment',
		// Forms and validation.
		'validate', 'validation', 'gravityforms', 'gform', 'wpcf7', 'contact-form-7', 'wpforms', 'fluentform', 'formidable', 'ninja-forms', 'forminator', 'hsforms',
	];

	/** CAPTCHA patterns, protected unless contextual CAPTCHA loading is selected. */
	protected const CAPTCHA = [ 'recaptcha', 'hcaptcha', 'turnstile', 'challenges.cloudflare.com', 'friendlycaptcha', 'captcha' ];

	/*
	SERVICES
	-- Built-in presets. 'src' patterns match external script URLs, 'inline'
	-- patterns match the configuration snippet that belongs to the service.
	-- 'context' delays a service until a matching element nears the viewport
	-- or receives focus, as well as until the first interaction
	---------------------------------------------------------- */

	public static function services(): array {

		$services = [
			'google-analytics'   => [
				'label'    => __( 'Google Analytics (gtag.js)', 'octave-addons' ),
				'category' => 'analytics',
				'src'      => [ 'googletagmanager.com/gtag/js', 'google-analytics.com/analytics.js', 'google-analytics.com/ga.js' ],
				'inline'   => [ 'gtag(', 'GoogleAnalyticsObject' ],
			],
			'google-tag-manager' => [
				'label'    => __( 'Google Tag Manager', 'octave-addons' ),
				'category' => 'analytics',
				'src'      => [ 'googletagmanager.com/gtm.js' ],
				'inline'   => [ 'googletagmanager.com/gtm.js', 'gtm.start' ],
			],
			'microsoft-clarity'  => [
				'label'    => __( 'Microsoft Clarity', 'octave-addons' ),
				'category' => 'analytics',
				'src'      => [ 'clarity.ms/tag' ],
				'inline'   => [ 'clarity.ms/tag' ],
			],
			'hotjar'             => [
				'label'    => __( 'Hotjar', 'octave-addons' ),
				'category' => 'analytics',
				'src'      => [ 'static.hotjar.com', 'script.hotjar.com' ],
				'inline'   => [ 'static.hotjar.com', '_hjSettings' ],
			],
			'meta-pixel'         => [
				'label'    => __( 'Meta Pixel', 'octave-addons' ),
				'category' => 'marketing',
				'src'      => [ 'connect.facebook.net' ],
				'inline'   => [ 'fbq(', 'connect.facebook.net' ],
			],
			'linkedin'           => [
				'label'    => __( 'LinkedIn Insight Tag', 'octave-addons' ),
				'category' => 'marketing',
				'src'      => [ 'snap.licdn.com' ],
				'inline'   => [ '_linkedin_partner_id', 'snap.licdn.com' ],
			],
			'tiktok'             => [
				'label'    => __( 'TikTok Pixel', 'octave-addons' ),
				'category' => 'marketing',
				'src'      => [ 'analytics.tiktok.com' ],
				'inline'   => [ 'analytics.tiktok.com' ],
			],
			'pinterest'          => [
				'label'    => __( 'Pinterest Tag', 'octave-addons' ),
				'category' => 'marketing',
				'src'      => [ 's.pinimg.com/ct' ],
				'inline'   => [ 'pintrk(', 's.pinimg.com/ct' ],
			],
			'x-ads'              => [
				'label'    => __( 'X (Twitter) Pixel', 'octave-addons' ),
				'category' => 'marketing',
				'src'      => [ 'static.ads-twitter.com' ],
				'inline'   => [ 'twq(', 'static.ads-twitter.com' ],
			],
			'google-ads'         => [
				'label'    => __( 'Google Ads and AdSense', 'octave-addons' ),
				'category' => 'marketing',
				'src'      => [ 'googleadservices.com', 'googlesyndication.com', 'doubleclick.net' ],
				'inline'   => [ 'adsbygoogle' ],
			],
			'chat'               => [
				'label'    => __( 'Chat widgets (Tawk.to, Intercom, Crisp, Tidio, HubSpot, Zendesk, Drift, LiveChat)', 'octave-addons' ),
				'category' => 'widgets',
				'src'      => [ 'embed.tawk.to', 'widget.intercom.io', 'js.intercomcdn.com', 'client.crisp.chat', 'code.tidio.co', 'js.hs-scripts.com', 'static.zdassets.com', 'js.driftt.com', 'cdn.livechatinc.com' ],
				'inline'   => [ 'Tawk_API', 'intercomSettings', 'CRISP_WEBSITE_ID', 'zESettings', 'drift.load', '__lc.license' ],
			],
			'reviews'            => [
				'label'    => __( 'Review widgets (Trustpilot, Reviews.io, Trustindex, Elfsight, Feefo)', 'octave-addons' ),
				'category' => 'widgets',
				'src'      => [ 'widget.trustpilot.com', 'widget.reviews.io', 'cdn.trustindex.io', 'apps.elfsight.com', 'static.elfsight.com', 'api.feefo.com' ],
				'inline'   => [],
			],
			'social-embeds'      => [
				'label'    => __( 'Social embeds (X, Instagram, LinkedIn)', 'octave-addons' ),
				'category' => 'widgets',
				'src'      => [ 'platform.twitter.com/widgets.js', 'www.instagram.com/embed.js', 'platform.linkedin.com' ],
				'inline'   => [],
			],
			'video-embeds'       => [
				'label'    => __( 'Video player APIs (YouTube, Vimeo, Wistia)', 'octave-addons' ),
				'category' => 'widgets',
				'src'      => [ 'youtube.com/iframe_api', 'youtube.com/player_api', 'player.vimeo.com/api', 'fast.wistia.com', 'fast.wistia.net' ],
				'inline'   => [ 'onYouTubeIframeAPIReady' ],
			],
			'maps'               => [
				'label'    => __( 'Maps (Google Maps, Mapbox, Leaflet), loaded as the map nears the viewport', 'octave-addons' ),
				'category' => 'contextual',
				'src'      => [ 'maps.googleapis.com', 'maps.google.com/maps/api', 'api.mapbox.com', 'api.tiles.mapbox.com', 'unpkg.com/leaflet', 'cdn.jsdelivr.net/npm/leaflet' ],
				'inline'   => [ 'google.maps.', 'mapboxgl.', 'L.map(' ],
				'context'  => '.acf-map, .wp-block-map, .gmap, .map, #map, [data-oa-map]',
			],
			'captcha'            => [
				'label'    => __( 'CAPTCHA (reCAPTCHA, hCaptcha, Turnstile), loaded as a form nears the viewport or gains focus', 'octave-addons' ),
				'category' => 'contextual',
				'src'      => [ 'google.com/recaptcha', 'gstatic.com/recaptcha', 'recaptcha.net', 'hcaptcha.com', 'challenges.cloudflare.com/turnstile' ],
				'inline'   => [ 'grecaptcha', 'hcaptcha', 'turnstile' ],
				'context'  => 'form, [data-oa-captcha]',
			],
		];

		/**
		 * Filters the built-in third-party script presets.
		 *
		 * @param array $services Keyed by id: label, category, src[], inline[], context.
		 */
		return (array) apply_filters( 'octave_addons_perf_delay_services', $services );

	}

	/*
	PROTECTED PATTERNS
	-- CAPTCHA protection is lifted only for an administrator who selected the
	-- contextual CAPTCHA preset
	---------------------------------------------------------- */

	public static function protected_patterns( array $service_ids ): array {

		$patterns = self::PROTECTED;

		if ( ! in_array( 'captcha', $service_ids, true ) ) {

			$patterns = array_merge( $patterns, self::CAPTCHA );

		}

		/**
		 * Filters the patterns that are never delayed.
		 *
		 * @param string[] $patterns    Case-insensitive substrings.
		 * @param string[] $service_ids Selected service ids.
		 */
		return (array) apply_filters( 'octave_addons_perf_delay_protected', $patterns, $service_ids );

	}

	/*
	PROCESS
	-- $config: services (ids), include (patterns), exclude (patterns).
	-- Returns the rewritten HTML, the number of scripts delayed and every
	-- decision, for diagnostics
	---------------------------------------------------------- */

	public static function process( string $html, array $config ): array {

		$ids       = array_values( (array) ( $config['services'] ?? [] ) );
		$services  = array_intersect_key( self::services(), array_flip( $ids ) );
		$protected = self::protected_patterns( $ids );
		$include   = (array) ( $config['include'] ?? [] );
		$exclude   = (array) ( $config['exclude'] ?? [] );
		$tags      = new WP_HTML_Tag_Processor( $html );
		$decisions = [];
		$delayed   = 0;

		while ( $tags->next_tag( 'SCRIPT' ) ) {

			$decision = self::decide( $tags, $services, $protected, $include, $exclude );

			if ( null === $decision ) {

				continue;

			}

			$decisions[] = $decision;

			if ( 'delayed' !== $decision['action'] ) {

				continue;

			}

			self::neutralize( $tags, $decision['service'], (string) ( $services[ $decision['service'] ]['context'] ?? '' ) );

			$delayed++;

		}

		return [
			'html'      => $delayed > 0 ? $tags->get_updated_html() : $html,
			'delayed'   => $delayed,
			'decisions' => $decisions,
		];

	}

	/*
	DECIDE
	-- Returns null for anything that is not an executable script, otherwise
	-- what happens to it and why
	---------------------------------------------------------- */

	public static function decide( WP_HTML_Tag_Processor $tags, array $services, array $protected, array $include, array $exclude ): ?array {

		$type = strtolower( trim( (string) $tags->get_attribute( 'type' ) ) );

		if ( ! in_array( $type, self::EXECUTABLE, true ) ) {

			return null;

		}

		$src    = trim( (string) $tags->get_attribute( 'src' ) );
		$id     = (string) $tags->get_attribute( 'id' );
		$inline = '' === $src && method_exists( $tags, 'get_modifiable_text' ) ? (string) $tags->get_modifiable_text() : '';

		if ( '' === $src && '' === trim( $inline ) ) {

			return null;

		}

		$label    = '' !== $src ? $src : 'inline: ' . substr( trim( (string) preg_replace( '/\s+/', ' ', $inline ) ), 0, 90 );
		$haystack = '' !== $src ? $src . ' ' . $id : $id . ' ' . $inline;
		$decision = [ 'script' => $label, 'id' => $id, 'action' => 'kept', 'reason' => '', 'service' => '' ];

		foreach ( self::CRITICAL_ATTRIBUTES as $attribute ) {

			if ( null !== $tags->get_attribute( $attribute ) ) {

				return '' !== $src ? array_merge( $decision, [ 'reason' => 'marked-critical' ] ) : null;

			}

		}

		$matched_protection = Octave_Addons_Perf::matches_any( $haystack, $protected );

		if ( '' !== $matched_protection ) {

			return '' !== $src ? array_merge( $decision, [ 'reason' => 'protected: ' . $matched_protection ] ) : null;

		}

		if ( '' !== Octave_Addons_Perf::matches_any( $haystack, $exclude ) ) {

			return array_merge( $decision, [ 'reason' => 'excluded-by-pattern' ] );

		}

		$service = self::match_service( $src, $inline, $services );

		if ( '' === $service && '' !== Octave_Addons_Perf::matches_any( $haystack, $include ) ) {

			$service = 'custom';

		}

		if ( '' === $service ) {

			if ( '' === $src ) {

				return null;

			}

			return array_merge( $decision, [ 'reason' => Octave_Addons_Perf::is_same_origin( $src ) ? 'same-origin' : 'not-selected' ] );

		}

		return array_merge( $decision, [ 'action' => 'delayed', 'reason' => $service, 'service' => $service ] );

	}

	/*
	MATCH SERVICE
	-- Service presets only match external scripts and inline snippets, never
	-- same-origin files
	---------------------------------------------------------- */

	protected static function match_service( string $src, string $inline, array $services ): string {

		foreach ( $services as $id => $service ) {

			if ( '' !== $src ) {

				if ( ! Octave_Addons_Perf::is_same_origin( $src ) && '' !== Octave_Addons_Perf::matches_any( $src, (array) ( $service['src'] ?? [] ) ) ) {

					return (string) $id;

				}

				continue;

			}

			if ( '' !== Octave_Addons_Perf::matches_any( $inline, (array) ( $service['inline'] ?? [] ) ) ) {

				return (string) $id;

			}

		}

		return '';

	}

	/*
	NEUTRALIZE
	-- Leaves the script in place, in order, but inert
	---------------------------------------------------------- */

	protected static function neutralize( WP_HTML_Tag_Processor $tags, string $service, string $context ): void {

		$type = $tags->get_attribute( 'type' );
		$src  = $tags->get_attribute( 'src' );

		if ( is_string( $type ) && '' !== trim( $type ) ) {

			$tags->set_attribute( 'data-oa-type', $type );

		}

		if ( is_string( $src ) && '' !== trim( $src ) ) {

			$tags->set_attribute( 'data-oa-src', $src );
			$tags->remove_attribute( 'src' );

		}

		$tags->set_attribute( 'type', self::TYPE );
		$tags->set_attribute( 'data-oa-delay', $service );

		if ( '' !== $context ) {

			$tags->set_attribute( 'data-oa-context', $context );

		}

	}

}
