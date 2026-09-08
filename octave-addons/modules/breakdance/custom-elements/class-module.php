<?php

/*
MODULE: BREAKDANCE ELEMENTS
-- Registers the Breakdance element locations used by Octave sites and gives
-- every discovered element its own on/off switch in the admin.
-- Two kinds of location exist, and the split matters:
--   library/  ships with the plugin, holds generic elements reused across
--             every site, and is read-only in Element Studio so a plugin
--             update can never destroy site work saved into it.
--   elements/ and any external location are writable save targets for
--             elements that only make sense on one site.
-- Elements are discovered by reflection, so a new element folder appears in
-- the admin on the next page load with no code changes anywhere.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Breakdance_Elements extends Octave_Addons_Module {


	/** Prevent duplicate registration if Breakdance is already loaded. */
	protected bool $has_registered = false;

	/** @var array|null Discovered elements, resolved once per request. */
	protected ?array $elements = null;

	public function __construct() {

		// Register unconditionally — the save locations must always be available
		// in Element Studio regardless of the admin toggle.
		add_action( 'breakdance_loaded', [ $this, 'register_breakdance_elements' ], 9 );
		if ( did_action( 'breakdance_loaded' ) ) {

			$this->register_breakdance_elements();

		}

		add_filter( 'breakdance_builder_elements', [ $this, 'filter_builder_elements' ] );

	}

	public function get_id(): string {

		// Kept as the original slug so saved settings survive the rename.
		return 'breakdance-custom-elements';

	}

	public function get_defaults(): array {

		return [ 'enabled' => true, 'elements' => [] ];

	}

	/*
	GET GROUP
	-- Shares the Breakdance page with the other builder-facing modules.
	---------------------------------------------------------- */

	public function get_group(): string {

		return 'breakdance';

	}

	public function get_title(): string {

		return __( 'Breakdance Elements', 'octave-addons' );

	}

	public function get_description(): string {
		return __( 'Custom Breakdance elements, each with its own switch. Turning one off hides it from the builder\'s add panel without touching pages that already use it.', 'octave-addons' );

	}

	public function show_in_admin(): bool {

		return true;

	}

	public function is_always_enabled(): bool {

		return true;

	}

	public function run( array $settings ): void {

		// Registration is handled unconditionally in __construct().

	}

	/*
	SANITIZE
	-- Each element renders a hidden 0 before its checkbox, so every element on
	-- screen is represented in the submission and absence means "new".
	---------------------------------------------------------- */

	public function sanitize( $input ): array {

		$clean = [
			'enabled'  => true,
			'elements' => [],
		];

		// No element inputs at all means the list could not be rendered — keep
		// what is already saved rather than silently switching everything on.
		if ( ! isset( $input['elements'] ) || ! is_array( $input['elements'] ) ) {

			$existing = $this->current_settings();

			$clean['elements'] = isset( $existing['elements'] ) && is_array( $existing['elements'] ) ? $existing['elements'] : [];

			return $clean;

		}

		foreach ( $input['elements'] as $key => $value ) {

			$clean['elements'][ sanitize_text_field( $key ) ] = ! empty( $value ) ? 1 : 0;

		}

		return $clean;

	}

	/*
	REGISTER BREAKDANCE ELEMENTS
	-- Adds the library as a read-only location plus every writable location.
	---------------------------------------------------------- */

	public function register_breakdance_elements(): void {

		if ( $this->has_registered ) {
			return;

		}

		if ( ! function_exists( '\Breakdance\ElementStudio\registerSaveLocation' ) || ! class_exists( '\Breakdance\Elements\ElementCategoriesController' ) ) {
			return;

		}

		// Ensure the in-plugin elements directory exists so Breakdance can write
		// new elements when no external location is available.
		$elements_dir = OCTAVE_ADDONS_DIR . 'modules/breakdance-custom-elements/elements';
		if ( ! is_dir( $elements_dir ) ) {

			wp_mkdir_p( $elements_dir );

		}

		// The shared library loads but is never offered as a save target.
		\Breakdance\ElementStudio\registerSaveLocation(
			'octave-addons/modules/breakdance-custom-elements/library',
			'OctaveCustomElements',
			'element',
			'Octave Elements (Library)',
			false,
			true
		);

		foreach ( $this->get_save_locations() as $location => $label ) {

			\Breakdance\ElementStudio\registerSaveLocation(
				$location,
				'OctaveCustomElements',
				'element',
				$label,
				false
			);

		}

		\Breakdance\Elements\ElementCategoriesController::getInstance()->registerCategory(
			'oa_custom_elements',
			'Octave Elements'
		);

		$this->has_registered = true;

	}

	/*
	FILTER BUILDER ELEMENTS
	-- Breakdance hides anything missing from this list in the add panel while
	-- still rendering it on pages that already use it, so switching an element
	-- off is never destructive.
	---------------------------------------------------------- */

	public function filter_builder_elements( $classnames ) {

		if ( ! is_array( $classnames ) ) {

			return $classnames;

		}

		$settings = $this->current_settings();
		$saved    = isset( $settings['elements'] ) && is_array( $settings['elements'] ) ? $settings['elements'] : [];

		if ( empty( $saved ) ) {

			return $classnames;

		}

		return array_values(
			array_filter(
				$classnames,
				static function ( $classname ) use ( $saved ) {

					$key = self::element_key( (string) $classname );

					return ! isset( $saved[ $key ] ) || ! empty( $saved[ $key ] );

				}
			)
		);

	}

	/*
	ELEMENT KEY
	-- Settings key for a class name. Backslashes are not usable in HTML input
	-- names, so they become a double underscore.
	---------------------------------------------------------- */

	public static function element_key( string $classname ): string {

		return str_replace( '\\', '__', ltrim( $classname, '\\' ) );

	}

	/*
	DISCOVER ELEMENTS
	-- Every declared Breakdance element whose file lives in one of our
	-- locations. Reflection means new elements need no registration step.
	---------------------------------------------------------- */

	public function discover_elements(): array {

		if ( null !== $this->elements ) {

			return $this->elements;

		}

		$this->elements = [];

		if ( ! class_exists( '\Breakdance\Elements\Element' ) ) {

			return $this->elements;

		}

		$roots = [
			'library' => wp_normalize_path( Octave_Addons_Elements_Manifest::library_dir() ),
			'site'    => wp_normalize_path( OCTAVE_ADDONS_DIR . 'modules/breakdance-custom-elements/elements' ),
		];

		foreach ( array_keys( $this->get_save_locations() ) as $location ) {

			$path = wp_normalize_path( WP_PLUGIN_DIR . '/' . $location );

			if ( $path !== $roots['site'] ) {

				$roots[ $location ] = $path;

			}

		}

		foreach ( get_declared_classes() as $classname ) {

			if ( ! is_subclass_of( $classname, '\Breakdance\Elements\Element' ) ) {

				continue;

			}

			try {

				$reflection = new ReflectionClass( $classname );

			} catch ( ReflectionException $exception ) {

				continue;

			}

			$file = $reflection->getFileName();

			if ( ! $file ) {

				continue;

			}

			$file   = wp_normalize_path( $file );
			$source = '';

			foreach ( $roots as $key => $root ) {

				if ( 0 === strpos( $file, trailingslashit( $root ) ) ) {

					$source = $key;

					break;

				}

			}

			if ( '' === $source ) {

				continue;

			}

			$folder = basename( dirname( $file ) );

			$this->elements[ self::element_key( $classname ) ] = [
				'classname'  => $classname,
				'folder'     => $folder,
				'source'     => $source,
				'name'       => (string) $classname::name(),
				'icon'       => (string) $classname::uiIcon(),
				'category'   => (string) $classname::category(),
				'customised' => 'library' === $source && Octave_Addons_Elements_Manifest::is_customised( $folder ),
			];

		}

		uasort(
			$this->elements,
			static function ( array $a, array $b ): int {

				return strcasecmp( $a['name'], $b['name'] );

			}
		);

		return $this->elements;

	}

	/*
	CURRENT SETTINGS
	-- Saved settings for this module, read directly so the filter can run
	-- before the module manager has booted.
	---------------------------------------------------------- */

	protected function current_settings(): array {

		$all   = get_option( OCTAVE_ADDONS_OPTION_KEY, [] );
		$saved = is_array( $all ) && isset( $all[ $this->get_id() ] ) && is_array( $all[ $this->get_id() ] ) ? $all[ $this->get_id() ] : [];

		return $this->get_settings( $saved );

	}

	/*
	ICON MARKUP
	-- Reuses the element's builder icon so the admin list matches the add
	-- panel. Hardcoded greys from Element Studio are swapped for currentColor
	-- so the icon inherits the surrounding text colour.
	---------------------------------------------------------- */

	protected function icon_markup( string $svg ): string {

		if ( '' === trim( $svg ) ) {

			return Octave_Addons_Icons::get( 'square', 18 );

		}

		$svg = preg_replace( '/\s(color|fill|stroke)="(?!none)(?!currentColor)[^"]*"/i', ' $1="currentColor"', $svg );
		$svg = preg_replace( '/(fill|stroke|color)\s*:\s*(?!none)(?!currentColor)[^;"]+/i', '$1:currentColor', $svg );

		$allowed = [
			'svg'      => [ 'xmlns' => true, 'viewbox' => true, 'width' => true, 'height' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'color' => true, 'class' => true, 'aria-hidden' => true, 'focusable' => true ],
			'path'     => [ 'd' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'fill-rule' => true, 'clip-rule' => true, 'opacity' => true, 'transform' => true, 'style' => true ],
			'g'        => [ 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'opacity' => true, 'transform' => true, 'clip-path' => true, 'style' => true ],
			'circle'   => [ 'cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'opacity' => true, 'style' => true ],
			'ellipse'  => [ 'cx' => true, 'cy' => true, 'rx' => true, 'ry' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'style' => true ],
			'rect'     => [ 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'transform' => true, 'style' => true ],
			'line'     => [ 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'style' => true ],
			'polyline' => [ 'points' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'style' => true ],
			'polygon'  => [ 'points' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'style' => true ],
			'defs'     => [],
			'clippath' => [ 'id' => true ],
			'title'    => [],
		];

		return wp_kses( $svg, $allowed );

	}

	/*
	RENDER SETTINGS
	-- One card per discovered element: builder icon, name, where it lives,
	-- whether it has been edited locally, and its switch.
	---------------------------------------------------------- */

	public function render_settings( array $settings ): void {

		$elements = $this->discover_elements();
		$saved    = isset( $settings['elements'] ) && is_array( $settings['elements'] ) ? $settings['elements'] : [];

		// The "Breakdance is not active" warning belongs to the group page that
		// hosts this panel — only the empty list is explained here.
		if ( ! class_exists( '\Breakdance\Elements\Element' ) ) :

		?>

		<p class="description"><?php esc_html_e( 'Elements can be listed once Breakdance is active.', 'octave-addons' ); ?></p>

		<?php

		return;

		endif;

		if ( empty( $elements ) ) :

		?>

		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'No custom elements found yet. Save one from Element Studio, or drop an element folder into the library.', 'octave-addons' ); ?></p>
		</div>

		<?php

		return;

		endif;

		$labels = [
			'library' => __( 'Library', 'octave-addons' ),
			'site'    => __( 'This site', 'octave-addons' ),
		];

		?>

		<div class="oa-element-toolbar">
			<p class="description">
				<?php esc_html_e( 'New elements appear here automatically and start switched on. Switching one off hides it from the builder\'s add panel; pages already using it keep working.', 'octave-addons' ); ?>
			</p>
		</div>

		<div class="oa-element-grid">
			<?php

			foreach ( $elements as $key => $element ) :

				$enabled = ! isset( $saved[ $key ] ) || ! empty( $saved[ $key ] );
				$source  = $labels[ $element['source'] ] ?? __( 'External', 'octave-addons' );
				$field   = sprintf( '%s[%s][elements][%s]', OCTAVE_ADDONS_OPTION_KEY, $this->get_id(), $key );
				$id      = 'oa-element-' . sanitize_html_class( $key );

			?>

			<div class="oa-element-card<?= $enabled ? '' : ' is-off'; ?>">

				<span class="oa-element-icon" aria-hidden="true"><?= $this->icon_markup( $element['icon'] ); ?></span>

				<span class="oa-element-copy">
					<strong><?= esc_html( $element['name'] ); ?></strong>
					<span class="oa-element-meta">
						<span class="oa-element-tag"><?= esc_html( $source ); ?></span>
						<?php

						if ( $element['customised'] ) :

						?>

						<span class="oa-element-tag is-customised" title="<?php esc_attr_e( 'Edited on this site — updates will not overwrite it', 'octave-addons' ); ?>">
							<?php esc_html_e( 'Customised', 'octave-addons' ); ?>
						</span>

						<?php

						endif;

						?>
					</span>
				</span>

				<label class="oa-switch oa-element-switch">
					<input type="hidden" name="<?= esc_attr( $field ); ?>" value="0">
					<input type="checkbox"
					       id="<?= esc_attr( $id ); ?>"
					       name="<?= esc_attr( $field ); ?>"
					       value="1"
					       <?php checked( $enabled ); ?>>
					<span class="oa-switch-slider"></span>
					<span class="screen-reader-text"><?= esc_html( $element['name'] ); ?></span>
				</label>

			</div>

			<?php

			endforeach;

			?>
		</div>

		<table class="form-table oa-form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Shared library', 'octave-addons' ); ?></th>
				<td>
					<code>octave-addons/modules/breakdance-custom-elements/library</code>
					<p class="description">
						<?php esc_html_e( 'Generic elements shipped with the plugin. Read-only in Element Studio, and any element edited here is carried across plugin updates rather than overwritten.', 'octave-addons' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Site save location', 'octave-addons' ); ?></th>
				<td>
					<code><?= esc_html( $this->get_site_location() ); ?></code>
					<p class="description">
						<?php esc_html_e( 'Where Element Studio saves elements that only apply to this site. Create wp-content/plugins/octave-elements/ to keep them outside the plugin so updates cannot remove them.', 'octave-addons' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php

	}

	/*
	SAVE LOCATIONS
	-- Writable Element Studio targets for site-specific elements, keyed by
	-- path relative to the plugins directory. Breakdance globs these paths
	-- under WP_PLUGIN_DIR, so a location must live inside wp-content/plugins.
	-- An external octave-elements/ folder is preferred when present because it
	-- survives updates to this plugin; the in-plugin folder is the fallback.
	---------------------------------------------------------- */

	protected function get_save_locations(): array {

		$locations = [];

		if ( is_dir( WP_PLUGIN_DIR . '/octave-elements' ) ) {

			$locations['octave-elements'] = 'Octave Elements (Site)';

		}

		$locations['octave-addons/modules/breakdance-custom-elements/elements'] = 'Octave Elements (Plugin)';

		/**
		 * Filters the writable Breakdance save locations.
		 *
		 * @param array $locations Path relative to WP_PLUGIN_DIR => Element Studio label.
		 */
		return (array) apply_filters( 'octave_addons_breakdance_save_locations', $locations );

	}

	/*
	SITE LOCATION
	-- The path shown in the admin as the preferred site save target.
	---------------------------------------------------------- */

	protected function get_site_location(): string {

		$locations = array_keys( $this->get_save_locations() );

		return (string) reset( $locations );

	}

}

return new Octave_Addons_Module_Breakdance_Elements();
