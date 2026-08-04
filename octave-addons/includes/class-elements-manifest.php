<?php

/*
ELEMENT MANIFEST
-- Fingerprints every element shipped in the library so the plugin can tell a
-- pristine element from one a developer has edited.
-- The manifest always holds the hashes of the *shipped* files. It is rebuilt
-- straight after an update, while the files on disk are still untouched, so
-- any later divergence means somebody edited that element locally.
-- Edited elements are carried across updates instead of being overwritten.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Elements_Manifest {

	const OPTION = 'octave_addons_element_manifest';

	/*
	LIBRARY DIR
	-- Absolute path to the shipped element library.
	---------------------------------------------------------- */

	public static function library_dir(): string {

		return OCTAVE_ADDONS_DIR . 'modules/breakdance-custom-elements/library';

	}

	/*
	ELEMENT DIRS
	-- Every element folder in the library, keyed by folder name.
	---------------------------------------------------------- */

	public static function element_dirs(): array {

		$dirs = glob( self::library_dir() . '/*', GLOB_ONLYDIR );

		if ( empty( $dirs ) ) {

			return [];

		}

		$found = [];

		foreach ( $dirs as $dir ) {

			if ( ! file_exists( $dir . '/element.php' ) ) {

				continue;

			}

			$found[ basename( $dir ) ] = $dir;

		}

		return $found;

	}

	/*
	HASH DIR
	-- Fingerprint of one element folder: every file's path and contents.
	-- Sorted so the result does not depend on filesystem ordering.
	---------------------------------------------------------- */

	public static function hash_dir( string $dir ): string {

		if ( ! is_dir( $dir ) ) {

			return '';

		}

		$files = [];

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {

			if ( ! $file->isFile() ) {

				continue;

			}

			$relative = str_replace( trailingslashit( $dir ), '', $file->getPathname() );

			$files[ $relative ] = md5_file( $file->getPathname() );

		}

		if ( empty( $files ) ) {

			return '';

		}

		ksort( $files );

		return md5( wp_json_encode( $files ) );

	}

	/*
	BUILD
	-- Recomputes and stores the manifest from whatever is on disk right now.
	-- Only ever call this when the library is known to be pristine.
	---------------------------------------------------------- */

	public static function build(): array {

		$manifest = [];

		foreach ( self::element_dirs() as $slug => $dir ) {

			$manifest[ $slug ] = self::hash_dir( $dir );

		}

		update_option( self::OPTION, $manifest, false );

		return $manifest;

	}

	/*
	GET
	-- Stored manifest, building it on first use so a fresh install has a
	-- baseline without waiting for an update.
	---------------------------------------------------------- */

	public static function get(): array {

		$manifest = get_option( self::OPTION, null );

		if ( ! is_array( $manifest ) ) {

			return self::build();

		}

		return $manifest;

	}

	/*
	IS CUSTOMISED
	-- True when an element's files no longer match the shipped fingerprint.
	-- Elements with no baseline are treated as pristine: they were added
	-- after the manifest was built and will be picked up on the next build.
	---------------------------------------------------------- */

	public static function is_customised( string $slug ): bool {

		$manifest = self::get();

		if ( empty( $manifest[ $slug ] ) ) {

			return false;

		}

		$dirs = self::element_dirs();

		if ( empty( $dirs[ $slug ] ) ) {

			return false;

		}

		return $manifest[ $slug ] !== self::hash_dir( $dirs[ $slug ] );

	}

	/*
	CUSTOMISED SLUGS
	-- Every locally edited element, used to decide what an update must keep.
	---------------------------------------------------------- */

	public static function customised_slugs(): array {

		$customised = [];

		foreach ( array_keys( self::element_dirs() ) as $slug ) {

			if ( self::is_customised( $slug ) ) {

				$customised[] = $slug;

			}

		}

		return $customised;

	}

}
