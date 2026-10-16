<?php

/*
MODULE: CUSTOM LOGIN URL
-- Moves wp-login.php to a configurable URL slug. Direct requests to
-- /wp-login.php or /wp-login are redirected to a configurable destination.
-- Login/logout links are rewritten automatically. The login page is also
-- restyled with modern CSS; colours, logo and custom CSS are all configurable.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

    exit;

}

class Octave_Addons_Module_Custom_Login extends Octave_Addons_Module {

	protected const SCRIPT_HANDLE = 'octave-addons-custom-login';
	protected const GOOGLE_ACTION = 'octave_login';
	protected const GOOGLE_VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';
	protected const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	protected static array $runtime_settings = [];

    public function get_id(): string {

        return 'custom-login';

    }

    public function get_title(): string {

        return __( 'Custom Login URL', 'octave-addons' );

    }

    public function get_description(): string {

        return __( 'Moves your login page to an address only you know, blocks the usual WordPress login address, and lets you add your logo and colours.', 'octave-addons' );

    }

    public function get_defaults(): array {

        return [
            'enabled'         => false,
            'login_slug'      => 'login',
            'redirect_url'    => '',
            'custom_logo_url' => '',
            'bg_color'        => '',
			'primary_color'     => '',
			'captcha_mode'      => '',
			'captcha_site_key'  => '',
			'captcha_secret_key' => '',
			'custom_css'        => '',
        ];

    }

    public function sanitize( $input ): array {

		$input = is_array( $input ) ? $input : [];

        $clean            = $this->get_defaults();
        $clean['enabled'] = ! empty( $input['enabled'] );

        if ( ! empty( $input['login_slug'] ) ) {

            $slug                = trim( sanitize_title_with_dashes( wp_unslash( $input['login_slug'] ) ), '-' );
            $clean['login_slug'] = $slug ?: 'login';

        }

        $clean['redirect_url']    = isset( $input['redirect_url'] )
            ? esc_url_raw( wp_unslash( $input['redirect_url'] ) )
            : '';
        $clean['custom_logo_url'] = isset( $input['custom_logo_url'] )
            ? esc_url_raw( wp_unslash( $input['custom_logo_url'] ) )
            : '';
        $clean['bg_color']        = isset( $input['bg_color'] )
            ? ( sanitize_hex_color( wp_unslash( $input['bg_color'] ) ) ?? '' )
            : '';
        $clean['primary_color']   = isset( $input['primary_color'] )
            ? ( sanitize_hex_color( wp_unslash( $input['primary_color'] ) ) ?? '' )
            : '';

		$captcha_mode = sanitize_key( (string) ( $input['captcha_mode'] ?? '' ) );

		if ( ! in_array( $captcha_mode, [ '', 'forms-google', 'custom-google', 'forms-turnstile', 'custom-turnstile' ], true ) ) {

			$captcha_mode = '';

		}

		$clean['captcha_mode']       = $captcha_mode;
		$clean['captcha_site_key']   = sanitize_text_field( wp_unslash( (string) ( $input['captcha_site_key'] ?? '' ) ) );
		$clean['captcha_secret_key'] = sanitize_text_field( wp_unslash( (string) ( $input['captcha_secret_key'] ?? '' ) ) );

        // Raw CSS — only manage_options users can reach this field.
        $clean['custom_css'] = isset( $input['custom_css'] )
            ? (string) wp_unslash( $input['custom_css'] )
            : '';

        return $clean;

    }

    // ---- Admin UI ----------------------------------------------------------

    public function render_settings( array $s ): void {

        $slug      = ! empty( $s['login_slug'] ) ? $s['login_slug'] : 'login';
        $home      = trailingslashit( home_url() );
        $login_url = $home . $slug . '/';

        $bg_color      = $s['bg_color']      ?: '#f0f2f5';
        $primary_color = $s['primary_color'] ?: '#4f8ef7';
        ?>

        <table class="form-table oa-form-table" role="presentation">

            <?php Octave_Addons_Fields::section( [
                'label' => __( 'URL Settings', 'octave-addons' ),
                'first' => true,
            ] ); ?>
            <?php Octave_Addons_Fields::row( [
                'for'   => $this->field_id( 'login_slug' ),
                'label' => __( 'Login URL slug', 'octave-addons' ),
                'field' => function () use ( $slug, $home, $login_url ) {

                    Octave_Addons_Fields::url_prefix( [
                        'id'        => $this->field_id( 'login_slug' ),
                        'name'      => $this->field_name( 'login_slug' ),
                        'value'     => $slug,
                        'prefix'    => $home,
                        'help_html' => sprintf(
                            /* translators: %s: full login URL */
                            esc_html__( 'Your login page: %s', 'octave-addons' ),
                            '<a href="' . esc_url( $login_url ) . '" target="_blank" rel="noopener">' . esc_html( $login_url ) . '</a>'
                        ),
                    ] );

                },
            ] ); ?>
            <?php Octave_Addons_Fields::row( [
                'for'   => $this->field_id( 'redirect_url' ),
                'label' => __( 'Redirect URL', 'octave-addons' ),
                'field' => function () use ( $s ) {

                    Octave_Addons_Fields::url( [
                        'id'          => $this->field_id( 'redirect_url' ),
                        'name'        => $this->field_name( 'redirect_url' ),
                        'value'       => $s['redirect_url'],
                        'placeholder' => home_url( '/' ),
                        'help'        => __( 'Where to send anyone who tries the usual WordPress login address. Leave blank to send them to your home page.', 'octave-addons' ),
                    ] );

                },
            ] ); ?>
			<?php Octave_Addons_Fields::section( [
				'label' => __( 'Login Protection', 'octave-addons' ),
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'captcha_mode' ),
				'label' => __( 'Captcha', 'octave-addons' ),
				'field' => function () use ( $s ) {

					?>

					<select
						id="<?= esc_attr( $this->field_id( 'captcha_mode' ) ); ?>"
						name="<?= esc_attr( $this->field_name( 'captcha_mode' ) ); ?>"
						data-controls-row="oaLoginCaptchaSite:custom-google|custom-turnstile,oaLoginCaptchaSecret:custom-google|custom-turnstile"
					>
						<option value=""<?php selected( '', $s['captcha_mode'] ); ?>><?php esc_html_e( 'Off', 'octave-addons' ); ?></option>
						<option value="forms-google"<?php selected( 'forms-google', $s['captcha_mode'] ); ?>><?php esc_html_e( 'Google reCAPTCHA — use Forms keys', 'octave-addons' ); ?></option>
						<option value="custom-google"<?php selected( 'custom-google', $s['captcha_mode'] ); ?>><?php esc_html_e( 'Google reCAPTCHA — custom keys', 'octave-addons' ); ?></option>
						<option value="forms-turnstile"<?php selected( 'forms-turnstile', $s['captcha_mode'] ); ?>><?php esc_html_e( 'Cloudflare Turnstile — use Forms keys', 'octave-addons' ); ?></option>
						<option value="custom-turnstile"<?php selected( 'custom-turnstile', $s['captcha_mode'] ); ?>><?php esc_html_e( 'Cloudflare Turnstile — custom keys', 'octave-addons' ); ?></option>
					</select>
					<span class="oa-help"><?php esc_html_e( 'Protects password sign-ins. Forms keys come from the Breakdance Forms module; custom keys are kept in this module.', 'octave-addons' ); ?></span>

					<?php

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'id'    => 'oaLoginCaptchaSite',
				'for'   => $this->field_id( 'captcha_site_key' ),
				'label' => __( 'Captcha site key', 'octave-addons' ),
				'field' => function () use ( $s ) {

					?>

					<input type="text" id="<?= esc_attr( $this->field_id( 'captcha_site_key' ) ); ?>" name="<?= esc_attr( $this->field_name( 'captcha_site_key' ) ); ?>" value="<?= esc_attr( $s['captcha_site_key'] ); ?>" class="regular-text" autocomplete="off" required>
					<span class="oa-help"><?php esc_html_e( 'The public site key for the provider selected above.', 'octave-addons' ); ?></span>

					<?php

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'id'    => 'oaLoginCaptchaSecret',
				'for'   => $this->field_id( 'captcha_secret_key' ),
				'label' => __( 'Captcha secret key', 'octave-addons' ),
				'field' => function () use ( $s ) {

					?>

					<input type="password" id="<?= esc_attr( $this->field_id( 'captcha_secret_key' ) ); ?>" name="<?= esc_attr( $this->field_name( 'captcha_secret_key' ) ); ?>" value="<?= esc_attr( $s['captcha_secret_key'] ); ?>" class="regular-text" autocomplete="off" required>
					<span class="oa-help"><?php esc_html_e( 'The private secret stays on the server and is never sent to the browser.', 'octave-addons' ); ?></span>

					<?php

				},
			] ); ?>
            <?php Octave_Addons_Fields::section( [
                'label' => __( 'Login Page Appearance', 'octave-addons' ),
            ] ); ?>
            <?php Octave_Addons_Fields::row( [
                'for'   => $this->field_id( 'custom_logo_url' ) . '-select',
                'label' => __( 'Custom logo', 'octave-addons' ),
                'field' => function () use ( $s ) {

                    Octave_Addons_Fields::media_image( [
                        'id'    => $this->field_id( 'custom_logo_url' ),
                        'name'  => $this->field_name( 'custom_logo_url' ),
                        'value' => $s['custom_logo_url'],
                        'help'  => __( 'Choose your logo from the Media Library.', 'octave-addons' ),
                    ] );

                },
            ] ); ?>
            <?php Octave_Addons_Fields::row( [
                'for'   => $this->field_id( 'bg_color' ),
                'label' => __( 'Background colour', 'octave-addons' ),
                'field' => function () use ( $bg_color ) {

                    Octave_Addons_Fields::color( [
                        'id'    => $this->field_id( 'bg_color' ),
                        'name'  => $this->field_name( 'bg_color' ),
                        'value' => $bg_color,
                        'help'  => __( 'Page background colour behind the login card.', 'octave-addons' ),
                    ] );

                },
            ] ); ?>
            <?php Octave_Addons_Fields::row( [
                'for'   => $this->field_id( 'primary_color' ),
                'label' => __( 'Primary colour', 'octave-addons' ),
                'field' => function () use ( $primary_color ) {

                    Octave_Addons_Fields::color( [
                        'id'    => $this->field_id( 'primary_color' ),
                        'name'  => $this->field_name( 'primary_color' ),
                        'value' => $primary_color,
                        'help'  => __( 'Used for the log in button, links and the outline of the field being typed in.', 'octave-addons' ),
                    ] );

                },
            ] ); ?>
            <?php Octave_Addons_Fields::custom_setup( [ 'oaLoginRowCss' ] ); ?>
            <?php Octave_Addons_Fields::row( [
                'id'    => 'oaLoginRowCss',
                'for'   => $this->field_id( 'custom_css' ),
                'label' => __( 'Custom CSS', 'octave-addons' ),
                'field' => function () use ( $s ) {

                    Octave_Addons_Fields::textarea( [
                        'id'          => $this->field_id( 'custom_css' ),
                        'name'        => $this->field_name( 'custom_css' ),
                        'value'       => $s['custom_css'],
                        'class'       => 'oa-code-editor',
                        'rows'        => 10,
                        'spellcheck'  => false,
                        'placeholder' => '/* Additional login page styles */',
                        'help'        => __( 'For developers. Extra CSS for the login page only.', 'octave-addons' ),
                    ] );

                },
            ] ); ?>
        </table>
        <?php

    }

    // ---- Frontend ----------------------------------------------------------

    public function run( array $s ): void {

		self::$runtime_settings = array_merge( $this->get_defaults(), $s );

        $slug     = trim( $s['login_slug'] ?? 'login', '/' );
        $redirect = $s['redirect_url'] ?? '';

        if ( empty( $slug ) ) {

            return;

        }

        // --- URL filters ------------------------------------------------

        add_filter( 'login_url', function ( $_url, $_redirect_to, $_force_reauth ) use ( $slug ) {

            return home_url( '/' . $slug . '/' );
        }, 10, 3 );

        // Rewrite site_url() calls that reference wp-login.php (logout, etc.).
        add_filter( 'site_url', function ( $url, $path, $_scheme, $_blog_id ) use ( $slug ) {
            if ( 0 === strpos( (string) $path, 'wp-login.php' ) ) {

                $url = str_replace( 'wp-login.php', $slug, $url );

            }
            return $url;
        }, 10, 4 );

        add_filter( 'network_site_url', function ( $url, $path, $_scheme ) use ( $slug ) {

            if ( 0 === strpos( (string) $path, 'wp-login.php' ) ) {

                $url = str_replace( 'wp-login.php', $slug, $url );

            }
            return $url;
        }, 10, 3 );

        // Catch programmatic wp_redirect() calls still targeting wp-login.php.
        add_filter( 'wp_redirect', function ( $location ) use ( $slug ) {

            if ( false !== strpos( $location, 'wp-login.php' ) ) {

                $location = str_replace( 'wp-login.php', $slug, $location );

            }
            return $location;
        } );

        // Point the logo link to the front page instead of wordpress.org.
        add_filter( 'login_headerurl', function () {
            return home_url( '/' );
        } );

		add_action( 'login_form', [ __CLASS__, 'render_captcha' ] );
		add_filter( 'authenticate', [ __CLASS__, 'validate_login_captcha' ], 30, 3 );

        // --- Login page styles ------------------------------------------

        add_action( 'login_enqueue_scripts', function () use ( $s ) {

			$asset = self::asset( 'modules/design/custom-login/assets/login.css' );

			wp_enqueue_style( 'oa-custom-login', $asset['url'], [], $asset['version'] );

            $inline = '';

            // Custom colours — override CSS tokens and recompute RGBA shadows.
            $bg      = ! empty( $s['bg_color'] )      ? $s['bg_color']      : '';
            $primary = ! empty( $s['primary_color'] ) ? $s['primary_color'] : '';

            $token_overrides = '';
            if ( $bg ) {

                $token_overrides .= '--oa-login-bg:' . esc_attr( $bg ) . ';';
                // Switch page-level link colour to white on dark backgrounds.
                if ( $this->is_dark_color( $bg ) ) {
                    $token_overrides .= '--oa-login-page-link:rgba(255,255,255,0.75);';
                    $token_overrides .= '--oa-login-page-link-hover:rgba(255,255,255,1);';

                }

            }
            if ( $primary ) {

                $token_overrides .= '--oa-login-primary:' . esc_attr( $primary ) . ';';
				$token_overrides .= '--oa-login-on-primary:' . ( $this->is_dark_color( $primary ) ? '#ffffff' : '#171916' ) . ';';
                // Recompute the pre-computed shadows with the chosen colour.
                $rgba = $this->hex_to_rgba( $primary );
                if ( $rgba ) {

                    $token_overrides .= '--oa-login-primary-shadow-sm:rgba(' . $rgba . ',0.25);';
                    $token_overrides .= '--oa-login-primary-shadow-lg:rgba(' . $rgba . ',0.40);';
                    $token_overrides .= '--oa-login-primary-focus-ring:rgba(' . $rgba . ',0.20);';

                }

            }
            if ( $token_overrides ) {

                $inline .= ':root{' . $token_overrides . '}';

            }

            // Custom logo replaces the WP logo; otherwise hide it entirely.
            if ( ! empty( $s['custom_logo_url'] ) ) {

                $inline .= '#login h1 a{background-image:url("' . esc_url( $s['custom_logo_url'] ) . '");}';

            } else {

                $inline .= '#login h1{display:none;}';

            }

            if ( $inline ) {

                wp_add_inline_style( 'oa-custom-login', $inline );

            }

            // Custom CSS is output last so it can override anything above.
            if ( ! empty( $s['custom_css'] ) ) {

                wp_add_inline_style( 'oa-custom-login', (string) $s['custom_css'] );

            }

			self::enqueue_captcha_assets();
        } );

        // --- Request interception ---------------------------------------
        // Priority 10 runs after run_enabled() fires at priority 5, so this
        // callback is guaranteed to execute within the same init pass.
        add_action( 'init', function () use ( $slug, $redirect ) {

            global $pagenow;

            $go_to = ! empty( $redirect ) ? $redirect : home_url( '/' );

            // Block direct access to wp-login.php.
            if ( 'wp-login.php' === $pagenow ) {

                wp_safe_redirect( esc_url_raw( $go_to ), 302 );
                exit;

            }

            // Resolve the request path (strips query string and trailing slash).
            $request_path = isset( $_SERVER['REQUEST_URI'] )
                ? (string) parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH )
                : '';
            $request_path = rtrim( $request_path, '/' );
            $home_path    = rtrim( (string) parse_url( home_url(), PHP_URL_PATH ), '/' );

            // Block /wp-login (without .php extension) — same destination.
            $wp_login_bare = $home_path . '/wp-login';
            if ( $request_path === $wp_login_bare ) {

                wp_safe_redirect( esc_url_raw( $go_to ), 302 );
                exit;

            }

            // Redirect non-logged-in visitors who hit /wp-admin to the 404 page.
            // DOING_AJAX is defined before WordPress loads on ajax requests, so
            // this safely skips admin-ajax.php without breaking JS-driven calls.
            $is_ajax        = defined( 'DOING_AJAX' ) && DOING_AJAX;
            $wp_admin_base  = $home_path . '/wp-admin';
            if ( ! $is_ajax && ! is_user_logged_in() && 0 === strpos( $request_path, $wp_admin_base ) ) {

                wp_safe_redirect( home_url( '/404' ), 302 );
                exit;

            }

            // Serve the login page when the custom slug URL is requested.
            $expected = $home_path . '/' . $slug;
            if ( $request_path === $expected ) {

                // wp-load.php uses require_once internally so this is safe to
                // call even though WordPress is already bootstrapped.
                require ABSPATH . 'wp-login.php';
                exit;

            }
        }, 10 );

    }

	/*
	RENDER CAPTCHA
	-- Adds the selected challenge to password login forms only
	---------------------------------------------------------- */

	public static function render_captcha(): void {

		$provider = self::captcha_provider();
		$site_key = self::captcha_key( 'site', $provider );

		if ( '' === $provider ) {

			return;

		}

		?>

		<div class="oa-login-captcha" data-oa-login-captcha="<?= esc_attr( $provider ); ?>">

			<?php

			if ( '' === $site_key ) :

			?>

			<p class="oa-login-captcha__error" role="alert"><?php esc_html_e( 'Captcha is not configured. Please contact the site administrator.', 'octave-addons' ); ?></p>

			<?php

			elseif ( 'google' === $provider ) :

			?>

			<input type="hidden" name="oa_login_captcha_token" value="">
			<p class="oa-login-captcha__status" aria-live="polite"><?php esc_html_e( 'Protected by reCAPTCHA', 'octave-addons' ); ?></p>

			<?php

			else :

			?>

			<div class="oa-login-captcha__widget" aria-live="polite"></div>

			<?php

			endif;

			?>

		</div>

		<?php

	}

	/*
	ENQUEUE CAPTCHA ASSETS
	-- Loads the chosen provider only when a public key is available
	---------------------------------------------------------- */

	public static function enqueue_captcha_assets(): void {

		$provider = self::captcha_provider();
		$site_key = self::captcha_key( 'site', $provider );

		if ( '' === $provider || '' === $site_key ) {

			return;

		}

		if ( 'google' === $provider ) {

			$provider_handle = 'octave-addons-google-recaptcha';
			$provider_url    = add_query_arg( [ 'render' => $site_key ], 'https://www.google.com/recaptcha/api.js' );

		} else {

			$provider_handle = 'octave-addons-cloudflare-turnstile';
			$provider_url    = add_query_arg( [ 'render' => 'explicit' ], 'https://challenges.cloudflare.com/turnstile/v0/api.js' );

		}

		$asset = self::asset( 'modules/design/custom-login/assets/login.js' );

		wp_enqueue_script( $provider_handle, $provider_url, [], null, true );
		wp_enqueue_script( self::SCRIPT_HANDLE, $asset['url'], [ $provider_handle ], $asset['version'], true );
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'OctaveCustomLoginCaptcha',
			[
				'provider'     => $provider,
				'siteKey'      => $site_key,
				'googleAction' => self::GOOGLE_ACTION,
				'errorMessage' => __( 'We could not complete the security check. Please try again.', 'octave-addons' ),
			]
		);

	}

	/*
	VALIDATE LOGIN CAPTCHA
	-- Rejects password login attempts until the provider verifies the token
	---------------------------------------------------------- */

	public static function validate_login_captcha( $user, string $username, string $password ) {

		$provider = self::captcha_provider();

		if ( '' === $provider || ! isset( $_POST['log'], $_POST['pwd'] ) ) {

			return $user;

		}

		$field = 'google' === $provider ? 'oa_login_captcha_token' : 'cf-turnstile-response';
		$token = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) ) : '';
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( '' !== $token && self::verify_captcha_response( $provider, $token, $ip ) ) {

			return $user;

		}

		return new WP_Error(
			'octave_login_captcha_failed',
			__( '<strong>Error:</strong> The security check could not be verified. Please try again.', 'octave-addons' )
		);

	}

	/*
	VERIFY CAPTCHA RESPONSE
	-- Checks the provider response server-side and enforces the Google action
	---------------------------------------------------------- */

	public static function verify_captcha_response( string $provider, string $token, string $ip = '' ): bool {

		if ( ! in_array( $provider, [ 'google', 'turnstile' ], true ) || '' === $token ) {

			return false;

		}

		$secret = self::captcha_key( 'secret', $provider );

		if ( '' === $secret ) {

			return false;

		}

		$url      = 'google' === $provider ? self::GOOGLE_VERIFY_URL : self::TURNSTILE_VERIFY_URL;
		$response = wp_remote_post(
			$url,
			[
				'body'    => [
					'secret'   => $secret,
					'response' => $token,
					'remoteip' => $ip,
				],
				'timeout' => 10,
			]
		);

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

		return true;

	}

	/*
	CAPTCHA PROVIDER
	-- Resolves the provider encoded in the selected credential mode
	---------------------------------------------------------- */

	protected static function captcha_provider(): string {

		$mode = (string) ( self::$runtime_settings['captcha_mode'] ?? '' );

		if ( false !== strpos( $mode, 'google' ) ) {

			return 'google';

		}

		if ( false !== strpos( $mode, 'turnstile' ) ) {

			return 'turnstile';

		}

		return '';

	}

	/*
	CAPTCHA KEY
	-- Uses module-specific credentials or the matching Forms credentials
	---------------------------------------------------------- */

	protected static function captcha_key( string $type, string $provider ): string {

		$mode = (string) ( self::$runtime_settings['captcha_mode'] ?? '' );

		if ( 0 === strpos( $mode, 'custom-' ) ) {

			return (string) ( self::$runtime_settings[ 'captcha_' . $type . '_key' ] ?? '' );

		}

		$all_settings = get_option( OCTAVE_ADDONS_OPTION_KEY, [] );
		$forms        = is_array( $all_settings ) && isset( $all_settings['breakdance-forms'] ) && is_array( $all_settings['breakdance-forms'] )
			? $all_settings['breakdance-forms']
			: [];
		$key          = (string) ( $forms[ $provider . '_' . $type . '_key' ] ?? '' );

		if ( '' === $key && 'google' === $provider ) {

			$constant = 'site' === $type ? 'BREAKDANCE_RECAPTCHA_SITE_KEY_NAME' : 'BREAKDANCE_RECAPTCHA_SECRET_KEY_NAME';

			if ( function_exists( 'Breakdance\\APIKeys\\getKey' ) && defined( $constant ) ) {

				$key = (string) \Breakdance\APIKeys\getKey( constant( $constant ) );

			}

		}

		return $key;

	}

    // ---- Helpers -----------------------------------------------------------

    /**
     * Convert a hex colour (#rrggbb or #rgb) to "r,g,b" for use in rgba().
     * Returns empty string on failure.
     */
    /**
     * Returns true when the perceived luminance of a hex colour is below 0.5,
     * i.e. the colour reads as dark and white text/links should be used on it.
     */
    private function is_dark_color( string $hex ): bool {

        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {

            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];

        }
        if ( strlen( $hex ) !== 6 ) {

            return false;

        }
        $r = hexdec( substr( $hex, 0, 2 ) );
        $g = hexdec( substr( $hex, 2, 2 ) );
        $b = hexdec( substr( $hex, 4, 2 ) );
        // ITU-R BT.601 perceived luminance, normalised to 0–1.
        return ( ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) / 255 ) < 0.5;

    }

    /**
     * Convert a hex colour (#rrggbb or #rgb) to "r,g,b" for use in rgba().
     * Returns empty string on failure.
     */
    private function hex_to_rgba( string $hex ): string {

        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {

            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];

        }
        if ( strlen( $hex ) !== 6 ) {

            return '';

        }
        return implode( ',', [
            hexdec( substr( $hex, 0, 2 ) ),
            hexdec( substr( $hex, 2, 2 ) ),
            hexdec( substr( $hex, 4, 2 ) ),
        ] );

    }

}

return new Octave_Addons_Module_Custom_Login();
