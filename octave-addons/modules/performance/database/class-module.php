<?php

/*
PERFORMANCE: DATABASE CLEANUP
-- Removes revisions, auto-drafts, trash, spam and transients on request, one
-- type at a time and in small batches, after showing how much there is.
-- Nothing runs on activation, update or save. An optional weekly or monthly
-- schedule runs only the types an administrator ticks, and is removed when
-- the module is switched off
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

require_once dirname( __DIR__ ) . '/services/bootstrap.php';

class Octave_Addons_Module_Performance_Database extends Octave_Addons_Module {

	use Octave_Addons_Perf_Field_Rows;

	public const CRON_HOOK     = 'octave_addons_perf_db_cleanup';
	public const CONTINUE_HOOK = 'octave_addons_perf_db_cleanup_continue';

	/** Seconds one scheduled run may spend before handing over to a follow-up. */
	protected const CRON_BUDGET = 20;

	public function get_id(): string {

		return 'performance-database';

	}

	public function get_title(): string {

		return __( 'Database Cleanup', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Clears out old revisions, drafts, trash, spam and other leftovers that slow your database down, when you choose or on a schedule.', 'octave-addons' );

	}

	public function get_order(): int {

		return 80;

	}

	public function get_defaults(): array {

		return [
			'enabled'         => false,
			'schedule'        => 'manual',
			'scheduled_items' => [ 'revisions', 'auto_drafts', 'spam_comments', 'trashed_comments', 'expired_transients' ],
		];

	}

	public function sanitize( $input ): array {

		$clean    = parent::sanitize( $input );
		$schedule = sanitize_key( $input['schedule'] ?? 'manual' );
		$items    = is_array( $input['scheduled_items'] ?? null ) ? array_map( 'sanitize_key', $input['scheduled_items'] ) : [];

		$clean['schedule']        = in_array( $schedule, [ 'manual', 'weekly', 'oa_monthly' ], true ) ? $schedule : 'manual';
		$clean['scheduled_items'] = array_values( array_intersect( $items, self::schedulable_items() ) );

		// A changed schedule is re-registered with its new recurrence on the next load.
		self::unschedule();

		return $clean;

	}

	/*
	SCHEDULABLE ITEMS
	-- Deleting every transient is too disruptive to happen unattended
	---------------------------------------------------------- */

	public static function schedulable_items(): array {

		return array_values( array_diff( array_keys( Octave_Addons_Perf_Cleanup::items() ), [ 'all_transients' ] ) );

	}

	public function run( array $s ): void {

		add_filter( 'cron_schedules', [ __CLASS__, 'add_monthly_schedule' ] );
		add_action( self::CRON_HOOK, [ $this, 'run_scheduled' ] );
		add_action( self::CONTINUE_HOOK, [ $this, 'run_scheduled' ] );

		if ( 'manual' === $s['schedule'] || empty( $s['scheduled_items'] ) ) {

			if ( wp_next_scheduled( self::CRON_HOOK ) ) {

				self::unschedule();

			}

			return;

		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {

			wp_schedule_event( time() + HOUR_IN_SECONDS, $s['schedule'], self::CRON_HOOK );

		}

	}

	public function run_disabled( array $s ): void {

		if ( wp_next_scheduled( self::CRON_HOOK ) || wp_next_scheduled( self::CONTINUE_HOOK ) ) {

			self::unschedule();

		}

	}

	public static function unschedule(): void {

		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::CONTINUE_HOOK );

	}

	public static function add_monthly_schedule( $schedules ): array {

		$schedules = is_array( $schedules ) ? $schedules : [];

		$schedules['oa_monthly'] = [
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Once a month (Octave)', 'octave-addons' ),
		];

		return $schedules;

	}

	/*
	RUN SCHEDULED
	-- Works within a time budget, and books a follow-up run if work remains
	---------------------------------------------------------- */

	public function run_scheduled(): void {

		$s      = Octave_Addons_Perf::settings( $this->get_id() );
		$result = Octave_Addons_Perf_Cleanup::run_until( (array) ( $s['scheduled_items'] ?? [] ), self::CRON_BUDGET );

		if ( ! $result['finished'] && ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {

			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::CONTINUE_HOOK );

		}

	}

	/*
	RENDER SETTINGS
	-- The cleanup checkboxes have no name, so they choose what to run now
	-- without ever being saved with the settings
	---------------------------------------------------------- */

	public function render_settings( array $s ): void {

		$items = Octave_Addons_Perf_Cleanup::items();
		$last  = Octave_Addons_Perf_Cleanup::last();

		?>

		<div class="notice notice-warning inline oa-inline-notice">
			<p><strong><?php esc_html_e( 'Cleanup cannot be undone.', 'octave-addons' ); ?></strong> <?php esc_html_e( 'Anything removed is gone for good. Make a backup of your database first.', 'octave-addons' ); ?></p>
		</div>

		<div class="oa-perf-db" data-oa-perf-db data-oa-perf-local>
			<table class="widefat striped oa-perf-db-table" data-oa-perf-select-group>
				<thead>
					<tr>
						<td class="check-column">
							<label class="screen-reader-text" for="oa-perf-db-all"><?php esc_html_e( 'Select all', 'octave-addons' ); ?></label>
							<input type="checkbox" id="oa-perf-db-all" data-oa-perf-select-all>
						</td>
						<th scope="col"><?php esc_html_e( 'Data', 'octave-addons' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Found', 'octave-addons' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php

					foreach ( $items as $key => $item ) :

					?>

					<tr data-oa-perf-db-item="<?= esc_attr( $key ); ?>">
						<th scope="row" class="check-column">
							<input type="checkbox" id="oa-perf-db-<?= esc_attr( $key ); ?>" value="<?= esc_attr( $key ); ?>" data-label="<?= esc_attr( $item['label'] ); ?>">
						</th>
						<td>
							<label for="oa-perf-db-<?= esc_attr( $key ); ?>"><?= esc_html( $item['label'] ); ?></label>
							<?php

							if ( 'all_transients' === $key ) :

							?>

							<span class="oa-help"><?php esc_html_e( 'Clears all temporary data other plugins have stored, even data still in use. It rebuilds itself, but pages may be a little slower for a short while.', 'octave-addons' ); ?></span>

							<?php

							elseif ( 'optimize_tables' === $key ) :

							?>

							<span class="oa-help"><?php esc_html_e( 'Tidies up your database tables one at a time. A large table can be briefly unavailable while this runs, so choose a quiet time.', 'octave-addons' ); ?></span>

							<?php

							endif;

							?>
						</td>
						<td data-oa-perf-db-count>—</td>
					</tr>

					<?php

					endforeach;

					?>
				</tbody>
			</table>

			<p class="oa-perf-actions">
				<button type="button" class="button" data-oa-perf-db-counts><?php esc_html_e( 'Refresh counts', 'octave-addons' ); ?></button>
				<button type="button" class="button button-primary" data-oa-perf-db-run><?php esc_html_e( 'Clean up selected', 'octave-addons' ); ?></button>
			</p>
			<?php

			$last_text = empty( $last['time'] ) ? '' : sprintf(
				/* translators: 1: manual or scheduled, 2: relative time, 3: number removed. */
				__( 'Last %1$s cleanup %2$s removed %3$d entries.', 'octave-addons' ),
				'scheduled' === ( $last['source'] ?? '' ) ? __( 'scheduled', 'octave-addons' ) : __( 'manual', 'octave-addons' ),
				Octave_Addons_Perf_Admin::time_ago( (int) $last['time'] ),
				(int) array_sum( (array) ( $last['totals'] ?? [] ) )
			);

			?>

			<div data-oa-perf-result data-oa-perf-db-result role="status" aria-live="polite"><?php

			if ( '' !== $last_text ) :

			?><div class="notice notice-info inline oa-inline-notice"><p><?= esc_html( $last_text ); ?></p></div><?php

			endif;

			?></div>
		</div>

		<table class="form-table oa-form-table" role="presentation">
			<?php

			Octave_Addons_Fields::section( [ 'label' => __( 'Schedule', 'octave-addons' ) ] );

			$this->select_row( 'schedule', __( 'Run automatically', 'octave-addons' ), [
				'manual'     => __( 'Never (manual only)', 'octave-addons' ),
				'weekly'     => __( 'Weekly', 'octave-addons' ),
				'oa_monthly' => __( 'Monthly', 'octave-addons' ),
			], __( 'Runs in the background in short bursts, so it never slows your site down for long.', 'octave-addons' ), $s );

			Octave_Addons_Fields::row( [
				'label' => __( 'Automatic cleanup includes', 'octave-addons' ),
				'field' => function () use ( $s, $items ) {

					?>

					<fieldset class="oa-perf-checklist">
						<?php

						foreach ( self::schedulable_items() as $key ) :

						?>

						<label>
							<input type="checkbox" name="<?= esc_attr( $this->field_name( 'scheduled_items' ) ); ?>[]" value="<?= esc_attr( $key ); ?>"<?php checked( in_array( $key, (array) $s['scheduled_items'], true ) ); ?>>
							<?= esc_html( $items[ $key ]['label'] ); ?>
						</label>

						<?php

						endforeach;

						?>
					</fieldset>

					<?php

				},
			] );

			?>
		</table>

		<?php

	}

}

return new Octave_Addons_Module_Performance_Database();
