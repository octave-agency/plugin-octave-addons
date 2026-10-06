<?php

/*
PERFORMANCE CONTEXT
-- Decides whether the current request may be optimised. Every frontend
-- transformation in the Performance area asks here first, so the bypass
-- rules live in one place and always lean towards leaving a page untouched
-- Developers can override the decision through octave_addons_perf_bypass_reason
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Context {

	/** Query argument that skips every Octave frontend transformation. */
	public const BYPASS_ARG = 'oa_no_optimize';

	/*
	CAN OPTIMIZE
	-- True when the named feature may transform this request
	---------------------------------------------------------- */

	public static function can_optimize( string $feature = '' ): bool {

		return '' === self::bypass_reason( $feature );

	}

	/*
	BYPASS REASON
	-- A short code naming why the request is left alone, or '' to optimise.
	-- Filterable so a site can force a bypass (or lift one) per feature
	---------------------------------------------------------- */

	public static function bypass_reason( string $feature = '' ): string {

		$reason = self::detect_reason( $feature );

		/**
		 * Filters why a request bypasses an Octave performance feature.
		 *
		 * @param string $reason  Bypass code, or '' to allow the optimisation.
		 * @param string $feature Feature asking: media, delay, files, preload, fonts, html.
		 */
		return (string) apply_filters( 'octave_addons_perf_bypass_reason', $reason, $feature );

	}

	/*
	DETECT REASON
	-- Conditional tags only answer once the main query has been parsed, so
	-- those checks wait for it rather than guessing early
	---------------------------------------------------------- */

	protected static function detect_reason( string $feature ): string {

		if ( self::bypass_requested() ) {

			return 'query-arg';

		}

		if ( is_admin() ) {

			return 'admin';

		}

		if ( wp_doing_ajax() ) {

			return 'ajax';

		}

		if ( wp_doing_cron() ) {

			return 'cron';

		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {

			return 'cli';

		}

		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) ) {

			return 'rest';

		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {

			return 'xmlrpc';

		}

		if ( Octave_Addons_Module::is_builder_request() ) {

			return 'builder';

		}

		if ( self::is_login_page() ) {

			return 'login';

		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( ! in_array( $method, [ 'GET', 'HEAD' ], true ) ) {

			return 'method';

		}

		if ( did_action( 'parse_query' ) ) {

			$query_reason = self::query_reason();

			if ( '' !== $query_reason ) {

				return $query_reason;

			}

		}

		// Logged-in users, including editors and customers in their account, always
		// see pages exactly as WordPress renders them.
		if ( is_user_logged_in() ) {

			return 'logged-in';

		}

		return '';

	}

	/*
	QUERY REASON
	-- Feeds, previews, embeds and WooCommerce's cart, checkout and account
	-- views, where an optimisation could interfere with what the page does
	---------------------------------------------------------- */

	protected static function query_reason(): string {

		if ( is_feed() ) {

			return 'feed';

		}

		if ( is_robots() || is_trackback() || ( function_exists( 'is_favicon' ) && is_favicon() ) ) {

			return 'non-html';

		}

		if ( is_embed() ) {

			return 'embed';

		}

		if ( is_preview() || is_customize_preview() ) {

			return 'preview';

		}

		if ( Octave_Addons_Module::is_sensitive_woocommerce_view() ) {

			return 'woocommerce';

		}

		return '';

	}

	/*
	IS LOGIN PAGE
	-- Login, registration and password reset all run through wp-login.php
	---------------------------------------------------------- */

	protected static function is_login_page(): bool {

		if ( function_exists( 'is_login' ) && is_login() ) {

			return true;

		}

		return 'wp-login.php' === ( $GLOBALS['pagenow'] ?? '' );

	}

	/*
	BYPASS REQUESTED
	-- ?oa_no_optimize=1 works for administrators. The site key works for
	-- anyone, so a logged-out browser can compare the original page without
	-- the switch becoming a public toggle
	---------------------------------------------------------- */

	public static function bypass_requested(): bool {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only diagnostic switch.
		if ( ! isset( $_GET[ self::BYPASS_ARG ] ) ) {

			return false;

		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only diagnostic switch.
		$value = sanitize_key( wp_unslash( $_GET[ self::BYPASS_ARG ] ) );

		if ( '' !== $value && hash_equals( self::bypass_key(), $value ) ) {

			return true;

		}

		return '1' === $value && is_user_logged_in() && current_user_can( 'manage_options' );

	}

	/*
	BYPASS KEY
	-- Site-specific value derived from the WordPress salts
	---------------------------------------------------------- */

	public static function bypass_key(): string {

		return substr( wp_hash( 'octave-addons-no-optimize' ), 0, 16 );

	}

}
