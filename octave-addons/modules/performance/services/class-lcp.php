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
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Lcp {

	public const OPTION = 'octave_addons_perf_lcp';
	public const ACTION = 'oa_perf_lcp_report';

	/** Narrower viewports report as phones ('m'), the rest as larger screens ('d'). */
	public const BREAKPOINT = 768;

	public const DEVICES = [ 'm', 'd' ];
	public const KINDS   = [ 'img', 'bg', 'poster', 'none' ];

	/** Records are relearned after this long, so a changed design is picked up. */
	public const TTL = WEEK_IN_SECONDS;

	/** Most late-found third-party origins preconnected per screen size. */
	public const MAX_ORIGINS = 2;

	/** Most pages remembered; the oldest record is dropped beyond this. */
	public const MAX_PAGES = 500;

	/*
	BOOT
	-- The report endpoint is public, since visitors send it
	---------------------------------------------------------- */

	public static function boot(): void {

		add_action( 'wp_ajax_' . self::ACTION, [ __CLASS__, 'ajax_report' ] );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, [ __CLASS__, 'ajax_report' ] );
		add_action( 'save_post', [ __CLASS__, 'forget_post' ] );

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
	---------------------------------------------------------- */

	public static function all(): array {

		$all = get_option( self::OPTION, [] );

		return is_array( $all ) ? $all : [];

	}

	public static function entry( string $path ): array {

		$entry = self::all()[ $path ] ?? [];

		return is_array( $entry ) ? $entry : [];

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

		$all = self::all();

		if ( isset( $all[ $path ] ) ) {

			unset( $all[ $path ] );
			update_option( self::OPTION, $all, false );

		}

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
	-- stale record is ever written, and the page only acts on an image that
	-- is the site's own or whose host the page already loads from
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

		$url = 'none' === $kind ? '' : self::clean_url( $url );

		if ( 'none' !== $kind && '' === $url ) {

			$kind = 'none';

		}

		$all   = self::all();
		$entry = is_array( $all[ $path ] ?? null ) ? $all[ $path ] : [];

		if ( ! self::is_stale( $entry, $device ) ) {

			wp_send_json_success();

		}

		$changed = ( $entry[ $device ]['url'] ?? null ) !== $url || ( $entry[ $device ]['kind'] ?? null ) !== $kind;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- public report, validated in clean_origin().
		$raw     = isset( $_POST['origins'] ) && is_array( $_POST['origins'] ) ? wp_unslash( $_POST['origins'] ) : [];
		$origins = array_slice( array_values( array_unique( array_filter( array_map( [ __CLASS__, 'clean_origin' ], array_map( 'strval', $raw ) ) ) ) ), 0, self::MAX_ORIGINS );

		$changed = $changed || ( $entry[ $device ]['origins'] ?? [] ) !== $origins;

		$entry[ $device ] = [ 'url' => $url, 'kind' => $kind, 'origins' => $origins, 'time' => time() ];

		unset( $all[ $path ] );
		$all[ $path ] = $entry;

		if ( count( $all ) > self::MAX_PAGES ) {

			$all = array_slice( $all, -self::MAX_PAGES, null, true );

		}

		update_option( self::OPTION, $all, false );

		if ( $changed ) {

			// The path already holds any subdirectory, so only the origin is added.
			$origin = (string) preg_replace( '#^(https?://[^/]+).*$#i', '$1', home_url() );

			Octave_Addons_Perf_Cache::purge_urls( [ $origin . $path ], 'content' );

		}

		wp_send_json_success();

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

		$a = (string) wp_parse_url( trim( $a ), PHP_URL_PATH );
		$b = (string) wp_parse_url( trim( $b ), PHP_URL_PATH );

		return '' !== $a && $a === $b;

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

		foreach ( $urls as $url => $devices ) {

			// Already preloaded by the theme or another plugin.
			if ( preg_match( '#<link\b[^>]*rel=["\']?preload[^>]*' . preg_quote( esc_url( $url ), '#' ) . '#i', $html ) ) {

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
	-- sends the final candidate, for a screen size still unknown, shortly after the load event, or as the page
	-- is hidden, whichever comes first. Third-party origins are reported when
-- their first request started after the HTML arrived and before the LCP
-- image was shown; a font or fetch from one means it needs CORS
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
