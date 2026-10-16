<?php

/*
BREAKDANCE FORMS
-- Adds an optional shared message and one forced captcha provider to every
-- native Breakdance Form Builder form, immediately above its submit button
-- Captcha responses are checked before Breakdance runs any form actions
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Breakdance_Forms extends Octave_Addons_Module {

	protected const SCRIPT_HANDLE = 'octave-addons-breakdance-forms';
	protected const GOOGLE_ACTION = 'octave_breakdance_submit';
	protected const GOOGLE_VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';
	protected const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	protected static array $runtime_settings = [];

	/*
	GET ID
	-- Returns the module settings key
	---------------------------------------------------------- */

	public function get_id(): string {

		return 'breakdance-forms';

	}

	/*
	GET TITLE
	-- Names the panel inside the shared Breakdance settings area
	---------------------------------------------------------- */

	public function get_title(): string {

		return __( 'Forms', 'octave-addons' );

	}

	/*
	GET DESCRIPTION
	-- Describes the global additions made to Breakdance forms
	---------------------------------------------------------- */

	public function get_description(): string {

		return __( 'Adds a shared message and forces Google reCAPTCHA or Cloudflare Turnstile on every Breakdance form.', 'octave-addons' );

	}

	/*
	GET DEFAULTS
	-- Captcha and message output are both opt-in
	---------------------------------------------------------- */

	public function get_defaults(): array {

		return [
			'enabled'              => false,
			'captcha_provider'     => '',
			'google_site_key'      => '',
			'google_secret_key'    => '',
			'turnstile_site_key'   => '',
			'turnstile_secret_key' => '',
			'message_enabled'      => false,
			'message_html'         => '',
		];

	}

	/*
	SANITIZE
	-- Restricts the provider to one choice and permits safe post-style HTML
	---------------------------------------------------------- */

	public function sanitize( $input ): array {

		$input    = is_array( $input ) ? $input : [];
		$provider = sanitize_key( (string) ( $input['captcha_provider'] ?? '' ) );

		if ( ! in_array( $provider, [ '', 'google', 'turnstile' ], true ) ) {

			$provider = '';

		}

		return [
			'enabled'              => ! empty( $input['enabled'] ),
			'captcha_provider'     => $provider,
			'google_site_key'      => sanitize_text_field( wp_unslash( (string) ( $input['google_site_key'] ?? '' ) ) ),
			'google_secret_key'    => sanitize_text_field( wp_unslash( (string) ( $input['google_secret_key'] ?? '' ) ) ),
			'turnstile_site_key'   => sanitize_text_field( wp_unslash( (string) ( $input['turnstile_site_key'] ?? '' ) ) ),
			'turnstile_secret_key' => sanitize_text_field( wp_unslash( (string) ( $input['turnstile_secret_key'] ?? '' ) ) ),
			'message_enabled'      => ! empty( $input['message_enabled'] ),
			'message_html'         => wp_kses_post( wp_unslash( (string) ( $input['message_html'] ?? '' ) ) ),
		];

	}

	/*
	RENDER SETTINGS
	-- Uses one provider selector so Google and Turnstile cannot both be active
	---------------------------------------------------------- */

	public function render_settings( array $s ): void {

		?>

		<table class="form-table oa-form-table" role="presentation">

			<?php

			Octave_Addons_Fields::section(
				[
					'label' => __( 'Form captcha', 'octave-addons' ),
					'first' => true,
				]
			);

			Octave_Addons_Fields::row(
				[
					'for'   => $this->field_id( 'captcha_provider' ),
					'label' => __( 'Force form captcha', 'octave-addons' ),
					'field' => function () use ( $s ) {

						?>

						<select
							id="<?= esc_attr( $this->field_id( 'captcha_provider' ) ); ?>"
							name="<?= esc_attr( $this->field_name( 'captcha_provider' ) ); ?>"
							data-controls-row="oaFormsGoogleSite:google,oaFormsGoogleSecret:google,oaFormsTurnstileSite:turnstile,oaFormsTurnstileSecret:turnstile"
						>
							<option value=""<?php selected( '', $s['captcha_provider'] ); ?>><?php esc_html_e( 'Off', 'octave-addons' ); ?></option>
							<option value="google"<?php selected( 'google', $s['captcha_provider'] ); ?>><?php esc_html_e( 'Google reCAPTCHA', 'octave-addons' ); ?></option>
							<option value="turnstile"<?php selected( 'turnstile', $s['captcha_provider'] ); ?>><?php esc_html_e( 'Cloudflare Turnstile', 'octave-addons' ); ?></option>
						</select>
						<span class="oa-help"><?php esc_html_e( 'Applies one captcha provider to every native Breakdance Form Builder form.', 'octave-addons' ); ?></span>

						<?php

					},
				]
			);

			$this->render_key_row( $s, 'oaFormsGoogleSite', 'google_site_key', __( 'Google site key', 'octave-addons' ), __( 'Optional. Leave blank to use the site key saved in Breakdance.', 'octave-addons' ) );
			$this->render_key_row( $s, 'oaFormsGoogleSecret', 'google_secret_key', __( 'Google secret key', 'octave-addons' ), __( 'Optional. Leave blank to use the secret key saved in Breakdance.', 'octave-addons' ), true );
			$this->render_key_row( $s, 'oaFormsTurnstileSite', 'turnstile_site_key', __( 'Turnstile site key', 'octave-addons' ), __( 'The public site key from your Cloudflare Turnstile widget.', 'octave-addons' ), false, true );
			$this->render_key_row( $s, 'oaFormsTurnstileSecret', 'turnstile_secret_key', __( 'Turnstile secret key', 'octave-addons' ), __( 'The private secret key from your Cloudflare Turnstile widget.', 'octave-addons' ), true, true );

			Octave_Addons_Fields::section( [ 'label' => __( 'Form message', 'octave-addons' ) ] );

			Octave_Addons_Fields::row(
				[
					'label' => __( 'Add message in all forms', 'octave-addons' ),
					'field' => function () use ( $s ) {

						Octave_Addons_Fields::switch_field(
							[
								'name'    => $this->field_name( 'message_enabled' ),
								'checked' => ! empty( $s['message_enabled'] ),
								'data'    => [ 'controls-row' => 'oaFormsMessageHtml' ],
								'help'    => __( 'Adds the same content above the submit button in every Breakdance form.', 'octave-addons' ),
							]
						);

					},
				]
			);

			Octave_Addons_Fields::row(
				[
					'id'    => 'oaFormsMessageHtml',
					'for'   => $this->field_id( 'message_html' ),
					'label' => __( 'Message HTML', 'octave-addons' ),
					'field' => function () use ( $s ) {

						Octave_Addons_Fields::textarea(
							[
								'id'         => $this->field_id( 'message_html' ),
								'name'       => $this->field_name( 'message_html' ),
								'value'      => $s['message_html'],
								'rows'       => 5,
								'class'      => 'large-text code',
								'spellcheck' => false,
								'help'       => __( 'Safe HTML is allowed, for example linked terms and privacy wording.', 'octave-addons' ),
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
	RENDER KEY ROW
	-- Outputs one provider key field with the standard conditional-row markup
	---------------------------------------------------------- */

	protected function render_key_row( array $s, string $row_id, string $key, string $label, string $help, bool $secret = false, bool $required = false ): void {

		Octave_Addons_Fields::row(
			[
				'id'    => $row_id,
				'for'   => $this->field_id( $key ),
				'label' => $label,
				'field' => function () use ( $s, $key, $help, $secret, $required ) {

					?>

					<input
						type="<?= $secret ? 'password' : 'text'; ?>"
						id="<?= esc_attr( $this->field_id( $key ) ); ?>"
						name="<?= esc_attr( $this->field_name( $key ) ); ?>"
						value="<?= esc_attr( $s[ $key ] ); ?>"
						class="regular-text"
						autocomplete="off"
						<?= $required ? 'required' : ''; ?>
					>
					<span class="oa-help"><?= esc_html( $help ); ?></span>

					<?php

				},
			]
		);

	}

	/*
	RUN
	-- Registers form markup, frontend assets and early AJAX validation
	---------------------------------------------------------- */

	public function run( array $s ): void {

		self::$runtime_settings = $this->get_settings( $s );

		add_action( 'breakdance_form_before_footer', [ __CLASS__, 'render_before_footer' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'wp_ajax_breakdance_form_custom', [ __CLASS__, 'validate_ajax_captcha' ], 0 );
		add_action( 'wp_ajax_nopriv_breakdance_form_custom', [ __CLASS__, 'validate_ajax_captcha' ], 0 );

	}

	/*
	RENDER BEFORE FOOTER
	-- Prints the message first and the selected captcha last above the button
	---------------------------------------------------------- */

	public static function render_before_footer(): void {

		$s        = self::$runtime_settings;
		$provider = $s['captcha_provider'] ?? '';

		if ( ! empty( $s['message_enabled'] ) && '' !== trim( (string) ( $s['message_html'] ?? '' ) ) ) {

			?>

			<div class="breakdance-form-field oa-breakdance-form-message"><?= wp_kses_post( $s['message_html'] ); ?></div>

			<?php

		}

		if ( ! in_array( $provider, [ 'google', 'turnstile' ], true ) ) {

			return;

		}

		?>

		<div class="breakdance-form-field oa-breakdance-form-captcha" data-oa-captcha="<?= esc_attr( $provider ); ?>">
			<div class="oa-breakdance-form-captcha__widget" aria-live="polite"></div>
			<p class="oa-breakdance-form-captcha__error" role="alert" hidden></p>
		</div>

		<?php

	}

	/*
	ENQUEUE ASSETS
	-- Loads only the selected provider and supplies no secret key to JavaScript
	---------------------------------------------------------- */

	public static function enqueue_assets(): void {

		$provider = self::$runtime_settings['captcha_provider'] ?? '';
		$site_key = self::site_key( $provider );

		if ( ! in_array( $provider, [ 'google', 'turnstile' ], true ) || '' === $site_key ) {

			return;

		}

		if ( 'google' === $provider ) {

			$provider_handle = 'octave-addons-google-recaptcha';
			$provider_url    = add_query_arg( [ 'render' => $site_key ], 'https://www.google.com/recaptcha/api.js' );

		} else {

			$provider_handle = 'octave-addons-cloudflare-turnstile';
			$provider_url    = add_query_arg( [ 'render' => 'explicit' ], 'https://challenges.cloudflare.com/turnstile/v0/api.js' );

		}

		$asset = self::asset( 'modules/breakdance/forms/assets/forms.js' );

		wp_enqueue_script( $provider_handle, $provider_url, [], null, true );
		wp_enqueue_script( self::SCRIPT_HANDLE, $asset['url'], [ $provider_handle ], $asset['version'], true );
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'OctaveBreakdanceForms',
			[
				'provider'     => $provider,
				'siteKey'      => $site_key,
				'googleAction' => self::GOOGLE_ACTION,
				'errorMessage' => __( 'Please complete the captcha check and try again.', 'octave-addons' ),
			]
		);

	}

	/*
	VALIDATE AJAX CAPTCHA
	-- Stops the native Breakdance handler before actions run when verification fails
	---------------------------------------------------------- */

	public static function validate_ajax_captcha(): void {

		$provider = self::$runtime_settings['captcha_provider'] ?? '';

		if ( ! in_array( $provider, [ 'google', 'turnstile' ], true ) ) {

			return;

		}

		$field = 'google' === $provider ? 'oa_captcha_token' : 'cf-turnstile-response';
		$token = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) ) : '';
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( '' === $token || ! self::verify_captcha_response( $provider, $token, $ip ) ) {

			wp_send_json_error(
				[ 'message' => __( 'Please complete the captcha check and try again.', 'octave-addons' ) ],
				400
			);

		}

	}

	/*
	VERIFY CAPTCHA RESPONSE
	-- Sends the browser token to the selected provider and checks its response
	---------------------------------------------------------- */

	public static function verify_captcha_response( string $provider, string $token, string $ip = '' ): bool {

		if ( ! in_array( $provider, [ 'google', 'turnstile' ], true ) ) {

			return false;

		}

		$secret = self::secret_key( $provider );

		if ( '' === $secret || '' === $token ) {

			return false;

		}

		$url  = 'google' === $provider ? self::GOOGLE_VERIFY_URL : self::TURNSTILE_VERIFY_URL;
		$body = [
			'secret'   => $secret,
			'response' => $token,
			'remoteip' => $ip,
		];

		$response = wp_remote_post( $url, [ 'body' => $body, 'timeout' => 10 ] );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {

			return false;

		}

		$result = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $result ) || empty( $result['success'] ) ) {

			return false;

		}

		if ( 'google' === $provider ) {

			$score  = isset( $result['score'] ) ? (float) $result['score'] : 0.0;
			$action = (string) ( $result['action'] ?? '' );

			return $score >= 0.5 && self::GOOGLE_ACTION === $action;

		}

		return 'turnstile' === $provider;

	}

	/*
	SITE KEY
	-- Uses module overrides first, then Breakdance's saved Google key
	---------------------------------------------------------- */

	protected static function site_key( string $provider ): string {

		if ( 'turnstile' === $provider ) {

			return (string) ( self::$runtime_settings['turnstile_site_key'] ?? '' );

		}

		$key = (string) ( self::$runtime_settings['google_site_key'] ?? '' );

		if ( '' === $key && function_exists( 'Breakdance\\APIKeys\\getKey' ) && defined( 'BREAKDANCE_RECAPTCHA_SITE_KEY_NAME' ) ) {

			$key = (string) \Breakdance\APIKeys\getKey( BREAKDANCE_RECAPTCHA_SITE_KEY_NAME );

		}

		return $key;

	}

	/*
	SECRET KEY
	-- Keeps provider secrets on the server and supports Breakdance's Google key
	---------------------------------------------------------- */

	protected static function secret_key( string $provider ): string {

		if ( 'turnstile' === $provider ) {

			return (string) ( self::$runtime_settings['turnstile_secret_key'] ?? '' );

		}

		$key = (string) ( self::$runtime_settings['google_secret_key'] ?? '' );

		if ( '' === $key && function_exists( 'Breakdance\\APIKeys\\getKey' ) && defined( 'BREAKDANCE_RECAPTCHA_SECRET_KEY_NAME' ) ) {

			$key = (string) \Breakdance\APIKeys\getKey( BREAKDANCE_RECAPTCHA_SECRET_KEY_NAME );

		}

		return $key;

	}

}

return new Octave_Addons_Module_Breakdance_Forms();
