<?php

/*
PERFORMANCE: LARGEST CONTENTFUL PAINT
-- Learns each page's Largest Contentful Paint image from the browsers that
-- load it, separately for phones and larger screens, so later page views
-- can fetch exactly that image first: an <img> gets fetchpriority="high",
-- and a CSS background or video poster, which the browser only finds late,
-- gets a preload in the <head>
-- The same report names up to two other sites the page only found after
-- the HTML had arrived but before that image was shown, such as a host a
-- stylesheet or script pulls an image from. Those get a preconnect hint
-- Pages carry a tiny inline reporter only while their record is missing or
-- a week old. A new record purges that page from the cache so the next view
-- is served with it
-- Each page's record is its own transient, keyed by path and a generation
-- number, so a report never rewrites every other page's record and a
-- design-wide change can retire them all at once. The public endpoint is
-- rate limited per visitor and site-wide, and each path can trigger at most
-- one purge an hour, so forged reports cannot cause a purge storm
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Lcp {

	/** Single option used before 3.33.0; removed on the next admin request. */
	public const LEGACY_OPTION = 'octave_addons_perf_lcp';

	public const GENERATION_OPTION = 'octave_addons_perf_lcp_generation';
	public const ACTION            = 'oa_perf_lcp_report';

	/** Narrower viewports report as phones ('m'), the rest as larger screens ('d'). */
	public const BREAKPOINT = 768;

	public const DEVICES = [ 'm', 'd' ];
	public const KINDS   = [ 'img', 'bg', 'poster', 'none' ];

	/** Records are relearned after this long, so a changed design is picked up. */
	public const TTL = WEEK_IN_SECONDS;

	/** Most late-found third-party origins preconnected per screen size. */
	public const MAX_ORIGINS = 2;

	/** Reports accepted per visitor IP per minute, and site-wide per minute. */
	public const RATE_PER_IP = 10;
	public const RATE_SITE   = 120;

	/** Records created for paths not seen before, site-wide per hour. */
	public const NEW_PER_HOUR = 100;

	/** Purges a changed record may trigger site-wide per hour. */
	public const PURGES_PER_HOUR = 30;

	/** An image larger than this, or than twice its rendered width, is flagged. */
	public const OVERSIZED_BYTES = 200 * KB_IN_BYTES;

	/*
	BOOT
	-- The report endpoint is public, since visitors send it
	---------------------------------------------------------- */

	public static function boot(): void {

		add_action( 'wp_ajax_' . self::ACTION, [ __CLASS__, 'ajax_report' ] );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, [ __CLASS__, 'ajax_report' ] );
		add_action( 'save_post', [ __CLASS__, 'forget_post' ] );
		add_action( 'admin_init', [ __CLASS__, 'drop_legacy_option' ] );

	}

	public static function drop_legacy_option(): void {

		if ( false !== get_option( self::LEGACY_OPTION, false ) ) {

			delete_option( self::LEGACY_OPTION );

		}

	}

	/*
	PATH
	-- The record key for a URL: its path, with a trailing slash, no query
	---------------------------------------------------------- */

	public static function path( string $url ): string {

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( '' === $path || '/' !== $path[0] || strlen( $path ) > 300 ) {

			return '';

		}

		return trailingslashit( $path );

	}

	public static function request_path(): string {

		return self::path( isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only the path is kept, and only as a key.

	}

	/*
	RECORDS
	-- One transient per path. Bumping the generation retires every record;
	-- the old transients simply expire
	---------------------------------------------------------- */

	public static function generation(): int {

		return max( 1, (int) get_option( self::GENERATION_OPTION, 1 ) );

	}

	protected static function key( string $path ): string {

		return 'oa_perf_lcp_' . self::generation() . '_' . md5( $path );

	}

	public static function entry( string $path ): array {

		$entry = '' === $path ? false : get_transient( self::key( $path ) );

		return is_array( $entry ) ? $entry : [];

	}

	public static function store( string $path, array $entry ): void {

		set_transient( self::key( $path ), $entry, 2 * self::TTL );

	}

	public static function is_stale( array $entry, string $device ): bool {

		return (int) ( $entry[ $device ]['time'] ?? 0 ) < time() - self::TTL;

	}

	public static function needs_report( array $entry ): bool {

		return ! empty( self::stale_devices( $entry ) );

	}

	public static function stale_devices( array $entry ): array {

		return array_values( array_filter( self::DEVICES, static function ( string $device ) use ( $entry ): bool {

			return self::is_stale( $entry, $device );

		} ) );

	}

	public static function forget( string $path ): void {

		if ( '' !== $path ) {

			delete_transient( self::key( $path ) );

		}

	}

	public static function forget_all(): void {

		update_option( self::GENERATION_OPTION, self::generation() + 1, true );

	}

	public static function forget_post( $post_id ): void {

		$url = get_permalink( $post_id );

		if ( is_string( $url ) && '' !== $url ) {

			self::forget( self::path( $url ) );

		}

	}

	/*
	AJAX REPORT
	-- No nonce, as cached pages outlive them. Instead only a missing or
	-- stale record is ever written, reports are rate limited, a path not
	-- seen before counts against an hourly budget, and the page only acts
	-- on an image that is the site's own or whose host it already loads from
	---------------------------------------------------------- */

	public static function ajax_report(): void {

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public report, validated below.
		$path   = self::path( isset( $_POST['path'] ) ? (string) wp_unslash( $_POST['path'] ) : '' );
		$device = isset( $_POST['device'] ) ? (string) wp_unslash( $_POST['device'] ) : '';
		$kind   = isset( $_POST['kind'] ) ? (string) wp_unslash( $_POST['kind'] ) : '';
		$url    = isset( $_POST['url'] ) ? trim( (string) wp_unslash( $_POST['url'] ) ) : '';
		// phpcs:enable

		if ( '' === $path || ! in_array( $device, self::DEVICES, true ) || ! in_array( $kind, self::KINDS, true ) ) {

			wp_send_json_error( null, 400 );

		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( ! self::allow( 'ip:' . $ip, self::RATE_PER_IP, MINUTE_IN_SECONDS ) || ! self::allow( 'site', self::RATE_SITE, MINUTE_IN_SECONDS ) ) {

			wp_send_json_error( null, 429 );

		}

		$url = 'none' === $kind ? '' : self::clean_url( $url );

		if ( 'none' !== $kind && '' === $url ) {

			$kind = 'none';

		}

		$entry = self::entry( $path );

		if ( ! self::is_stale( $entry, $device ) ) {

			wp_send_json_success();

		}

		if ( empty( $entry ) && ! self::allow( 'new', self::NEW_PER_HOUR, HOUR_IN_SECONDS ) ) {

			wp_send_json_error( null, 429 );

		}

		$changed = ( $entry[ $device ]['url'] ?? null ) !== $url || ( $entry[ $device ]['kind'] ?? null ) !== $kind;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- public report, validated in clean_origin().
		$raw     = isset( $_POST['origins'] ) && is_array( $_POST['origins'] ) ? wp_unslash( $_POST['origins'] ) : [];
		$origins = array_slice( array_values( array_unique( array_filter( array_map( [ __CLASS__, 'clean_origin' ], array_map( 'strval', $raw ) ) ) ) ), 0, self::MAX_ORIGINS );

		$changed = $changed || ( $entry[ $device ]['origins'] ?? [] ) !== $origins;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- public report, validated in clean_details().
		$entry[ $device ] = array_merge( [ 'url' => $url, 'kind' => $kind, 'origins' => $origins, 'time' => time() ], 'none' === $kind ? [] : self::clean_details( wp_unslash( $_POST ) ) );

		self::store( $path, $entry );

		// A purge per path per hour, and a site-wide hourly budget, at most.
		if ( $changed && false === get_transient( 'oa_perf_lcp_purged_' . md5( $path ) ) && self::allow( 'purge', self::PURGES_PER_HOUR, HOUR_IN_SECONDS ) ) {

			set_transient( 'oa_perf_lcp_purged_' . md5( $path ), 1, HOUR_IN_SECONDS );

			// The path already holds any subdirectory, so only the origin is added.
			$origin = (string) preg_replace( '#^(https?://[^/]+).*$#i', '$1', home_url() );

			Octave_Addons_Perf_Cache::purge_urls( [ $origin . $path ], 'lcp' );

		}

		wp_send_json_success();

	}

	/*
	ALLOW
	-- A fixed-window counter: true while the bucket has room this window
	---------------------------------------------------------- */

	public static function allow( string $bucket, int $limit, int $window ): bool {

		$key   = 'oa_perf_lcp_rl_' . md5( $bucket );
		$state = get_transient( $key );
		$state = is_array( $state ) && (int) ( $state['start'] ?? 0 ) > time() - $window ? $state : [ 'start' => time(), 'count' => 0 ];

		if ( (int) $state['count'] >= $limit ) {

			return false;

		}

		$state['count'] = (int) $state['count'] + 1;

		set_transient( $key, $state, $window );

		return true;

	}

	/*
	CLEAN DETAILS
	-- What the browser measured about the image: intrinsic and rendered
	-- size, bytes transferred and the MIME type it arrived as. Anything out
	-- of range is dropped rather than trusted
	---------------------------------------------------------- */

	public static function clean_details( array $post ): array {

		$details = [];

		foreach ( [ 'w', 'h', 'rw', 'rh', 'bytes' ] as $key ) {

			$value = isset( $post[ $key ] ) ? (int) $post[ $key ] : 0;

			if ( $value > 0 && $value <= ( 'bytes' === $key ? 50 * MB_IN_BYTES : 20000 ) ) {

				$details[ $key ] = $value;

			}

		}

		$type = strtolower( trim( (string) ( $post['type'] ?? '' ) ) );

		if ( preg_match( '#^image/[a-z0-9.+-]{1,20}$#', $type ) ) {

			$details['type'] = $type;

		}

		return $details;

	}

	/*
	FORMAT
	-- The delivered format: the MIME type the browser saw, which reveals a
	-- WebP or AVIF served under a .jpg URL by rewrite rules, else the URL's
	-- extension
	---------------------------------------------------------- */

	public static function format( array $record ): string {

		if ( ! empty( $record['type'] ) ) {

			return strtoupper( (string) preg_replace( '#^image/(x-)?#', '', (string) $record['type'] ) );

		}

		$extension = strtolower( pathinfo( (string) wp_parse_url( (string) ( $record['url'] ?? '' ), PHP_URL_PATH ), PATHINFO_EXTENSION ) );

		return 'jpg' === $extension ? 'JPEG' : strtoupper( $extension );

	}

	/*
	WARNINGS
	-- Readable problems with a record's image for the diagnostics
	---------------------------------------------------------- */

	public static function warnings( array $record ): array {

		$warnings = [];
		$format   = self::format( $record );

		if ( '' !== (string) ( $record['url'] ?? '' ) && ! in_array( $format, [ 'WEBP', 'AVIF', 'SVG+XML' ], true ) ) {

			/* translators: %s: image format. */
			$warnings[] = sprintf( __( 'Sent as %s: a modern format (WebP or AVIF) would be smaller.', 'octave-addons' ), $format );

		}

		if ( (int) ( $record['bytes'] ?? 0 ) > self::OVERSIZED_BYTES ) {

			/* translators: %s: file size. */
			$warnings[] = sprintf( __( 'The image is %s; under 200 KB loads faster.', 'octave-addons' ), size_format( (int) $record['bytes'] ) );

		}

		if ( (int) ( $record['rw'] ?? 0 ) > 0 && (int) ( $record['w'] ?? 0 ) > 2 * (int) $record['rw'] ) {

			/* translators: 1: intrinsic width, 2: rendered width. */
			$warnings[] = sprintf( __( 'The image is %1$dpx wide but only shown %2$dpx wide; a smaller version would load faster.', 'octave-addons' ), (int) $record['w'], (int) $record['rw'] );

		}

		return $warnings;

	}

	/*
	CLEAN URL
	-- An absolute http(s) image URL, or ''
	---------------------------------------------------------- */

	public static function clean_url( string $url ): string {

		if ( strlen( $url ) > 1000 || ! preg_match( '#^https?://[^\s"\'<>]+$#i', $url ) ) {

			return '';

		}

		return $url;

	}

	/*
	CLEAN ORIGIN
	-- "https://host[:port]", optionally followed by " crossorigin", or ''
	---------------------------------------------------------- */

	public static function clean_origin( string $origin ): string {

		$origin = strtolower( trim( $origin ) );

		return preg_match( '#^https://[a-z0-9.-]+(?::\d{1,5})?(?: crossorigin)?$#', $origin ) ? $origin : '';

	}

	/*
	SAME FILE
	-- Whether two URLs name the same file, whatever the host or query
	---------------------------------------------------------- */

	public static function same_file( string $a, string $b ): bool {

		$a = self::source_path( $a );
		$b = self::source_path( $b );

		return '' !== $a && $a === $b;

	}

	/*
	SOURCE PATH
	-- A URL's path with any next-generation suffix removed. Imagify's picture
	-- delivery names its copies photo.jpg.webp, so the browser reports that
	-- file while the page's <img> names photo.jpg; rewrite delivery keeps
	-- photo.jpg throughout
	---------------------------------------------------------- */

	public static function source_path( string $url ): string {

		$path = (string) wp_parse_url( trim( $url ), PHP_URL_PATH );

		return (string) preg_replace( '/(\.(?:jpe?g|png|gif))\.(?:webp|avif)$/i', '$1', $path );

	}

	public static function in_srcset( string $srcset, string $url ): bool {

		foreach ( explode( ',', $srcset ) as $candidate ) {

			if ( self::same_file( (string) strtok( trim( $candidate ), ' ' ), $url ) ) {

				return true;

			}

		}

		return false;

	}

	/*
	PRELOAD MARKUP
	-- Preloads for the backgrounds and posters on record, scoped to the
	-- screen sizes that reported them. An image on another host is only
	-- preloaded when the page itself already refers to that host
	---------------------------------------------------------- */

	public static function preload_markup( array $entry, string $html ): string {

		$media = [
			'm' => '(max-width: ' . ( self::BREAKPOINT - 1 ) . 'px)',
			'd' => '(min-width: ' . self::BREAKPOINT . 'px)',
		];

		$urls = [];

		foreach ( self::DEVICES as $device ) {

			$record = $entry[ $device ] ?? [];

			if ( ! in_array( $record['kind'] ?? '', [ 'bg', 'poster' ], true ) || '' === ( $record['url'] ?? '' ) ) {

				continue;

			}

			$url = (string) $record['url'];

			if ( ! Octave_Addons_Perf::is_same_origin( $url ) && false === stripos( $html, '//' . (string) wp_parse_url( $url, PHP_URL_HOST ) ) ) {

				continue;

			}

			$urls[ $url ][] = $device;

		}

		$markup = '';

		// Preconnects first: they only open a connection, so they cost little.
		foreach ( self::origins( $entry, $html ) as $origin => $cors ) {

			$markup .= '<link rel="preconnect" href="' . esc_url( $origin ) . '"' . ( $cors ? ' crossorigin' : '' ) . '>' . "\n";

		}

		// Another plugin already chose a high-priority image preload; a second would compete with it.
		$competing = (bool) preg_match( '#<link\b(?=[^>]*rel=["\']?preload)(?=[^>]*as=["\']?image)(?=[^>]*fetchpriority=["\']?high)[^>]*>#i', $html );

		foreach ( $urls as $url => $devices ) {

			// Already preloaded by the theme or another plugin.
			if ( $competing || preg_match( '#<link\b[^>]*rel=["\']?preload[^>]*' . preg_quote( esc_url( $url ), '#' ) . '#i', $html ) ) {

				continue;

			}

			$scope   = count( $devices ) === count( self::DEVICES ) ? '' : ' media="' . esc_attr( $media[ $devices[0] ] ) . '"';
			$markup .= '<link rel="preload" as="image" href="' . esc_url( $url ) . '" fetchpriority="high"' . $scope . '>' . "\n";

		}

		return $markup;

	}

	/*
	ORIGINS
	-- Late-found origins on record for either screen size, as origin =>
	-- whether it needs a CORS connection. Only hosts the page already names
	-- are used, and none it already preconnects to
	---------------------------------------------------------- */

	public static function origins( array $entry, string $html ): array {

		$origins = [];

		foreach ( self::DEVICES as $device ) {

			foreach ( (array) ( $entry[ $device ]['origins'] ?? [] ) as $value ) {

				$value = self::clean_origin( (string) $value );

				if ( '' === $value ) {

					continue;

				}

				$cors   = ' crossorigin' === substr( $value, -12 );
				$origin = $cors ? substr( $value, 0, -12 ) : $value;
				$host   = (string) wp_parse_url( $origin, PHP_URL_HOST );

				if ( false === stripos( $html, '//' . $host ) || preg_match( '#<link\b[^>]*rel=["\']?preconnect[^>]*//' . preg_quote( $host, '#' ) . '#i', $html ) ) {

					continue;

				}

				$origins[ $origin ] = ( $origins[ $origin ] ?? false ) || $cors;

			}

		}

		return $origins;

	}

	/*
	REPORTER
	-- Inline, so it costs no request. Watches Largest Contentful Paint and
	-- sends the final candidate, for a screen size still unknown, shortly
	-- after the load event, or as the page is hidden, whichever comes first,
	-- with the image's intrinsic and rendered size, bytes and MIME type
	-- Third-party origins are reported when their first request started
	-- after the HTML arrived and before the LCP image was shown; a font or
	-- fetch from one means it needs CORS
	---------------------------------------------------------- */

	public static function reporter( string $path, array $devices = self::DEVICES ): string {

		$config = wp_json_encode( [
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'action'     => self::ACTION,
			'path'       => $path,
			'breakpoint' => self::BREAKPOINT,
			'maxOrigins' => self::MAX_ORIGINS,
			'devices'    => array_values( $devices ),
		] );

		return '<script data-oa-no-delay>(function(c){'
			. 'if(!window.PerformanceObserver||!navigator.sendBeacon||!window.FormData)return;'
			. 'var device=window.innerWidth<c.breakpoint?"m":"d";if(c.devices.indexOf(device)<0)return;'
			. 'var last=null,sent=false;'
			. 'try{new PerformanceObserver(function(l){var e=l.getEntries();if(e.length)last=e[e.length-1];}).observe({type:"largest-contentful-paint",buffered:true});}catch(e){return;}'
			. 'function send(){if(sent||!last)return;sent=true;'
			. 'var el=last.element,tag=el&&el.tagName?el.tagName.toUpperCase():"",kind=!last.url?"none":("IMG"===tag?"img":("VIDEO"===tag?"poster":"bg"));'
			. 'var b=new FormData();b.append("action",c.action);b.append("path",c.path);'
			. 'b.append("device",device);b.append("kind",kind);b.append("url",last.url||"");'
			. 'try{if(el&&el.naturalWidth){b.append("w",el.naturalWidth);b.append("h",el.naturalHeight);}'
			. 'if(el&&el.getBoundingClientRect){var box=el.getBoundingClientRect();b.append("rw",Math.round(box.width));b.append("rh",Math.round(box.height));}'
			. 'var res=last.url?performance.getEntriesByName(last.url)[0]:null;if(res){b.append("bytes",res.encodedBodySize||res.transferSize||0);if(res.contentType)b.append("type",res.contentType);}}catch(e){}'
			. 'try{var nav=performance.getEntriesByType("navigation")[0],after=nav?nav.responseEnd+50:0,seen={},list=[];'
			. 'performance.getEntriesByType("resource").forEach(function(r){var o=new URL(r.name).origin;if(o===location.origin||0!==o.indexOf("https:"))return;'
			. 'var s=seen[o]||(seen[o]={t:r.startTime,f:false});if(r.startTime<s.t)s.t=r.startTime;'
			. 'if(/\\.(woff2?|ttf|otf)(\\?|$)/i.test(r.name)||"fetch"===r.initiatorType||"xmlhttprequest"===r.initiatorType)s.f=true;});'
			. 'Object.keys(seen).forEach(function(o){if(seen[o].t>after&&seen[o].t<last.startTime)list.push(o);});'
			. 'list.sort(function(a,z){return seen[a].t-seen[z].t;}).slice(0,c.maxOrigins).forEach(function(o){b.append("origins[]",o+(seen[o].f?" crossorigin":""));});}catch(e){}'
			. 'navigator.sendBeacon(c.ajaxUrl,b);}'
			. 'addEventListener("load",function(){setTimeout(send,1500);});'
			. 'addEventListener("pagehide",send);'
			. 'document.addEventListener("visibilitychange",function(){if("hidden"===document.visibilityState)send();});'
			. '})(' . $config . ');</script>';

	}

}
