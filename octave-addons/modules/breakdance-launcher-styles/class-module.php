<?php

/*
MODULE: BREAKDANCE LAUNCHER STYLES
-- Gets Breakdance's Gutenberg launcher stylesheet into the block editor canvas
-- Breakdance registers breakdance/global-block on the server and hands it an
-- editor_style, so that block's CSS reaches the canvas. The launcher block is
-- registered in JavaScript only, and its stylesheet is enqueued on
-- admin_enqueue_scripts, which reaches the editor page but never the canvas
-- iframe — so the launcher lands there with browser default buttons, serif
-- text and no card
-- WordPress builds the iframe's stylesheet list by walking every registered
-- block type and collecting its style handles, so the fix is to declare the
-- launcher sheet as an editor style on a block that is registered server-side
-- Always on and hidden from the admin — there is nothing to configure
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Breakdance_Launcher_Styles extends Octave_Addons_Module {

	/** Handle this module registers for Breakdance's launcher stylesheet. */
	protected const HANDLE = 'oa-breakdance-launcher-canvas';

	/** Server-registered Breakdance block used to carry the handle into the canvas. */
	protected const CARRIER_BLOCK = 'breakdance/global-block';

	/** Path to the launcher stylesheet, relative to the Breakdance plugin root. */
	protected const STYLESHEET = 'plugin/admin/launcher/css/shared.css';

	/*
	GET ID
	-- Returns the module settings key
	---------------------------------------------------------- */

	public function get_id(): string {

		return 'breakdance-launcher-styles';

	}

	/*
	GET TITLE
	-- Names the module for anything that lists modules internally
	---------------------------------------------------------- */

	public function get_title(): string {

		return __( 'Breakdance Launcher Styles', 'octave-addons' );

	}

	/*
	GET DESCRIPTION
	-- Describes the module for anything that lists modules internally
	---------------------------------------------------------- */

	public function get_description(): string {

		return __( 'Declares Breakdance\'s launcher stylesheet as a block editor style so the "Edit in Breakdance" block keeps its card and buttons inside the editor canvas iframe.', 'octave-addons' );

	}

	/*
	SHOW IN ADMIN
	-- Hidden: this repairs a fixed incompatibility, so there is nothing to present
	---------------------------------------------------------- */

	public function show_in_admin(): bool {

		return false;

	}

	/*
	IS ALWAYS ENABLED
	-- Runs regardless of saved settings
	---------------------------------------------------------- */

	public function is_always_enabled(): bool {

		return true;

	}

	/*
	RUN
	-- Modules boot on init at priority 5, so the handle exists and the filter is
	-- attached before Breakdance registers its block on init at priority 10
	---------------------------------------------------------- */

	public function run( array $s ): void {

		if ( ! is_admin() ) {

			return;

		}

		if ( ! $this->register_style() ) {

			return;

		}

		add_filter( 'register_block_type_args', [ $this, 'attach_launcher_style' ], 10, 2 );

	}

	/*
	REGISTER STYLE
	-- Points a handle of our own at Breakdance's file rather than reusing
	-- Breakdance's handle, which is only registered later on admin_enqueue_scripts
	-- and so may not exist when WordPress collects the canvas stylesheets
	---------------------------------------------------------- */

	protected function register_style(): bool {

		if ( ! Octave_Addons::is_breakdance_active() ) {

			return false;

		}

		if ( ! defined( 'BREAKDANCE_PLUGIN_URL' ) || ! defined( '__BREAKDANCE_DIR__' ) ) {

			return false;

		}

		if ( ! file_exists( trailingslashit( __BREAKDANCE_DIR__ ) . self::STYLESHEET ) ) {

			return false;

		}

		$version = defined( '__BREAKDANCE_VERSION' ) ? (string) __BREAKDANCE_VERSION : OCTAVE_ADDONS_VERSION;

		wp_register_style( self::HANDLE, BREAKDANCE_PLUGIN_URL . self::STYLESHEET, [], $version );

		return true;

	}

	/*
	ATTACH LAUNCHER STYLE
	-- Adds the handle to the carrier block's editor styles. Both the modern
	-- handles array and the older single-value key are folded into one array,
	-- because WordPress keeps only one of the two and which one it reads
	-- depends on the order the arguments happen to be in
	---------------------------------------------------------- */

	public function attach_launcher_style( $args, $name ) {

		if ( ! is_array( $args ) || self::CARRIER_BLOCK !== $name ) {

			return $args;

		}

		$handles = [];

		if ( ! empty( $args['editor_style_handles'] ) ) {

			$handles = (array) $args['editor_style_handles'];

		}

		if ( ! empty( $args['editor_style'] ) ) {

			$handles = array_merge( $handles, (array) $args['editor_style'] );

		}

		$handles[] = self::HANDLE;

		unset( $args['editor_style'] );

		$args['editor_style_handles'] = array_values( array_unique( $handles ) );

		return $args;

	}

}

return new Octave_Addons_Module_Breakdance_Launcher_Styles();
