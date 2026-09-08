<?php

/*
MODULE: AGENT AUTH DISCOVERY
-- Publishes /auth.md so an automated client can find out how to authenticate
-- before it starts guessing, following the Auth.md discovery convention.
-- WordPress has no OAuth authorization server, so this takes the standard's
-- self-contained route: the document names its audience, says where an
-- operator asks for access, lists the methods the site accepts and shows how
-- the credential is used. A site that does run an OAuth server can publish
-- Protected Resource Metadata through the filter below and the document links
-- to it instead.
-- The document never names a sign-in URL. Where a person signs in is not what
-- an agent needs, and on a site running Custom Login URL, publishing it would
-- undo the only thing that module does.
--
-- Skill: https://isitagentready.com/.well-known/agent-skills/auth-md/SKILL.md
---------------------------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Auth_Discovery extends Octave_Addons_Module {


	/** Where the Auth.md document is published. */
	protected const AUTH_PATH = '/auth.md';

	/** Where OAuth Protected Resource Metadata goes when a site has any. */
	protected const PRM_PATH = '/.well-known/oauth-protected-resource';

	public function get_id(): string {

		return 'auth-discovery';

	}

	public function get_title(): string {

		return __( 'Agent Auth Discovery', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Publishes /auth.md so agents can discover how to authenticate with the site — what is readable without credentials, how an operator asks for access, and how a credential is sent.', 'octave-addons' );

	}

	public function get_group(): string {

		return 'ai-agents';

	}

	public function get_order(): int {

		return 30;

	}

	/*
	GET DEFAULTS
	-- On by default like the rest of the area. The two URLs are the only
	-- things that genuinely differ site to site: where an account lives and
	-- who to ask for access. Everything else is derived.
	---------------------------------------------------------------------------- */

	public function get_defaults(): array {

		return [
			'enabled'       => true,
			'account_url'   => '',
			'register_url'  => '',
			'contact_email' => '',
		];

	}

	public function sanitize( $input ): array {

		$clean = $this->get_defaults();

		$clean['enabled']       = ! empty( $input['enabled'] );
		$clean['account_url']   = esc_url_raw( trim( (string) ( $input['account_url'] ?? '' ) ) );
		$clean['register_url']  = esc_url_raw( trim( (string) ( $input['register_url'] ?? '' ) ) );
		$clean['contact_email'] = sanitize_email( trim( (string) ( $input['contact_email'] ?? '' ) ) );

		return $clean;

	}

	public function run( array $s ): void {

		add_action( 'parse_request', function () use ( $s ) {

			$this->maybe_serve( $s );

		}, 0 );

	}

	/*
	MAYBE SERVE
	-- Answers both documents before WordPress decides they are 404s. Matching
	-- the path directly means no rewrite rules to flush.
	---------------------------------------------------------------------------- */

	protected function maybe_serve( array $s ): void {

		$path = $this->request_path();

		if ( self::AUTH_PATH === $path ) {

			$this->send( $this->build_document( $s ), 'text/markdown; charset=utf-8' );

		}

		if ( self::PRM_PATH !== $path ) {

			return;

		}

		$metadata = $this->protected_resource_metadata();

		if ( ! $metadata ) {

			return;

		}

		$this->send(
			(string) wp_json_encode( $metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'application/json'
		);

	}

	/*
	SEND
	-- Emits a discovery document and stops. Both are public by definition, so
	-- they are readable cross-origin.
	---------------------------------------------------------------------------- */

	protected function send( string $body, string $type ): void {

		status_header( 200 );

		header( 'Content-Type: ' . $type, true );
		header( 'X-Content-Type-Options: nosniff', true );
		header( 'Access-Control-Allow-Origin: *', true );

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- discovery document, not HTML.

		exit;

	}

	/*
	REQUEST PATH
	-- The requested path, normalised, and with a subdirectory install's base
	-- removed so the documents answer where the standard expects them.
	---------------------------------------------------------------------------- */

	protected function request_path(): string {

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitised -- path is compared, never output.
		$request = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		$path = (string) wp_parse_url( $request, PHP_URL_PATH );
		$path = '/' . trim( rawurldecode( $path ), '/' );

		$base = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );

		if ( '' !== $base && 0 === strpos( $path, $base . '/' ) ) {

			$path = substr( $path, strlen( $base ) );

		}

		return $path;

	}

	/*
	PROTECTED RESOURCE METADATA
	-- Empty unless a site actually runs an OAuth authorization server. Nothing
	-- in WordPress issues OAuth tokens, and advertising an authorization server
	-- that does not exist would send every agent that trusts the document into
	-- a dead end, so the endpoint stays unpublished until a site fills it in.
	---------------------------------------------------------------------------- */

	protected function protected_resource_metadata(): array {

		/**
		 * Filters the OAuth Protected Resource Metadata this site publishes.
		 *
		 * Return an array carrying at least `resource` and `authorization_servers`
		 * to publish /.well-known/oauth-protected-resource. Each authorization
		 * server named must serve its own metadata, with an `issuer` matching
		 * the value advertised here.
		 *
		 * @param array $metadata  PRM document, empty to publish nothing.
		 */
		$metadata = (array) apply_filters( 'octave_addons_oauth_protected_resource', [] );

		if ( empty( $metadata['resource'] ) || empty( $metadata['authorization_servers'] ) ) {

			return [];

		}

		// Both are required by the standard and are the same on every resource
		// server WordPress can be, so they are filled in rather than demanded.
		$metadata['bearer_methods_supported'] = $metadata['bearer_methods_supported'] ?? [ 'header' ];
		$metadata['scopes_supported']         = $metadata['scopes_supported'] ?? [];

		return $metadata;

	}

	/*
	BUILD DOCUMENT
	-- The Auth.md document itself. The H1 carries the words "auth.md" because
	-- that is what identifies the document to anything looking for it.
	---------------------------------------------------------------------------- */

	protected function build_document( array $s ): string {

		$name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$api  = (string) rest_url();

		$lines = [
			'# auth.md',
			'',
			sprintf(
				/* translators: %s: site name. */
				__( 'How an automated client authenticates with %s.', 'octave-addons' ),
				$name
			),
			'',
			'## ' . __( 'Audience', 'octave-addons' ),
			'',
			__( 'This document is for AI agents and other software acting on a person\'s behalf. A person browsing the site does not need it.', 'octave-addons' ),
			'',
			'## ' . __( 'What needs no credential', 'octave-addons' ),
			'',
			sprintf(
				/* translators: %s: REST API base URL. */
				__( 'Published content is readable anonymously over the REST API at %s. Most agents need nothing beyond this, and should try it before asking anyone for access.', 'octave-addons' ),
				'`' . $api . '`'
			),
		];

		$catalog = $this->api_catalog_url();

		if ( '' !== $catalog ) {

			$lines[] = '';
			$lines[] = sprintf(
				/* translators: %s: API catalog URL. */
				__( 'The APIs this site publishes are catalogued at %s.', 'octave-addons' ),
				'`' . $catalog . '`'
			);

		}

		$lines = array_merge( $lines, $this->registration_section( $s ), $this->methods_section() );

		/**
		 * Filters the finished Auth.md document.
		 *
		 * @param string $document  Markdown source.
		 */
		return (string) apply_filters( 'octave_addons_auth_md', implode( "\n", $lines ) . "\n" );

	}

	/*
	REGISTRATION SECTION
	-- Where an operator goes to get a credential. Deliberately an account or
	-- contact address rather than a sign-in URL: an agent's operator needs to
	-- know who to ask, not where the login form is.
	---------------------------------------------------------------------------- */

	protected function registration_section( array $s ): array {

		$lines = [ '', '## ' . __( 'Requesting access', 'octave-addons' ), '' ];

		$account  = trim( (string) $s['account_url'] );
		$register = trim( (string) $s['register_url'] );
		$email    = trim( (string) $s['contact_email'] );

		if ( '' === $account && '' === $register && '' === $email ) {

			$lines[] = __( 'This site publishes no public route to request programmatic access. If you already hold credentials for an account here, use the method below. Otherwise read anonymously as described above — do not attempt to register, and do not probe for a registration endpoint.', 'octave-addons' );

			return $lines;

		}

		if ( '' !== $register ) {

			$lines[] = sprintf(
				/* translators: %s: registration URL. */
				__( 'Register for access: %s', 'octave-addons' ),
				'`' . $register . '`'
			);
			$lines[] = '';

		}

		if ( '' !== $account ) {

			$lines[] = sprintf(
				/* translators: %s: account URL. */
				__( 'Manage an existing account and its credentials: %s', 'octave-addons' ),
				'`' . $account . '`'
			);
			$lines[] = '';

		}

		if ( '' !== $email ) {

			$lines[] = sprintf(
				/* translators: %s: contact email address. */
				__( 'Ask a human about access: %s', 'octave-addons' ),
				'`' . $email . '`'
			);
			$lines[] = '';

		}

		$lines[] = __( 'Credentials are issued to a person or an organisation, not to an agent directly. An agent authenticates as whoever it acts for.', 'octave-addons' );

		return $lines;

	}

	/*
	METHODS SECTION
	-- What the site accepts, and how the credential is sent. Application
	-- passwords are named only when the site really has them switched on,
	-- since WordPress lets them be disabled and an agent should not be told to
	-- use something that will be refused.
	---------------------------------------------------------------------------- */

	protected function methods_section(): array {

		$lines = [ '', '## ' . __( 'Methods', 'octave-addons' ), '' ];

		$available = function_exists( 'wp_is_application_passwords_available' )
			&& wp_is_application_passwords_available();

		if ( ! $available ) {

			$lines[] = __( 'No credentialled method is currently offered. Read anonymously, or ask about access using the details above.', 'octave-addons' );

			return $lines;

		}

		$lines[] = '### ' . __( 'Application password', 'octave-addons' );
		$lines[] = '';
		$lines[] = __( 'The site accepts WordPress application passwords over HTTP Basic authentication. An application password is issued per client, is not the account password, and can be revoked on its own without affecting anything else.', 'octave-addons' );
		$lines[] = '';
		$lines[] = '## ' . __( 'Using the credential', 'octave-addons' );
		$lines[] = '';
		$lines[] = __( 'Send it on every request as an Authorization header. Never in a query string.', 'octave-addons' );
		$lines[] = '';
		$lines[] = '```http';
		$lines[] = 'Authorization: Basic <base64 of "username:application_password">';
		$lines[] = '```';
		$lines[] = '';
		$lines[] = __( 'Requests must be made over HTTPS. What the credential can reach is whatever its owner can reach, so an agent holding one is bound by that account\'s permissions.', 'octave-addons' );

		return $lines;

	}

	/*
	API CATALOG URL
	-- Cross-references the catalog when that module is publishing one, so an
	-- agent reading either document can find the other.
	---------------------------------------------------------------------------- */

	protected function api_catalog_url(): string {

		$settings = get_option( OCTAVE_ADDONS_OPTION_KEY, [] );
		$catalog  = is_array( $settings ) ? ( $settings['api-catalog'] ?? null ) : null;

		// Absent means the module has never been saved, and it ships enabled.
		$enabled = ! is_array( $catalog ) || ! empty( $catalog['enabled'] );

		return $enabled ? home_url( '/.well-known/api-catalog' ) : '';

	}

	/*
	RENDER SETTINGS
	---------------------------------------------------------------------------- */

	public function render_settings( array $s ): void {

		?>

		<ul class="oa-help oa-md-summary">
			<li>
				<?php

				printf(
					/* translators: %s: the auth.md URL. */
					esc_html__( 'Published at %s. Anonymous read access and how a credential is sent are described automatically.', 'octave-addons' ),
					'<code>' . esc_html( home_url( self::AUTH_PATH ) ) . '</code>'
				);

				?>
			</li>
			<li><?php esc_html_e( 'No sign-in URL is ever published, so a site using Custom Login URL keeps its login address private.', 'octave-addons' ); ?></li>
		</ul>

		<table class="form-table oa-form-table" role="presentation">

			<?php Octave_Addons_Fields::section( [ 'label' => __( 'Where to ask for access', 'octave-addons' ), 'first' => true ] ); ?>

			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'account_url' ),
				'label' => __( 'Account URL', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::url( [
						'id'          => $this->field_id( 'account_url' ),
						'name'        => $this->field_name( 'account_url' ),
						'value'       => $s['account_url'],
						'placeholder' => home_url( '/account/' ),
						'help'        => __( 'Where someone manages an existing account and its credentials — /account, /my-account, whatever this site uses. Leave empty if there is none.', 'octave-addons' ),
					] );

				},
			] ); ?>

			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'register_url' ),
				'label' => __( 'Registration URL', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::url( [
						'id'          => $this->field_id( 'register_url' ),
						'name'        => $this->field_name( 'register_url' ),
						'value'       => $s['register_url'],
						'placeholder' => home_url( '/register/' ),
						'help'        => __( 'Where an operator signs up for programmatic access, if that is a different page from the account one.', 'octave-addons' ),
					] );

				},
			] ); ?>

			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'contact_email' ),
				'label' => __( 'Access contact', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::email( [
						'id'          => $this->field_id( 'contact_email' ),
						'name'        => $this->field_name( 'contact_email' ),
						'value'       => $s['contact_email'],
						'placeholder' => 'api@example.com',
						'help'        => __( 'An address an operator can write to. Published in the document, so use one that can take public mail.', 'octave-addons' ),
					] );

				},
			] ); ?>

		</table>

		<?php

	}

}

return new Octave_Addons_Module_Auth_Discovery();
