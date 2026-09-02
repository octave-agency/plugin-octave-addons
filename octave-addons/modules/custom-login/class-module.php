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


    public function get_id(): string {

        return 'custom-login';

    }

    public function get_title(): string {

        return __( 'Custom Login URL', 'octave-addons' );

    }

    public function get_description(): string {

        return __( 'Moves the WordPress login page to a custom URL slug, blocks direct access to /wp-login.php and /wp-login, and lets you style the login page with custom colours and logo.', 'octave-addons' );

    }

    public function get_group(): string {

        return 'branding';

    }

    public function get_order(): int {

        return 20;

    }

    public function get_defaults(): array {

        return [
            'enabled'         => false,
            'login_slug'      => 'login',
            'redirect_url'    => '',
            'custom_logo_url' => '',
            'bg_color'        => '',
            'primary_color'   => '',
            'custom_css'      => '',
        ];

    }

    public function sanitize( $input ): array {

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
                        'help'        => __( 'Where to send anyone who accesses /wp-login.php or /wp-login directly. Defaults to home page if blank.', 'octave-addons' ),
                    ] );

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
                        'help'  => __( 'Choose the brand logo from the WordPress Media Library.', 'octave-addons' ),
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
                        'help'  => __( 'Used for the submit button, input focus ring and links.', 'octave-addons' ),
                    ] );

                },
            ] ); ?>
            <?php Octave_Addons_Fields::row( [
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
                        'help'        => __( 'Appended after the built-in login styles. Targets the WordPress login page only.', 'octave-addons' ),
                    ] );

                },
            ] ); ?>
        </table>
        <?php

    }

    // ---- Frontend ----------------------------------------------------------

    public function run( array $s ): void {

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

        // --- Login page styles ------------------------------------------

        add_action( 'login_enqueue_scripts', function () use ( $s ) {

            $css_path = OCTAVE_ADDONS_DIR . 'modules/custom-login/assets/login.css';
            $css_url  = OCTAVE_ADDONS_URL . 'modules/custom-login/assets/login.css';
            $version  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : OCTAVE_ADDONS_VERSION;

            wp_enqueue_style( 'oa-custom-login', $css_url, [], $version );

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
