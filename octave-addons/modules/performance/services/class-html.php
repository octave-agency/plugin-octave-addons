<?php

/*
HTML OPTIMIZATION PIPELINE
-- One output buffer shared by every Performance module that rewrites page
-- markup. It only starts when at least one transformer is registered and the
-- request may be optimised, so with every module off the page is untouched
-- Each transformer runs inside its own guard: an exception, an empty result
-- or a non-string discards that transformer's work and keeps the HTML it was
-- given, so one failing feature never blanks or breaks a page
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Html {

	/** @var array<int, array{feature: string, callback: callable, priority: int}> */
	protected static array $transformers = [];

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
	-- Adds a transformer: callable( string $html ): string
	---------------------------------------------------------- */

	public static function register( string $feature, callable $callback, int $priority = 10 ): void {

		self::$transformers[] = [
			'feature'  => $feature,
			'callback' => $callback,
			'priority' => $priority,
		];

	}

	public static function reset(): void {

		self::$transformers = [];
		self::$started      = false;
		self::$pending      = '';

	}

	public static function start(): void {

		if ( self::$started || empty( self::$transformers ) ) {

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

		foreach ( $transformers as $transformer ) {

			$html = self::run_guarded( $transformer, $html );

		}

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
