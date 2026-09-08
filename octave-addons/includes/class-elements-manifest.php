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

	/** Where the module lived before it moved under the breakdance area. */
	const LEGACY_MODULE_DIR = 'modules/breakdance-custom-elements';

	/** Where it lives now. */
	const MODULE_DIR = 'modules/breakdance/custom-elements';

	/*
	LIBRARY DIR
	-- Absolute path to the shipped element library.
	---------------------------------------------------------- */

	public static function library_dir(): string {

		return OCTAVE_ADDONS_DIR . self::MODULE_DIR . '/library';

	}

	/*
	MIGRATE LEGACY LOCATION
	-- Moves elements left behind at the module's old path.
	-- The updater backs client elements up before an update and restores them
	-- afterwards, but the restore runs from the code that is already in memory
	-- — the version being replaced — so on the update that moved this module
	-- it writes them back to the path it knew rather than the new one. Nothing
	-- reads that path any more, so without this the elements would still be on
	-- disk while having silently vanished from the builder.
	-- Guarded on the legacy folder existing, so it costs one stat call on
	-- every other site and stops running entirely once it has done its work.
	---------------------------------------------------------- */

	public static function migrate_legacy_location(): bool {

		$legacy = OCTAVE_ADDONS_DIR . self::LEGACY_MODULE_DIR;

		if ( ! is_dir( $legacy ) ) {

			return false;

		}

		$moved = false;

		foreach ( [ 'elements', 'library' ] as $folder ) {

			$source = $legacy . '/' . $folder;

			if ( ! is_dir( $source ) ) {

				continue;

			}

			$target = OCTAVE_ADDONS_DIR . self::MODULE_DIR . '/' . $folder;

			foreach ( (array) glob( $source . '/*', GLOB_ONLYDIR ) as $element ) {

				$destination = $target . '/' . basename( $element );

				// Anything sitting at the legacy path was put back there by the
				// update that was trying to preserve it, so it goes over the top
				// of the freshly installed element exactly as a normal update
				// would have done. Skipping it would throw the edit away.
				self::delete_dir( $destination );

				if ( self::move_dir( $element, $destination ) ) {

					self::repoint_module_paths( $destination );

					$moved = true;

				}

			}

		}

		self::delete_dir( $legacy );

		if ( $moved ) {

			self::build();

		}

		return $moved;

	}

	/*
	REPOINT MODULE PATHS
	-- A preserved element carries whatever asset URLs it was written with, and
	-- an edited copy of a shipped element still names the module's old path.
	-- Those files would otherwise ask the browser for scripts that moved, so
	-- the old path is rewritten to the new one wherever it appears.
	---------------------------------------------------------- */

	protected static function repoint_module_paths( string $dir ): void {

		$files = glob( $dir . '/*.php' );

		foreach ( (array) $files as $file ) {

			$contents = file_get_contents( $file );

			if ( false === $contents || false === strpos( $contents, self::LEGACY_MODULE_DIR ) ) {

				continue;

			}

			file_put_contents( $file, str_replace( self::LEGACY_MODULE_DIR, self::MODULE_DIR, $contents ) );

		}

	}

	/*
	MOVE DIR
	-- Copies a directory tree and removes the original. rename() is tried
	-- first because it is atomic, and only falls back to a copy when the two
	-- paths are on different filesystems.
	---------------------------------------------------------- */

	protected static function move_dir( string $from, string $to ): bool {

		if ( ! wp_mkdir_p( dirname( $to ) ) ) {

			return false;

		}

		if ( @rename( $from, $to ) ) {

			return true;

		}

		if ( ! wp_mkdir_p( $to ) ) {

			return false;

		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $from, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $items as $item ) {

			$destination = $to . '/' . str_replace( trailingslashit( $from ), '', $item->getPathname() );

			if ( $item->isDir() ) {

				wp_mkdir_p( $destination );

				continue;

			}

			copy( $item->getPathname(), $destination );

		}

		self::delete_dir( $from );

		return true;

	}

	/*
	DELETE DIR
	-- Removes a directory tree, deepest entries first.
	---------------------------------------------------------- */

	protected static function delete_dir( string $dir ): void {

		if ( ! is_dir( $dir ) ) {

			return;

		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {

			if ( $item->isDir() ) {

				@rmdir( $item->getPathname() );

				continue;

			}

			@unlink( $item->getPathname() );

		}

		@rmdir( $dir );

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
