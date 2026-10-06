<?php

/*
PERFORMANCE FILE STORE
-- The one place Octave writes generated performance files. Everything lives
-- under wp-content/cache/octave-addons/, writes go through a temporary file
-- renamed into place so a visitor never receives a half-written file, and
-- deletion refuses any path outside that directory
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Store {

	/*
	DIR
	-- Absolute path of the cache directory, or of a sub-folder inside it
	---------------------------------------------------------- */

	public static function dir( string $sub = '' ): string {

		/**
		 * Filters the Octave performance cache directory. Filter the URL to match.
		 *
		 * @param string $dir Absolute path with a trailing slash.
		 */
		$base = trailingslashit( (string) apply_filters( 'octave_addons_perf_cache_dir', trailingslashit( WP_CONTENT_DIR ) . 'cache/octave-addons/' ) );

		return '' === $sub ? $base : trailingslashit( $base . trim( $sub, '/' ) );

	}

	/*
	URL
	-- Public URL matching dir()
	---------------------------------------------------------- */

	public static function url( string $sub = '' ): string {

		/**
		 * Filters the public URL of the Octave performance cache directory.
		 *
		 * @param string $url URL with a trailing slash.
		 */
		$base = trailingslashit( (string) apply_filters( 'octave_addons_perf_cache_url', trailingslashit( content_url() ) . 'cache/octave-addons/' ) );

		return '' === $sub ? $base : trailingslashit( $base . trim( $sub, '/' ) );

	}

	/*
	WRITE
	-- Writes beside the target and renames over it. rename() inside one
	-- directory is atomic, so readers see the old file or the new one only
	---------------------------------------------------------- */

	public static function write( string $path, string $contents ): bool {

		$dir = dirname( $path );

		if ( ! self::is_inside( $dir ) || ! wp_mkdir_p( $dir ) ) {

			return false;

		}

		self::protect_root();

		$tmp = $path . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- cache writes need an atomic rename the Filesystem API cannot guarantee.
		if ( strlen( $contents ) !== file_put_contents( $tmp, $contents, LOCK_EX ) ) {

			self::unlink( $tmp );

			return false;

		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic replace.
		if ( ! @rename( $tmp, $path ) ) {

			self::unlink( $tmp );

			return false;

		}

		return true;

	}

	/*
	DELETE
	-- Removes a sub-folder, or the whole cache when $sub is empty. Symlinks
	-- are unlinked rather than followed
	---------------------------------------------------------- */

	public static function delete( string $sub = '' ): array {

		$target = untrailingslashit( self::dir( $sub ) );
		$result = [ 'files' => 0, 'bytes' => 0 ];

		if ( ! is_dir( $target ) || ! self::is_inside( $target ) ) {

			return $result;

		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $target, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {

			if ( $item->isDir() && ! $item->isLink() ) {

				@rmdir( $item->getPathname() );

				continue;

			}

			$result['bytes'] += (int) $item->getSize();
			$result['files'] += self::unlink( $item->getPathname() ) ? 1 : 0;

		}

		if ( '' !== $sub ) {

			@rmdir( $target );

		}

		return $result;

	}

	/*
	SIZE
	-- Total bytes and file count under the cache, or one sub-folder of it
	---------------------------------------------------------- */

	public static function size( string $sub = '' ): array {

		$target = untrailingslashit( self::dir( $sub ) );
		$result = [ 'files' => 0, 'bytes' => 0 ];

		if ( ! is_dir( $target ) ) {

			return $result;

		}

		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $target, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $items as $item ) {

			if ( $item->isFile() && 'index.html' !== $item->getFilename() ) {

				$result['files']++;
				$result['bytes'] += (int) $item->getSize();

			}

		}

		return $result;

	}

	/*
	IS INSIDE
	-- Whether a path resolves to somewhere within the cache directory. The
	-- deepest existing ancestor is resolved, so paths about to be created pass
	---------------------------------------------------------- */

	public static function is_inside( string $path ): bool {

		$base = untrailingslashit( self::dir() );

		if ( false !== strpos( $path, '..' ) ) {

			return false;

		}

		$probe = $path;

		while ( ! file_exists( $probe ) && dirname( $probe ) !== $probe ) {

			$probe = dirname( $probe );

		}

		$real_probe = realpath( $probe );
		$real_base  = file_exists( $base ) ? realpath( $base ) : $base;

		if ( false === $real_probe || false === $real_base ) {

			return false;

		}

		// The base itself may not exist yet, in which case the probe is above it.
		if ( ! file_exists( $base ) ) {

			return 0 === strpos( $path, $base );

		}

		return $real_probe === $real_base || 0 === strpos( $real_probe, trailingslashit( $real_base ) );

	}

	/*
	PROTECT ROOT
	-- An empty index.html stops directory listings on servers that allow them
	---------------------------------------------------------- */

	protected static function protect_root(): void {

		$index = self::dir() . 'index.html';

		if ( ! file_exists( $index ) ) {

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny guard file.
			@file_put_contents( $index, '' );

		}

	}

	protected static function unlink( string $path ): bool {

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removes Octave's own cache files only.
		return file_exists( $path ) || is_link( $path ) ? @unlink( $path ) : false;

	}

}
