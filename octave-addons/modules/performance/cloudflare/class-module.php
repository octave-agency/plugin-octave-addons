<?php

/*
PERFORMANCE: CLOUDFLARE
-- Purges Cloudflare's cache from WordPress using a scoped API token. Joins
-- the cache manager's purge reports as its own layer: targeted URL purges
-- are queued and sent from cron after content changes, and a purge
-- everything is only sent for an explicit full purge by an administrator,
-- and only when that option is switched on
-- The token is saved in its own non-autoloaded option, never echoed back
-- into the page, and can instead be defined in wp-config.php:
--   define( 'OCTAVE_ADDONS_CLOUDFLARE_ZONE_ID', '...' );
--   define( 'OCTAVE_ADDONS_CLOUDFLARE_API_TOKEN', '...' );
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

require_once dirname( __DIR__ ) . '/services/bootstrap.php';

class Octave_Addons_Module_Performance_Cloudflare extends Octave_Addons_Module {

	use Octave_Addons_Perf_Field_Rows;

	public function get_id(): string {

		return 'performance-cloudflare';

	}

	public function get_title(): string {

		return __( 'Cloudflare', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Purges Cloudflare\'s cache when content changes, and on demand, using a scoped API token.', 'octave-addons' );

	}

	public function get_order(): int {

		return 70;

	}

	public function get_defaults(): array {

		return [
			'enabled'       => false,
			'zone_id'       => '',
			'auto_purge'    => true,
			'purge_on_full' => false,
		];

	}

	/*
	SANITIZE
	-- A new token is stored separately and never kept in the settings array.
	-- An empty token field keeps the saved token
	---------------------------------------------------------- */

	public function sanitize( $input ): array {

		$clean   = parent::sanitize( $input );
		$zone_id = strtolower( sanitize_text_field( wp_unslash( $input['zone_id'] ?? '' ) ) );
		$token   = trim( sanitize_text_field( wp_unslash( $input['api_token'] ?? '' ) ) );

		$clean['zone_id']       = Octave_Addons_Perf_Cloudflare::is_valid_zone_id( $zone_id ) ? $zone_id : '';
		$clean['auto_purge']    = ! empty( $input['auto_purge'] );
		$clean['purge_on_full'] = ! empty( $input['purge_on_full'] );

		if ( ! empty( $input['remove_token'] ) ) {

			delete_option( Octave_Addons_Perf_Cloudflare::TOKEN_OPTION );

		} elseif ( '' !== $token ) {

			update_option( Octave_Addons_Perf_Cloudflare::TOKEN_OPTION, $token, false );

		}

		return $clean;

	}

	public function run( array $s ): void {

		add_filter( 'octave_addons_perf_purge_all_layers', static function ( $report, $reason ) use ( $s ) {

			return self::purge_all_layer( (array) $report, (string) $reason, $s );

		}, 10, 2 );

		add_filter( 'octave_addons_perf_purge_url_layers', static function ( $report, $urls, $reason ) use ( $s ) {

			return self::purge_url_layer( (array) $report, (array) $urls, (string) $reason, $s );

		}, 10, 3 );

		add_action( Octave_Addons_Perf_Cloudflare::FLUSH_HOOK, [ 'Octave_Addons_Perf_Cloudflare', 'flush_queue' ] );

	}

	/*
	PURGE ALL LAYER
	-- Purge everything only with the option on, and only for a manual full
	-- purge or a Breakdance change that restyles every page. Settings saves
	-- and other automatic purges never empty the whole zone
	---------------------------------------------------------- */

	public static function purge_all_layer( array $report, string $reason, array $s ): array {

		$label = __( 'Cloudflare', 'octave-addons' );

		if ( ! Octave_Addons_Perf_Cloudflare::is_configured() ) {

			$report['cloudflare'] = [ 'label' => $label, 'status' => 'error', 'message' => __( 'Not configured: add a Zone ID and API token.', 'octave-addons' ) ];

			return $report;

		}

		if ( ! in_array( $reason, [ 'manual', 'breakdance' ], true ) || empty( $s['purge_on_full'] ) ) {

			$report['cloudflare'] = [ 'label' => $label, 'status' => 'skipped', 'message' => __( 'Not purged. Turn on "Purge Cloudflare during a full purge" to include it.', 'octave-addons' ) ];

			return $report;

		}

		$result = Octave_Addons_Perf_Cloudflare::purge_everything();

		$report['cloudflare'] = [ 'label' => $label, 'status' => $result['ok'] ? 'success' : 'error', 'message' => $result['message'] ];

		return $report;

	}

	/*
	PURGE URL LAYER
	-- Manual purges run now; automatic ones are queued for cron
	---------------------------------------------------------- */

	public static function purge_url_layer( array $report, array $urls, string $reason, array $s ): array {

		$label = __( 'Cloudflare', 'octave-addons' );

		if ( ! Octave_Addons_Perf_Cloudflare::is_configured() ) {

			$report['cloudflare'] = [ 'label' => $label, 'status' => 'error', 'message' => __( 'Not configured: add a Zone ID and API token.', 'octave-addons' ) ];

			return $report;

		}

		if ( 'manual' === $reason ) {

			$result = Octave_Addons_Perf_Cloudflare::purge_files( $urls );

			$report['cloudflare'] = [ 'label' => $label, 'status' => $result['ok'] ? 'success' : 'error', 'message' => $result['message'] ];

			return $report;

		}

		if ( empty( $s['auto_purge'] ) ) {

			return $report;

		}

		Octave_Addons_Perf_Cloudflare::queue( $urls );

		$report['cloudflare'] = [ 'label' => $label, 'status' => 'queued', 'message' => __( 'Queued; sent to Cloudflare within a minute.', 'octave-addons' ) ];

		return $report;

	}

	/*
	RENDER SETTINGS
	---------------------------------------------------------- */

	public function render_settings( array $s ): void {

		$zone_constant  = defined( 'OCTAVE_ADDONS_CLOUDFLARE_ZONE_ID' );
		$token_constant = defined( 'OCTAVE_ADDONS_CLOUDFLARE_API_TOKEN' );
		$has_token      = '' !== (string) get_option( Octave_Addons_Perf_Cloudflare::TOKEN_OPTION, '' );

		?>

		<p class="oa-help oa-help--intro"><?php esc_html_e( 'Create a token in Cloudflare under My Profile > API Tokens with the single permission Zone > Cache Purge > Purge, limited to this site\'s zone. The token never leaves the server.', 'octave-addons' ); ?></p>

		<table class="form-table oa-form-table" role="presentation">
			<?php

			Octave_Addons_Fields::section( [ 'label' => __( 'Connection', 'octave-addons' ), 'first' => true ] );

			Octave_Addons_Fields::row( [
				'label' => __( 'Zone ID', 'octave-addons' ),
				'for'   => $this->field_id( 'zone_id' ),
				'field' => function () use ( $s, $zone_constant ) {

					if ( $zone_constant ) {

						echo '<p class="oa-help">' . esc_html__( 'Defined in wp-config.php.', 'octave-addons' ) . '</p>';

						return;

					}

					Octave_Addons_Fields::text( [
						'name'        => $this->field_name( 'zone_id' ),
						'id'          => $this->field_id( 'zone_id' ),
						'value'       => $s['zone_id'],
						'placeholder' => '0123456789abcdef0123456789abcdef',
						'class'       => 'regular-text code',
						'help'        => __( 'Shown on the zone\'s Overview page in Cloudflare.', 'octave-addons' ),
					] );

				},
			] );

			Octave_Addons_Fields::row( [
				'label' => __( 'API token', 'octave-addons' ),
				'for'   => $this->field_id( 'api_token' ),
				'field' => function () use ( $token_constant, $has_token ) {

					if ( $token_constant ) {

						echo '<p class="oa-help">' . esc_html__( 'Defined in wp-config.php.', 'octave-addons' ) . '</p>';

						return;

					}

					?>

					<input type="password" id="<?= esc_attr( $this->field_id( 'api_token' ) ); ?>" name="<?= esc_attr( $this->field_name( 'api_token' ) ); ?>" class="regular-text code" autocomplete="new-password" spellcheck="false" placeholder="<?= $has_token ? esc_attr__( 'Saved. Leave blank to keep it.', 'octave-addons' ) : ''; ?>">

					<?php

					if ( $has_token ) :

					?>

					<label class="oa-perf-inline-check">
						<input type="checkbox" name="<?= esc_attr( $this->field_name( 'remove_token' ) ); ?>" value="1">
						<?php esc_html_e( 'Remove the saved token', 'octave-addons' ); ?>
					</label>

					<?php

					endif;

				},
			] );

			Octave_Addons_Fields::row( [
				'label' => __( 'Test', 'octave-addons' ),
				'field' => function () {

					$fields = 'zone_id:' . $this->field_id( 'zone_id' ) . ' token:' . $this->field_id( 'api_token' );

					?>

					<button type="button" class="button" data-oa-perf-action="oa_perf_cf_test" data-fields="<?= esc_attr( $fields ); ?>" data-result="oa-perf-cf-result"><?php esc_html_e( 'Test connection', 'octave-addons' ); ?></button>
					<span class="oa-help"><?php esc_html_e( 'Tests the values above, saved or not, and runs again when they change.', 'octave-addons' ); ?></span>
					<div id="oa-perf-cf-result" data-oa-perf-result role="status" aria-live="polite"></div>

					<?php

				},
			] );

			Octave_Addons_Fields::section( [ 'label' => __( 'Automatic purging', 'octave-addons' ) ] );

			$this->switch_row( 'auto_purge', __( 'Purge changed pages', 'octave-addons' ), __( 'Recommended. When content is published, updated or removed, its URL, the home page and related archives are purged in a batch shortly after.', 'octave-addons' ), $s );
			$this->switch_row( 'purge_on_full', __( 'Purge Cloudflare during a full purge', 'octave-addons' ), __( 'When an administrator uses Clear Performance Cache or the admin bar, or a Breakdance header, footer, template, global block or global style changes, also purge everything in the zone. Settings saves never purge everything.', 'octave-addons' ), $s );

			?>
		</table>

		<?php

	}

}

return new Octave_Addons_Module_Performance_Cloudflare();
