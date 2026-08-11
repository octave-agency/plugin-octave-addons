<?php

/*
MODULE: MOBILE CONTACT POPUP
-- Renders a sticky bar at the bottom of the screen on mobile.
-- Tapping the trigger slides up a sheet with the configured contact details.
-- Optionally shows a direct Call button alongside the main trigger.
-- Phone, email, and address can each be individually toggled on/off.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Mobile_Contact_Popup extends Octave_Addons_Module {


	public function get_id(): string {

		return 'mobile-contact-popup';

	}

	public function get_title(): string {

		return __( 'Mobile Contact Popup', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Adds a sticky bar on mobile with a "Contact Us" popup trigger and an optional direct Call button.', 'octave-addons' );

	}

	public function get_defaults(): array {

		return [
			'enabled'        => false,
			'show_phone'     => false,
			'phone'          => '',
			'phone_icon'     => '',
			'show_email'     => false,
			'email'          => '',
			'email_icon'     => '',
			'show_address'   => false,
			'address'        => '',
			'address_icon'   => '',
			'trigger_label'  => 'Contact Us',
			'trigger_icon'   => '',
			'trigger_bg'     => '#111111',
			'trigger_color'  => '#ffffff',
			'popup_label'    => 'Contact Us',
			'show_call_btn'  => false,
			'call_label'     => 'Call Now',
			'call_icon'      => '',
			'call_bg'        => '#16a34a',
			'call_color'     => '#ffffff',
		];

	}

	public function sanitize( $input ): array {

		$clean                  = $this->get_defaults();
		$clean['enabled']       = ! empty( $input['enabled'] );
		$clean['show_phone']    = ! empty( $input['show_phone'] );
		$clean['phone']         = isset( $input['phone'] )   ? sanitize_text_field( wp_unslash( $input['phone'] ) )       : '';
		$clean['phone_icon']    = self::sanitize_svg( $input['phone_icon'] ?? '' );
		$clean['show_email']    = ! empty( $input['show_email'] );
		$clean['email']         = isset( $input['email'] )   ? sanitize_email( wp_unslash( $input['email'] ) )             : '';
		$clean['email_icon']    = self::sanitize_svg( $input['email_icon'] ?? '' );
		$clean['show_address']  = ! empty( $input['show_address'] );
		$clean['address']       = isset( $input['address'] ) ? sanitize_textarea_field( wp_unslash( $input['address'] ) ) : '';
		$clean['address_icon']  = self::sanitize_svg( $input['address_icon'] ?? '' );
		$clean['trigger_label'] = isset( $input['trigger_label'] ) ? sanitize_text_field( wp_unslash( $input['trigger_label'] ) ) : 'Contact Us';
		$clean['trigger_icon']  = self::sanitize_svg( $input['trigger_icon'] ?? '' );
		$clean['trigger_bg']    = sanitize_hex_color( $input['trigger_bg'] ?? '' ) ?? '#111111';
		$clean['trigger_color'] = sanitize_hex_color( $input['trigger_color'] ?? '' ) ?? '#ffffff';
		$clean['popup_label']   = isset( $input['popup_label'] ) ? sanitize_text_field( wp_unslash( $input['popup_label'] ) ) : 'Contact Us';
		$clean['show_call_btn'] = ! empty( $input['show_call_btn'] );
		$clean['call_label']    = isset( $input['call_label'] ) ? sanitize_text_field( wp_unslash( $input['call_label'] ) ) : 'Call Now';
		$clean['call_icon']     = self::sanitize_svg( $input['call_icon'] ?? '' );
		$clean['call_bg']       = sanitize_hex_color( $input['call_bg'] ?? '' ) ?? '#16a34a';
		$clean['call_color']    = sanitize_hex_color( $input['call_color'] ?? '' ) ?? '#ffffff';
		return $clean;

	}

	public function render_settings( array $s ): void {

		?>

		<p class="oa-help oa-help--intro">
			<?php esc_html_e( 'Fill in at least one contact field below. The bar only appears on mobile screens (≤767 px) and only when at least one field is enabled and has a value.', 'octave-addons' ); ?>
		</p>

		<table class="form-table oa-form-table" role="presentation">

			<?php Octave_Addons_Fields::section( [ 'label' => __( 'Phone', 'octave-addons' ), 'first' => true ] ); ?>
			<?php Octave_Addons_Fields::row( [
				'label' => __( 'Show phone', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'id'      => $this->field_id( 'show_phone' ),
						'name'    => $this->field_name( 'show_phone' ),
						'checked' => ! empty( $s['show_phone'] ),
						'data'    => [ 'controls-row' => 'oaMcpRowPhone,oaMcpRowPhoneIcon' ],
					] );

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'id'    => 'oaMcpRowPhone',
				'for'   => $this->field_id( 'phone' ),
				'label' => __( 'Phone number', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::text( [
						'id'    => $this->field_id( 'phone' ),
						'name'  => $this->field_name( 'phone' ),
						'value' => $s['phone'],
						'help'  => __( 'Rendered as a tappable tel: link.', 'octave-addons' ),
					] );

				},
			] ); ?>
			<?php $this->render_icon_row( 'phone_icon', 'phone', $s, 'oaMcpRowPhoneIcon' ); ?>
			<?php Octave_Addons_Fields::section( [ 'label' => __( 'Email', 'octave-addons' ) ] ); ?>
			<?php Octave_Addons_Fields::row( [
				'label' => __( 'Show email', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'id'      => $this->field_id( 'show_email' ),
						'name'    => $this->field_name( 'show_email' ),
						'checked' => ! empty( $s['show_email'] ),
						'data'    => [ 'controls-row' => 'oaMcpRowEmail,oaMcpRowEmailIcon' ],
					] );

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'id'    => 'oaMcpRowEmail',
				'for'   => $this->field_id( 'email' ),
				'label' => __( 'Email address', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::email( [
						'id'    => $this->field_id( 'email' ),
						'name'  => $this->field_name( 'email' ),
						'value' => $s['email'],
						'help'  => __( 'Rendered as a tappable mailto: link.', 'octave-addons' ),
					] );

				},
			] ); ?>
			<?php $this->render_icon_row( 'email_icon', 'email', $s, 'oaMcpRowEmailIcon' ); ?>
			<?php Octave_Addons_Fields::section( [ 'label' => __( 'Address', 'octave-addons' ) ] ); ?>
			<?php Octave_Addons_Fields::row( [
				'label' => __( 'Show address', 'octave-addons' ),
				'field' => function () use ( $s ) {
					Octave_Addons_Fields::switch_field( [
						'id'      => $this->field_id( 'show_address' ),
						'name'    => $this->field_name( 'show_address' ),
						'checked' => ! empty( $s['show_address'] ),
						'data'    => [ 'controls-row' => 'oaMcpRowAddress,oaMcpRowAddressIcon' ],
					] );

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'id'    => 'oaMcpRowAddress',
				'for'   => $this->field_id( 'address' ),
				'label' => __( 'Address', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::textarea( [
						'id'    => $this->field_id( 'address' ),
						'name'  => $this->field_name( 'address' ),
						'value' => $s['address'],
						'help'  => __( 'Plain text — line breaks are preserved.', 'octave-addons' ),
					] );

				},
			] ); ?>
			<?php $this->render_icon_row( 'address_icon', 'address', $s, 'oaMcpRowAddressIcon' ); ?>
			<?php Octave_Addons_Fields::section( [ 'label' => __( 'Trigger Button', 'octave-addons' ) ] ); ?>
			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'trigger_label' ),
				'label' => __( 'Label', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::text( [
						'id'    => $this->field_id( 'trigger_label' ),
						'name'  => $this->field_name( 'trigger_label' ),
						'value' => $s['trigger_label'],
						'help'  => __( 'Text shown on the sticky bar button.', 'octave-addons' ),
					] );

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'trigger_bg' ),
				'label' => __( 'Background colour', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::color( [
						'id'    => $this->field_id( 'trigger_bg' ),
						'name'  => $this->field_name( 'trigger_bg' ),
						'value' => $s['trigger_bg'],
					] );

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'trigger_color' ),
				'label' => __( 'Text &amp; icon colour', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::color( [
						'id'    => $this->field_id( 'trigger_color' ),
						'name'  => $this->field_name( 'trigger_color' ),
						'value' => $s['trigger_color'],
					] );

				},
			] ); ?>
			<?php $this->render_icon_row( 'trigger_icon', 'trigger', $s, 'oaMcpRowTriggerIcon' ); ?>
			<?php Octave_Addons_Fields::section( [ 'label' => __( 'Call Button', 'octave-addons' ) ] ); ?>
			<?php Octave_Addons_Fields::row( [
				'label' => __( 'Show call button', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'id'      => $this->field_id( 'show_call_btn' ),
						'name'    => $this->field_name( 'show_call_btn' ),
						'checked' => ! empty( $s['show_call_btn'] ),
						'data'    => [ 'controls-row' => 'oaMcpRowCall' ],
						'help'    => __( 'Adds a direct Call button beside the trigger. Requires Phone to be enabled above.', 'octave-addons' ),
					] );

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'id'    => 'oaMcpRowCall',
				'for'   => $this->field_id( 'call_label' ),
				'label' => __( 'Label', 'octave-addons' ),
				'field' => function () use ( $s ) {
					Octave_Addons_Fields::text( [
						'id'    => $this->field_id( 'call_label' ),
						'name'  => $this->field_name( 'call_label' ),
						'value' => $s['call_label'],
					] );

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'call_bg' ),
				'label' => __( 'Background colour', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::color( [
						'id'    => $this->field_id( 'call_bg' ),
						'name'  => $this->field_name( 'call_bg' ),
						'value' => $s['call_bg'],
					] );

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'call_color' ),
				'label' => __( 'Text &amp; icon colour', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::color( [
						'id'    => $this->field_id( 'call_color' ),
						'name'  => $this->field_name( 'call_color' ),
						'value' => $s['call_color'],
					] );

				},
			] ); ?>
			<?php $this->render_icon_row( 'call_icon', 'call', $s, 'oaMcpRowCallIcon' ); ?>
		</table>
		<?php

	}

	public function run( array $s ): void {

		$has_phone   = ! empty( $s['show_phone'] )   && ! empty( $s['phone'] );
		$has_email   = ! empty( $s['show_email'] )   && ! empty( $s['email'] );
		$has_address = ! empty( $s['show_address'] ) && ! empty( $s['address'] );

		if ( ! $has_phone && ! $has_email && ! $has_address ) {

			return;

		}

		add_action( 'wp_enqueue_scripts', function () {
			$this->enqueue_assets();
		} );

		// Priority 5 — must run before wp_print_footer_scripts (priority 20)
		// so the HTML elements exist in the DOM when the inline script runs.
		add_action( 'wp_footer', function () use ( $s ) {
			$this->render_popup( $s );
		}, 5 );

	}

	protected function enqueue_assets(): void {

		$base_dir = OCTAVE_ADDONS_DIR . 'modules/mobile-contact-popup/assets/';
		$base_url = OCTAVE_ADDONS_URL . 'modules/mobile-contact-popup/assets/';

		$css_path = $base_dir . 'mobile-contact-popup.css';
		$js_path  = $base_dir . 'mobile-contact-popup.js';

		wp_enqueue_style(
			'octave-mobile-contact-popup',
			$base_url . 'mobile-contact-popup.css',
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : OCTAVE_ADDONS_VERSION
		);

		wp_enqueue_script(
			'octave-mobile-contact-popup',
			$base_url . 'mobile-contact-popup.js',
			[],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : OCTAVE_ADDONS_VERSION,
			true
		);

	}

	protected function render_popup( array $s ): void {

		$has_phone   = ! empty( $s['show_phone'] )   && ! empty( $s['phone'] );
		$has_email   = ! empty( $s['show_email'] )   && ! empty( $s['email'] );
		$has_address = ! empty( $s['show_address'] ) && ! empty( $s['address'] );

		$phone   = $s['phone']   ?? '';
		$email   = $s['email']   ?? '';
		$address = $s['address'] ?? '';

		$phone_href = preg_replace( '/[^+0-9]/', '', $phone );

		$trigger_label = ! empty( $s['trigger_label'] ) ? $s['trigger_label'] : __( 'Contact Us', 'octave-addons' );
		$trigger_bg    = $s['trigger_bg']    ?? '#111111';
		$trigger_color = $s['trigger_color'] ?? '#ffffff';

		$popup_label = ! empty( $s['popup_label'] ) ? $s['popup_label'] : __( 'Contact Us', 'octave-addons' );

		$show_call_btn = ! empty( $s['show_call_btn'] ) && $has_phone;
		$call_label    = ! empty( $s['call_label'] ) ? $s['call_label'] : __( 'Call Now', 'octave-addons' );
		$call_bg       = $s['call_bg']    ?? '#16a34a';
		$call_color    = $s['call_color'] ?? '#ffffff';

		$phone_icon   = ! empty( $s['phone_icon'] )   ? $s['phone_icon']   : self::default_svg( 'phone' );
		$call_icon    = ! empty( $s['call_icon'] )   ? $s['call_icon']   : ( ! empty( $s['phone_icon'] ) ? $s['phone_icon'] : self::default_svg( 'phone' ) );
		$trigger_icon = ! empty( $s['trigger_icon'] )   ? $s['trigger_icon']   :  self::default_svg( 'phone' );
		$email_icon   = ! empty( $s['email_icon'] )   ? $s['email_icon']   : self::default_svg( 'email' );
		$address_icon = ! empty( $s['address_icon'] ) ? $s['address_icon'] : self::default_svg( 'address' );

		?>

		<div id="oaContactPopupWrap" aria-hidden="true" class="breakdance">

			<div id="oaContactPopupOverlay"></div>

			<div id="oaContactPopup" role="dialog" aria-modal="true"
			     aria-label="<?= esc_html( $popup_label ); ?>">
				<div class="oa-contact-popup-header">
					<span class="oa-contact-popup-title"><?= esc_html( $popup_label ); ?></span>
					<button class="oa-contact-popup-close" type="button"
					        aria-label="<?php esc_attr_e( 'Close contact popup', 'octave-addons' ); ?>">
						<?php Octave_Addons_Icons::render( 'close', 18 ); ?>
					</button>
				</div>

				<div class="oa-contact-popup-body">

					<?php

					if ( $has_phone ) :

					?>

					<a href="tel:<?= esc_attr( $phone_href ); ?>"
					   class="oa-contact-item oa-contact-phone">
						<span class="oa-contact-icon" aria-hidden="true">
							<?php echo $phone_icon; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</span>
						<p class="oa-contact-text bde-text"><?= esc_html( $phone ); ?></p>
					</a>
					<?php

					endif;

					?>

					<?php

					if ( $has_email ) :

					?>

					<a href="mailto:<?= esc_attr( $email ); ?>"
					   class="oa-contact-item oa-contact-email">
						<span class="oa-contact-icon" aria-hidden="true">
							<?php echo $email_icon; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</span>
						<p class="oa-contact-text bde-text"><?= esc_html( $email ); ?></p>
					</a>
					<?php

					endif;

					?>

					<?php

					if ( $has_address ) :

					?>

					<a href="https://maps.google.com/?q=<?= rawurlencode( $address ); ?>"
					   class="oa-contact-item oa-contact-address"
					   target="_blank" rel="noopener noreferrer">
						<span class="oa-contact-icon" aria-hidden="true">
							<?php echo $address_icon; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</span>
						<p class="oa-contact-text bde-text"><?= nl2br( esc_html( $address ) ); ?></p>
					</a>
					<?php

					endif;

					?>

				</div>

			</div><!-- #oa-contact-popup -->

		</div><!-- #oa-contact-popup-wrap -->

		<div id="oaContactPopupBar">

			<?php

			if ( $show_call_btn ) :

			?>

			<a id="oaContactCallBtn" href="tel:<?= esc_attr( $phone_href ); ?>"
			   style="background-color:<?= esc_attr( $call_bg ); ?>;color:<?= esc_attr( $call_color ); ?>;">
				<?= $call_icon; ?>
				<span><?= esc_html( $call_label ); ?></span>
			</a>
			<?php

			endif;

			?>

			<button id="oaContactPopupTrigger" type="button"
			        aria-controls="oaContactPopup" aria-expanded="false"
			        style="background-color:<?= esc_attr( $trigger_bg ); ?>;color:<?= esc_attr( $trigger_color ); ?>;">
				<?= $trigger_icon; ?>
				<span><?= esc_html( $trigger_label ); ?></span>
			</button>

		</div><!-- #oa-contact-popup-bar -->
		<?php

	}

	// ---- Private helpers ------------------------------------------------

	private static function default_svg( string $type ): string {

		$icons = [
			'phone'   => 'phone',
			'call'    => 'phone',
			'email'   => 'mail',
			'address' => 'map-pin',
			'trigger' => 'message',
		];

		if ( ! isset( $icons[ $type ] ) ) {

			return '';

		}

		return Octave_Addons_Icons::get( $icons[ $type ], 20 );

	}

	private static function sanitize_svg( string $raw ): string {

		if ( '' === trim( $raw ) ) {
			return '';

		}
		$allowed = [
			'svg'      => [ 'xmlns' => [], 'viewbox' => [], 'width' => [], 'height' => [], 'fill' => [], 'stroke' => [], 'stroke-width' => [], 'stroke-linecap' => [], 'stroke-linejoin' => [], 'aria-hidden' => [], 'id' => [], 'class' => [], 'style' => [] ],
			'path'     => [ 'd' => [], 'fill' => [], 'stroke' => [], 'stroke-width' => [], 'stroke-linecap' => [], 'stroke-linejoin' => [] ],
			'circle'   => [ 'cx' => [], 'cy' => [], 'r' => [], 'fill' => [], 'stroke' => [], 'stroke-width' => [] ],
			'line'     => [ 'x1' => [], 'x2' => [], 'y1' => [], 'y2' => [], 'stroke' => [], 'stroke-width' => [], 'stroke-linecap' => [] ],
			'polyline' => [ 'points' => [], 'fill' => [], 'stroke' => [], 'stroke-width' => [], 'stroke-linecap' => [], 'stroke-linejoin' => [] ],
			'polygon'  => [ 'points' => [], 'fill' => [], 'stroke' => [] ],
			'rect'     => [ 'x' => [], 'y' => [], 'width' => [], 'height' => [], 'rx' => [], 'ry' => [], 'fill' => [], 'stroke' => [] ],
			'g'        => [ 'fill' => [], 'stroke' => [], 'id' => [], 'class' => [], 'transform' => [] ],
			'defs'     => [],
			'use'      => [ 'href' => [], 'width' => [], 'height' => [] ],
			'title'    => [],
		];
		return wp_kses( wp_unslash( $raw ), $allowed );

	}

	private function render_icon_row( string $key, string $type, array $s, string $row_id, string $preview_style = '' ): void {

		$has_bd      = function_exists( 'Breakdance\Icons\find_icons' );
		$stored      = $s[ $key ] ?? '';
		$field_id    = $this->field_id( $key );
		$field_name  = $this->field_name( $key );
		$default_svg = self::default_svg( $type );
		$preview     = $stored ?: $default_svg;

		Octave_Addons_Fields::row( [
			'id'    => $row_id,
			'label' => __( 'Icon', 'octave-addons' ),
			'field' => function () use ( $has_bd, $stored, $preview, $field_id, $field_name, $preview_style ) {

				?>

				<div class="oa-icon-picker-wrap">
					<div class="oa-icon-preview<?= $stored ? '' : ' is-default'; ?>"<?= $preview_style ? ' style="' . esc_attr( $preview_style ) . '"' : ''; ?> aria-hidden="true">
						<?php echo $preview; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
					<div class="oa-icon-picker-controls">
						<?php

						if ( $has_bd ) :

						?>

						<button type="button" class="button oa-icon-pick-btn"
						        data-target="<?= esc_attr( $field_id ); ?>">
							<?php esc_html_e( 'Choose Icon', 'octave-addons' ); ?>
						</button>
						<?php

						if ( $stored ) :

						?>

						<button type="button" class="button oa-icon-clear-btn"
						        data-target="<?= esc_attr( $field_id ); ?>">
							<?php esc_html_e( 'Use Default', 'octave-addons' ); ?>
						</button>
						<?php

						endif;

						?>

						<?php

						else :

						?>

						<span class="oa-help oa-help--inline">
							<?php esc_html_e( 'Icon picker requires Breakdance.', 'octave-addons' ); ?>
						</span>
						<?php

						endif;

						?>

					</div>
					<input type="hidden"
					       id="<?= esc_attr( $field_id ); ?>"
					       name="<?= esc_attr( $field_name ); ?>"
					       value="<?= esc_attr( $stored ); ?>">
				</div>
				<?php

			},
		] );

	}

}

return new Octave_Addons_Module_Mobile_Contact_Popup();
