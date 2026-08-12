<?php

/*
BREAKDANCE DEFAULT SPACING
-- Gives Breakdance elements a default bottom margin so pages keep an even
-- vertical rhythm without anyone hand-writing a spacing stylesheet
-- Spacing is entered as plain numbers per Breakdance breakpoint and compiled
-- into one inline stylesheet on the frontend
-- Two shared tokens, --default-element-gap and --default-heading-margin, are
-- printed on :root so existing custom CSS keeps resolving against them
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Breakdance_Spacing extends Octave_Addons_Module {

	/**
	 * Style handle the compiled stylesheet is attached to.
	 */
	protected const HANDLE = 'octave-addons-breakdance-spacing';

	/**
	 * Breakpoint id Breakdance uses for the unqualified, widest values.
	 */
	protected const BASE_BREAKPOINT = 'breakpoint_base';

	/**
	 * Length units a value can be expressed in.
	 */
	protected const UNITS = [ 'px', 'rem', 'em', '%', 'vh', 'vw' ];

	/**
	 * Resolved breakpoint list, cached because normalising it is walked once
	 * per row while settings are filled, sanitized and compiled.
	 */
	protected ?array $breakpoints = null;

	/*
	GET ID
	-- Returns the module settings key
	---------------------------------------------------------- */

	public function get_id(): string {

		return 'breakdance-spacing';

	}

	/*
	GET GROUP
	-- Shares the Breakdance page with the other builder-facing modules
	---------------------------------------------------------- */

	public function get_group(): string {

		return 'breakdance';

	}

	/*
	GET TITLE
	-- Returns the admin navigation label
	---------------------------------------------------------- */

	public function get_title(): string {

		return __( 'Breakdance Default Spacing', 'octave-addons' );

	}

	/*
	GET DESCRIPTION
	-- Describes the module in the Octave Addons settings screen
	---------------------------------------------------------- */

	public function get_description(): string {

		return __( 'Sets the default bottom margin for Breakdance elements, per breakpoint, from two shared spacing tokens — so sites stop needing a hand-written spacing stylesheet.', 'octave-addons' );

	}

	/*
	TOKENS
	-- The two shared spacing variables every element row can point at.
	-- The var name is what existing site CSS already references, so it must
	-- stay exactly as it is.
	---------------------------------------------------------- */

	protected function tokens(): array {

		return [
			'element-gap' => [
				'var'     => '--default-element-gap',
				'label'   => __( 'Default element gap', 'octave-addons' ),
				'help'    => __( 'Used by content elements and available to your own CSS.', 'octave-addons' ),
				'default' => '30',
			],
			'heading-margin' => [
				'var'     => '--default-heading-margin',
				'label'   => __( 'Default heading margin', 'octave-addons' ),
				'help'    => __( 'Used by headings and available to your own CSS.', 'octave-addons' ),
				'default' => '30',
			],
		];

	}

	/*
	SECTIONS
	-- Groups the element rows so the grid stays scannable.
	---------------------------------------------------------- */

	protected function sections(): array {

		return [
			'headings' => __( 'Headings', 'octave-addons' ),
			'content'  => __( 'Content elements', 'octave-addons' ),
		];

	}

	/*
	ELEMENTS
	-- Every element that gets a spacing row, in output order. Heading levels
	-- follow the catch-all heading row so their rules land after it.
	-- reset_via marks rows whose last-child reset is already covered by
	-- another row's selector, so the reset rule stays short.
	---------------------------------------------------------- */

	protected function elements(): array {

		return [
			'heading' => [
				'label'    => __( 'Heading — all levels', 'octave-addons' ),
				'selector' => '.bde-heading',
				'section'  => 'headings',
				'unit'     => 'heading-margin',
			],
			'h1' => [
				'label'     => __( 'Heading 1', 'octave-addons' ),
				'selector'  => 'h1.bde-heading',
				'section'   => 'headings',
				'child'     => true,
				'reset_via' => 'heading',
			],
			'h2' => [
				'label'     => __( 'Heading 2', 'octave-addons' ),
				'selector'  => 'h2.bde-heading',
				'section'   => 'headings',
				'child'     => true,
				'reset_via' => 'heading',
			],
			'h3' => [
				'label'     => __( 'Heading 3', 'octave-addons' ),
				'selector'  => 'h3.bde-heading',
				'section'   => 'headings',
				'child'     => true,
				'reset_via' => 'heading',
			],
			'h4' => [
				'label'     => __( 'Heading 4', 'octave-addons' ),
				'selector'  => 'h4.bde-heading',
				'section'   => 'headings',
				'child'     => true,
				'reset_via' => 'heading',
			],
			'h5' => [
				'label'     => __( 'Heading 5', 'octave-addons' ),
				'selector'  => 'h5.bde-heading',
				'section'   => 'headings',
				'child'     => true,
				'reset_via' => 'heading',
			],
			'h6' => [
				'label'     => __( 'Heading 6', 'octave-addons' ),
				'selector'  => 'h6.bde-heading',
				'section'   => 'headings',
				'child'     => true,
				'reset_via' => 'heading',
			],
			'text' => [
				'label'    => __( 'Text', 'octave-addons' ),
				'selector' => '.bde-text',
				'section'  => 'content',
				'unit'     => 'element-gap',
			],
			'rich-text' => [
				'label'    => __( 'Rich Text', 'octave-addons' ),
				'selector' => '.bde-rich-text',
				'section'  => 'content',
				'unit'     => 'element-gap',
			],
			'blockquote' => [
				'label'    => __( 'Blockquote', 'octave-addons' ),
				'selector' => '.bde-blockquote',
				'section'  => 'content',
			],
			'basic-list' => [
				'label'    => __( 'List', 'octave-addons' ),
				'selector' => '.bde-basic-list',
				'section'  => 'content',
			],
			'icon-list' => [
				'label'    => __( 'Icon List', 'octave-addons' ),
				'selector' => '.bde-icon-list',
				'section'  => 'content',
			],
			'checkmark-list' => [
				'label'    => __( 'Checkmark List', 'octave-addons' ),
				'selector' => '.bde-checkmark-list',
				'section'  => 'content',
			],
			'image' => [
				'label'    => __( 'Image', 'octave-addons' ),
				'selector' => '.bde-image',
				'section'  => 'content',
			],
			'gallery' => [
				'label'    => __( 'Gallery', 'octave-addons' ),
				'selector' => '.bde-gallery',
				'section'  => 'content',
			],
			'video' => [
				'label'    => __( 'Video', 'octave-addons' ),
				'selector' => '.bde-video',
				'section'  => 'content',
			],
			'button' => [
				'label'    => __( 'Button', 'octave-addons' ),
				'selector' => '.bde-button',
				'section'  => 'content',
			],
			'icon' => [
				'label'    => __( 'Icon', 'octave-addons' ),
				'selector' => '.bde-icon',
				'section'  => 'content',
			],
			'fancy-divider' => [
				'label'    => __( 'Fancy Divider', 'octave-addons' ),
				'selector' => '.bde-fancy-divider',
				'section'  => 'content',
			],
			'form-builder' => [
				'label'    => __( 'Form', 'octave-addons' ),
				'selector' => '.bde-form-builder',
				'section'  => 'content',
			],
			'code-block' => [
				'label'    => __( 'Code Block', 'octave-addons' ),
				'selector' => '.bde-code-block',
				'section'  => 'content',
			],
			'shortcode' => [
				'label'    => __( 'Shortcode', 'octave-addons' ),
				'selector' => '.bde-shortcode',
				'section'  => 'content',
			],
			'google-map' => [
				'label'    => __( 'Google Map', 'octave-addons' ),
				'selector' => '.bde-google-map',
				'section'  => 'content',
			],
		];

	}

	/*
	BREAKPOINTS
	-- Reads Breakdance's own breakpoints so custom ones appear here too, and
	-- falls back to the built-in set when Breakdance is not loaded.
	-- Wider max-width queries are emitted first so narrower ones win.
	---------------------------------------------------------- */

	protected function breakpoints(): array {

		if ( null !== $this->breakpoints ) {

			return $this->breakpoints;

		}

		$raw = [];

		if ( function_exists( '\Breakdance\Config\Breakpoints\get_breakpoints' ) ) {

			$raw = (array) \Breakdance\Config\Breakpoints\get_breakpoints();

		}

		if ( empty( $raw ) ) {

			$raw = [
				[ 'id' => self::BASE_BREAKPOINT, 'label' => __( 'Desktop', 'octave-addons' ) ],
				[ 'id' => 'breakpoint_tablet_landscape', 'label' => __( 'Tablet Landscape', 'octave-addons' ), 'maxWidth' => 1119 ],
				[ 'id' => 'breakpoint_tablet_portrait', 'label' => __( 'Tablet Portrait', 'octave-addons' ), 'maxWidth' => 1023 ],
				[ 'id' => 'breakpoint_phone_landscape', 'label' => __( 'Phone Landscape', 'octave-addons' ), 'maxWidth' => 767 ],
				[ 'id' => 'breakpoint_phone_portrait', 'label' => __( 'Phone Portrait', 'octave-addons' ), 'maxWidth' => 479 ],
			];

		}

		$base    = [];
		$scaling = [];

		foreach ( $raw as $breakpoint ) {

			$id = (string) ( $breakpoint['id'] ?? '' );

			if ( '' === $id ) {

				continue;

			}

			$entry = [
				'id'    => $id,
				'label' => (string) ( $breakpoint['label'] ?? $id ),
				'media' => $this->media_query( $breakpoint ),
				'max'   => (int) ( $breakpoint['maxWidth'] ?? 0 ),
			];

			if ( self::BASE_BREAKPOINT === $id || '' === $entry['media'] ) {

				$entry['media'] = '';
				$base[]         = $entry;

				continue;

			}

			$scaling[] = $entry;

		}

		// Narrower screens must come last so their values win the cascade.
		usort( $scaling, static function ( array $a, array $b ): int {

			return ( $b['max'] ?: PHP_INT_MAX ) <=> ( $a['max'] ?: PHP_INT_MAX );

		} );

		$ordered = array_merge( $base, $scaling );

		if ( empty( $ordered ) ) {

			$ordered = [ [ 'id' => self::BASE_BREAKPOINT, 'label' => __( 'Desktop', 'octave-addons' ), 'media' => '', 'max' => 0 ] ];

		}

		$this->breakpoints = $ordered;

		return $ordered;

	}

	/*
	MEDIA QUERY
	-- Builds the media query string for one Breakdance breakpoint, deferring
	-- to Breakdance's own helper when it is available.
	---------------------------------------------------------- */

	protected function media_query( array $breakpoint ): string {

		if ( function_exists( '\Breakdance\Config\Breakpoints\mediaQueryString' ) ) {

			$query = \Breakdance\Config\Breakpoints\mediaQueryString( $breakpoint );

			return is_string( $query ) ? $query : '';

		}

		$min = (int) ( $breakpoint['minWidth'] ?? 0 );
		$max = (int) ( $breakpoint['maxWidth'] ?? 0 );

		if ( ! $min && $max ) {

			return '@media (max-width: ' . $max . 'px)';

		}

		if ( $min && ! $max ) {

			return '@media (min-width: ' . $min . 'px)';

		}

		if ( $min && $max ) {

			return '@media (min-width: ' . $min . 'px) and (max-width: ' . $max . 'px)';

		}

		return '';

	}

	/*
	GET DEFAULTS
	-- Seeds both tokens at 30px, points headings and text at them, and leaves
	-- every other element blank so nothing is spaced until it is asked for.
	---------------------------------------------------------- */

	public function get_defaults(): array {

		$base = self::BASE_BREAKPOINT;

		$defaults = [
			'enabled'          => false,
			'last_child_reset' => true,
			'tokens'           => [],
			'elements'         => [],
		];

		foreach ( $this->tokens() as $key => $token ) {

			$defaults['tokens'][ $key ] = [
				'unit'   => 'px',
				'values' => [ $base => $token['default'] ],
			];

		}

		foreach ( $this->elements() as $key => $element ) {

			$defaults['elements'][ $key ] = [
				'unit'   => $element['unit'] ?? 'px',
				'values' => [ $base => '' ],
			];

		}

		return $defaults;

	}

	/*
	GET SETTINGS
	-- Fills in any token, element or breakpoint that was not in the saved
	-- payload, so rows added by a plugin update appear blank instead of
	-- throwing notices.
	---------------------------------------------------------- */

	public function get_settings( array $saved ): array {

		$settings = wp_parse_args( $saved, $this->get_defaults() );

		$settings['tokens']   = $this->fill_rows( $settings['tokens'] ?? [], $this->tokens() );
		$settings['elements'] = $this->fill_rows( $settings['elements'] ?? [], $this->elements() );

		return $settings;

	}

	/*
	FILL ROWS
	-- Normalises one saved row set against its definitions.
	---------------------------------------------------------- */

	protected function fill_rows( $saved, array $definitions ): array {

		$saved  = is_array( $saved ) ? $saved : [];
		$filled = [];

		foreach ( $definitions as $key => $definition ) {

			$row    = is_array( $saved[ $key ] ?? null ) ? $saved[ $key ] : [];
			$values = is_array( $row['values'] ?? null ) ? $row['values'] : [];

			$filled[ $key ] = [
				'unit'   => (string) ( $row['unit'] ?? $definition['unit'] ?? 'px' ),
				'values' => $values,
			];

			foreach ( $this->breakpoints() as $breakpoint ) {

				$filled[ $key ]['values'][ $breakpoint['id'] ] = (string) ( $values[ $breakpoint['id'] ] ?? '' );

			}

		}

		return $filled;

	}

	/*
	SANITIZE
	-- Validates every number and unit before WordPress stores them
	---------------------------------------------------------- */

	public function sanitize( $input ): array {

		$input = is_array( $input ) ? $input : [];
		$clean = $this->get_defaults();

		$clean['enabled']          = ! empty( $input['enabled'] );
		$clean['last_child_reset'] = ! empty( $input['last_child_reset'] );

		$clean['tokens']   = $this->sanitize_rows( $input['tokens'] ?? [], $this->tokens(), false );
		$clean['elements'] = $this->sanitize_rows( $input['elements'] ?? [], $this->elements(), true );

		return $clean;

	}

	/*
	SANITIZE ROWS
	-- Cleans one row set. Element rows may point their unit at a token, token
	-- rows may only carry a real length unit.
	---------------------------------------------------------- */

	protected function sanitize_rows( $input, array $definitions, bool $allow_tokens ): array {

		$input = is_array( $input ) ? $input : [];
		$units = self::UNITS;

		if ( $allow_tokens ) {

			$units = array_merge( $units, array_keys( $this->tokens() ) );

		}

		$clean = [];

		foreach ( $definitions as $key => $definition ) {

			$row  = is_array( $input[ $key ] ?? null ) ? $input[ $key ] : [];
			$unit = (string) ( $row['unit'] ?? '' );

			$clean[ $key ] = [
				'unit'   => in_array( $unit, $units, true ) ? $unit : ( $definition['unit'] ?? 'px' ),
				'values' => [],
			];

			$values = is_array( $row['values'] ?? null ) ? $row['values'] : [];

			foreach ( $this->breakpoints() as $breakpoint ) {

				$clean[ $key ]['values'][ $breakpoint['id'] ] = $this->sanitize_number( $values[ $breakpoint['id'] ] ?? '' );

			}

		}

		return $clean;

	}

	/*
	SANITIZE NUMBER
	-- Accepts an optionally negative, optionally decimal number and nothing
	-- else, so no raw CSS can reach the stylesheet through a value field.
	---------------------------------------------------------- */

	protected function sanitize_number( $value ): string {

		$value = trim( (string) $value );

		if ( '' === $value ) {

			return '';

		}

		if ( ! preg_match( '/^-?\d+(\.\d+)?$/', $value ) ) {

			return '';

		}

		// Trim a trailing decimal zero run so "30.00" is stored as "30".
		if ( false !== strpos( $value, '.' ) ) {

			$value = rtrim( rtrim( $value, '0' ), '.' );

		}

		return '' === $value ? '' : $value;

	}

	/*
	RESOLVE VALUE
	-- Turns one row's stored number and unit into a CSS value, or an empty
	-- string when the row has nothing to say at this breakpoint.
	---------------------------------------------------------- */

	protected function resolve_value( array $row, string $breakpoint_id ): string {

		$unit   = (string) ( $row['unit'] ?? 'px' );
		$tokens = $this->tokens();

		if ( isset( $tokens[ $unit ] ) ) {

			// Token rows follow the variable, which carries its own responsive
			// values, so they only ever emit one rule at the base breakpoint.
			return self::BASE_BREAKPOINT === $breakpoint_id ? 'var(' . $tokens[ $unit ]['var'] . ')' : '';

		}

		$value = (string) ( $row['values'][ $breakpoint_id ] ?? '' );

		if ( '' === $value ) {

			return '';

		}

		if ( ! in_array( $unit, self::UNITS, true ) ) {

			$unit = 'px';

		}

		return '0' === $value ? '0px' : $value . $unit;

	}

	/*
	BUILD CSS
	-- Compiles the whole settings payload into one stylesheet.
	-- Rules are scoped to .breakdance and emitted in element order, so the
	-- heading level overrides land after the catch-all heading rule.
	---------------------------------------------------------- */

	public function build_css( array $s ): string {

		$breakpoints = $this->breakpoints();
		$tokens      = $this->tokens();
		$elements    = $this->elements();
		$blocks      = [];
		$reset       = [];

		foreach ( $breakpoints as $breakpoint ) {

			$rules = [];

			// -------- Shared tokens --------
			$declarations = [];

			foreach ( $tokens as $key => $token ) {

				$row   = $s['tokens'][ $key ] ?? [];
				$value = $this->resolve_value( is_array( $row ) ? $row : [], $breakpoint['id'] );

				if ( '' === $value ) {

					continue;

				}

				$declarations[] = sprintf( "\t%s: %s;", $token['var'], $value );

			}

			if ( ! empty( $declarations ) ) {

				$rules[] = ":root {\n" . implode( "\n", $declarations ) . "\n}";

			}

			// -------- Element rules --------
			foreach ( $elements as $key => $element ) {

				$row   = $s['elements'][ $key ] ?? [];
				$row   = is_array( $row ) ? $row : [];
				$value = $this->resolve_value( $row, $breakpoint['id'] );

				if ( '' === $value ) {

					continue;

				}

				$rules[] = sprintf(
					".breakdance %s {\n\tmargin-bottom: %s;\n}",
					$element['selector'],
					$value
				);

				$reset[ $key ] = $element;

			}

			if ( empty( $rules ) ) {

				continue;

			}

			if ( '' === $breakpoint['media'] ) {

				$blocks[] = implode( "\n\n", $rules );

				continue;

			}

			$blocks[] = $breakpoint['media'] . " {\n\n" . $this->indent( implode( "\n\n", $rules ) ) . "\n\n}";

		}

		// -------- Last-child reset --------
		// Emitted last so it wins over every breakpoint rule above it, while
		// still sitting below anything the builder writes.
		if ( ! empty( $s['last_child_reset'] ) && ! empty( $reset ) ) {

			$selectors = $this->reset_selectors( $reset );

			if ( ! empty( $selectors ) ) {

				$blocks[] = implode( ",\n", $selectors ) . " {\n\tmargin-bottom: 0;\n}";

			}

		}

		if ( empty( $blocks ) ) {

			return '';

		}

		return "/* Octave Addons — Breakdance default spacing */\n\n" . implode( "\n\n", $blocks ) . "\n";

	}

	/*
	RESET SELECTORS
	-- Builds the last-child selector list, dropping any row already covered by
	-- another configured row so the rule stays readable.
	---------------------------------------------------------- */

	protected function reset_selectors( array $rows ): array {

		$selectors = [];

		foreach ( $rows as $element ) {

			$covered_by = $element['reset_via'] ?? '';

			if ( '' !== $covered_by && isset( $rows[ $covered_by ] ) ) {

				continue;

			}

			$selectors[] = '.breakdance ' . $element['selector'] . ':last-child';

		}

		return array_values( array_unique( $selectors ) );

	}

	/*
	INDENT
	-- Pushes a block of rules one tab in, for rules nested in a media query.
	---------------------------------------------------------- */

	protected function indent( string $css ): string {

		$lines = explode( "\n", $css );

		foreach ( $lines as $index => $line ) {

			$lines[ $index ] = '' === trim( $line ) ? '' : "\t" . $line;

		}

		return implode( "\n", $lines );

	}

	/*
	RUN
	-- Inlines the compiled stylesheet on the frontend, late enough to land
	-- after Breakdance's own CSS.
	---------------------------------------------------------- */

	public function run( array $s ): void {

		add_action( 'wp_enqueue_scripts', function () use ( $s ) {

			$this->enqueue_css( $s );

		}, 20 );

	}

	/*
	ENQUEUE CSS
	-- Attaches the compiled stylesheet to an otherwise empty handle, which is
	-- the standard way to print inline-only CSS through the queue.
	---------------------------------------------------------- */

	protected function enqueue_css( array $s ): void {

		if ( ! Octave_Addons::is_breakdance_active() ) {

			return;

		}

		$css = $this->build_css( $s );

		if ( '' === $css ) {

			return;

		}

		wp_register_style( self::HANDLE, false, [], OCTAVE_ADDONS_VERSION );
		wp_enqueue_style( self::HANDLE );
		wp_add_inline_style( self::HANDLE, $css );

	}

	/*
	RENDER SETTINGS
	-- Draws the spacing grid: a breakpoint tab row, the two shared tokens,
	-- then one row per element with a number per breakpoint and a single unit.
	---------------------------------------------------------- */

	public function render_settings( array $s ): void {

		$breakpoints = $this->breakpoints();
		$tokens      = $this->tokens();
		$elements    = $this->elements();
		$sections    = $this->sections();

		?>

		<div class="oa-spacing" data-oa-spacing>

			<p class="oa-help oa-help--intro">
				<?php esc_html_e( 'Default bottom margins for Breakdance elements. These load after Breakdance\'s own CSS at matching specificity, so they take over from spacing set on individual elements in the builder — treat this as the site\'s spacing scale.', 'octave-addons' ); ?>
			</p>

			<div class="oa-spacing-bar">
				<div class="oa-spacing-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Breakpoint', 'octave-addons' ); ?>">
					<?php

					foreach ( $breakpoints as $index => $breakpoint ) :

						$is_first = 0 === $index;

					?>

					<button type="button"
					        class="oa-spacing-tab<?= $is_first ? ' is-active' : ''; ?>"
					        role="tab"
					        aria-selected="<?= $is_first ? 'true' : 'false'; ?>"
					        data-breakpoint="<?= esc_attr( $breakpoint['id'] ); ?>"
					        data-media="<?= esc_attr( $breakpoint['media'] ); ?>"<?= self::BASE_BREAKPOINT === $breakpoint['id'] ? ' data-base="1"' : ''; ?>>
						<span class="oa-spacing-tab-label"><?= esc_html( $breakpoint['label'] ); ?></span>
						<span class="oa-spacing-tab-dot" aria-hidden="true"></span>
					</button>

					<?php

					endforeach;

					?>

				</div>
				<p class="oa-spacing-bar-note">
					<?php esc_html_e( 'Each breakpoint applies from its width down. Leave a field blank to inherit the wider breakpoint.', 'octave-addons' ); ?>
				</p>
			</div>

			<div class="oa-spacing-section oa-spacing-section--tokens">
				<h4 class="oa-spacing-section-title"><?php esc_html_e( 'Shared spacing tokens', 'octave-addons' ); ?></h4>
				<p class="oa-spacing-section-note">
					<?php esc_html_e( 'Two values the whole site can point at. Elements set to a token follow it at every breakpoint, and your own CSS can use the variable name shown.', 'octave-addons' ); ?>
				</p>

				<div class="oa-spacing-table">

					<?php

					foreach ( $tokens as $key => $token ) :

						$row = $s['tokens'][ $key ] ?? [];
						$row = is_array( $row ) ? $row : [];

					?>

					<div class="oa-spacing-row oa-spacing-row--token"
					     data-row="<?= esc_attr( $key ); ?>"
					     data-var="<?= esc_attr( $token['var'] ); ?>">
						<span class="oa-spacing-label">
							<span class="oa-spacing-name"><?= esc_html( $token['label'] ); ?></span>
							<code class="oa-spacing-selector"><?= esc_html( $token['var'] ); ?></code>
						</span>
						<span class="oa-spacing-values">
							<?php $this->render_value_inputs( 'tokens', $key, $row, $breakpoints, __( 'not set', 'octave-addons' ), $token['label'] ); ?>
						</span>
						<span class="oa-spacing-unit">
							<?php $this->render_unit_select( 'tokens', $key, (string) ( $row['unit'] ?? 'px' ), false, $token['label'] ); ?>
						</span>
					</div>

					<?php

					endforeach;

					?>

				</div>
			</div>

			<?php

			foreach ( $sections as $section_key => $section_label ) :

			?>

			<div class="oa-spacing-section">
				<h4 class="oa-spacing-section-title"><?= esc_html( $section_label ); ?></h4>

				<div class="oa-spacing-table">

					<div class="oa-spacing-head" aria-hidden="true">
						<span><?php esc_html_e( 'Element', 'octave-addons' ); ?></span>
						<span><?php esc_html_e( 'Bottom margin', 'octave-addons' ); ?></span>
						<span><?php esc_html_e( 'Unit', 'octave-addons' ); ?></span>
					</div>

					<?php

					foreach ( $elements as $key => $element ) :

						if ( ( $element['section'] ?? '' ) !== $section_key ) {

							continue;

						}

						$row      = $s['elements'][ $key ] ?? [];
						$row      = is_array( $row ) ? $row : [];
						$unit     = (string) ( $row['unit'] ?? 'px' );
						$is_token = isset( $tokens[ $unit ] );

					?>

					<div class="oa-spacing-row<?= ! empty( $element['child'] ) ? ' is-child' : ''; ?><?= $is_token ? ' is-token' : ''; ?>"
					     data-selector="<?= esc_attr( $element['selector'] ); ?>"
					     data-reset-via="<?= esc_attr( $element['reset_via'] ?? '' ); ?>"
					     data-row="<?= esc_attr( $key ); ?>">
						<span class="oa-spacing-label">
							<span class="oa-spacing-name"><?= esc_html( $element['label'] ); ?></span>
							<code class="oa-spacing-selector"><?= esc_html( $element['selector'] ); ?></code>
						</span>
						<span class="oa-spacing-values">
							<?php $this->render_value_inputs( 'elements', $key, $row, $breakpoints, __( 'none', 'octave-addons' ), $element['label'] ); ?>
						</span>
						<span class="oa-spacing-unit">
							<?php $this->render_unit_select( 'elements', $key, $unit, true, $element['label'] ); ?>
						</span>
					</div>

					<?php

					endforeach;

					?>

				</div>
			</div>

			<?php

			endforeach;

			?>

			<table class="form-table oa-form-table" role="presentation">
				<?php Octave_Addons_Fields::row( [
					'label' => __( 'Reset the last element', 'octave-addons' ),
					'field' => function () use ( $s ) {

						Octave_Addons_Fields::switch_field( [
							'name'    => $this->field_name( 'last_child_reset' ),
							'id'      => $this->field_id( 'last_child_reset' ),
							'checked' => ! empty( $s['last_child_reset'] ),
							'help'    => __( 'Drops the bottom margin on the last spaced element in a container', 'octave-addons' ),
						] );
						?><span class="oa-help"><?php esc_html_e( 'Stops the gap doubling up against the padding at the bottom of a section.', 'octave-addons' ); ?></span><?php

					},
				] ); ?>
			</table>

			<details class="oa-spacing-output">
				<summary><?php esc_html_e( 'Generated CSS', 'octave-addons' ); ?></summary>
				<p class="oa-help"><?php esc_html_e( 'Read-only preview of exactly what gets added to the frontend.', 'octave-addons' ); ?></p>
				<?php $empty_css = __( '/* Nothing to output yet — set a value above. */', 'octave-addons' ); ?>

				<pre class="oa-spacing-css" data-empty="<?= esc_attr( $empty_css ); ?>"><code><?= esc_html( $this->build_css( $s ) ?: $empty_css ); ?></code></pre>
			</details>

		</div>

		<?php

	}

	/*
	RENDER VALUE INPUTS
	-- One number input per breakpoint. All of them stay in the form so values
	-- for hidden breakpoints are never lost on save; only the active one is
	-- shown.
	---------------------------------------------------------- */

	protected function render_value_inputs( string $bucket, string $key, array $row, array $breakpoints, string $base_placeholder, string $aria_prefix ): void {

		$is_token = isset( $this->tokens()[ (string) ( $row['unit'] ?? '' ) ] );

		foreach ( $breakpoints as $index => $breakpoint ) :

			$is_first    = 0 === $index;
			$value       = (string) ( $row['values'][ $breakpoint['id'] ] ?? '' );
			$field_name  = sprintf( '%s[%s][%s][%s][values][%s]', OCTAVE_ADDONS_OPTION_KEY, $this->get_id(), $bucket, $key, $breakpoint['id'] );
			$placeholder = $is_first ? $base_placeholder : __( 'inherit', 'octave-addons' );

		?>

		<input type="number"
		       class="oa-spacing-input<?= $is_first ? '' : ' oa-hidden'; ?>"
		       step="any"
		       inputmode="decimal"
		       data-breakpoint="<?= esc_attr( $breakpoint['id'] ); ?>"
		       aria-label="<?php echo esc_attr( sprintf( /* translators: 1: field name, 2: breakpoint name. */ __( '%1$s — %2$s', 'octave-addons' ), $aria_prefix, $breakpoint['label'] ) ); ?>"
		       name="<?= esc_attr( $field_name ); ?>"
		       value="<?= esc_attr( $value ); ?>"
		       placeholder="<?= esc_attr( $placeholder ); ?>"<?= $is_token ? ' readonly' : ''; ?>>

		<?php

		endforeach;

	}

	/*
	RENDER UNIT SELECT
	-- The unit for a whole row. Element rows can also point at a token, in
	-- which case the row inherits that token's responsive values.
	---------------------------------------------------------- */

	protected function render_unit_select( string $bucket, string $key, string $current, bool $allow_tokens, string $aria_prefix ): void {

		$field_name = sprintf( '%s[%s][%s][%s][unit]', OCTAVE_ADDONS_OPTION_KEY, $this->get_id(), $bucket, $key );

		?>

		<select class="oa-spacing-unit-select"
		        name="<?= esc_attr( $field_name ); ?>"
		        aria-label="<?php echo esc_attr( sprintf( /* translators: %s: field name. */ __( 'Unit — %s', 'octave-addons' ), $aria_prefix ) ); ?>">
			<?php

			foreach ( self::UNITS as $unit ) :

			?>

			<option value="<?= esc_attr( $unit ); ?>"<?php selected( $current, $unit ); ?>><?= esc_html( $unit ); ?></option>

			<?php

			endforeach;

			if ( $allow_tokens ) :

				foreach ( $this->tokens() as $token_key => $token ) :

			?>

			<option value="<?= esc_attr( $token_key ); ?>"
			        data-var="<?= esc_attr( $token['var'] ); ?>"<?php selected( $current, $token_key ); ?>><?= esc_html( $token['label'] ); ?></option>

			<?php

				endforeach;

			endif;

			?>

		</select>

		<?php

	}

}

return new Octave_Addons_Module_Breakdance_Spacing();
