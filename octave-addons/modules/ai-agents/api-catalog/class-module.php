<?php

/*
MODULE: API CATALOG
-- Publishes /.well-known/api-catalog so an agent can discover what the site
-- offers programmatically instead of guessing at URLs, as defined by RFC 9727.
-- The catalog is a linkset: one entry per API, each naming where the API
-- lives, where its machine-readable description is, and where a human would
-- read about it.
-- Entries are built from the REST namespaces the site actually registers, so
-- the catalog describes this site rather than a generic WordPress one, and
-- never advertises an API that is not there.
--
-- Spec: https://www.rfc-editor.org/rfc/rfc9727
-- Skill: https://isitagentready.com/.well-known/agent-skills/api-catalog/SKILL.md
---------------------------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Api_Catalog extends Octave_Addons_Module {


	/** The well-known path RFC 9727 registers. */
	protected const WELL_KNOWN_PATH = '/.well-known/api-catalog';

	/** Media type for a linkset document, profiled to this RFC. */
	protected const CONTENT_TYPE = 'application/linkset+json; profile="https://www.rfc-editor.org/info/rfc9727"';

	/** Link relation used to advertise the catalog from elsewhere on the site. */
	protected const LINK_RELATION = 'api-catalog';

	public function get_id(): string {

		return 'api-catalog';

	}

	public function get_title(): string {

		return __( 'API Catalog', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Publishes /.well-known/api-catalog so agents can discover the site\'s APIs automatically, following RFC 9727. Built from the REST namespaces the site actually registers.', 'octave-addons' );

	}

	public function get_group(): string {

		return 'ai-agents';

	}

	public function get_order(): int {

		return 20;

	}

	/*
	GET DEFAULTS
	-- Ships switched on. The catalog only ever describes endpoints that are
	-- already public, so publishing it exposes nothing new — it just saves an
	-- agent from having to guess where they are.
	---------------------------------------------------------------------------- */

	public function get_defaults(): array {

		return [ 'enabled' => true ];

	}

	/*
	RUN
	-- Serves the catalog, and advertises it both ways RFC 9727 allows: a Link
	-- header on every page and a link element in the head.
	---------------------------------------------------------------------------- */

	public function run( array $s ): void {

		add_action( 'parse_request', [ $this, 'maybe_serve_catalog' ], 0 );
		add_action( 'send_headers', [ $this, 'send_link_header' ] );
		add_action( 'wp_head', [ $this, 'print_link_element' ], 2 );

	}

	/*
	MAYBE SERVE CATALOG
	-- Answers the well-known path before WordPress can decide it is a 404.
	-- Matching on the request path rather than adding a rewrite rule means
	-- there are no rewrite rules to flush, so the catalog works the moment the
	-- module is switched on and cannot be broken by a permalink resave.
	---------------------------------------------------------------------------- */

	public function maybe_serve_catalog(): void {

		if ( ! $this->is_catalog_request() ) {

			return;

		}

		$catalog = $this->build_catalog();

		status_header( 200 );

		header( 'Content-Type: ' . self::CONTENT_TYPE, true );
		header( 'X-Content-Type-Options: nosniff', true );
		header( 'Access-Control-Allow-Origin: *', true );

		echo wp_json_encode( $catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		exit;

	}

	/*
	IS CATALOG REQUEST
	-- The well-known path lives at the host root by definition. A WordPress
	-- installed in a subdirectory is also matched below its own base, since
	-- that is the only place such an install can answer at all.
	---------------------------------------------------------------------------- */

	protected function is_catalog_request(): bool {

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitised -- path is compared, never output.
		$request = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		$path = (string) wp_parse_url( $request, PHP_URL_PATH );
		$path = '/' . trim( rawurldecode( $path ), '/' );

		if ( self::WELL_KNOWN_PATH === $path ) {

			return true;

		}

		$base = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$base = rtrim( (string) $base, '/' );

		return '' !== $base && $base . self::WELL_KNOWN_PATH === $path;

	}

	/*
	BUILD CATALOG
	-- One linkset entry per API the site publishes.
	---------------------------------------------------------------------------- */

	protected function build_catalog(): array {

		$entries = [];

		foreach ( $this->catalogued_namespaces() as $namespace => $api ) {

			$anchor = (string) rest_url( $namespace );

			$entry = [
				'anchor' => $anchor,

				// WordPress publishes no OpenAPI document, but a namespace index
				// is a full machine-readable description of its own routes —
				// every endpoint, method and argument — which is what the
				// service-desc relation is for.
				'service-desc' => [
					[
						'href'  => $anchor,
						'type'  => 'application/json',
						'title' => $api['title'],
					],
				],

				'service-doc' => [
					[
						'href'  => $api['doc'],
						'type'  => 'text/html',
						'title' => $api['title'],
					],
				],
			];

			if ( ! empty( $api['status'] ) ) {

				$entry['status'] = [ [ 'href' => $api['status'] ] ];

			}

			$entries[] = $entry;

		}

		/**
		 * Filters the linkset entries published in the API catalog.
		 *
		 * Add an entry for an API this site serves itself. Each entry follows
		 * RFC 9727: an `anchor` plus arrays of link objects keyed by relation.
		 *
		 * @param array $entries  Linkset entries.
		 */
		$entries = (array) apply_filters( 'octave_addons_api_catalog_entries', $entries );

		return [ 'linkset' => array_values( $entries ) ];

	}

	/*
	CATALOGUED NAMESPACES
	-- The APIs worth telling an agent about, limited to those this site really
	-- registers. A WordPress install carries a long tail of namespaces that
	-- exist to serve its own admin screens — an object cache's statistics, an
	-- SEO plugin's setup wizard, the block editor's internals. Listing those
	-- would describe the site's plumbing rather than its API surface, so the
	-- catalog names only the ones an outside caller can meaningfully use.
	---------------------------------------------------------------------------- */

	protected function catalogued_namespaces(): array {

		$known = [
			'wp/v2' => [
				'title' => __( 'WordPress REST API', 'octave-addons' ),
				'doc'   => 'https://developer.wordpress.org/rest-api/',
			],
			'oembed/1.0' => [
				'title' => __( 'oEmbed', 'octave-addons' ),
				'doc'   => 'https://oembed.com/',
			],
			'mcp' => [
				'title' => __( 'Model Context Protocol', 'octave-addons' ),
				'doc'   => 'https://modelcontextprotocol.io/',
			],
			'wc/v3' => [
				'title' => __( 'WooCommerce REST API', 'octave-addons' ),
				'doc'   => 'https://woocommerce.github.io/woocommerce-rest-api-docs/',
			],
			'wc/store/v1' => [
				'title' => __( 'WooCommerce Store API', 'octave-addons' ),
				'doc'   => 'https://woocommerce.github.io/woocommerce-rest-api-docs/',
			],
		];

		$registered = $this->registered_namespaces();

		if ( ! $registered ) {

			return [];

		}

		return array_intersect_key( $known, array_flip( $registered ) );

	}

	/*
	REGISTERED NAMESPACES
	-- Asks the REST server what it has. Reading the live registry is what
	-- keeps the catalog honest: activate WooCommerce and it appears, deactivate
	-- it and it is gone, with nothing to keep in step by hand.
	---------------------------------------------------------------------------- */

	protected function registered_namespaces(): array {

		if ( ! function_exists( 'rest_get_server' ) ) {

			return [];

		}

		$server = rest_get_server();

		if ( ! $server instanceof WP_REST_Server ) {

			return [];

		}

		return (array) $server->get_namespaces();

	}

	/*
	CATALOG URL
	-- The catalog's own address, at the host root the RFC specifies.
	---------------------------------------------------------------------------- */

	protected function catalog_url(): string {

		return home_url( self::WELL_KNOWN_PATH );

	}

	/*
	SEND LINK HEADER
	-- Advertises the catalog on every response. The third argument is false so
	-- this is appended, leaving the Link headers WordPress already sends for
	-- the REST route and the shortlink intact.
	---------------------------------------------------------------------------- */

	public function send_link_header(): void {

		if ( is_admin() || headers_sent() ) {

			return;

		}

		header( sprintf( 'Link: <%s>; rel="%s"', $this->catalog_url(), self::LINK_RELATION ), false );

	}

	/*
	PRINT LINK ELEMENT
	-- The same advertisement for anything reading the markup rather than the
	-- headers.
	---------------------------------------------------------------------------- */

	public function print_link_element(): void {

		printf(
			'<link rel="%s" href="%s" />' . "\n",
			esc_attr( self::LINK_RELATION ),
			esc_url( $this->catalog_url() )
		);

	}

	/*
	RENDER SETTINGS
	-- Nothing to configure: the switch is the setting.
	---------------------------------------------------------------------------- */

	public function render_settings( array $s ): void {

		$catalogued = array_keys( $this->catalogued_namespaces() );

		?>

		<ul class="oa-help oa-md-summary">
			<li>
				<?php

				printf(
					/* translators: %s: the catalog URL. */
					esc_html__( 'Published at %s as an RFC 9727 linkset.', 'octave-addons' ),
					'<code>' . esc_html( $this->catalog_url() ) . '</code>'
				);

				?>
			</li>
			<li><?php esc_html_e( 'Advertised with an api-catalog link relation in both the response headers and the page head.', 'octave-addons' ); ?></li>
			<li>
				<?php

				if ( $catalogued ) {

					printf(
						/* translators: %s: comma-separated list of REST API namespaces. */
						esc_html__( 'Currently listing: %s. The list follows what the site registers, so an API appears and disappears with the plugin that provides it.', 'octave-addons' ),
						'<code>' . implode( '</code>, <code>', array_map( 'esc_html', $catalogued ) ) . '</code>'
					);

				} else {

					esc_html_e( 'No catalogued APIs were detected on this site yet.', 'octave-addons' );

				}

				?>
			</li>
		</ul>

		<?php

	}

}

return new Octave_Addons_Module_Api_Catalog();
