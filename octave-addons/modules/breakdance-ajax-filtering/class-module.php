<?php

/*
BREAKDANCE AJAX FILTERING
-- Turns the Breakdance Filter Bar into server-backed filtering and paging
-- The post type and taxonomy are read from the loop itself, so there is no
-- per-page connection to configure
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Breakdance_Ajax_Filtering extends Octave_Addons_Module {

	protected static array $runtime_settings = [];

	protected static bool $query_applied = false;

	protected static string $detected_post_type = '';

	protected static bool $hooks_registered = false;

	/*
	GET ID
	-- Returns the module settings key
	---------------------------------------------------------- */

	public function get_id(): string {

		return 'breakdance-ajax-filtering';

	}

	/*
	GET TITLE
	-- Returns the admin navigation label
	---------------------------------------------------------- */

	public function get_title(): string {

		return __( 'Breakdance AJAX Filtering', 'octave-addons' );

	}

	/*
	GET DESCRIPTION
	-- Describes the module in the Octave Addons settings screen
	---------------------------------------------------------- */

	public function get_description(): string {

		return __( 'Backs the Breakdance Filter Bar with a real query so filters and paging load every matching post, not just the ones already on the page.', 'octave-addons' );

	}

	/*
	GET DEFAULTS
	-- Supplies the small set of settings the module still needs
	---------------------------------------------------------- */

	public function get_defaults(): array {

		return [
			'enabled'           => false,
			'schema'            => 3,
			'all_archives'      => true,
			'page_path'         => '',
			'posts_per_page'    => self::reading_posts_per_page(),
			'navigation_mode'   => 'load_more',
			'load_more_label'   => 'Load more',
			'show_result_count' => true,
			'update_url'        => false,
			'scroll_to_results' => true,
			// Retained for the legacy Array Query helper only — not shown in the UI.
			'orderby'           => 'date',
			'order'             => 'DESC',
		];

	}

	/*
	GET SETTINGS
	-- Merges saved settings with the defaults and upgrades older layouts
	-- Schema 2 dropped the removed options and switched browser URL updates off
	-- Schema 3 releases posts per page so it follows Settings → Reading again
	---------------------------------------------------------- */

	public function get_settings( array $saved ): array {

		$defaults = $this->get_defaults();
		$schema   = absint( $saved['schema'] ?? 0 );

		if ( ! empty( $saved ) && $schema < $defaults['schema'] ) {

			$saved = array_intersect_key( $saved, $defaults );

			if ( $schema < 2 ) {

				unset( $saved['update_url'] );

			}

			unset( $saved['posts_per_page'] );

			$saved['schema'] = $defaults['schema'];

		}

		return wp_parse_args( $saved, $defaults );

	}

	/*
	SANITIZE
	-- Validates settings before WordPress stores them
	---------------------------------------------------------- */

	public function sanitize( $input ): array {

		$defaults = $this->get_defaults();
		$clean    = $defaults;

		$clean['enabled']           = ! empty( $input['enabled'] );
		$clean['all_archives']      = ! empty( $input['all_archives'] );
		$clean['page_path']         = self::sanitize_page_path( $input['page_path'] ?? '' );
		$clean['posts_per_page']    = min( 100, max( 1, absint( $input['posts_per_page'] ?? 0 ) ?: $defaults['posts_per_page'] ) );
		$clean['navigation_mode']   = self::sanitize_navigation_mode( $input['navigation_mode'] ?? $defaults['navigation_mode'] );
		$clean['load_more_label']   = isset( $input['load_more_label'] ) ? sanitize_text_field( wp_unslash( $input['load_more_label'] ) ) : $defaults['load_more_label'];
		$clean['show_result_count'] = ! empty( $input['show_result_count'] );
		$clean['update_url']        = ! empty( $input['update_url'] );
		$clean['scroll_to_results'] = ! empty( $input['scroll_to_results'] );

		if ( '' === $clean['load_more_label'] ) {

			$clean['load_more_label'] = $defaults['load_more_label'];

		}

		return $clean;

	}

	/*
	RENDER SETTINGS
	-- Outputs the short configuration list and the Breakdance requirement notice
	---------------------------------------------------------- */

	public function render_settings( array $settings ): void {

		if ( ! self::is_breakdance_available() ) :

		?>

		<div class="notice notice-error inline oa-inline-notice">
			<p><strong><?php esc_html_e( 'Breakdance is not installed or active.', 'octave-addons' ); ?></strong></p>
			<p><?php esc_html_e( 'This module will remain inactive until Breakdance is available.', 'octave-addons' ); ?></p>
		</div>

		<?php

		endif;

		?>

		<div class="notice notice-info inline oa-inline-notice">
			<p><strong><?php esc_html_e( 'This requires the Breakdance Filter to be enabled.', 'octave-addons' ); ?></strong></p>
			<p><?php esc_html_e( 'Add a Filter Bar to the Breakdance Post Loop you want to filter. Its buttons are taken over and backed by a real query, so the post type and taxonomy are read from the loop itself. Loops without a Filter Bar are left untouched.', 'octave-addons' ); ?></p>
		</div>

		<table class="form-table oa-form-table" role="presentation">

			<?php

			Octave_Addons_Fields::section(
				[
					'label' => __( 'Where it runs', 'octave-addons' ),
					'first' => true,
				]
			);

			Octave_Addons_Fields::row(
				[
					'label' => __( 'Apply to all Breakdance areas', 'octave-addons' ),
					'field' => function () use ( $settings ) {

						Octave_Addons_Fields::switch_field(
							[
								'name'    => $this->field_name( 'all_archives' ),
								'checked' => ! empty( $settings['all_archives'] ),
								'data'    => [ 'controls-row-hide' => 'oaBaaRowPagePath' ],
								'help'    => __( 'Enhances every Breakdance loop that has a Filter Bar, including templates, global blocks, and content areas. Switch off to limit it to a single page.', 'octave-addons' ),
							]
						);

					},
				]
			);

			Octave_Addons_Fields::row(
				[
					'id'    => 'oaBaaRowPagePath',
					'for'   => $this->field_id( 'page_path' ),
					'label' => __( 'Page path', 'octave-addons' ),
					'field' => function () use ( $settings ) {

						Octave_Addons_Fields::text(
							[
								'id'          => $this->field_id( 'page_path' ),
								'name'        => $this->field_name( 'page_path' ),
								'value'       => $settings['page_path'],
								'placeholder' => '/about-us/blog/',
								'help'        => __( 'Site-relative path of the only page that should be enhanced.', 'octave-addons' ),
							]
						);

					},
				]
			);

			Octave_Addons_Fields::section( [ 'label' => __( 'Results', 'octave-addons' ) ] );

			Octave_Addons_Fields::row(
				[
					'for'   => $this->field_id( 'posts_per_page' ),
					'label' => __( 'Posts per page', 'octave-addons' ),
					'field' => function () use ( $settings ) {

						Octave_Addons_Fields::text(
							[
								'id'    => $this->field_id( 'posts_per_page' ),
								'name'  => $this->field_name( 'posts_per_page' ),
								'value' => (string) $settings['posts_per_page'],
								'class' => 'small-text',
								'help'  => sprintf(
									/* translators: %d: the Settings → Reading posts per page value. */
									__( 'How many posts each filter or page request returns. Defaults to the Reading setting (%d). Maximum 100.', 'octave-addons' ),
									self::reading_posts_per_page()
								),
							]
						);

					},
				]
			);

			Octave_Addons_Fields::row(
				[
					'for'   => $this->field_id( 'navigation_mode' ),
					'label' => __( 'Results navigation', 'octave-addons' ),
					'field' => function () use ( $settings ) {

						?>

						<select
							id="<?= esc_attr( $this->field_id( 'navigation_mode' ) ); ?>"
							name="<?= esc_attr( $this->field_name( 'navigation_mode' ) ); ?>"
						>
							<option value="load_more"<?php selected( 'load_more', $settings['navigation_mode'] ); ?>><?php esc_html_e( 'Load More button', 'octave-addons' ); ?></option>
							<option value="pagination"<?php selected( 'pagination', $settings['navigation_mode'] ); ?>><?php esc_html_e( 'Numbered pagination', 'octave-addons' ); ?></option>
						</select>

						<?php

					},
				]
			);

			Octave_Addons_Fields::row(
				[
					'for'   => $this->field_id( 'load_more_label' ),
					'label' => __( 'Load More label', 'octave-addons' ),
					'field' => function () use ( $settings ) {

						Octave_Addons_Fields::text(
							[
								'id'    => $this->field_id( 'load_more_label' ),
								'name'  => $this->field_name( 'load_more_label' ),
								'value' => $settings['load_more_label'],
							]
						);

					},
				]
			);

			Octave_Addons_Fields::row(
				[
					'label' => __( 'Show result count', 'octave-addons' ),
					'field' => function () use ( $settings ) {

						Octave_Addons_Fields::switch_field(
							[
								'name'    => $this->field_name( 'show_result_count' ),
								'checked' => ! empty( $settings['show_result_count'] ),
								'help'    => __( 'Displays a message such as “12 Lifestyle posts” beneath the filter bar.', 'octave-addons' ),
							]
						);

					},
				]
			);

			Octave_Addons_Fields::section( [ 'label' => __( 'Behaviour', 'octave-addons' ) ] );

			Octave_Addons_Fields::row(
				[
					'label' => __( 'Update browser URL', 'octave-addons' ),
					'field' => function () use ( $settings ) {

						Octave_Addons_Fields::switch_field(
							[
								'name'    => $this->field_name( 'update_url' ),
								'checked' => ! empty( $settings['update_url'] ),
								'help'    => __( 'Creates shareable filter URLs and supports the browser Back button. Off by default so filtering leaves the address bar alone.', 'octave-addons' ),
							]
						);

					},
				]
			);

			Octave_Addons_Fields::row(
				[
					'label' => __( 'Scroll after loading', 'octave-addons' ),
					'field' => function () use ( $settings ) {

						Octave_Addons_Fields::switch_field(
							[
								'name'    => $this->field_name( 'scroll_to_results' ),
								'checked' => ! empty( $settings['scroll_to_results'] ),
								'help'    => __( 'Smoothly returns the viewport to the refreshed loop.', 'octave-addons' ),
							]
						);

					},
				]
			);

			?>

		</table>

		<?php

	}

	/*
	RUN
	-- Registers the query bridge, assets, and the hidden control payload
	---------------------------------------------------------- */

	public function run( array $settings ): void {

		self::$runtime_settings = $settings;

		add_action( 'breakdance_loaded', [ $this, 'register_hooks' ], 20 );

		if ( self::is_breakdance_available() ) {

			$this->register_hooks();

		}

	}

	/*
	REGISTER HOOKS
	-- Boots the module once, and only after Breakdance is available
	---------------------------------------------------------- */

	public function register_hooks(): void {

		if ( self::$hooks_registered || ! self::is_breakdance_available() ) {

			return;

		}

		self::$hooks_registered = true;

		add_shortcode( 'octave_breakdance_ajax_filters', [ $this, 'render_controls_shortcode' ] );

		if ( ! shortcode_exists( 'breakdance_ajax_controls' ) ) {

			add_shortcode( 'breakdance_ajax_controls', [ $this, 'render_controls_shortcode' ] );

		}

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_filter( 'breakdance_query_control_query', [ $this, 'filter_breakdance_query' ], 50 );
		add_action( 'pre_get_posts', [ $this, 'filter_loop_query' ], 50 );
		add_action( 'wp_footer', [ $this, 'render_automatic_controls' ], 5 );

	}

	/*
	IS BREAKDANCE AVAILABLE
	-- Confirms Breakdance completed its load lifecycle before integration starts
	---------------------------------------------------------- */

	protected static function is_breakdance_available(): bool {

		return did_action( 'breakdance_loaded' ) > 0 || class_exists( '\\Breakdance\\Elements\\Element' );

	}

	/*
	FILTER BREAKDANCE QUERY
	-- Adds filtering and page offsets to layouts using the Array Query helper
	---------------------------------------------------------- */

	public function filter_breakdance_query( $query ) {

		if ( ! is_array( $query ) || ! self::is_eligible_request() ) {

			return $query;

		}

		$post_types = isset( $query['post_type'] ) ? (array) $query['post_type'] : [ 'post' ];
		$post_types = array_filter( array_map( 'sanitize_key', $post_types ) );
		$post_type  = self::public_post_type( (string) reset( $post_types ) );

		if ( '' === $post_type ) {

			return $query;

		}

		$selected_term = self::request_term();
		$taxonomy      = self::term_taxonomy( $selected_term, $post_type );
		$search        = self::request_search();

		$query['posts_per_page']      = self::posts_per_page();
		$query['paged']               = self::request_page();
		$query['ignore_sticky_posts'] = true;

		unset( $query['offset'] );

		if ( '' !== $search ) {

			$query['s'] = $search;

		}

		if ( '' !== $taxonomy ) {

			$tax_query   = isset( $query['tax_query'] ) && is_array( $query['tax_query'] ) ? $query['tax_query'] : [];
			$tax_query[] = [
				'taxonomy' => $taxonomy,
				'field'    => 'term_id',
				'terms'    => [ $selected_term ],
			];

			$query['tax_query'] = $tax_query;

		}

		self::$query_applied      = true;
		self::$detected_post_type = $post_type;

		return $query;

	}

	/*
	FILTER LOOP QUERY
	-- Applies the selected term, search text, and page offset to the Breakdance loop
	-- Runs on pre_get_posts because a Post Loop without a Custom Query reuses the
	-- main query verbatim: on a posts page or archive Breakdance returns
	-- $wp_query->query_vars before its own query filter ever fires
	-- The post type comes from the loop itself and only the first matching loop
	-- on a request is touched
	---------------------------------------------------------- */

	public function filter_loop_query( $query ): void {

		if ( ! $query instanceof WP_Query || self::$query_applied ) {

			return;

		}

		if ( is_admin() || $query->is_feed() ) {

			return;

		}

		if ( ! self::is_eligible_request() || ! self::is_loop_query( $query ) ) {

			return;

		}

		$post_types = array_filter( array_map( 'sanitize_key', (array) $query->get( 'post_type' ) ) );
		$post_type  = self::public_post_type( empty( $post_types ) ? 'post' : (string) reset( $post_types ) );

		if ( '' === $post_type ) {

			return;

		}

		$selected_term = self::request_term();
		$taxonomy      = self::term_taxonomy( $selected_term, $post_type );
		$search        = self::request_search();

		self::$query_applied      = true;
		self::$detected_post_type = $post_type;

		$query->set( 'posts_per_page', self::posts_per_page() );
		$query->set( 'ignore_sticky_posts', true );

		// Only take over paging once the module asks for an offset, so WordPress's
		// own /page/2/ links keep working on an untouched archive.
		if ( isset( $_GET['baa_page'] ) || ! $query->is_main_query() ) {

			$query->set( 'paged', self::request_page() );
			$query->set( 'offset', '' );
			$query->set( 'nopaging', false );

		}

		if ( '' !== $search ) {

			$query->set( 's', $search );

		}

		if ( '' !== $taxonomy ) {

			$tax_query   = array_filter( (array) $query->get( 'tax_query' ) );
			$tax_query[] = [
				'taxonomy' => $taxonomy,
				'field'    => 'term_id',
				'terms'    => [ $selected_term ],
			];

			$query->set( 'tax_query', $tax_query );

		}

	}

	/*
	IS LOOP QUERY
	-- Decides whether a query is the loop being enhanced
	-- Counting queries, single-item widgets, and manual selections are skipped
	-- The main query counts on a posts page or archive, because that is exactly
	-- what Breakdance hands to a Post Loop that has no Custom Query
	-- A secondary query must originate from Breakdance unless a page path pins it
	---------------------------------------------------------- */

	protected static function is_loop_query( WP_Query $query ): bool {

		if ( '' !== (string) $query->get( 'fields' ) || ! empty( $query->get( 'post__in' ) ) ) {

			return false;

		}

		if ( 1 === (int) $query->get( 'posts_per_page' ) ) {

			return false;

		}

		if ( $query->is_main_query() ) {

			$is_loop = $query->is_home() || $query->is_archive() || $query->is_search();

		} else {

			$is_loop = self::is_breakdance_context() || '' !== self::configured_page_path();

		}

		return (bool) apply_filters( 'octave_breakdance_ajax_is_loop_query', $is_loop, $query );

	}

	/*
	IS BREAKDANCE CONTEXT
	-- Confirms the running query was started while Breakdance rendered an element
	-- Keeps "apply to all archives" from adopting an unrelated secondary query
	---------------------------------------------------------- */

	protected static function is_breakdance_context(): bool {

		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 30 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace

		foreach ( $frames as $frame ) {

			if ( isset( $frame['class'] ) && 0 === stripos( (string) $frame['class'], 'breakdance' ) ) {

				return true;

			}

			if ( isset( $frame['file'] ) && false !== stripos( (string) $frame['file'], 'breakdance' ) ) {

				return true;

			}

		}

		return false;

	}

	/*
	ENQUEUE ASSETS
	-- Loads the small frontend bridge whenever the enabled module can be used
	---------------------------------------------------------- */

	public function enqueue_assets(): void {

		if ( $this->is_breakdance_builder_request() ) {

			return;

		}

		$assets_dir = OCTAVE_ADDONS_DIR . 'modules/breakdance-ajax-filtering/assets/';
		$assets_url = OCTAVE_ADDONS_URL . 'modules/breakdance-ajax-filtering/assets/';
		$css_path   = $assets_dir . 'filtering.css';
		$js_path    = $assets_dir . 'filtering.js';

		wp_enqueue_style(
			'octave-breakdance-ajax-filtering',
			$assets_url . 'filtering.css',
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : OCTAVE_ADDONS_VERSION
		);

		wp_enqueue_script(
			'octave-breakdance-ajax-filtering',
			$assets_url . 'filtering.js',
			[],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : OCTAVE_ADDONS_VERSION,
			true
		);

		wp_localize_script(
			'octave-breakdance-ajax-filtering',
				'OctaveBreakdanceAjax',
				[
					'errorMessage'    => __( 'The posts could not be loaded. Please try again.', 'octave-addons' ),
					'loadedMessage'   => __( 'Posts updated.', 'octave-addons' ),
					'loadingMessage'  => __( 'Loading posts…', 'octave-addons' ),
					'loadingMore'     => __( 'Loading…', 'octave-addons' ),
					'nextLabel'       => __( 'Next', 'octave-addons' ),
					'noResults'       => __( 'No posts match this filter.', 'octave-addons' ),
					'paginationLabel' => __( 'Posts pagination', 'octave-addons' ),
					'postPlural'      => __( 'posts', 'octave-addons' ),
					'postSingular'    => __( 'post', 'octave-addons' ),
					'previousLabel'   => __( 'Previous', 'octave-addons' ),
				]
			);

	}

	/*
	RENDER CONTROLS SHORTCODE
	-- Kept so existing manual placements keep working after the settings rewrite
	---------------------------------------------------------- */

	public function render_controls_shortcode( $attributes = [] ): string {

		return $this->render_controls( false );

	}

	/*
	RENDER AUTOMATIC CONTROLS
	-- Prints the hidden payload the frontend bridge reads
	-- Skipped entirely when no loop was adopted, so unrelated pages do no work
	---------------------------------------------------------- */

	public function render_automatic_controls(): void {

		if ( ! self::$query_applied || $this->is_breakdance_builder_request() ) {

			return;

		}

		echo $this->render_controls( true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	}

	/*
	RENDER CONTROLS
	-- Outputs the term lookup and result counts the Filter Bar bridge needs
	-- Nothing here is visible: Breakdance's own bar stays the visible interface
	---------------------------------------------------------- */

	protected function render_controls( bool $automatic ): string {

		$settings      = self::get_runtime_settings();
		$post_type     = self::resolved_post_type();
		$terms         = self::get_filter_terms( $post_type );
		$selected_term = self::request_term();
		$search        = self::request_search();
		$total_count   = self::get_total_count( $post_type );
		$current_count = self::get_current_count( $post_type, $selected_term, $search, $total_count );

		if ( empty( $terms ) ) {

			return '';

		}

		ob_start();

		?>

		<div
			class="oa-breakdance-ajax-controls"
			data-base-url="<?= esc_url( self::get_base_url() ); ?>"
			data-post-type="<?= esc_attr( $post_type ); ?>"
			data-posts-per-page="<?= esc_attr( (string) self::posts_per_page() ); ?>"
			data-total-count="<?= esc_attr( (string) $total_count ); ?>"
			data-current-count="<?= esc_attr( (string) $current_count ); ?>"
			data-show-result-count="<?= ! empty( $settings['show_result_count'] ) ? 'true' : 'false'; ?>"
			data-navigation-mode="<?= esc_attr( self::sanitize_navigation_mode( $settings['navigation_mode'] ) ); ?>"
			data-load-more-label="<?= esc_attr( (string) $settings['load_more_label'] ); ?>"
			data-update-url="<?= ! empty( $settings['update_url'] ) ? 'true' : 'false'; ?>"
			data-scroll-to-results="<?= ! empty( $settings['scroll_to_results'] ) ? 'true' : 'false'; ?>"
			data-auto="<?= $automatic ? 'true' : 'false'; ?>"
		>
			<div class="oa-breakdance-ajax-controls__form" hidden>

				<button
					class="oa-breakdance-ajax-filter__button"
					type="button"
					data-term="0"
					data-slug="all"
					data-taxonomy=""
					data-count="<?= esc_attr( (string) $total_count ); ?>"
					data-label=""
				></button>

				<?php

				foreach ( $terms as $term ) :

				?>

				<button
					class="oa-breakdance-ajax-filter__button"
					type="button"
					data-term="<?= esc_attr( (string) $term->term_id ); ?>"
					data-slug="<?= esc_attr( $term->slug ); ?>"
					data-taxonomy="<?= esc_attr( $term->taxonomy ); ?>"
					data-count="<?= esc_attr( (string) $term->count ); ?>"
					data-label="<?= esc_attr( $term->name ); ?>"
				></button>

				<?php

				endforeach;

				?>

			</div>

			<div class="oa-breakdance-ajax-feedback" aria-live="polite" aria-atomic="true"></div>
		</div>

		<?php

		return (string) ob_get_clean();

	}

	/*
	GET FILTER TERMS
	-- Collects every populated term across the post type's public taxonomies
	-- The bridge matches Breakdance's slug-based filter values against this list,
	-- which is why no taxonomy has to be chosen by hand
	---------------------------------------------------------- */

	protected static function get_filter_terms( string $post_type ): array {

		$taxonomies = [];

		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {

			if ( ! empty( $taxonomy->public ) && ! empty( $taxonomy->show_ui ) ) {

				$taxonomies[] = $taxonomy->name;

			}

		}

		if ( empty( $taxonomies ) ) {

			return [];

		}

		$terms = get_terms(
			[
				'taxonomy'   => $taxonomies,
				'hide_empty' => true,
				'orderby'    => 'name',
				'order'      => 'ASC',
			]
		);

		if ( is_wp_error( $terms ) ) {

			return [];

		}

		return (array) apply_filters( 'octave_breakdance_ajax_filter_terms', $terms, $post_type );

	}

	/*
	BUILD QUERY ARGS
	-- Creates Breakdance Array Query arguments for the current URL state
	-- Retained for layouts that still call the helper from an Array Query field
	---------------------------------------------------------- */

	public static function build_query_args( array $overrides = [] ): array {

		$settings      = wp_parse_args( $overrides, self::get_runtime_settings() );
		$post_type     = self::public_post_type( sanitize_key( (string) ( $settings['post_type'] ?? 'post' ) ) );
		$post_type     = '' === $post_type ? 'post' : $post_type;
		$selected_term = self::request_term();
		$taxonomy      = self::term_taxonomy( $selected_term, $post_type );
		$search        = self::request_search();
		$orderby       = self::sanitize_orderby( $settings['orderby'] ?? 'date' );
		$order         = 'ASC' === strtoupper( (string) ( $settings['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';

		$query_args = [
			'post_type'           => $post_type,
			'post_status'         => 'publish',
			'posts_per_page'      => self::posts_per_page( $settings ),
			'paged'               => self::request_page(),
			'orderby'             => $orderby,
			'order'               => $order,
			'ignore_sticky_posts' => true,
		];

		if ( '' !== $search ) {

			$query_args['s'] = $search;

		}

		if ( '' !== $taxonomy ) {

			$query_args['tax_query'] = [
				[
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => [ $selected_term ],
				],
			];

		}

		return (array) apply_filters( 'octave_breakdance_ajax_query_args', $query_args, $settings );

	}

	/*
	GET RUNTIME SETTINGS
	-- Returns enabled module settings or the module defaults
	---------------------------------------------------------- */

	protected static function get_runtime_settings(): array {

		if ( ! empty( self::$runtime_settings ) ) {

			return self::$runtime_settings;

		}

		$module = new self();

		return $module->get_defaults();

	}

	/*
	POSTS PER PAGE
	-- Returns the configured page size within safe bounds
	---------------------------------------------------------- */

	protected static function posts_per_page( ?array $settings = null ): int {

		$settings = null === $settings ? self::get_runtime_settings() : $settings;
		$per_page = absint( $settings['posts_per_page'] ?? 0 );

		if ( $per_page < 1 ) {

			$per_page = self::reading_posts_per_page();

		}

		return min( 100, max( 1, $per_page ) );

	}

	/*
	READING POSTS PER PAGE
	-- Returns the site's Settings → Reading page size, used as the default
	---------------------------------------------------------- */

	protected static function reading_posts_per_page(): int {

		return min( 100, max( 1, absint( get_option( 'posts_per_page', 10 ) ) ) );

	}

	/*
	RESOLVED POST TYPE
	-- Returns the post type detected on the rendered loop, or a safe fallback
	---------------------------------------------------------- */

	protected static function resolved_post_type(): string {

		if ( '' !== self::$detected_post_type ) {

			return self::$detected_post_type;

		}

		$queried = get_queried_object();

		if ( $queried instanceof WP_Post_Type && $queried->public ) {

			return $queried->name;

		}

		if ( $queried instanceof WP_Term ) {

			$taxonomy = get_taxonomy( $queried->taxonomy );

			if ( $taxonomy && ! empty( $taxonomy->object_type ) ) {

				$post_type = self::public_post_type( (string) reset( $taxonomy->object_type ) );

				if ( '' !== $post_type ) {

					return $post_type;

				}

			}

		}

		return 'post';

	}

	/*
	PUBLIC POST TYPE
	-- Returns the slug only when it belongs to a public post type
	---------------------------------------------------------- */

	protected static function public_post_type( string $post_type ): string {

		$object = get_post_type_object( $post_type );

		return $object && $object->public ? $object->name : '';

	}

	/*
	TERM TAXONOMY
	-- Resolves the taxonomy straight from the selected term
	-- Removes the need to configure a taxonomy and keeps mixed filter bars working
	---------------------------------------------------------- */

	protected static function term_taxonomy( int $term_id, string $post_type ): string {

		if ( $term_id < 1 ) {

			return '';

		}

		$term = get_term( $term_id );

		if ( ! $term instanceof WP_Term ) {

			return '';

		}

		return in_array( $term->taxonomy, get_object_taxonomies( $post_type ), true ) ? $term->taxonomy : '';

	}

	/*
	GET BASE URL
	-- Resolves the canonical first page used for new filters
	---------------------------------------------------------- */

	protected static function get_base_url(): string {

		$queried_id = get_queried_object_id();
		$permalink  = $queried_id > 0 ? get_permalink( $queried_id ) : '';

		if ( is_string( $permalink ) && '' !== $permalink ) {

			return $permalink;

		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$request_uri = strtok( $request_uri, '?' );

		return home_url( is_string( $request_uri ) ? $request_uri : '/' );

	}

	/*
	IS BREAKDANCE BUILDER REQUEST
	-- Prevents the live bridge from replacing elements inside builder previews
	---------------------------------------------------------- */

	protected function is_breakdance_builder_request(): bool {

		$breakdance_mode = isset( $_GET['breakdance'] ) ? sanitize_key( wp_unslash( $_GET['breakdance'] ) ) : '';
		$admin_page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$iframe_mode     = isset( $_GET['breakdance_iframe'] ) ? sanitize_key( wp_unslash( $_GET['breakdance_iframe'] ) ) : '';

		if ( 'builder' === $breakdance_mode || isset( $_GET['breakdance_frame'] ) ) {

			return true;

		}

		if ( '' !== $iframe_mode || isset( $_GET['breakdance_open_document'] ) ) {

			return true;

		}

		if ( is_admin() && false !== strpos( $admin_page, 'breakdance' ) ) {

			return true;

		}

		return false;

	}

	/*
	CONFIGURED PAGE PATH
	-- Returns the single page the module is pinned to, or an empty string
	---------------------------------------------------------- */

	protected static function configured_page_path(): string {

		$settings = self::get_runtime_settings();

		if ( ! empty( $settings['all_archives'] ) ) {

			return '';

		}

		return self::sanitize_page_path( $settings['page_path'] ?? '' );

	}

	/*
	IS ELIGIBLE REQUEST
	-- Confirms the current request is one the module should enhance
	-- A request carrying the module's own parameters is always treated as ours,
	-- so a stale page path cannot silently disable filtering
	---------------------------------------------------------- */

	protected static function is_eligible_request(): bool {

		$page_path = self::configured_page_path();

		if ( '' === $page_path ) {

			return true;

		}

		if ( isset( $_GET['baa_term'] ) || isset( $_GET['baa_page'] ) || isset( $_GET['baa_search'] ) ) {

			return true;

		}

		$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$request_path = wp_parse_url( $request_uri, PHP_URL_PATH );
		$request_path = is_string( $request_path ) ? $request_path : '/';

		// Treat /blog/page/2/ as the same page so native pagination still matches.
		$request_path = (string) preg_replace( '#/page/\d+/?$#', '/', $request_path );
		$request_path = self::sanitize_page_path( $request_path );

		return $page_path === $request_path;

	}

	/*
	REQUEST TERM
	-- Reads the public taxonomy filter from the URL
	---------------------------------------------------------- */

	protected static function request_term(): int {

		return isset( $_GET['baa_term'] ) ? absint( $_GET['baa_term'] ) : 0;

	}

	/*
	REQUEST PAGE
	-- Reads the AJAX page offset without changing WordPress permalink routing
	---------------------------------------------------------- */

	protected static function request_page(): int {

		return isset( $_GET['baa_page'] ) ? max( 1, absint( $_GET['baa_page'] ) ) : 1;

	}

	/*
	REQUEST SEARCH
	-- Reads the public search filter from the URL
	---------------------------------------------------------- */

	protected static function request_search(): string {

		return isset( $_GET['baa_search'] ) ? sanitize_text_field( wp_unslash( $_GET['baa_search'] ) ) : '';

	}

	/*
	GET TOTAL COUNT
	-- Returns the number of published items for the detected post type
	---------------------------------------------------------- */

	protected static function get_total_count( string $post_type ): int {

		$counts = wp_count_posts( $post_type );

		if ( ! is_object( $counts ) || ! isset( $counts->publish ) ) {

			return 0;

		}

		return absint( $counts->publish );

	}

	/*
	GET CURRENT COUNT
	-- Returns the real number of matching posts for the selected term and search
	-- Term counts are not used because they include every post type in the term,
	-- which made Load More stop early or offer a page that does not exist
	---------------------------------------------------------- */

	protected static function get_current_count( string $post_type, int $selected_term, string $search, int $total_count ): int {

		$taxonomy = self::term_taxonomy( $selected_term, $post_type );

		if ( '' === $search && '' === $taxonomy ) {

			return $total_count;

		}

		$query_args = [
			'fields'                 => 'ids',
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];

		if ( '' !== $search ) {

			$query_args['s'] = $search;

		}

		if ( '' !== $taxonomy ) {

			$query_args['tax_query'] = [
				[
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => [ $selected_term ],
				],
			];

		}

		$count_query = new WP_Query( $query_args );
		$count       = absint( $count_query->found_posts );

		wp_reset_postdata();

		return $count;

	}

	/*
	SANITIZE PAGE PATH
	-- Normalizes an optional site-relative path used to scope the module
	---------------------------------------------------------- */

	protected static function sanitize_page_path( $value ): string {

		$path = trim( sanitize_text_field( wp_unslash( (string) $value ) ) );

		if ( '' === $path ) {

			return '';

		}

		$path = '/' . trim( $path, '/' );

		return trailingslashit( $path );

	}

	/*
	SANITIZE NAVIGATION MODE
	-- Restricts navigation to the supported AJAX interfaces
	---------------------------------------------------------- */

	protected static function sanitize_navigation_mode( $value ): string {

		return 'pagination' === sanitize_key( (string) $value ) ? 'pagination' : 'load_more';

	}

	/*
	SANITIZE ORDERBY
	-- Restricts query ordering to public, predictable fields
	---------------------------------------------------------- */

	protected static function sanitize_orderby( $value ): string {

		$allowed = [ 'date', 'modified', 'title', 'menu_order', 'comment_count' ];
		$value   = sanitize_key( (string) $value );

		return in_array( $value, $allowed, true ) ? $value : 'date';

	}

}

/*
OCTAVE BREAKDANCE AJAX QUERY
-- Exposes stable Breakdance Array Query helpers even while the UI module is disabled
---------------------------------------------------------- */

if ( ! function_exists( 'octave_breakdance_ajax_query' ) ) {

	function octave_breakdance_ajax_query( array $overrides = [] ): array {

		return Octave_Addons_Module_Breakdance_Ajax_Filtering::build_query_args( $overrides );

	}

}

/*
BREAKDANCE AJAX ARCHIVE QUERY ALIAS
-- Preserves compatibility with the helper name used by the reference plugin
---------------------------------------------------------- */

if ( ! function_exists( 'baa_breakdance_query' ) ) {

	function baa_breakdance_query( array $overrides = [] ): array {

		return octave_breakdance_ajax_query( $overrides );

	}

}

return new Octave_Addons_Module_Breakdance_Ajax_Filtering();
