<?php

/*
ICON KIT
-- One consistent stroke icon set for every SVG the plugin draws itself.
-- Each icon shares a 24x24 canvas, a 2px round-capped stroke and currentColor,
-- so any icon stays legible at any size and inherits the surrounding colour.
-- Paths are plugin-authored constants, never user input, so they are printed
-- as-is rather than run through an escaping filter that would strip them.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Icons {

	/*
	SHAPES
	-- The inner markup of every icon, keyed by its stable kit name.
	---------------------------------------------------------- */

	protected const SHAPES = [

		// Module and navigation icons.
		'sparkles'   => '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M18.5 15.5l.7 1.8 1.8.7-1.8.7-.7 1.8-.7-1.8-1.8-.7 1.8-.7z"/>',
		'layout'     => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/>',
		'filter'     => '<path d="M22 3H2l8 9.5V19l4 2v-8.5z"/>',
		'blocks'     => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M17.5 14v7"/><path d="M14 17.5h7"/>',
		'lock'       => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
		'layers'     => '<path d="M12 2L2 7l10 5 10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>',
		'message'    => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
		'message-off' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M3 3l18 18"/>',
		'unlink'     => '<path d="M18.84 12.25l1.72-1.71a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M5.17 11.75l-1.71 1.71a5 5 0 0 0 7.07 7.07l1.71-1.71"/><path d="M8 2v3"/><path d="M2 8h3"/><path d="M16 19v3"/><path d="M19 16h3"/>',
		'smartphone' => '<rect x="5" y="2" width="14" height="20" rx="2.5"/><path d="M12 18h.01"/>',
		'zap'        => '<path d="M13 2L3 14h9l-1 8 10-12h-9z"/>',
		'sliders'    => '<path d="M4 21v-7"/><path d="M4 10V3"/><path d="M12 21v-9"/><path d="M12 8V3"/><path d="M20 21v-5"/><path d="M20 12V3"/><path d="M1 14h6"/><path d="M9 8h6"/><path d="M17 16h6"/>',
		'grid'       => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/>',

		// Shared interface icons.
		'arrow-right'  => '<path d="M5 12h14"/><path d="M13 6l6 6-6 6"/>',
		'chevron-down' => '<path d="M6 9l6 6 6-6"/>',
		'close'        => '<path d="M18 6L6 18"/><path d="M6 6l12 12"/>',
		'alert'        => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
		'square'       => '<rect x="3" y="3" width="18" height="18" rx="3"/>',

		// Contact icons used on the frontend.
		'phone'   => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 3.07 10.8 19.79 19.79 0 0 1 .01 2.22 2 2 0 0 1 2 .04h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L6.09 7.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>',
		'mail'    => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 6l-10 7L2 6"/>',
		'map-pin' => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',

	];

	/*
	GET
	-- Returns the complete inline SVG markup for one icon name.
	-- Unknown names fall back to the neutral square so a missing key can never
	-- break a layout that expects an icon to occupy space.
	---------------------------------------------------------- */

	public static function get( string $name, int $size = 20, string $class = '' ): string {

		$shape   = self::SHAPES[ $name ] ?? self::SHAPES['square'];
		$classes = trim( 'oa-icon ' . $class );

		return sprintf(
			'<svg class="%1$s" xmlns="http://www.w3.org/2000/svg" width="%2$d" height="%2$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%3$s</svg>',
			esc_attr( $classes ),
			$size,
			$shape
		);

	}

	/*
	RENDER
	-- Prints one icon directly, for use inside template markup.
	---------------------------------------------------------- */

	public static function render( string $name, int $size = 20, string $class = '' ): void {

		echo self::get( $name, $size, $class ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plugin-authored static SVG markup.

	}

	/*
	HAS
	-- Reports whether a name exists in the kit.
	---------------------------------------------------------- */

	public static function has( string $name ): bool {

		return array_key_exists( $name, self::SHAPES );

	}

}
