<?php

/*
HTML OPTIMIZATION PIPELINE
-- One output buffer shared by every Performance module that rewrites page
-- markup. It only starts when at least one transformer is registered and the
-- request may be optimised, so with every module off the page is untouched
-- Each transformer runs inside its own guard: an exception, an empty result
-- or a non-string discards that transformer's work and keeps the HTML it was
-- given, so one failing feature never blanks or breaks a page
-- A transformer can say it has nothing to do this request, for instance
-- because another plugin owns its feature; when none has, no buffer starts
-- Each transformer is timed. A diagnostics scan, or a request carrying the
-- signed oa_timing argument, also receives the timings as Server-Timing
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Html {

	/** Query argument requesting Server-Timing; its value must be timing_key(). */
	public const TIMING_ARG = 'oa_timing';

	/** Transformer feature => what the timings call it. */
	public const TIMING_NAMES = [
		'media'        => 'oa-media',
		'delay'        => 'oa-delay',
		'fonts'        => 'oa-fonts',
		'files'        => 'oa-css-inline',
		'files-bundle' => 'oa-css-bundle',
	];

	/** @var array<int, array{feature: string, callback: callable, priority: int, needed: ?callable}> */
	protected static array $transformers = [];

	/** @var array<string, float> Milliseconds per transformer, plus total. */
	protected static array $timings = [];

	protected static bool $started = false;

	protected static string $pending = '';

	/*
	BOOT
	-- Starts the buffer as late as possible so Octave's buffer sits inside any
	-- page-cache buffer: Octave processes first, the page cache stores the result
	---------------------------------------------------------- */

	public static function boot(): void {

		add_action( 'template_redirect', [ __CLASS__, 'start' ], PHP_INT_MAX );
		add_action( 'template_redirect', [ 'Octave_Addons_Perf_Log', 'maybe_begin_report' ], 0 );

	}

	/*
	REGISTER
	-- Adds a transformer: callable( string $html ): string. $needed, asked
	-- when the buffer would start, returns false when there is nothing for
	-- the transformer to do on this request
	---------------------------------------------------------- */

	public static function register( string $feature, callable $callback, int $priority = 10, ?callable $needed = null ): void {

		self::$transformers[] = [
			'feature'  => $feature,
			'callback' => $callback,
			'priority' => $priority,
			'needed'   => $needed,
		];

	}

	public static function reset(): void {

		self::$transformers = [];
		self::$started      = false;
		self::$pending      = '';
		self::$timings      = [];

	}

	public static function timings(): array {

		return self::$timings;

	}

	public static function start(): void {

		if ( self::$started ) {

			return;

		}

		self::$transformers = array_values( array_filter( self::$transformers, static function ( array $transformer ): bool {

			return null === $transformer['needed'] || (bool) call_user_func( $transformer['needed'] );

		} ) );

		// A diagnostics scan always gets a buffer, so it records a report even
		// when no page transformation is switched on.
		if ( empty( self::$transformers ) && ! Octave_Addons_Perf_Log::is_reporting() ) {

			return;

		}

		if ( ! Octave_Addons_Perf_Context::can_optimize( 'html' ) ) {

			Octave_Addons_Perf_Log::save_report( Octave_Addons_Perf_Context::bypass_reason( 'html' ) );

			return;

		}

		self::$started = true;

		ob_start( [ __CLASS__, 'buffer' ] );

	}

	/*
	BUFFER
	-- Collects flushed chunks so a mid-page ob_flush() never hands the
	-- transformers half a document, then processes the whole page at the end
	---------------------------------------------------------- */

	public static function buffer( string $chunk, int $phase ): string {

		if ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) {

			self::$pending = '';

		}

		self::$pending .= $chunk;

		if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {

			return '';

		}

		$html          = self::$pending;
		self::$pending = '';

		return self::process( $html );

	}

	/*
	PROCESS
	-- Runs every transformer over a full HTML document
	---------------------------------------------------------- */

	public static function process( string $html ): string {

		if ( ! self::is_html_document( $html ) || ! self::is_html_response() ) {

			Octave_Addons_Perf_Log::save_report( 'non-html' );

			return $html;

		}

		$transformers = self::$transformers;

		usort( $transformers, static function ( array $a, array $b ): int {

			return $a['priority'] <=> $b['priority'];

		} );

		$start = microtime( true );

		foreach ( $transformers as $transformer ) {

			$began = microtime( true );
			$html  = self::run_guarded( $transformer, $html );
			$name  = self::TIMING_NAMES[ $transformer['feature'] ] ?? 'oa-' . sanitize_key( $transformer['feature'] );

			self::$timings[ $name ] = ( self::$timings[ $name ] ?? 0 ) + ( microtime( true ) - $began ) * 1000;

		}

		self::$timings['oa-total'] = ( microtime( true ) - $start ) * 1000;

		Octave_Addons_Perf_Log::summary( 'timings', array_map( static function ( float $ms ): float {

			return round( $ms, 2 );

		}, self::$timings ) );

		self::send_server_timing();

		Octave_Addons_Perf_Log::save_report();

		return $html;

	}

	/*
	RUN GUARDED
	-- Fail open: anything other than a non-empty string returns the input
	---------------------------------------------------------- */

	protected static function run_guarded( array $transformer, string $html ): string {

		if ( ! Octave_Addons_Perf_Context::can_optimize( $transformer['feature'] ) ) {

			return $html;

		}

		try {

			$result = call_user_func( $transformer['callback'], $html );

		} catch ( \Throwable $error ) {

			Octave_Addons_Perf_Log::error( $transformer['feature'], $error->getMessage(), 'html' );

			return $html;

		}

		if ( ! is_string( $result ) || '' === trim( $result ) ) {

			Octave_Addons_Perf_Log::error( $transformer['feature'], __( 'A page transformation returned no markup, so the original page was served.', 'octave-addons' ), 'html' );

			return $html;

		}

		return $result;

	}

	/*
	SEND SERVER TIMING
	-- Only for a diagnostics scan or the signed argument, so timings are
	-- never exposed to ordinary visitors. Names and durations only
	---------------------------------------------------------- */

	protected static function send_server_timing(): void {

		if ( headers_sent() || ( ! Octave_Addons_Perf_Log::is_reporting() && ! self::timing_requested() ) ) {

			return;

		}

		$parts = [];

		foreach ( self::$timings as $name => $ms ) {

			$parts[] = $name . ';dur=' . number_format( $ms, 2, '.', '' );

		}

		header( 'Server-Timing: ' . implode( ', ', $parts ), false );

	}

	public static function timing_requested(): bool {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only diagnostic switch checked against a site key.
		$value = isset( $_GET[ self::TIMING_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::TIMING_ARG ] ) ) : '';

		return '' !== $value && hash_equals( self::timing_key(), $value );

	}

	public static function timing_key(): string {

		return substr( wp_hash( 'octave-addons-server-timing' ), 0, 16 );

	}

	/*
	IS HTML DOCUMENT
	-- Only full documents are processed, never JSON, XML or fragments
	---------------------------------------------------------- */

	public static function is_html_document( string $html ): bool {

		$head = strtolower( ltrim( substr( $html, 0, 1024 ) ) );

		return 0 === strpos( $head, '<!doctype html' ) || 0 === strpos( $head, '<html' );

	}

	/*
	IS HTML RESPONSE
	-- A template can send its own Content-Type; anything but HTML is left alone
	---------------------------------------------------------- */

	protected static function is_html_response(): bool {

		foreach ( headers_list() as $header ) {

			if ( 0 === stripos( $header, 'content-type:' ) && false === stripos( $header, 'text/html' ) ) {

				return false;

			}

		}

		return true;

	}

	/*
	INJECT BEFORE BODY END
	-- Shared helper for transformers that add markup at the end of the page
	---------------------------------------------------------- */

	public static function inject_before_body_end( string $html, string $markup ): string {

		$position = strripos( $html, '</body>' );

		if ( false === $position ) {

			return $html . $markup;

		}

		return substr( $html, 0, $position ) . $markup . substr( $html, $position );

	}

}
