<?php

/*
DATABASE CLEANUP
-- Counts and removes one kind of leftover data at a time, in small batches,
-- through WordPress's own delete functions so hooks and object caches stay
-- consistent. Each batch is a short request; the admin page (or cron) keeps
-- asking until nothing remains
-- Orphaned metadata is deliberately out of scope
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Cleanup {

	public const BATCH       = 100;
	public const LAST_OPTION = 'octave_addons_perf_db_last';

	/*
	ITEMS
	-- Every cleanup the module offers, with plain-language labels
	---------------------------------------------------------- */

	public static function items(): array {

		return [
			'revisions'          => [ 'label' => __( 'Post revisions', 'octave-addons' ), 'advanced' => false ],
			'auto_drafts'        => [ 'label' => __( 'Auto-drafts', 'octave-addons' ), 'advanced' => false ],
			'trashed_posts'      => [ 'label' => __( 'Trashed posts', 'octave-addons' ), 'advanced' => false ],
			'spam_comments'      => [ 'label' => __( 'Spam comments', 'octave-addons' ), 'advanced' => false ],
			'trashed_comments'   => [ 'label' => __( 'Trashed comments', 'octave-addons' ), 'advanced' => false ],
			'expired_transients' => [ 'label' => __( 'Expired temporary data', 'octave-addons' ), 'advanced' => false ],
			'all_transients'     => [ 'label' => __( 'All temporary data (advanced)', 'octave-addons' ), 'advanced' => true ],
			'optimize_tables'    => [ 'label' => __( 'Tidy up database tables (advanced)', 'octave-addons' ), 'advanced' => true ],
		];

	}

	public static function is_item( string $item ): bool {

		return isset( self::items()[ $item ] );

	}

	/*
	COUNT
	-- How many rows (or tables) an item would touch
	---------------------------------------------------------- */

	public static function count( string $item ): int {

		global $wpdb;

		switch ( $item ) {

			case 'revisions':

				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );

			case 'auto_drafts':

				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );

			case 'trashed_posts':

				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" );

			case 'spam_comments':

				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" );

			case 'trashed_comments':

				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'" );

			case 'expired_transients':

				return count( self::expired_transient_names( PHP_INT_MAX ) );

			case 'all_transients':

				return count( self::all_transient_names( PHP_INT_MAX ) );

			case 'optimize_tables':

				return count( self::tables() );

		}

		return 0;

	}

	/*
	RUN BATCH
	-- Removes up to $limit entries and reports what is left. Table optimisation
	-- removes nothing, so it walks the table list by offset instead
	---------------------------------------------------------- */

	public static function run_batch( string $item, int $limit = self::BATCH, int $offset = 0 ): array {

		global $wpdb;

		$limit = max( 1, $limit );
		$done  = 0;

		switch ( $item ) {

			case 'revisions':

				foreach ( self::post_ids( "post_type = 'revision'", $limit ) as $id ) {

					$done += wp_delete_post_revision( $id ) ? 1 : 0;

				}

				break;

			case 'auto_drafts':

				foreach ( self::post_ids( "post_status = 'auto-draft'", $limit ) as $id ) {

					$done += wp_delete_post( $id, true ) ? 1 : 0;

				}

				break;

			case 'trashed_posts':

				foreach ( self::post_ids( "post_status = 'trash'", $limit ) as $id ) {

					$done += wp_delete_post( $id, true ) ? 1 : 0;

				}

				break;

			case 'spam_comments':
			case 'trashed_comments':

				$status = 'spam_comments' === $item ? 'spam' : 'trash';
				$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = %s LIMIT %d", $status, $limit ) );

				foreach ( (array) $ids as $id ) {

					$done += wp_delete_comment( (int) $id, true ) ? 1 : 0;

				}

				break;

			case 'expired_transients':

				foreach ( self::expired_transient_names( $limit ) as $name ) {

					$done += self::delete_transient_option( $name ) ? 1 : 0;

				}

				break;

			case 'all_transients':

				foreach ( self::all_transient_names( $limit ) as $name ) {

					$done += self::delete_transient_option( $name ) ? 1 : 0;

				}

				break;

			case 'optimize_tables':

				$tables = self::tables();

				foreach ( array_slice( $tables, $offset, 1 ) as $table ) {

					// The table name comes from SHOW TABLES, never from the request.
					$wpdb->query( 'OPTIMIZE TABLE `' . str_replace( '`', '', $table ) . '`' );
					$done++;

				}

				return [ 'done' => $done, 'remaining' => max( 0, count( $tables ) - $offset - $done ), 'offset' => $offset + $done ];

		}

		return [ 'done' => $done, 'remaining' => self::count( $item ), 'offset' => 0 ];

	}

	/*
	RUN UNTIL
	-- Cron path: works through the given items until done or out of time, and
	-- reports whether anything is left for a follow-up run
	---------------------------------------------------------- */

	public static function run_until( array $items, int $seconds ): array {

		$deadline = microtime( true ) + $seconds;
		$totals   = [];
		$finished = true;

		foreach ( $items as $item ) {

			if ( ! self::is_item( $item ) || 'all_transients' === $item ) {

				continue;

			}

			$totals[ $item ] = 0;
			$offset          = 0;

			do {

				if ( microtime( true ) > $deadline ) {

					$finished = false;

					break 2;

				}

				$batch            = self::run_batch( $item, self::BATCH, $offset );
				$offset           = $batch['offset'];
				$totals[ $item ] += $batch['done'];

			} while ( $batch['done'] > 0 && $batch['remaining'] > 0 );

		}

		self::record( $totals, 'scheduled' );

		return [ 'totals' => $totals, 'finished' => $finished ];

	}

	public static function record( array $totals, string $source ): void {

		update_option( self::LAST_OPTION, [ 'time' => time(), 'source' => $source, 'totals' => $totals ], false );

	}

	public static function last(): array {

		$last = get_option( self::LAST_OPTION, [] );

		return is_array( $last ) ? $last : [];

	}

	/*
	HELPERS
	---------------------------------------------------------- */

	protected static function post_ids( string $where, int $limit ): array {

		global $wpdb;

		// $where is one of the fixed clauses above, never request input.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE {$where} LIMIT %d", $limit ) );

		return array_map( 'intval', (array) $ids );

	}

	/*
	EXPIRED TRANSIENT NAMES
	-- Option names of transients whose timeout has passed. Site transients
	-- live in the options table only on single-site installs
	---------------------------------------------------------- */

	protected static function expired_transient_names( int $limit ): array {

		global $wpdb;

		$names = $wpdb->get_col( $wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE ( option_name LIKE %s OR option_name LIKE %s ) AND option_value < %d LIMIT %d",
			$wpdb->esc_like( '_transient_timeout_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
			time(),
			$limit
		) );

		$clean = [];

		foreach ( (array) $names as $name ) {

			$clean[] = str_replace( '_timeout_', '_', (string) $name );

		}

		return $clean;

	}

	protected static function all_transient_names( int $limit ): array {

		global $wpdb;

		$names = $wpdb->get_col( $wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE ( option_name LIKE %s OR option_name LIKE %s ) AND option_name NOT LIKE %s AND option_name NOT LIKE %s LIMIT %d",
			$wpdb->esc_like( '_transient_' ) . '%',
			$wpdb->esc_like( '_site_transient_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
			$limit
		) );

		return array_map( 'strval', (array) $names );

	}

	/*
	DELETE TRANSIENT OPTION
	-- Deletes through the transient API so object caches hear about it. The
	-- raw row is removed too, in case an object cache bypassed the table
	---------------------------------------------------------- */

	protected static function delete_transient_option( string $option_name ): bool {

		global $wpdb;

		if ( 0 === strpos( $option_name, '_site_transient_' ) ) {

			delete_site_transient( substr( $option_name, strlen( '_site_transient_' ) ) );

		} elseif ( 0 === strpos( $option_name, '_transient_' ) ) {

			delete_transient( substr( $option_name, strlen( '_transient_' ) ) );

		} else {

			return false;

		}

		$timeout = preg_replace( '/^(_site)?_transient_/', '$1_transient_timeout_', $option_name );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN ( %s, %s )", $option_name, $timeout ) );

		return true;

	}

	/*
	TABLES
	-- This site's own tables, on MySQL or MariaDB only
	---------------------------------------------------------- */

	public static function tables(): array {

		global $wpdb;

		if ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) {

			return [];

		}

		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );

		return array_map( 'strval', (array) $tables );

	}

}
