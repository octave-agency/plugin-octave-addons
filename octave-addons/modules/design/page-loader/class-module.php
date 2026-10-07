<?php

/*
MODULE: PAGE TRANSITIONS
-- Two independent systems sharing one colour palette:
-- An initial page loader shown on a cold, full page load, rendered early
-- through wp_body_open and removed on real readiness signals
-- Internal page transitions that cover the page on same-origin link clicks,
-- navigate normally, and reveal the destination
-- Every loader timing derives from one base value, the Loader duration,
-- and every transition timing from another, the Transition duration
-- A core script that custom code cannot replace owns the lifecycle, the
-- hard timeouts and the scroll lock, and a CSS failsafe releases the page
-- even if that script never runs
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Page_Loader extends Octave_Addons_Module {

	/**
	 * Asset folder relative to the plugin root.
	 */
	protected const ASSETS = 'modules/design/page-loader/assets/';

	/**
	 * The Transition duration the COVER_MS and REVEAL_MS values are written
	 * against; a saved duration scales them in proportion.
	 */
	protected const TRANSITION_BASE_MS = 600;

	/**
	 * How long each transition takes to cover the page, in ms at the base
	 * duration. Navigation starts as soon as this has passed.
	 */
	protected const COVER_MS = [
		'slide-up'       => 420,
		'curve-rise'     => 560,
		'circle-reveal'  => 520,
		'column-stagger' => 580,
		'diagonal-sweep' => 520,
		'split-curtain'  => 520,
		'brand-wipe'     => 460,
		'soft-fade'      => 320,
		'custom'         => 400,
	];

	/**
	 * How long each transition takes to reveal the destination, in ms at the
	 * base duration.
	 */
	protected const REVEAL_MS = [
		'slide-up'       => 620,
		'curve-rise'     => 760,
		'circle-reveal'  => 760,
		'column-stagger' => 720,
		'diagonal-sweep' => 700,
		'split-curtain'  => 740,
		'brand-wipe'     => 720,
		'soft-fade'      => 460,
		'replay-loader'  => 0,
		'custom'         => 600,
	];

	/**
	 * Strips in the Column Stagger transition.
	 */
	protected const COLUMNS = 5;

	/**
	 * What a loader shows in its middle. '' follows the style: text for
	 * Brand Counter, Curtain Reveal and Custom only, nothing for Orbital.
	 */
	protected const LOADER_CONTENT = [ '' => true, 'text' => true, 'logo' => true, 'logo-text' => true, 'none' => true ];

	/**
	 * Loader styles that show the chosen content.
	 */
	protected const CONTENT_LOADERS = [ 'brand-counter', 'curtain', 'orbital', 'custom' ];

	/**
	 * What a transition shows while it covers the page. '' follows the
	 * style: the site name for Brand Wipe, nothing for the rest.
	 */
	protected const TRANSITION_CONTENT = [ '' => true, 'none' => true, 'text' => true, 'logo' => true, 'image' => true, 'spinner' => true ];

	/**
	 * Text size multipliers.
	 */
	protected const TEXT_SIZES = [ 'small' => 0.8, 'medium' => 1, 'large' => 1.35, 'xl' => 1.75 ];

	/**
	 * Whether the overlays were printed through wp_body_open.
	 */
	protected $rendered = false;

	public function get_id(): string {

		return 'page-loader';

	}

	public function get_title(): string {

		return __( 'Page Loader & Transitions', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'A branded loader while your site first opens, and smooth transitions between its pages.', 'octave-addons' );

	}

	public function get_defaults(): array {

		return [
			'enabled'                => false,

			'loader_enabled'         => false,
			'loader_type'            => 'brand-counter',
			'loader_frequency'       => 'session',
			'loader_duration'        => 900,
			'loader_text'            => '',
			'loader_show_progress'   => true,
			'loader_logo'            => 0,
			'loader_image'           => 0,
			'loader_content'         => '',
			'loader_tagline'         => false,
			'loader_css'             => '',
			'loader_js'              => '',

			'transitions_enabled'    => false,
			'performance'            => false,
			'transition_type'        => 'slide-up',
			'transition_loader_type' => 'match',
			'transition_duration'    => 600,
			'transition_content'     => '',
			'transition_css'         => '',
			'transition_js'          => '',

			'accent_source'          => 'brand',
			'accent_color'           => '#3B82F6',
			'background_source'      => 'custom',
			'background_color'       => '#0E0E0F',
			'text_source'            => 'custom',
			'text_color'             => '#F4F2EE',
			'tagline'                => '',
			'text_size'              => 'medium',
		];

	}

	/*
	LOADER TYPES
	-- Initial-loader presets, in dropdown order
	---------------------------------------------------------- */

	protected function loader_types(): array {

		return [
			'brand-counter' => __( 'Brand Counter', 'octave-addons' ),
			'logo-mask'     => __( 'Logo Mask', 'octave-addons' ),
			'image-window'  => __( 'Image Window', 'octave-addons' ),
			'curtain'       => __( 'Curtain Reveal', 'octave-addons' ),
			'orbital'       => __( 'Orbital Signal', 'octave-addons' ),
			'custom'        => __( 'Custom only', 'octave-addons' ),
		];

	}

	/*
	TRANSITION TYPES
	-- Internal page-transition presets, in dropdown order
	---------------------------------------------------------- */

	protected function transition_types(): array {

		return [
			'slide-up'       => __( 'Slide Up', 'octave-addons' ),
			'curve-rise'     => __( 'Curve Rise', 'octave-addons' ),
			'circle-reveal'  => __( 'Circle Reveal', 'octave-addons' ),
			'column-stagger' => __( 'Column Stagger', 'octave-addons' ),
			'diagonal-sweep' => __( 'Diagonal Sweep', 'octave-addons' ),
			'split-curtain'  => __( 'Split Curtain', 'octave-addons' ),
			'brand-wipe'     => __( 'Brand Wipe', 'octave-addons' ),
			'soft-fade'      => __( 'Frosted Fade', 'octave-addons' ),
			'replay-loader'  => __( 'Replay Loader', 'octave-addons' ),
			'custom'         => __( 'Custom only', 'octave-addons' ),
		];

	}

	public function sanitize( $input ): array {

		$clean    = $this->get_defaults();
		$defaults = $clean;

		$clean['enabled']              = ! empty( $input['enabled'] );
		$clean['loader_enabled']       = ! empty( $input['loader_enabled'] );
		$clean['loader_show_progress'] = ! empty( $input['loader_show_progress'] );
		$clean['transitions_enabled']  = ! empty( $input['transitions_enabled'] );
		$clean['performance']          = ! empty( $input['performance'] );

		$loader_type          = sanitize_key( $input['loader_type'] ?? '' );
		$clean['loader_type'] = array_key_exists( $loader_type, $this->loader_types() ) ? $loader_type : $defaults['loader_type'];

		$transition_type          = sanitize_key( $input['transition_type'] ?? '' );
		$clean['transition_type'] = array_key_exists( $transition_type, $this->transition_types() ) ? $transition_type : $defaults['transition_type'];

		$replay                          = sanitize_key( $input['transition_loader_type'] ?? '' );
		$clean['transition_loader_type'] = 'match' === $replay || array_key_exists( $replay, $this->loader_types() ) ? $replay : 'match';

		$frequency                    = sanitize_key( $input['loader_frequency'] ?? '' );
		$clean['loader_frequency']    = in_array( $frequency, [ 'every', 'once' ], true ) ? $frequency : 'session';
		$clean['loader_tagline']      = ! empty( $input['loader_tagline'] );
		$clean['tagline']             = sanitize_text_field( $input['tagline'] ?? '' );

		$content                   = sanitize_key( $input['loader_content'] ?? '' );
		$clean['loader_content']   = array_key_exists( $content, self::LOADER_CONTENT ) ? $content : '';
		$content                   = sanitize_key( $input['transition_content'] ?? '' );
		$clean['transition_content'] = array_key_exists( $content, self::TRANSITION_CONTENT ) ? $content : '';
		$size                      = sanitize_key( $input['text_size'] ?? '' );
		$clean['text_size']        = array_key_exists( $size, self::TEXT_SIZES ) ? $size : 'medium';
		$clean['loader_duration']     = max( 400, min( 2000, absint( $input['loader_duration'] ?? 900 ) ) );
		$clean['transition_duration'] = max( 300, min( 1500, absint( $input['transition_duration'] ?? 600 ) ) );
		$clean['loader_text']         = sanitize_text_field( $input['loader_text'] ?? '' );

		$clean['loader_logo']  = Octave_Addons_Fields::sanitize_media_asset( $input['loader_logo'] ?? 0 );
		$clean['loader_image'] = Octave_Addons_Fields::sanitize_media_asset( $input['loader_image'] ?? 0 );

		foreach ( [ 'accent', 'background', 'text' ] as $role ) {

			$clean[ $role . '_source' ] = Octave_Addons_Colors::sanitize_source( $input[ $role . '_source' ] ?? '', $defaults[ $role . '_source' ] );
			$clean[ $role . '_color' ]  = sanitize_hex_color( $input[ $role . '_color' ] ?? '' ) ?: $defaults[ $role . '_color' ];

		}

		// Raw CSS/JS, like the Scroll Animations overrides: only users with
		// manage_options can save this screen, and KSES would break the code.
		foreach ( [ 'loader_css', 'loader_js', 'transition_css', 'transition_js' ] as $key ) {

			$clean[ $key ] = isset( $input[ $key ] ) ? (string) $input[ $key ] : '';

		}

		return $clean;

	}

	/*
	RENDER SETTINGS
	-- Three cards: the page loader, page transitions, and the look both
	-- share. Rows only appear when the chosen style uses them
	---------------------------------------------------------- */

	public function render_settings( array $s ): void {

		$colors   = Octave_Addons_Colors::resolved_values();
		$controls = [
			'loaderType'         => $this->field_id( 'loader_type' ),
			'loaderContent'      => $this->field_id( 'loader_content' ),
			'loaderTagline'      => $this->field_id( 'loader_tagline' ),
			'transitionType'     => $this->field_id( 'transition_type' ),
			'transitionContent'  => $this->field_id( 'transition_content' ),
			'replayType'         => $this->field_id( 'transition_loader_type' ),
			'progress'           => $this->field_id( 'loader_show_progress' ),
			'text'               => $this->field_id( 'loader_text' ),
			'tagline'            => $this->field_id( 'tagline' ),
			'textSize'           => $this->field_id( 'text_size' ),
			'duration'           => $this->field_id( 'loader_duration' ),
			'transitionDuration' => $this->field_id( 'transition_duration' ),
			'logo'               => $this->field_id( 'loader_logo' ),
			'image'              => $this->field_id( 'loader_image' ),
			'accentSource'       => $this->field_id( 'accent_source' ),
			'accentColor'        => $this->field_id( 'accent_color' ) . '-value',
			'backgroundSource'   => $this->field_id( 'background_source' ),
			'backgroundColor'    => $this->field_id( 'background_color' ) . '-value',
			'textSource'         => $this->field_id( 'text_source' ),
			'textColor'          => $this->field_id( 'text_color' ) . '-value',
			'siteName'           => get_bloginfo( 'name' ),
		];

		?>

		<div class="notice notice-warning inline oa-inline-notice">
			<p><?php esc_html_e( 'Loaders and transitions cover the page while they play, so visitors wait a little longer to see and use it, and speed tests such as Lighthouse score it lower. Turn on "Keep pages fast" in Customisation to keep every page visible the moment it arrives.', 'octave-addons' ); ?></p>
		</div>

		<?php

		$this->render_loader_card( $s, $controls, $colors );
		$this->render_transition_card( $s, $controls, $colors );
		$this->render_customisation_card( $s );

	}

	/*
	CARD
	-- Opens one settings card: a title, a sentence, an optional on/off
	-- switch that shows or hides the card's rows, and its table
	---------------------------------------------------------- */

	protected function open_card( string $title, string $description, string $switch_key = '', array $rows = [], array $s = [] ): void {

		?>

		<section class="oa-settings-card">
			<header class="oa-settings-card-head">
				<div>
					<h3><?= esc_html( $title ); ?></h3>
					<p><?= esc_html( $description ); ?></p>
				</div>

				<?php

				if ( '' !== $switch_key ) {

					Octave_Addons_Fields::switch_field( [
						'id'      => $this->field_id( $switch_key ),
						'name'    => $this->field_name( $switch_key ),
						'checked' => ! empty( $s[ $switch_key ] ),
						'data'    => [ 'controls-row' => implode( ',', $rows ) ],
					] );

				}

				?>

			</header>
			<table class="form-table oa-form-table" role="presentation">

		<?php

	}

	protected function close_card(): void {

		?>

			</table>
		</section>

		<?php

	}

	/*
	LOADER CARD
	---------------------------------------------------------- */

	protected function render_loader_card( array $s, array $controls, array $colors ): void {

		$rows = [ 'oaPlRowLoaderType', 'oaPlRowLoaderPreview', 'oaPlRowLoaderContent', 'oaPlRowLoaderTagline', 'oaPlRowProgress', 'oaPlRowFrequency', 'oaPlRowDuration', 'oaPlRowLoaderCustom', 'oaPlRowLoaderCss', 'oaPlRowLoaderJs' ];

		$this->open_card( __( 'Page loader', 'octave-addons' ), __( 'A short animation shown while a page first loads, then lifted away to reveal it.', 'octave-addons' ), 'loader_enabled', $rows, $s );

		Octave_Addons_Fields::row( [
			'id'    => 'oaPlRowLoaderType',
			'for'   => $this->field_id( 'loader_type' ),
			'label' => __( 'Style', 'octave-addons' ),
			'field' => function () use ( $s ) {

				$this->render_select(
					'loader_type',
					$this->loader_types(),
					$s['loader_type'],
					'oaPlRowLoaderContent:' . implode( '|', self::CONTENT_LOADERS ) . ',oaPlRowProgress:brand-counter'
				);

				?>

				<span class="oa-help"><?php esc_html_e( 'Logo Mask always shows your logo and Image Window your image, both set in Customisation.', 'octave-addons' ); ?></span>
				<?php

			},
		] );

		Octave_Addons_Fields::row( [
			'id'    => 'oaPlRowLoaderPreview',
			'label' => __( 'Preview', 'octave-addons' ),
			'field' => function () use ( $controls, $colors ) {

				Octave_Addons_Fields::motion_preview( 'loader', $controls, $colors );

			},
		] );

		Octave_Addons_Fields::row( [
			'id'    => 'oaPlRowLoaderContent',
			'for'   => $this->field_id( 'loader_content' ),
			'label' => __( 'Show', 'octave-addons' ),
			'field' => function () use ( $s ) {

				$this->render_select( 'loader_content', [
					''          => __( 'What the style normally shows', 'octave-addons' ),
					'text'      => __( 'Brand text', 'octave-addons' ),
					'logo'      => __( 'Logo', 'octave-addons' ),
					'logo-text' => __( 'Logo and brand text', 'octave-addons' ),
					'none'      => __( 'Nothing', 'octave-addons' ),
				], $s['loader_content'] );

			},
		] );

		Octave_Addons_Fields::row( [
			'id'    => 'oaPlRowLoaderTagline',
			'label' => __( 'Tagline', 'octave-addons' ),
			'field' => function () use ( $s ) {

				Octave_Addons_Fields::switch_field( [
					'id'      => $this->field_id( 'loader_tagline' ),
					'name'    => $this->field_name( 'loader_tagline' ),
					'checked' => ! empty( $s['loader_tagline'] ),
					'help'    => __( 'Shows the tagline from Customisation under the brand text or logo.', 'octave-addons' ),
				] );

			},
		] );

		Octave_Addons_Fields::row( [
			'id'    => 'oaPlRowProgress',
			'label' => __( 'Percentage', 'octave-addons' ),
			'field' => function () use ( $s ) {

				Octave_Addons_Fields::switch_field( [
					'id'      => $this->field_id( 'loader_show_progress' ),
					'name'    => $this->field_name( 'loader_show_progress' ),
					'checked' => ! empty( $s['loader_show_progress'] ),
					'help'    => __( 'Counts up to 100% as the page actually loads.', 'octave-addons' ),
				] );

			},
		] );

		Octave_Addons_Fields::row( [
			'id'    => 'oaPlRowFrequency',
			'for'   => $this->field_id( 'loader_frequency' ),
			'label' => __( 'How often', 'octave-addons' ),
			'field' => function () use ( $s ) {

				$this->render_select( 'loader_frequency', [
					'session' => __( 'Once per visit', 'octave-addons' ),
					'once'    => __( 'Only on someone\'s first visit', 'octave-addons' ),
					'every'   => __( 'Every time a page loads', 'octave-addons' ),
				], $s['loader_frequency'] );

				?>

				<span class="oa-help"><?php esc_html_e( 'A visit ends when the browser tab is closed. "First visit" is remembered on that device.', 'octave-addons' ); ?></span>
				<?php

			},
		] );

		Octave_Addons_Fields::row( [
			'id'    => 'oaPlRowDuration',
			'for'   => $this->field_id( 'loader_duration' ),
			'label' => __( 'Speed', 'octave-addons' ),
			'field' => function () use ( $s ) {

				Octave_Addons_Fields::number( [
					'id'     => $this->field_id( 'loader_duration' ),
					'name'   => $this->field_name( 'loader_duration' ),
					'value'  => $s['loader_duration'],
					'min'    => 400,
					'max'    => 2000,
					'step'   => 50,
					'suffix' => 'ms',
					'help'   => __( 'Higher numbers play the loader more slowly. 900 is the default. The loader stays up just long enough to finish its animation on fast pages, and never longer than 8 seconds.', 'octave-addons' ),
				] );

			},
		] );

		$this->render_custom_code( 'oaPlRowLoaderCustom', 'oaPlRowLoaderCss', 'oaPlRowLoaderJs', 'loader', $s );

		$this->close_card();

	}

	/*
	TRANSITION CARD
	---------------------------------------------------------- */

	protected function render_transition_card( array $s, array $controls, array $colors ): void {

		$rows = [ 'oaPlRowTransitionType', 'oaPlRowTransitionPreview', 'oaPlRowTransitionContent', 'oaPlRowTransitionDuration', 'oaPlRowReplay', 'oaPlRowTransitionCustom', 'oaPlRowTransitionCss', 'oaPlRowTransitionJs' ];

		$this->open_card( __( 'Page transitions', 'octave-addons' ), __( 'Covers the page when someone clicks a link to another page on your site, then reveals the new page.', 'octave-addons' ), 'transitions_enabled', $rows, $s );

		Octave_Addons_Fields::row( [
			'id'    => 'oaPlRowTransitionType',
			'for'   => $this->field_id( 'transition_type' ),
			'label' => __( 'Style', 'octave-addons' ),
			'field' => function () use ( $s ) {

				// Replay Loader plays the page loader, so it has its own speed and content.
				$covering = implode( '|', array_diff( array_keys( $this->transition_types() ), [ 'replay-loader' ] ) );

				$this->render_select( 'transition_type', $this->transition_types(), $s['transition_type'], 'oaPlRowReplay:replay-loader,oaPlRowTransitionDuration:' . $covering . ',oaPlRowTransitionContent:' . $covering );

				?>

				<span class="oa-help"><?php esc_html_e( 'To skip the transition for a particular link, add data-oa-no-transition to the link or to something around it.', 'octave-addons' ); ?></span>
				<?php

			},
		] );

		Octave_Addons_Fields::row( [
			'id'    => 'oaPlRowTransitionPreview',
			'label' => __( 'Preview', 'octave-addons' ),
			'field' => function () use ( $controls, $colors ) {

				Octave_Addons_Fields::motion_preview( 'transition', $controls, $colors );

			},
		] );

		Octave_Addons_Fields::row( [
			'id'    => 'oaPlRowTransitionContent',
			'for'   => $this->field_id( 'transition_content' ),
			'label' => __( 'Show while covered', 'octave-addons' ),
			'field' => function () use ( $s ) {

				$this->render_select( 'transition_content', [
					''        => __( 'What the style normally shows', 'octave-addons' ),
					'none'    => __( 'Nothing', 'octave-addons' ),
					'text'    => __( 'Brand text', 'octave-addons' ),
					'logo'    => __( 'Logo', 'octave-addons' ),
					'image'   => __( 'Image', 'octave-addons' ),
					'spinner' => __( 'Loading spinner', 'octave-addons' ),
				], $s['transition_content'] );

				?>

				<span class="oa-help"><?php esc_html_e( 'Appears in the middle of the screen while the next page loads. Brand Wipe normally shows your brand text; the other styles show nothing.', 'octave-addons' ); ?></span>
				<?php

			},
		] );

		Octave_Addons_Fields::row( [
			'id'    => 'oaPlRowTransitionDuration',
			'for'   => $this->field_id( 'transition_duration' ),
			'label' => __( 'Speed', 'octave-addons' ),
			'field' => function () use ( $s ) {

				Octave_Addons_Fields::number( [
					'id'     => $this->field_id( 'transition_duration' ),
					'name'   => $this->field_name( 'transition_duration' ),
					'value'  => $s['transition_duration'],
					'min'    => 300,
					'max'    => 1500,
					'step'   => 50,
					'suffix' => 'ms',
					'help'   => __( 'Higher numbers play the transition more slowly. 600 is the default.', 'octave-addons' ),
				] );

			},
		] );

		Octave_Addons_Fields::row( [
			'id'    => 'oaPlRowReplay',
			'for'   => $this->field_id( 'transition_loader_type' ),
			'label' => __( 'Loader to play', 'octave-addons' ),
			'field' => function () use ( $s ) {

				$this->render_select( 'transition_loader_type', array_merge(
					[ 'match' => __( 'Same as the page loader', 'octave-addons' ) ],
					$this->loader_types()
				), $s['transition_loader_type'] );

				?>

				<span class="oa-help"><?php esc_html_e( 'Plays a page loader between pages, using its speed and content settings and your Customisation. While the page loader is on, its own style is used.', 'octave-addons' ); ?></span>
				<?php

			},
		] );

		$this->render_custom_code( 'oaPlRowTransitionCustom', 'oaPlRowTransitionCss', 'oaPlRowTransitionJs', 'transition', $s );

		$this->close_card();

	}

	/*
	CUSTOMISATION CARD
	-- The brand content and colours both systems use, and the speed mode
	---------------------------------------------------------- */

	protected function render_customisation_card( array $s ): void {

		$this->open_card( __( 'Customisation', 'octave-addons' ), __( 'Your brand text, logo, image and colours, shared by the page loader and page transitions.', 'octave-addons' ) );

		Octave_Addons_Fields::section( [ 'label' => __( 'Brand', 'octave-addons' ), 'first' => true ] );

		Octave_Addons_Fields::row( [
			'for'   => $this->field_id( 'loader_text' ),
			'label' => __( 'Brand text', 'octave-addons' ),
			'field' => function () use ( $s ) {

				Octave_Addons_Fields::text( [
					'id'          => $this->field_id( 'loader_text' ),
					'name'        => $this->field_name( 'loader_text' ),
					'value'       => $s['loader_text'],
					'placeholder' => get_bloginfo( 'name' ),
					'help'        => __( 'Leave blank to use your site name.', 'octave-addons' ),
				] );

			},
		] );

		Octave_Addons_Fields::row( [
			'for'   => $this->field_id( 'tagline' ),
			'label' => __( 'Tagline', 'octave-addons' ),
			'field' => function () use ( $s ) {

				Octave_Addons_Fields::text( [
					'id'          => $this->field_id( 'tagline' ),
					'name'        => $this->field_name( 'tagline' ),
					'value'       => $s['tagline'],
					'placeholder' => get_bloginfo( 'description' ),
					'help'        => __( 'A short line shown under the brand text when the page loader\'s Tagline is on.', 'octave-addons' ),
				] );

			},
		] );

		Octave_Addons_Fields::row( [
			'for'   => $this->field_id( 'loader_logo' ) . '-select',
			'label' => __( 'Logo', 'octave-addons' ),
			'field' => function () use ( $s ) {

				Octave_Addons_Fields::media_asset( [
					'id'    => $this->field_id( 'loader_logo' ),
					'name'  => $this->field_name( 'loader_logo' ),
					'value' => $s['loader_logo'],
					'title' => __( 'Choose a logo', 'octave-addons' ),
					'help'  => __( 'Any image, or an SVG if your site allows them. Without a logo, the brand text is shown instead.', 'octave-addons' ),
				] );

			},
		] );

		Octave_Addons_Fields::row( [
			'for'   => $this->field_id( 'loader_image' ) . '-select',
			'label' => __( 'Image', 'octave-addons' ),
			'field' => function () use ( $s ) {

				Octave_Addons_Fields::media_asset( [
					'id'    => $this->field_id( 'loader_image' ),
					'name'  => $this->field_name( 'loader_image' ),
					'value' => $s['loader_image'],
					'help'  => __( 'Used by the Image Window loader, and by transitions set to show an image.', 'octave-addons' ),
				] );

			},
		] );

		Octave_Addons_Fields::row( [
			'for'   => $this->field_id( 'text_size' ),
			'label' => __( 'Text size', 'octave-addons' ),
			'field' => function () use ( $s ) {

				$this->render_select( 'text_size', [
					'small'  => __( 'Small', 'octave-addons' ),
					'medium' => __( 'Medium', 'octave-addons' ),
					'large'  => __( 'Large', 'octave-addons' ),
					'xl'     => __( 'Extra large', 'octave-addons' ),
				], $s['text_size'] );

			},
		] );

		Octave_Addons_Fields::section( [ 'label' => __( 'Colours', 'octave-addons' ) ] );

		$this->render_color_rows( 'accent', __( 'Accent colour', 'octave-addons' ), __( 'Progress lines, wipes and highlights.', 'octave-addons' ), $s );
		$this->render_color_rows( 'background', __( 'Background colour', 'octave-addons' ), __( 'The colour that covers the page.', 'octave-addons' ), $s );
		$this->render_color_rows( 'text', __( 'Text colour', 'octave-addons' ), __( 'Brand text, tagline and percentage.', 'octave-addons' ), $s );

		Octave_Addons_Fields::section( [ 'label' => __( 'Speed', 'octave-addons' ) ] );

		Octave_Addons_Fields::row( [
			'label' => __( 'Keep pages fast', 'octave-addons' ),
			'field' => function () use ( $s ) {

				Octave_Addons_Fields::switch_field( [
					'name'    => $this->field_name( 'performance' ),
					'checked' => ! empty( $s['performance'] ),
					'help'    => __( 'Recommended. The page loader never shows and a new page is never covered as it arrives: transitions only play on the page being left. Visitors who prefer reduced motion, and the browser\'s back and forward buttons, never see either.', 'octave-addons' ),
				] );

			},
		] );

		$this->close_card();

	}

	/*
	RENDER CUSTOM CODE
	-- A collapsed "Custom setup" group with CSS and JavaScript rows
	---------------------------------------------------------- */

	protected function render_custom_code( string $toggle_id, string $css_row, string $js_row, string $system, array $s ): void {

		?>

		<tr id="<?= esc_attr( $toggle_id ); ?>">
			<th colspan="2" class="oa-section-heading oa-section-heading--toggle">
				<button type="button" class="oa-section-toggle" aria-expanded="false"
				        aria-controls="<?= esc_attr( $css_row . ' ' . $js_row ); ?>"
				        data-controls-row="<?= esc_attr( $css_row . ',' . $js_row ); ?>">
					<span><?php esc_html_e( 'Custom code', 'octave-addons' ); ?></span>
					<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
				</button>
			</th>
		</tr>

		<?php

		if ( 'loader' === $system ) {

			$this->render_code_row( $css_row, 'loader_css', __( 'Loader CSS', 'octave-addons' ), '#oa-page-loader { }', __( 'For developers. Added after the loader\'s own styles. While the loader shows, the page has the class oa-loader-active, then oa-loader-exit as it lifts away; --oa-progress counts from 0 to 1.', 'octave-addons' ), $s );
			$this->render_code_row( $js_row, 'loader_js', __( 'Loader JavaScript', 'octave-addons' ), "document.addEventListener( 'oa-loader:progress', function ( event ) { } );", __( 'For developers. Runs after Octave\'s own loader script. Listen for oa-loader:start, oa-loader:progress (event.detail.progress), oa-loader:exit and oa-loader:done. The loader still always closes after 8 seconds.', 'octave-addons' ), $s );

			return;

		}

		$this->render_code_row( $css_row, 'transition_css', __( 'Transition CSS', 'octave-addons' ), '#oa-page-transition { }', __( 'For developers. Added after the transition\'s own styles. The page has the class oa-transition-out while covering, then oa-transition-in and oa-transition-reveal on the new page. Use --oa-transition-duration to match the Speed setting.', 'octave-addons' ), $s );
		$this->render_code_row( $js_row, 'transition_js', __( 'Transition JavaScript', 'octave-addons' ), "document.addEventListener( 'oa-transition:out', function ( event ) { } );", __( 'For developers. Runs after Octave\'s own transition script. Listen for oa-transition:out (event.detail.url), oa-transition:in and oa-transition:done.', 'octave-addons' ), $s );

	}

	/*
	RENDER SELECT
	-- A plain select, optionally driving conditional rows
	---------------------------------------------------------- */

	protected function render_select( string $key, array $options, string $selected, string $controls = '' ): void {

		?>

		<select id="<?= esc_attr( $this->field_id( $key ) ); ?>"
		        name="<?= esc_attr( $this->field_name( $key ) ); ?>"<?= $controls ? ' data-controls-row="' . esc_attr( $controls ) . '"' : ''; ?>>
			<?php

			foreach ( $options as $value => $label ) {

				?>

				<option value="<?= esc_attr( $value ); ?>" <?php selected( $selected, $value ); ?>><?= esc_html( $label ); ?></option>
				<?php

			}

			?>

		</select>
		<?php

	}

	/*
	RENDER CODE ROW
	-- A custom CSS or JavaScript textarea row
	---------------------------------------------------------- */

	protected function render_code_row( string $row_id, string $key, string $label, string $placeholder, string $help, array $s ): void {

		Octave_Addons_Fields::row( [
			'id'    => $row_id,
			'for'   => $this->field_id( $key ),
			'label' => $label,
			'field' => function () use ( $key, $placeholder, $help, $s ) {

				Octave_Addons_Fields::textarea( [
					'id'          => $this->field_id( $key ),
					'name'        => $this->field_name( $key ),
					'value'       => $s[ $key ],
					'class'       => 'oa-code-editor',
					'rows'        => 8,
					'spellcheck'  => false,
					'placeholder' => $placeholder,
					'help'        => $help,
				] );

			},
		] );

	}

	/*
	RENDER COLOR ROWS
	-- A colour-source select plus a custom hex row shown only for Custom,
	-- following the Text Selection architecture
	---------------------------------------------------------- */

	protected function render_color_rows( string $role, string $label, string $help, array $s ): void {

		$row_id = 'oaPlRowColor' . ucfirst( $role );

		Octave_Addons_Fields::row( [
			'for'   => $this->field_id( $role . '_source' ),
			'label' => $label,
			'field' => function () use ( $role, $row_id, $help, $s ) {

				?>

				<select id="<?= esc_attr( $this->field_id( $role . '_source' ) ); ?>"
				        name="<?= esc_attr( $this->field_name( $role . '_source' ) ); ?>"
				        data-controls-row="<?= esc_attr( $row_id ); ?>" data-controls-value="custom">
					<?php Octave_Addons_Colors::render_options( $s[ $role . '_source' ] ); ?>
					<option value="custom" <?php selected( $s[ $role . '_source' ], 'custom' ); ?>><?php esc_html_e( 'Custom colour', 'octave-addons' ); ?></option>
				</select>
				<span class="oa-help"><?= esc_html( $help ); ?></span>
				<?php

			},
		] );

		Octave_Addons_Fields::row( [
			'id'    => $row_id,
			'for'   => $this->field_id( $role . '_color' ),
			'label' => sprintf(
				/* translators: %s: colour role, e.g. Accent colour. */
				__( 'Custom %s', 'octave-addons' ),
				strtolower( $label )
			),
			'field' => function () use ( $role, $s ) {

				Octave_Addons_Fields::color( [
					'id'    => $this->field_id( $role . '_color' ),
					'name'  => $this->field_name( $role . '_color' ),
					'value' => $s[ $role . '_color' ],
				] );

			},
		] );

	}

	/*
	RUN
	-- Registers the frontend hooks when either system is on
	---------------------------------------------------------- */

	public function run( array $s ): void {

		if ( empty( $s['loader_enabled'] ) && empty( $s['transitions_enabled'] ) ) {

			return;

		}

		add_action( 'wp_enqueue_scripts', function () use ( $s ) {

			$this->enqueue_assets( $s );

		} );

		add_action( 'wp_body_open', function () use ( $s ) {

			$this->render_overlays( $s );

		}, 1 );

	}

	/*
	SHOULD RUN
	-- Frontend HTML pages only: never the admin, login, REST, feeds, AJAX,
	-- previews, builder or editor canvases, embeds, or WooCommerce cart,
	-- checkout and account views
	---------------------------------------------------------- */

	protected function should_run(): bool {

		if ( is_admin() || wp_doing_ajax() || wp_is_json_request() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {

			return false;

		}

		if ( is_feed() || is_embed() || is_preview() || is_customize_preview() || is_robots() ) {

			return false;

		}

		return ! self::is_builder_request() && ! self::is_sensitive_woocommerce_view();

	}

	/*
	ACTIVE TYPES
	-- Resolves what this request needs: the loader type (for the initial
	-- loader or a Replay Loader transition) and the transition type
	---------------------------------------------------------- */

	protected function active_types( array $s ): array {

		$transition = ! empty( $s['transitions_enabled'] ) ? $s['transition_type'] : '';
		$loader     = ! empty( $s['loader_enabled'] ) ? $s['loader_type'] : '';

		// Performance mode never covers a page as it loads, so no loader is
		// needed, and a Replay Loader transition has nothing left to play.
		if ( ! empty( $s['performance'] ) ) {

			return [ '', 'replay-loader' === $transition ? '' : $transition ];

		}

		// One loader element serves both systems, so the initial loader's own
		// style replays whenever it is on.
		if ( 'replay-loader' === $transition && '' === $loader ) {

			$loader = 'match' === $s['transition_loader_type'] ? $s['loader_type'] : $s['transition_loader_type'];

		}

		return [ $loader, $transition ];

	}

	/*
	ENQUEUE ASSETS
	-- Core CSS and the lifecycle script load in the head so the overlays are
	-- styled before first paint. Only the selected presets' CSS loads.
	---------------------------------------------------------- */

	protected function enqueue_assets( array $s ): void {

		if ( ! $this->should_run() ) {

			return;

		}

		list( $loader, $transition ) = $this->active_types( $s );

		// Performance mode can leave nothing to play; then nothing loads.
		if ( '' === $loader && '' === $transition ) {

			return;

		}

		$this->enqueue_style( 'octave-addons-page-motion', 'core.css', [] );

		$css_handle = 'octave-addons-page-motion';

		if ( '' !== $loader && 'custom' !== $loader ) {

			$css_handle = 'octave-addons-page-loader';
			$this->enqueue_style( $css_handle, 'loaders/' . $loader . '.css', [ 'octave-addons-page-motion' ] );

		}

		if ( '' !== $transition && ! in_array( $transition, [ 'custom', 'replay-loader' ], true ) ) {

			$this->enqueue_style( 'octave-addons-page-transition', 'transitions/' . $transition . '.css', [ 'octave-addons-page-motion' ] );

		}

		wp_add_inline_style( 'octave-addons-page-motion', $this->root_css( $s ) );

		if ( '' !== $loader && '' !== trim( $s['loader_css'] ) ) {

			wp_add_inline_style( $css_handle, $s['loader_css'] );

		}

		if ( '' !== $transition && '' !== trim( $s['transition_css'] ) ) {

			$handle = wp_style_is( 'octave-addons-page-transition', 'enqueued' ) ? 'octave-addons-page-transition' : $css_handle;

			wp_add_inline_style( $handle, $s['transition_css'] );

		}

		$core = self::asset( self::ASSETS . 'core.js' );

		wp_enqueue_script( 'octave-addons-page-motion', $core['url'], [], $core['version'], false );
		wp_add_inline_script( 'octave-addons-page-motion', 'window.oaPageMotion = ' . wp_json_encode( $this->script_config( $s, $loader, $transition ) ) . ';', 'before' );

		$custom = [
			'loader'     => '' !== $loader ? $s['loader_js'] : '',
			'transition' => '' !== $transition ? $s['transition_js'] : '',
		];

		foreach ( $custom as $system => $code ) {

			if ( '' === trim( $code ) ) {

				continue;

			}

			$handle = 'octave-addons-page-' . $system . '-custom';

			wp_register_script( $handle, false, [ 'octave-addons-page-motion' ], null, true );
			wp_enqueue_script( $handle );
			wp_add_inline_script( $handle, $code );

		}

	}

	/*
	ROOT CSS
	-- Colours and both base durations as custom properties. A Breakdance
	-- source follows its variable, with the saved hex as fallback for sites
	-- where the variable is missing.
	---------------------------------------------------------- */

	protected function root_css( array $s ): string {

		return sprintf(
			':root{--oa-pm-accent:%s;--oa-pm-bg:%s;--oa-pm-fg:%s;--oa-loader-duration:%dms;--oa-transition-duration:%dms;--oa-pm-text-scale:%s;}',
			$this->color_value( 'accent', $s ),
			$this->color_value( 'background', $s ),
			$this->color_value( 'text', $s ),
			(int) $s['loader_duration'],
			(int) $s['transition_duration'],
			(string) ( self::TEXT_SIZES[ $s['text_size'] ?? 'medium' ] ?? 1 )
		);

	}

	protected function color_value( string $role, array $s ): string {

		$sources = Octave_Addons_Colors::sources();
		$source  = $s[ $role . '_source' ];
		$hex     = sanitize_hex_color( $s[ $role . '_color' ] ) ?: $this->get_defaults()[ $role . '_color' ];

		return isset( $sources[ $source ] ) ? 'var(' . $sources[ $source ]['variable'] . ', ' . $hex . ')' : $hex;

	}

	/*
	SCRIPT CONFIG
	-- Whitelisted values only; nothing typed into a text field reaches it
	---------------------------------------------------------- */

	protected function script_config( array $s, string $loader, string $transition ): array {

		$exclude = [ wp_parse_url( wp_login_url(), PHP_URL_PATH ), wp_parse_url( admin_url(), PHP_URL_PATH ) ];

		foreach ( [ 'cart', 'checkout', 'myaccount' ] as $page ) {

			if ( function_exists( 'wc_get_page_id' ) && wc_get_page_id( $page ) > 0 ) {

				$exclude[] = wp_parse_url( (string) get_permalink( wc_get_page_id( $page ) ), PHP_URL_PATH );

			}

		}

		$scale = (int) $s['transition_duration'] / self::TRANSITION_BASE_MS;

		return [
			'loader'      => ! empty( $s['loader_enabled'] ) && empty( $s['performance'] ),
			'performance' => ! empty( $s['performance'] ),
			'frequency'   => $s['loader_frequency'],
			'duration'    => (int) $s['loader_duration'],
			'transition'  => $transition,
			'replay'      => 'replay-loader' === $transition,
			'cover'       => 'replay-loader' === $transition ? (int) round( $s['loader_duration'] / 3 ) : (int) round( ( self::COVER_MS[ $transition ] ?? 400 ) * $scale ),
			'reveal'      => (int) round( ( self::REVEAL_MS[ $transition ] ?? 600 ) * $scale ),
			'exclude'     => array_values( array_unique( array_filter( $exclude ) ) ),
		];

	}

	/*
	RENDER OVERLAYS
	-- Printed at the top of the body. Both overlays are hidden by CSS unless
	-- the head script decides to show them, so without JavaScript nothing
	-- appears. A theme without wp_body_open simply gets no overlay, and the
	-- head script releases its scroll lock on DOMContentLoaded.
	---------------------------------------------------------- */

	protected function render_overlays( array $s ): void {

		if ( $this->rendered || ! $this->should_run() ) {

			return;

		}

		$this->rendered = true;

		list( $loader, $transition ) = $this->active_types( $s );

		$text = '' !== $s['loader_text'] ? $s['loader_text'] : get_bloginfo( 'name' );

		if ( '' !== $loader ) {

			$this->render_loader( $loader, $text, $s );

		}

		if ( '' !== $transition && 'replay-loader' !== $transition ) {

			?>

			<div id="oa-page-transition" class="oa-transition oa-transition--<?= esc_attr( $transition ); ?>" aria-hidden="true">
				<span class="oa-transition__layer"></span>
				<span class="oa-transition__layer oa-transition__layer--b"></span>
				<?php

				if ( 'column-stagger' === $transition ) {

					for ( $i = 0; $i < self::COLUMNS; $i++ ) {

						printf( '<span class="oa-transition__col" style="--oa-i:%d"></span>', (int) $i );

					}

				}

				$content = $this->transition_content( $transition, $s );

				if ( '' !== $content ) :

				?>

				<span class="oa-transition__content oa-transition__content--<?= esc_attr( $content ); ?>"><?php $this->print_transition_content( $content, $text, $s ); ?></span>
				<?php

				endif;

				?>

			</div>
			<?php

		}

	}

	/*
	RENDER LOADER
	-- Decorative markup per preset, kept out of the accessibility tree.
	-- Media presets fall back to the loader text when their attachment is
	-- missing, so a deleted image never leaves a broken frame.
	---------------------------------------------------------- */

	protected function render_loader( string $type, string $text, array $s ): void {

		$logo  = 'logo-mask' === $type ? Octave_Addons_Fields::media_asset_url( $s['loader_logo'] ) : '';
		$image = 'image-window' === $type ? Octave_Addons_Fields::media_asset_url( $s['loader_image'] ) : '';

		?>

		<div id="oa-page-loader" class="oa-loader oa-loader--<?= esc_attr( $type ); ?>" aria-hidden="true">
			<div class="oa-loader__inner">
				<?php

				if ( 'brand-counter' === $type ) :

				?>

				<?php $this->print_mark( $type, $text, $s ); ?>
				<span class="oa-loader__meter">
					<span class="oa-loader__line"><span class="oa-loader__bar"></span></span>
					<?php

					if ( ! empty( $s['loader_show_progress'] ) ) :

					?>

					<span class="oa-loader__count"><span data-oa-loader-count>0</span></span>
					<?php

					endif;

					?>

				</span>
				<?php

				elseif ( 'logo-mask' === $type ) :

				?>

				<span class="oa-loader__logo">
					<?php

					if ( '' !== $logo ) :

					?>

					<img src="<?= esc_url( $logo ); ?>" alt="" decoding="async">
					<?php

					else :

					?>

					<span class="oa-loader__brand"><?= esc_html( $text ); ?></span>
					<?php

					endif;

					?>

				</span>
				<span class="oa-loader__line"><span class="oa-loader__bar"></span></span>
				<?php

				elseif ( 'image-window' === $type ) :

				?>

				<span class="oa-loader__window<?= '' === $image ? ' oa-loader__window--empty' : ''; ?>">
					<?php

					if ( '' !== $image ) :

					?>

					<img src="<?= esc_url( $image ); ?>" alt="" decoding="async">
					<?php

					endif;

					?>

				</span>
				<?php

				elseif ( 'curtain' === $type ) :

				?>

				<span class="oa-loader__panel oa-loader__panel--a"></span>
				<span class="oa-loader__panel oa-loader__panel--b"></span>
				<?php $this->print_mark( $type, $text, $s ); ?>
				<?php

				elseif ( 'orbital' === $type ) :

				?>

				<svg class="oa-loader__orbit" viewBox="0 0 120 120" focusable="false">
					<circle class="oa-loader__ring" cx="60" cy="60" r="44"></circle>
					<circle class="oa-loader__arc" cx="60" cy="60" r="44" pathLength="100"></circle>
					<g class="oa-loader__tracker"><circle cx="60" cy="16" r="3"></circle></g>
				</svg>
				<?php

				$this->print_mark( $type, $text, $s );

				else :

				?>

				<?php

				$this->print_mark( $type, $text, $s, ' data-oa-loader-text' );

				endif;

				?>

			</div>
		</div>
		<?php

	}

	/*
	PRINT MARK
	-- What a loader shows in its middle: the brand text, the logo, both or
	-- nothing, with the tagline under it when chosen. A missing logo falls
	-- back to the text. The wrapper keeps .oa-loader__brand, which each
	-- style positions and animates
	---------------------------------------------------------- */

	protected function print_mark( string $type, string $text, array $s, string $attributes = '' ): void {

		$content = (string) $s['loader_content'];

		if ( '' === $content ) {

			$content = 'orbital' === $type ? 'none' : 'text';

		}

		$logo    = in_array( $content, [ 'logo', 'logo-text' ], true ) ? Octave_Addons_Fields::media_asset_url( $s['loader_logo'] ) : '';
		$tagline = ! empty( $s['loader_tagline'] ) ? trim( (string) $s['tagline'] ) : '';

		if ( 'none' === $content && '' === $tagline ) {

			return;

		}

		$show_text = 'text' === $content || 'logo-text' === $content || ( 'logo' === $content && '' === $logo );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string from this class.
		echo '<span class="oa-loader__brand"' . $attributes . '>';

		if ( '' !== $logo ) {

			echo '<img class="oa-pm-logo" src="' . esc_url( $logo ) . '" alt="" decoding="async">';

		}

		if ( $show_text ) {

			echo '<span class="oa-pm-text">' . esc_html( $text ) . '</span>';

		}

		if ( '' !== $tagline ) {

			echo '<span class="oa-pm-tagline">' . esc_html( $tagline ) . '</span>';

		}

		echo '</span>';

	}

	/*
	TRANSITION CONTENT
	-- What a covering transition shows in the middle, '' for nothing. The
	-- style's own choice is the site name for Brand Wipe only
	---------------------------------------------------------- */

	protected function transition_content( string $transition, array $s ): string {

		$content = (string) $s['transition_content'];

		if ( '' === $content ) {

			$content = 'brand-wipe' === $transition ? 'text' : 'none';

		}

		return 'none' === $content ? '' : $content;

	}

	protected function print_transition_content( string $content, string $text, array $s ): void {

		$logo  = 'logo' === $content ? Octave_Addons_Fields::media_asset_url( $s['loader_logo'] ) : '';
		$image = 'image' === $content ? Octave_Addons_Fields::media_asset_url( $s['loader_image'] ) : '';

		if ( '' !== $logo ) {

			echo '<img class="oa-pm-logo" src="' . esc_url( $logo ) . '" alt="" decoding="async">';

		} elseif ( '' !== $image ) {

			echo '<img class="oa-pm-image" src="' . esc_url( $image ) . '" alt="" decoding="async">';

		} elseif ( 'spinner' === $content ) {

			echo '<span class="oa-pm-spinner"></span>';

		} else {

			// Text, and the fallback for a logo or image that is not set.
			echo '<span class="oa-pm-text">' . esc_html( $text ) . '</span>';

		}

	}

	/*
	ENQUEUE STYLE
	-- Enqueues a bundled stylesheet versioned by its modification time
	---------------------------------------------------------- */

	protected function enqueue_style( string $handle, string $file, array $deps ): void {

		$asset = self::asset( self::ASSETS . $file );

		wp_enqueue_style( $handle, $asset['url'], $deps, $asset['version'] );

	}

}

return new Octave_Addons_Module_Page_Loader();
