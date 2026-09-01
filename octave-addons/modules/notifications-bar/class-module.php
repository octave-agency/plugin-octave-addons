<?php

/*
MODULE: NOTIFICATIONS BAR
-- Renders a stack of announcement banners at the top or bottom of the site.
-- Each banner carries its own text, optional Breakdance button, link, show
-- from and show to dates, and an optional background override.
-- A banner only appears while today sits inside its dates, and a banner with
-- no dates set is always live, so the bar itself is only printed when at
-- least one banner qualifies.
-- Closing a banner writes a cookie that hides it for the configured number
-- of days, kept per banner so dismissing one leaves the rest in place.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Notifications_Bar extends Octave_Addons_Module {

	/** Prefix for the per-banner dismissal cookie. */
	protected const COOKIE_PREFIX = 'oa_nb_';

	/** Placeholder index the browser swaps out when cloning the card template. */
	protected const TEMPLATE_INDEX = '__INDEX__';


	public function get_id(): string {

		return 'notifications-bar';

	}

	public function get_title(): string {

		return __( 'Notifications Bar', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Shows scheduled announcement banners across the top or bottom of the site, each with its own message, button, dates and background.', 'octave-addons' );

	}

	public function get_defaults(): array {

		return [
			'enabled'         => false,
			'position'        => 'top',
			'sticky'          => false,
			'cookie_days'     => 7,
			'bg'              => self::background_defaults(),
			'text_color'      => '#ffffff',
			'link_color'      => '#ffffff',
			'close_color'     => '#ffffff',
			'button_style'    => 'primary',
			'button_override' => false,
			'button_bg'       => '#ffffff',
			'button_color'    => '#111827',
			'button_radius'   => 8,
			'custom_css'      => '',
			'banners'         => [],
		];

	}

	/*
	BACKGROUND DEFAULTS
	-- The starting value for every background control in this module, used
	-- for the module default and for each banner's own override.
	---------------------------------------------------------- */

	protected static function background_defaults(): array {

		return [
			'type'  => 'solid',
			'color' => '#111827',
			'from'  => '#111827',
			'to'    => '#2563EB',
			'angle' => 135,
		];

	}

	/*
	BANNER DEFAULTS
	-- One empty repeater row. The id is filled in by sanitize() so a saved
	-- banner keeps a stable dismissal cookie however it is later reordered.
	---------------------------------------------------------- */

	protected static function banner_defaults(): array {

		return [
			'id'          => '',
			'enabled'     => true,
			'text'        => '',
			'link'        => '',
			'new_tab'     => false,
			'button_text' => '',
			'date_from'   => '',
			'date_to'     => '',
			'appearance'  => 'default',
			'bg'          => self::background_defaults(),
			'text_color'  => '#ffffff',
		];

	}

	public function sanitize( $input ): array {

		$clean = $this->get_defaults();

		$clean['enabled']         = ! empty( $input['enabled'] );
		$clean['sticky']          = ! empty( $input['sticky'] );
		$clean['button_override'] = ! empty( $input['button_override'] );

		$clean['position'] = in_array( $input['position'] ?? '', [ 'top', 'bottom' ], true )
			? $input['position'] : 'top';

		$clean['cookie_days'] = max( 0, min( 365, (int) ( $input['cookie_days'] ?? 7 ) ) );

		$clean['bg']          = Octave_Addons_Fields::sanitize_background( $input['bg'] ?? [], self::background_defaults() );
		$clean['text_color']  = sanitize_hex_color( $input['text_color'] ?? '' )  ?: '#ffffff';
		$clean['link_color']  = sanitize_hex_color( $input['link_color'] ?? '' )  ?: '#ffffff';
		$clean['close_color'] = sanitize_hex_color( $input['close_color'] ?? '' ) ?: '#ffffff';

		$clean['button_style']  = $this->sanitize_button_style( $input['button_style'] ?? '' );
		$clean['button_bg']     = sanitize_hex_color( $input['button_bg'] ?? '' )    ?: '#ffffff';
		$clean['button_color']  = sanitize_hex_color( $input['button_color'] ?? '' ) ?: '#111827';
		$clean['button_radius'] = max( 0, min( 100, (int) ( $input['button_radius'] ?? 8 ) ) );

		$clean['custom_css'] = self::sanitize_css( $input['custom_css'] ?? '' );
		$clean['banners']    = $this->sanitize_banners( $input['banners'] ?? [] );

		return $clean;

	}

	/*
	SANITIZE BANNERS
	-- Rebuilds the repeater from the submitted rows, dropping the template row
	-- the browser never renamed and giving every row a unique stable id.
	---------------------------------------------------------- */

	protected function sanitize_banners( $input ): array {

		if ( ! is_array( $input ) ) {

			return [];

		}

		$banners = [];
		$used    = [];

		foreach ( $input as $index => $raw ) {

			if ( self::TEMPLATE_INDEX === (string) $index || ! is_array( $raw ) ) {

				continue;

			}

			$banner = self::banner_defaults();

			$banner['text']        = self::sanitize_message( $raw['text'] ?? '' );
			$banner['button_text'] = sanitize_text_field( wp_unslash( $raw['button_text'] ?? '' ) );
			$banner['link']        = esc_url_raw( wp_unslash( $raw['link'] ?? '' ) );
			$banner['enabled']     = ! empty( $raw['enabled'] );
			$banner['new_tab']     = ! empty( $raw['new_tab'] );
			$banner['date_from']   = self::sanitize_date( $raw['date_from'] ?? '' );
			$banner['date_to']     = self::sanitize_date( $raw['date_to'] ?? '' );

			$banner['appearance'] = 'custom' === ( $raw['appearance'] ?? '' ) ? 'custom' : 'default';
			$banner['bg']         = Octave_Addons_Fields::sanitize_background( $raw['bg'] ?? [], self::background_defaults() );
			$banner['text_color'] = sanitize_hex_color( $raw['text_color'] ?? '' ) ?: '#ffffff';

			// An empty row is a card the editor added and never filled in.
			if ( '' === trim( wp_strip_all_tags( $banner['text'] ) ) && '' === $banner['button_text'] ) {

				continue;

			}

			$id = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) ( $raw['id'] ?? '' ) ) );
			$id = substr( (string) $id, 0, 12 );

			if ( '' === $id || isset( $used[ $id ] ) ) {

				$id = self::new_banner_id( $used );

			}

			$used[ $id ]  = true;
			$banner['id'] = $id;

			$banners[] = $banner;

		}

		return $banners;

	}

	/*
	NEW BANNER ID
	-- A short random token that has not already been handed to another row in
	-- this submission, used as the banner's dismissal cookie name.
	---------------------------------------------------------- */

	protected static function new_banner_id( array $used ): string {

		do {

			$id = substr( md5( uniqid( 'oa-nb', true ) ), 0, 8 );

		} while ( isset( $used[ $id ] ) );

		return $id;

	}

	/*
	SANITIZE MESSAGE
	-- Banner copy keeps the inline formatting an editor is likely to reach for
	-- and nothing else.
	---------------------------------------------------------- */

	protected static function sanitize_message( $raw ): string {

		$allowed = [
			'strong' => [],
			'b'      => [],
			'em'     => [],
			'i'      => [],
			'br'     => [],
			'span'   => [ 'class' => [] ],
			'a'      => [ 'href' => [], 'title' => [], 'target' => [], 'rel' => [] ],
		];

		return wp_kses( wp_unslash( (string) $raw ), $allowed );

	}

	/*
	SANITIZE DATE
	-- Accepts the YYYY-MM-DD a date control submits and rejects anything else,
	-- so a broken value can never widen a banner's schedule.
	---------------------------------------------------------- */

	protected static function sanitize_date( $raw ): string {

		$value = trim( (string) $raw );

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) ) {

			return '';

		}

		if ( ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {

			return '';

		}

		return $value;

	}

	/*
	SANITIZE CSS
	-- Keeps the custom stylesheet to declarations only. Markup is stripped so
	-- the field can never close the style element it is printed inside.
	---------------------------------------------------------- */

	protected static function sanitize_css( $raw ): string {

		$css = wp_strip_all_tags( (string) wp_unslash( $raw ) );

		return trim( str_replace( [ '<', '>' ], '', $css ) );

	}

	protected function sanitize_button_style( $raw ): string {

		$value = sanitize_text_field( (string) $raw );

		if ( in_array( $value, [ 'primary', 'secondary', 'text' ], true ) ) {

			return $value;

		}

		if ( preg_match( '/^preset-[A-Za-z0-9_-]{1,64}$/', $value ) ) {

			return $value;

		}

		return 'primary';

	}

	/*
	BUTTON STYLE OPTIONS
	-- The three Breakdance button styles plus every button preset the site has
	-- defined, so a banner button can match the rest of the design.
	---------------------------------------------------------- */

	protected function button_style_options(): array {

		$options = [
			'primary'   => __( 'Breakdance primary', 'octave-addons' ),
			'secondary' => __( 'Breakdance secondary', 'octave-addons' ),
			'text'      => __( 'Breakdance text link', 'octave-addons' ),
		];

		if ( ! function_exists( '\Breakdance\Data\get_global_settings_array' ) ) {

			return $options;

		}

		$global   = \Breakdance\Data\get_global_settings_array();
		$presets  = $global['settings']['buttons']['button_presets']['button_presets'] ?? [];

		if ( ! is_array( $presets ) ) {

			return $options;

		}

		foreach ( $presets as $preset ) {

			$id = isset( $preset['id'] ) ? (string) $preset['id'] : '';

			if ( '' === $id || ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $id ) ) {

				continue;

			}

			$label = isset( $preset['name'] ) && '' !== $preset['name']
				? (string) $preset['name']
				: $id;

			/* translators: %s: Breakdance button preset name. */
			$options[ 'preset-' . $id ] = sprintf( __( 'Preset — %s', 'octave-addons' ), $label );

		}

		return $options;

	}

	/*
	SETTINGS
	---------------------------------------------------------- */

	public function render_settings( array $s ): void {

		?>

		<p class="oa-help oa-help--intro">
			<?php esc_html_e( 'Add one card per announcement. A banner shows while today falls between its dates, and a banner with no dates set is always live. The bar is only printed when at least one banner qualifies.', 'octave-addons' ); ?>
		</p>

		<?php $this->render_banners( $s ); ?>

		<table class="form-table oa-form-table" role="presentation">

			<?php Octave_Addons_Fields::section( [ 'label' => __( 'Placement', 'octave-addons' ), 'first' => true ] ); ?>
			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'position' ),
				'label' => __( 'Position', 'octave-addons' ),
				'field' => function () use ( $s ) {

					?>

					<select id="<?= esc_attr( $this->field_id( 'position' ) ); ?>"
					        name="<?= esc_attr( $this->field_name( 'position' ) ); ?>"
					        data-controls-row="oaNbRowSticky" data-controls-value="top">
						<option value="top"    <?php selected( $s['position'], 'top' ); ?>><?php esc_html_e( 'Top of the page', 'octave-addons' ); ?></option>
						<option value="bottom" <?php selected( $s['position'], 'bottom' ); ?>><?php esc_html_e( 'Fixed to the bottom of the screen', 'octave-addons' ); ?></option>
					</select>
					<span class="oa-help"><?php esc_html_e( 'A top bar sits above the header in the page flow. A bottom bar floats over the page like a cookie notice.', 'octave-addons' ); ?></span>
					<?php

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'id'    => 'oaNbRowSticky',
				'label' => __( 'Stay visible on scroll', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'id'      => $this->field_id( 'sticky' ),
						'name'    => $this->field_name( 'sticky' ),
						'checked' => ! empty( $s['sticky'] ),
						'help'    => __( 'Sticks the top bar to the top of the window as the page scrolls.', 'octave-addons' ),
					] );

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'cookie_days' ),
				'label' => __( 'Hide for', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::number( [
						'id'     => $this->field_id( 'cookie_days' ),
						'name'   => $this->field_name( 'cookie_days' ),
						'value'  => $s['cookie_days'],
						'min'    => 0,
						'max'    => 365,
						'suffix' => __( 'days', 'octave-addons' ),
						'help'   => __( 'How long a closed banner stays hidden for that visitor. Each banner is remembered separately, and 0 hides it until the browser is closed.', 'octave-addons' ),
					] );

				},
			] ); ?>

			<?php Octave_Addons_Fields::section( [ 'label' => __( 'Default Appearance', 'octave-addons' ) ] ); ?>
			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'bg' ),
				'label' => __( 'Background', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::background( [
						'id'    => $this->field_id( 'bg' ),
						'name'  => $this->field_name( 'bg' ),
						'value' => $s['bg'],
						'help'  => __( 'Used by every banner that has not set its own background.', 'octave-addons' ),
					] );

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'text_color' ),
				'label' => __( 'Text colour', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::color( [
						'id'    => $this->field_id( 'text_color' ),
						'name'  => $this->field_name( 'text_color' ),
						'value' => $s['text_color'],
					] );

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'link_color' ),
				'label' => __( 'Link colour', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::color( [
						'id'    => $this->field_id( 'link_color' ),
						'name'  => $this->field_name( 'link_color' ),
						'value' => $s['link_color'],
						'help'  => __( 'Applied to links inside the message, and to the whole message when a banner has a link but no button.', 'octave-addons' ),
					] );

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'close_color' ),
				'label' => __( 'Close icon colour', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::color( [
						'id'    => $this->field_id( 'close_color' ),
						'name'  => $this->field_name( 'close_color' ),
						'value' => $s['close_color'],
					] );

				},
			] ); ?>

			<?php Octave_Addons_Fields::section( [ 'label' => __( 'Button', 'octave-addons' ) ] ); ?>
			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'button_style' ),
				'label' => __( 'Button style', 'octave-addons' ),
				'field' => function () use ( $s ) {

					$options = $this->button_style_options();

					?>

					<select id="<?= esc_attr( $this->field_id( 'button_style' ) ); ?>"
					        name="<?= esc_attr( $this->field_name( 'button_style' ) ); ?>">
						<?php

						foreach ( $options as $key => $label ) {

							printf(
								'<option value="%1$s"%2$s>%3$s</option>',
								esc_attr( $key ),
								selected( $s['button_style'], $key, false ),
								esc_html( $label )
							);

						}

						?>

					</select>
					<span class="oa-help"><?php esc_html_e( 'Banner buttons are rendered with Breakdance button markup, so they inherit the site button styling set in Breakdance global settings.', 'octave-addons' ); ?></span>
					<?php

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'label' => __( 'Override button styling', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'id'      => $this->field_id( 'button_override' ),
						'name'    => $this->field_name( 'button_override' ),
						'checked' => ! empty( $s['button_override'] ),
						'data'    => [ 'controls-row' => 'oaNbRowButtonBg,oaNbRowButtonColor,oaNbRowButtonRadius' ],
						'help'    => __( 'Replaces the colours and corner radius of the chosen Breakdance style for banner buttons only. Everything else — typography, padding, hover — is left to Breakdance.', 'octave-addons' ),
					] );

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'id'    => 'oaNbRowButtonBg',
				'for'   => $this->field_id( 'button_bg' ),
				'label' => __( 'Button background', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::color( [
						'id'    => $this->field_id( 'button_bg' ),
						'name'  => $this->field_name( 'button_bg' ),
						'value' => $s['button_bg'],
					] );

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'id'    => 'oaNbRowButtonColor',
				'for'   => $this->field_id( 'button_color' ),
				'label' => __( 'Button text colour', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::color( [
						'id'    => $this->field_id( 'button_color' ),
						'name'  => $this->field_name( 'button_color' ),
						'value' => $s['button_color'],
					] );

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'id'    => 'oaNbRowButtonRadius',
				'for'   => $this->field_id( 'button_radius' ),
				'label' => __( 'Button corner radius', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::number( [
						'id'     => $this->field_id( 'button_radius' ),
						'name'   => $this->field_name( 'button_radius' ),
						'value'  => $s['button_radius'],
						'min'    => 0,
						'max'    => 100,
						'suffix' => 'px',
					] );

				},
			] ); ?>

			<?php Octave_Addons_Fields::section( [ 'label' => __( 'Custom CSS', 'octave-addons' ) ] ); ?>
			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'custom_css' ),
				'label' => __( 'Additional CSS', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::textarea( [
						'id'          => $this->field_id( 'custom_css' ),
						'name'        => $this->field_name( 'custom_css' ),
						'value'       => $s['custom_css'],
						'rows'        => 8,
						'class'       => 'large-text code oa-code-area',
						'spellcheck'  => false,
						'placeholder' => ".oa-nb__banner {\n    font-size: 15px;\n}",
						'help'        => __( 'Printed after the bar stylesheet. Target .oa-nb for the bar, .oa-nb__banner for one banner, .oa-nb__text, .oa-nb__button and .oa-nb__close for the parts inside it.', 'octave-addons' ),
					] );

				},
			] ); ?>
		</table>
		<?php

	}

	/*
	BANNERS
	-- The repeater itself: one card per banner, plus the hidden template the
	-- browser clones when Add banner is pressed.
	---------------------------------------------------------- */

	protected function render_banners( array $s ): void {

		$banners = is_array( $s['banners'] ?? null ) ? $s['banners'] : [];

		?>

		<div class="oa-nb-section oa-custom-posts-box" data-oa-nb-repeater>
			<div class="oa-nb-section-head">
				<div>
					<h3><?php esc_html_e( 'Banners', 'octave-addons' ); ?></h3>
					<p><?php esc_html_e( 'Every banner in date range is shown, stacked in this order. Each one closes on its own.', 'octave-addons' ); ?></p>
				</div>
				<button type="button" class="button oa-nb-add">
					<span class="oa-nb-add-icon" aria-hidden="true">+</span>
					<?php esc_html_e( 'Add banner', 'octave-addons' ); ?>
				</button>
			</div>

			<div class="oa-nb-list" data-empty-text="<?php esc_attr_e( 'No banners have been added yet.', 'octave-addons' ); ?>"><?php

			foreach ( $banners as $index => $banner ) {

				$this->render_banner_card( (string) $index, $banner, true );

			}

			?></div>

			<template class="oa-nb-template">
				<?php $this->render_banner_card( self::TEMPLATE_INDEX, self::banner_defaults(), false ); ?>
			</template>
		</div>
		<?php

	}

	/*
	BANNER CARD
	-- One repeater row. $saved is false for the template, which starts open so
	-- a freshly added banner is ready to type into.
	---------------------------------------------------------- */

	protected function render_banner_card( string $index, array $banner, bool $saved ): void {

		$banner = wp_parse_args( $banner, self::banner_defaults() );
		$title  = trim( wp_strip_all_tags( (string) $banner['text'] ) );
		$title  = '' !== $title ? $title : __( 'New banner', 'octave-addons' );

		?>

		<article class="oa-nb-item" data-oa-nb-card>
			<div class="oa-nb-item-head">
				<button type="button" class="oa-nb-expand" aria-expanded="<?= $saved ? 'false' : 'true'; ?>">
					<span class="oa-nb-expand-copy">
						<span class="oa-nb-swatch" aria-hidden="true"></span>
						<strong class="oa-nb-item-title"><?= esc_html( $title ); ?></strong>
						<span class="oa-nb-item-dates"><?= esc_html( $this->schedule_label( $banner ) ); ?></span>
					</span>
					<span class="dashicons dashicons-arrow-down-alt2 oa-nb-expand-icon" aria-hidden="true"></span>
					<span class="screen-reader-text"><?php esc_html_e( 'Toggle banner settings', 'octave-addons' ); ?></span>
				</button>
				<div class="oa-nb-enabled-summary">
					<span><?php esc_html_e( 'Live', 'octave-addons' ); ?></span>
					<label class="oa-switch">
						<input type="checkbox" class="oa-nb-enabled-toggle"
						       name="<?= esc_attr( $this->banner_field_name( $index, 'enabled' ) ); ?>"
						       value="1"<?php checked( ! empty( $banner['enabled'] ) ); ?>>
						<span class="oa-switch-slider"></span>
					</label>
				</div>
				<button type="button" class="oa-nb-remove" aria-label="<?php esc_attr_e( 'Remove this banner', 'octave-addons' ); ?>">
					<span class="dashicons dashicons-trash" aria-hidden="true"></span>
				</button>
			</div>

			<div class="oa-nb-groups<?= $saved ? ' oa-hidden' : ''; ?>">

				<input type="hidden" name="<?= esc_attr( $this->banner_field_name( $index, 'id' ) ); ?>" value="<?= esc_attr( $banner['id'] ); ?>">

				<fieldset class="oa-nb-group">
					<legend><?php esc_html_e( 'Message', 'octave-addons' ); ?></legend>
					<div class="oa-nb-fields">
						<label class="oa-nb-field oa-nb-field--full">
							<span><?php esc_html_e( 'Text', 'octave-addons' ); ?></span>
							<textarea data-oa-nb-title rows="2"
							          name="<?= esc_attr( $this->banner_field_name( $index, 'text' ) ); ?>"
							          placeholder="<?php esc_attr_e( 'Free delivery on every order this week', 'octave-addons' ); ?>"><?= esc_textarea( $banner['text'] ); ?></textarea>
							<small><?php esc_html_e( 'Basic formatting is allowed: strong, em, span, br and a.', 'octave-addons' ); ?></small>
						</label>
						<label class="oa-nb-field oa-nb-field--full">
							<span><?php esc_html_e( 'Link', 'octave-addons' ); ?></span>
							<input type="url" name="<?= esc_attr( $this->banner_field_name( $index, 'link' ) ); ?>"
							       value="<?= esc_attr( $banner['link'] ); ?>"
							       placeholder="https://">
							<small><?php esc_html_e( 'Used by the button below. With no button text, the message itself becomes the link.', 'octave-addons' ); ?></small>
						</label>
						<label class="oa-nb-field">
							<span><?php esc_html_e( 'Button text', 'octave-addons' ); ?></span>
							<input type="text" name="<?= esc_attr( $this->banner_field_name( $index, 'button_text' ) ); ?>"
							       value="<?= esc_attr( $banner['button_text'] ); ?>"
							       placeholder="<?php esc_attr_e( 'Shop now', 'octave-addons' ); ?>">
							<small><?php esc_html_e( 'Leave empty for a message with no button.', 'octave-addons' ); ?></small>
						</label>
						<label class="oa-nb-field oa-nb-field--switch">
							<span><?php esc_html_e( 'Open in a new tab', 'octave-addons' ); ?></span>
							<span class="oa-switch">
								<input type="checkbox" name="<?= esc_attr( $this->banner_field_name( $index, 'new_tab' ) ); ?>"
								       value="1"<?php checked( ! empty( $banner['new_tab'] ) ); ?>>
								<span class="oa-switch-slider"></span>
							</span>
						</label>
					</div>
				</fieldset>

				<fieldset class="oa-nb-group">
					<legend><?php esc_html_e( 'Schedule', 'octave-addons' ); ?></legend>
					<p class="oa-nb-group-description"><?php esc_html_e( 'Both dates are inclusive and use the site timezone. Leave a date empty for no limit in that direction, and leave both empty to run the banner permanently.', 'octave-addons' ); ?></p>
					<div class="oa-nb-fields">
						<label class="oa-nb-field">
							<span><?php esc_html_e( 'Show from', 'octave-addons' ); ?></span>
							<?php

							Octave_Addons_Fields::date( [
								'name'  => $this->banner_field_name( $index, 'date_from' ),
								'value' => $banner['date_from'],
								'class' => '',
							] );

							?>

						</label>
						<label class="oa-nb-field">
							<span><?php esc_html_e( 'Show to', 'octave-addons' ); ?></span>
							<?php

							Octave_Addons_Fields::date( [
								'name'  => $this->banner_field_name( $index, 'date_to' ),
								'value' => $banner['date_to'],
								'class' => '',
							] );

							?>

						</label>
					</div>
				</fieldset>

				<fieldset class="oa-nb-group">
					<legend><?php esc_html_e( 'Appearance', 'octave-addons' ); ?></legend>
					<div class="oa-nb-fields">
						<label class="oa-nb-field">
							<span><?php esc_html_e( 'Colours', 'octave-addons' ); ?></span>
							<select class="oa-nb-appearance" name="<?= esc_attr( $this->banner_field_name( $index, 'appearance' ) ); ?>">
								<option value="default"<?php selected( $banner['appearance'], 'default' ); ?>><?php esc_html_e( 'Use the defaults below', 'octave-addons' ); ?></option>
								<option value="custom"<?php selected( $banner['appearance'], 'custom' ); ?>><?php esc_html_e( 'Set for this banner', 'octave-addons' ); ?></option>
							</select>
						</label>
						<div class="oa-nb-field oa-nb-field--custom<?= 'custom' === $banner['appearance'] ? '' : ' oa-hidden'; ?>">
							<span><?php esc_html_e( 'Text colour', 'octave-addons' ); ?></span>
							<?php

							Octave_Addons_Fields::color( [
								'name'  => $this->banner_field_name( $index, 'text_color' ),
								'value' => $banner['text_color'],
							] );

							?>

						</div>
						<div class="oa-nb-field oa-nb-field--full oa-nb-field--custom<?= 'custom' === $banner['appearance'] ? '' : ' oa-hidden'; ?>">
							<span><?php esc_html_e( 'Background', 'octave-addons' ); ?></span>
							<?php

							Octave_Addons_Fields::background( [
								'name'  => $this->banner_field_name( $index, 'bg' ),
								'value' => $banner['bg'],
							] );

							?>

						</div>
					</div>
				</fieldset>

			</div>
		</article>
		<?php

	}

	/*
	SCHEDULE LABEL
	-- The short date summary shown on a collapsed card.
	---------------------------------------------------------- */

	protected function schedule_label( array $banner ): string {

		$from = $banner['date_from'] ?? '';
		$to   = $banner['date_to']   ?? '';

		if ( '' === $from && '' === $to ) {

			return __( 'Always on', 'octave-addons' );

		}

		if ( '' === $to ) {

			/* translators: %s: start date. */
			return sprintf( __( 'From %s', 'octave-addons' ), $from );

		}

		if ( '' === $from ) {

			/* translators: %s: end date. */
			return sprintf( __( 'Until %s', 'octave-addons' ), $to );

		}

		/* translators: 1: start date, 2: end date. */
		return sprintf( __( '%1$s to %2$s', 'octave-addons' ), $from, $to );

	}

	protected function banner_field_name( string $index, string $key ): string {

		return sprintf(
			'%s[%s][banners][%s][%s]',
			OCTAVE_ADDONS_OPTION_KEY,
			$this->get_id(),
			$index,
			$key
		);

	}

	/*
	FRONTEND
	---------------------------------------------------------- */

	public function run( array $s ): void {

		add_action( 'wp_enqueue_scripts', function () use ( $s ) {

			$this->enqueue_assets( $s );

		} );

		$hook = 'bottom' === ( $s['position'] ?? 'top' ) ? 'wp_footer' : 'wp_body_open';

		add_action( $hook, function () use ( $s ) {

			$this->render_bar( $s );

		}, 5 );

	}

	/*
	ACTIVE BANNERS
	-- The banners that are switched on and inside their dates, where a banner
	-- with no dates is always in range.
	-- Dismissal is deliberately left to the browser: filtering on the cookie
	-- here would let a page cached for someone who had closed a banner be
	-- served to everyone else with that banner missing.
	---------------------------------------------------------- */

	protected function active_banners( array $s ): array {

		$banners = is_array( $s['banners'] ?? null ) ? $s['banners'] : [];
		$today   = current_time( 'Y-m-d' );
		$active  = [];

		foreach ( $banners as $banner ) {

			if ( ! is_array( $banner ) || empty( $banner['enabled'] ) ) {

				continue;

			}

			$from = $banner['date_from'] ?? '';
			$to   = $banner['date_to']   ?? '';

			if ( '' !== $from && $today < $from ) {

				continue;

			}

			if ( '' !== $to && $today > $to ) {

				continue;

			}

			$active[] = $banner;

		}

		return $active;

	}

	protected function enqueue_assets( array $s ): void {

		if ( empty( $this->active_banners( $s ) ) ) {

			return;

		}

		$base_dir = OCTAVE_ADDONS_DIR . 'modules/notifications-bar/assets/';
		$base_url = OCTAVE_ADDONS_URL . 'modules/notifications-bar/assets/';

		$css_path = $base_dir . 'notifications-bar.css';
		$js_path  = $base_dir . 'notifications-bar.js';

		wp_enqueue_style(
			'octave-notifications-bar',
			$base_url . 'notifications-bar.css',
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : OCTAVE_ADDONS_VERSION
		);

		wp_enqueue_script(
			'octave-notifications-bar',
			$base_url . 'notifications-bar.js',
			[],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : OCTAVE_ADDONS_VERSION,
			true
		);

		wp_add_inline_style( 'octave-notifications-bar', $this->inline_css( $s ) );

	}

	/*
	INLINE CSS
	-- The saved defaults as custom properties, the optional button overrides,
	-- and finally the module's own custom CSS so it wins over both.
	---------------------------------------------------------- */

	protected function inline_css( array $s ): string {

		$background = Octave_Addons_Fields::background_css( $s['bg'] ?? [] ) ?: '#111827';

		$css = sprintf(
			'.oa-nb{--oa-nb-bg:%1$s;--oa-nb-text:%2$s;--oa-nb-link:%3$s;--oa-nb-close:%4$s;}',
			$background,
			$s['text_color']  ?? '#ffffff',
			$s['link_color']  ?? '#ffffff',
			$s['close_color'] ?? '#ffffff'
		);

		// Four class levels so the override lands above the Breakdance global
		// button rules whichever order the two stylesheets are printed in.
		if ( ! empty( $s['button_override'] ) ) {

			$css .= sprintf(
				'.breakdance .oa-nb .oa-nb__button.button-atom{background-color:%1$s;background-image:none;border-color:%1$s;color:%2$s;border-radius:%3$dpx;}',
				$s['button_bg']     ?? '#ffffff',
				$s['button_color']  ?? '#111827',
				(int) ( $s['button_radius'] ?? 8 )
			);

			$css .= sprintf(
				'.breakdance .oa-nb .oa-nb__button.button-atom .button-atom__text{color:%s;}',
				$s['button_color'] ?? '#111827'
			);

		}

		if ( ! Octave_Addons::is_breakdance_active() ) {

			$css .= '.oa-nb .oa-nb__button{display:inline-flex;align-items:center;gap:8px;padding:9px 18px;border:1px solid currentColor;border-radius:8px;font-weight:600;line-height:1.2;text-decoration:none;}';

		}

		if ( ! empty( $s['custom_css'] ) ) {

			$css .= "\n" . $s['custom_css'];

		}

		return $css;

	}

	/*
	RENDER BAR
	-- Prints the bar and, immediately after it, the small script that clears
	-- banners this visitor has already closed. That runs before the first
	-- paint, so a page served from a cache never flashes a dismissed banner.
	---------------------------------------------------------- */

	protected function render_bar( array $s ): void {

		$banners = $this->active_banners( $s );

		if ( empty( $banners ) ) {

			return;

		}

		$position = 'bottom' === ( $s['position'] ?? 'top' ) ? 'bottom' : 'top';
		$classes  = [ 'oa-nb', 'oa-nb--' . $position, 'breakdance' ];

		if ( 'top' === $position && ! empty( $s['sticky'] ) ) {

			$classes[] = 'oa-nb--sticky';

		}

		?>

		<div id="oaNotificationsBar" class="<?= esc_attr( implode( ' ', $classes ) ); ?>"
		     data-cookie-days="<?= esc_attr( (string) ( $s['cookie_days'] ?? 7 ) ); ?>"
		     role="region" aria-label="<?php esc_attr_e( 'Site notifications', 'octave-addons' ); ?>">

			<?php

			foreach ( $banners as $banner ) {

				$this->render_banner( $s, $banner );

			}

			?>

		</div>
		<?php

		wp_print_inline_script_tag( $this->dismissal_script() );

	}

	protected function render_banner( array $s, array $banner ): void {

		$style = '';

		if ( 'custom' === ( $banner['appearance'] ?? 'default' ) ) {

			$background = Octave_Addons_Fields::background_css( $banner['bg'] ?? [] );
			$text_color = $banner['text_color'] ?? '';

			$style .= $background ? 'background:' . $background . ';' : '';
			$style .= $text_color ? 'color:' . $text_color . ';--oa-nb-link:' . $text_color . ';--oa-nb-close:' . $text_color . ';' : '';

		}

		$link        = $banner['link']        ?? '';
		$button_text = $banner['button_text'] ?? '';
		$new_tab     = ! empty( $banner['new_tab'] );
		$link_text   = '' !== $link && '' === $button_text;

		$target = $new_tab ? ' target="_blank" rel="noopener noreferrer"' : '';

		?>

		<div class="oa-nb__banner" data-oa-nb-id="<?= esc_attr( $banner['id'] ?? '' ); ?>"<?= $style ? ' style="' . esc_attr( $style ) . '"' : ''; ?>>

			<div class="oa-nb__inner">

				<?php

				if ( $link_text ) :

				?>

				<a class="oa-nb__text oa-nb__text--link" href="<?= esc_url( $link ); ?>"<?= $target; ?>><?= wp_kses_post( $banner['text'] ?? '' ); ?></a>

				<?php

				else :

				?>

				<p class="oa-nb__text"><?= wp_kses_post( $banner['text'] ?? '' ); ?></p>

				<?php

				endif;

				if ( '' !== $button_text ) :

				?>

				<a class="<?= esc_attr( $this->button_classes( $s ) ); ?>" href="<?= esc_url( $link ?: '#' ); ?>"<?= $target; ?>>
					<span class="button-atom__text"><?= esc_html( $button_text ); ?></span>
				</a>

				<?php

				endif;

				?>

			</div>

			<button type="button" class="oa-nb__close" aria-label="<?php esc_attr_e( 'Dismiss this notification', 'octave-addons' ); ?>">
				<?php Octave_Addons_Icons::render( 'close', 18 ); ?>
			</button>

		</div>
		<?php

	}

	/*
	BUTTON CLASSES
	-- Mirrors the class list Breakdance's own button macro builds, so a banner
	-- button picks up the site's global button styling untouched.
	---------------------------------------------------------- */

	protected function button_classes( array $s ): string {

		$style   = $s['button_style'] ?? 'primary';
		$classes = [ 'button-atom', 'oa-nb__button' ];

		if ( 0 === strpos( $style, 'preset-' ) ) {

			$classes[] = 'button-atom--custom';
			$classes[] = 'button-atom--' . $style;

			return implode( ' ', $classes );

		}

		$classes[] = 'button-atom--' . $style;

		return implode( ' ', $classes );

	}

	/*
	DISMISSAL SCRIPT
	-- Runs where it is printed, before the browser paints, so a banner the
	-- visitor closed is gone rather than removed a moment later.
	---------------------------------------------------------- */

	protected function dismissal_script(): string {

		$prefix = wp_json_encode( self::COOKIE_PREFIX );

		return <<<JS
(function () {

	var bar = document.getElementById( 'oaNotificationsBar' );

	if ( ! bar ) {

		return;

	}

	var cookies = document.cookie;

	Array.prototype.forEach.call( bar.querySelectorAll( '[data-oa-nb-id]' ), function ( banner ) {

		if ( -1 !== cookies.indexOf( {$prefix} + banner.getAttribute( 'data-oa-nb-id' ) + '=' ) ) {

			banner.parentNode.removeChild( banner );

		}

	} );

	if ( ! bar.querySelector( '[data-oa-nb-id]' ) ) {

		bar.parentNode.removeChild( bar );

	}

})();
JS;

	}

}

return new Octave_Addons_Module_Notifications_Bar();
