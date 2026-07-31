<?php

/*
MODULE: EMPTY LINK HIGHLIGHTER
-- Ported from the standalone Empty Link Highlighter plugin. Scans the
-- frontend for <a> tags with empty/placeholder href and flags them.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Empty_Link_Highlighter extends Octave_Addons_Module {


	public function get_id(): string {

		return 'empty-link-highlighter';

	}

	public function get_title(): string {

		return __( 'Empty Link Highlighter', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Highlights links with an empty href or href="#" on the frontend so broken navigation is immediately obvious.', 'octave-addons' );

	}

	public function get_defaults(): array {

		return [
			'enabled'        => false,
			'visibility'     => 'admins',     // everyone | logged_in | admins
			'style'          => 'outline',    // outline | glow | badge | all
			'color'          => '#ff3b3b',
			'show_tooltip'   => true,
			'show_admin_bar' => true,
			'ignore_classes' => '',
		];

	}

	public function sanitize( $input ): array {

		$clean                   = $this->get_defaults();
		$clean['enabled']        = ! empty( $input['enabled'] );
		$clean['show_tooltip']   = ! empty( $input['show_tooltip'] );
		$clean['show_admin_bar'] = ! empty( $input['show_admin_bar'] );

		$clean['visibility'] = in_array( $input['visibility'] ?? '', [ 'everyone', 'logged_in', 'admins' ], true )
			? $input['visibility'] : 'admins';

		$clean['style'] = in_array( $input['style'] ?? '', [ 'outline', 'glow', 'badge', 'all' ], true )
			? $input['style'] : 'outline';

		$clean['color'] = sanitize_hex_color( $input['color'] ?? '#ff3b3b' ) ?: '#ff3b3b';
		$clean['ignore_classes'] = $this->sanitize_ignore_classes( $input['ignore_classes'] ?? '' );

		return $clean;

	}

	/**
	 * Sanitize a comma/newline separated list of CSS classes.
	 *
	 * @param string $value Raw field value.
	 * @return string
	 */
	protected function sanitize_ignore_classes( $value ): string {
		$value = is_string( $value ) ? $value : '';
		$parts = preg_split( '/[\s,]+/', $value );
		$parts = is_array( $parts ) ? $parts : [];
		$classes = [];

		foreach ( $parts as $part ) {
			$part = ltrim( sanitize_html_class( $part ), '.' );
			if ( '' !== $part ) {
				$classes[] = $part;

			}

		}

		$classes = array_values( array_unique( $classes ) );

		return implode( ', ', $classes );

	}

	public function render_settings( array $s ): void {

		?>

		<table class="form-table oa-form-table" role="presentation">

			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'visibility' ),
				'label' => __( 'Visible to', 'octave-addons' ),
				'field' => function () use ( $s ) {

					?>

					<select id="<?= esc_attr( $this->field_id( 'visibility' ) ); ?>" name="<?= esc_attr( $this->field_name( 'visibility' ) ); ?>">
						<option value="everyone"  <?php selected( $s['visibility'], 'everyone' ); ?>><?php esc_html_e( 'Everyone', 'octave-addons' ); ?></option>
						<option value="logged_in" <?php selected( $s['visibility'], 'logged_in' ); ?>><?php esc_html_e( 'Logged-in users only', 'octave-addons' ); ?></option>
						<option value="admins"    <?php selected( $s['visibility'], 'admins' ); ?>><?php esc_html_e( 'Admins only', 'octave-addons' ); ?></option>
					</select>
					<span class="oa-help"><?php esc_html_e( 'Who sees the highlight on the frontend. Admins-only is usually what you want in production.', 'octave-addons' ); ?></span>
					<?php

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'style' ),
				'label' => __( 'Highlight style', 'octave-addons' ),
				'field' => function () use ( $s ) {

					?>

					<select id="<?= esc_attr( $this->field_id( 'style' ) ); ?>" name="<?= esc_attr( $this->field_name( 'style' ) ); ?>">
						<option value="outline" <?php selected( $s['style'], 'outline' ); ?>><?php esc_html_e( 'Dashed outline', 'octave-addons' ); ?></option>
						<option value="glow"    <?php selected( $s['style'], 'glow' ); ?>><?php esc_html_e( 'Red glow (box-shadow)', 'octave-addons' ); ?></option>
						<option value="badge"   <?php selected( $s['style'], 'badge' ); ?>><?php esc_html_e( '⚠ Badge label', 'octave-addons' ); ?></option>
						<option value="all"     <?php selected( $s['style'], 'all' ); ?>><?php esc_html_e( 'All three combined', 'octave-addons' ); ?></option>
					</select>
					<?php

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'color' ),
				'label' => __( 'Highlight colour', 'octave-addons' ),
				'field' => function () use ( $s ) {
					Octave_Addons_Fields::color( [
						'id'    => $this->field_id( 'color' ),
						'name'  => $this->field_name( 'color' ),
						'value' => $s['color'],
						'help'  => __( 'Used for outline, glow and badge.', 'octave-addons' ),
					] );

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'label' => __( 'Tooltip on hover', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'name'    => $this->field_name( 'show_tooltip' ),
						'checked' => $s['show_tooltip'],
						'help'    => __( 'Show "Empty link" tooltip when hovering a flagged link', 'octave-addons' ),
					] );

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'label' => __( 'Admin bar counter', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'name'    => $this->field_name( 'show_admin_bar' ),
						'checked' => $s['show_admin_bar'],
						'help'    => __( 'Show a live empty-link count in the WordPress admin bar', 'octave-addons' ),
					] );

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'ignore_classes' ),
				'label' => __( 'Ignore link classes', 'octave-addons' ),
				'field' => function () use ( $s ) {
					Octave_Addons_Fields::text( [
						'id'          => $this->field_id( 'ignore_classes' ),
						'name'        => $this->field_name( 'ignore_classes' ),
						'value'       => $s['ignore_classes'],
						'placeholder' => __( 'button, reset_variations, my-link-class', 'octave-addons' ),
						'help'        => __( 'Comma or space separated classes to skip when checking empty links.', 'octave-addons' ),
					] );

				},
			] ); ?>
		</table>
		<?php

	}

	public function run( array $s ): void {

		add_action( 'wp_enqueue_scripts', function () use ( $s ) {

			$this->inject_assets( $s );
		} );

		add_action( 'admin_bar_menu', function ( WP_Admin_Bar $bar ) use ( $s ) {

			$this->admin_bar_node( $bar, $s );
		}, 999 );

	}

	protected function inject_assets( array $s ): void {

		if ( is_admin() ) {

			return;

		}

		if ( $this->is_woocommerce_page() ) {

			return;

		}

		// Visibility gate.
		switch ( $s['visibility'] ) {

			case 'admins':
				if ( ! current_user_can( 'manage_options' ) ) {
					return;

				}
				break;
			case 'logged_in':
				if ( ! is_user_logged_in() ) {
					return;

				}
				break;

		}

		$color   = esc_attr( $s['color'] );
		$rgb_arr = self::hex_to_rgb( $color );
		$rgb     = implode( ', ', $rgb_arr );

		$css = "[data-oa-elh-empty] { --oa-elh-color: {$color}; --oa-elh-rgb: {$rgb}; position: relative; }";

		if ( in_array( $s['style'], [ 'outline', 'all' ], true ) ) {

			$css .= "[data-oa-elh-empty] { outline: 2px dashed var(--oa-elh-color) !important; outline-offset: 3px; }";

		}
		if ( in_array( $s['style'], [ 'glow', 'all' ], true ) ) {

			$css .= "[data-oa-elh-empty] { box-shadow: 0 0 0 3px rgba(var(--oa-elh-rgb), 0.35), 0 0 12px rgba(var(--oa-elh-rgb), 0.45) !important; }";

		}
		if ( in_array( $s['style'], [ 'badge', 'all' ], true ) ) {

			$css .= "[data-oa-elh-empty]::after { content: '⚠ empty link'; position: absolute; top: -22px; left: 0; background: var(--oa-elh-color); color: #fff; font: bold 10px/1 sans-serif; padding: 3px 6px; border-radius: 3px; white-space: nowrap; pointer-events: none; z-index: 99999; letter-spacing: 0.04em; }";

		}
		if ( $s['show_tooltip'] ) {

			$css .= "[data-oa-elh-empty] { cursor: help !important; }";

		}
		$css .= "#wp-admin-bar-oa-elh-counter .ab-item { font-weight: 600; text-transform: capitalize; display: flex; align-items: center; gap: 6px; }";
		$css .= "#wp-admin-bar-oa-elh-counter .oa-elh-icon { width: auto; max-width: 20px; height: 20px; display: inline-block; flex: 0 0 auto; object-fit: contain; }";
		$css .= "#wp-admin-bar-oa-elh-counter .oa-elh-label { display: inline-block; }";

		wp_register_style( 'oa-empty-link-highlighter', false, [], OCTAVE_ADDONS_VERSION );
		wp_enqueue_style( 'oa-empty-link-highlighter' );
		wp_add_inline_style( 'oa-empty-link-highlighter', $css );

		$cfg = wp_json_encode( [
			'showTooltip'   => (bool) $s['show_tooltip'],
			'showAdminBar'  => (bool) $s['show_admin_bar'],
			'ignoreClasses' => array_values( array_filter( preg_split( '/[\s,]+/', (string) $s['ignore_classes'] ) ) ),
		] );

		$js = <<<JS
(function() {

	var cfg = {$cfg};
	var tooltipSuffix = ' [Empty link - no destination set]';
	function shouldIgnoreLink(a) {

		if (!cfg.ignoreClasses || !cfg.ignoreClasses.length) {

			return false;

		}

		return cfg.ignoreClasses.some(function(className) {

			return className && a.classList && a.classList.contains(className);

		});

	}
	function scan() {

		var links = document.querySelectorAll('a');
		var count = 0;
		links.forEach(function(a) {

			if (a.closest('#wpadminbar')) {

				a.removeAttribute('data-oa-elh-empty');
				var adminBarOriginalTitle = a.getAttribute('data-oa-elh-original-title');
				if (adminBarOriginalTitle !== null) {

					if (adminBarOriginalTitle) {

						a.setAttribute('title', adminBarOriginalTitle);

					} else {

						a.removeAttribute('title');

					}
					a.removeAttribute('data-oa-elh-original-title');

				}
				return;

			}
			if (shouldIgnoreLink(a)) {

				a.removeAttribute('data-oa-elh-empty');
				var ignoredOriginalTitle = a.getAttribute('data-oa-elh-original-title');
				if (ignoredOriginalTitle !== null) {

					if (ignoredOriginalTitle) {

						a.setAttribute('title', ignoredOriginalTitle);

					} else {

						a.removeAttribute('title');

					}
					a.removeAttribute('data-oa-elh-original-title');

				}
				return;

			}
			var href = (a.getAttribute('href') || '').trim();
			var isEmpty = href === '' || href === '#';
			if (isEmpty) {

				a.setAttribute('data-oa-elh-empty', '1');
				if (cfg.showTooltip) {

					var storedTitle = a.getAttribute('data-oa-elh-original-title');
					var currentTitle = a.getAttribute('title');
					if (storedTitle === null) {

						storedTitle = currentTitle || '';
						a.setAttribute('data-oa-elh-original-title', storedTitle);

					}
					if (!currentTitle || currentTitle.slice(-tooltipSuffix.length) !== tooltipSuffix) {

						a.setAttribute('title', storedTitle + tooltipSuffix);

					}

				}
				count++;

			} else {

				a.removeAttribute('data-oa-elh-empty');
				var originalTitle = a.getAttribute('data-oa-elh-original-title');
				if (originalTitle !== null) {

					if (originalTitle) {

						a.setAttribute('title', originalTitle);

					} else {

						a.removeAttribute('title');

					}
					a.removeAttribute('data-oa-elh-original-title');

				}

			}

		});
		if (cfg.showAdminBar) {

			var bar = document.getElementById('wp-admin-bar-oa-elh-counter');
			if (bar) {

				var label = bar.querySelector('.ab-item');
				if (label) {

					var labelText = label.querySelector('.oa-elh-label');
					if (labelText) {

						labelText.textContent = count > 0
						? '⚠ ' + count + ' Empty Link' + (count === 1 ? '' : 's')
						: '✓ No Empty Links';

					}
					label.style.color = count > 0 ? '#ff6b6b' : '#46b450';
					bar.style.display = '';

				}

			}

		}
		return count;

	}
	if (document.readyState === 'loading') {

		document.addEventListener('DOMContentLoaded', scan);

	} else {

		scan();

	}
})();
JS;

		wp_register_script( 'oa-empty-link-highlighter', false, [], OCTAVE_ADDONS_VERSION, true );
		wp_enqueue_script( 'oa-empty-link-highlighter' );
		wp_add_inline_script( 'oa-empty-link-highlighter', $js );

	}

	protected function admin_bar_node( WP_Admin_Bar $bar, array $s ): void {

		if ( is_admin() || empty( $s['show_admin_bar'] ) ) {

			return;

		}

		if ( $this->is_woocommerce_page() ) {

			return;

		}

		// Visibility gate (mirror of inject_assets).
		switch ( $s['visibility'] ) {

			case 'admins':
				if ( ! current_user_can( 'manage_options' ) ) {

					return;

				}
				break;
			case 'logged_in':
				if ( ! is_user_logged_in() ) {

					return;

				}
				break;

		}

		$icon = sprintf(
			'<img src="%s" alt="" class="oa-elh-icon" />',
			esc_url( OCTAVE_ADDONS_URL . 'assets/admin-icon.png' )
		);
		$label = sprintf(
			'<span class="oa-elh-label">%s</span>',
			esc_html__( 'Scanning links…', 'octave-addons' )
		);

		$bar->add_node( [
			'id'    => 'oa-elh-counter',
			'title' => $label,
			'href'  => admin_url( 'admin.php?page=' . OCTAVE_ADDONS_SLUG . '&tab=' . $this->get_id() ),
			'meta'  => [ 'title' => __( 'Octave Addons – empty-link count. Click to open settings.', 'octave-addons' ) ],
		] );

	}

	/**
	 * Detect frontend WooCommerce views without assuming WooCommerce is active.
	 */
	protected function is_woocommerce_page(): bool {

		if ( function_exists( 'is_woocommerce' ) && is_woocommerce() ) {

			return true;

		}

		$conditional_tags = [
			'is_shop',
			'is_product',
			'is_product_category',
			'is_product_tag',
			'is_cart',
			'is_checkout',
			'is_account_page',
		];

		foreach ( $conditional_tags as $tag ) {

			if ( function_exists( $tag ) && $tag() ) {

				return true;

			}

		}

		return false;

	}

	protected static function hex_to_rgb( string $hex ): array {

		$hex = ltrim( $hex, '#' );
		if ( strlen( $hex ) === 3 ) {

			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];

		}
		return [
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		];

	}

}

return new Octave_Addons_Module_Empty_Link_Highlighter();
