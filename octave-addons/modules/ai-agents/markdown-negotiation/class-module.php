<?php

/*
MODULE: MARKDOWN FOR AGENTS
-- Answers a request carrying Accept: text/markdown with a Markdown
-- representation of the same page, while every browser continues to receive
-- the normal HTML. One URL, two representations, chosen by the header the
-- client sends — which is what content negotiation is for.
-- An agent asking for a page today has to scrape a builder's nested markup
-- and spend most of its context window on layout. This hands it the text.
-- There is nothing to configure. Every choice inside is the one that reads
-- best to a chatbot, and it is the same on every site, so the module is a
-- single switch.
--
-- Skill: https://isitagentready.com/.well-known/agent-skills/markdown-negotiation/SKILL.md
-- Reference: https://developers.cloudflare.com/fundamentals/reference/markdown-for-agents/
---------------------------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

require_once __DIR__ . '/class-markdown-negotiator.php';
require_once __DIR__ . '/class-html-to-markdown.php';
require_once __DIR__ . '/class-markdown-document.php';

class Octave_Addons_Module_Markdown_Negotiation extends Octave_Addons_Module {


	/** Option holding the cache generation stamp, bumped to invalidate everything at once. */
	protected const GENERATION_OPTION = 'octave_addons_markdown_generation';

	/** How long one converted document is kept. */
	protected const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/** Query argument that previews the Markdown variant in a browser. */
	protected const PREVIEW_ARG = 'format';

	public function get_id(): string {

		return 'markdown-negotiation';

	}

	public function get_title(): string {

		return __( 'Markdown for Agents', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Answers requests sending Accept: text/markdown with a clean Markdown version of the page, so agents read the text instead of scraping the layout. Browsers keep getting HTML.', 'octave-addons' );

	}

	public function get_group(): string {

		return 'ai-agents';

	}

	public function get_order(): int {

		return 10;

	}

	/*
	GET DEFAULTS
	-- Ships switched on. Serving Markdown to a client that asked for it takes
	-- nothing away from anyone else — a browser sees the identical HTML — so
	-- the useful default is that an agent arriving at an Octave site is
	-- answered properly without anyone having to know to turn this on.
	---------------------------------------------------------------------------- */

	public function get_defaults(): array {

		return [ 'enabled' => true ];

	}

	/*
	SANITIZE
	-- Saving can change what a stored document would say, so they go.
	---------------------------------------------------------------------------- */

	public function sanitize( $input ): array {

		$this->flush_cache();

		return parent::sanitize( $input );

	}

	/*
	RUN
	-- Registers the negotiation itself plus the two things that make the HTML
	-- variant behave correctly alongside it: a Vary header so caches keep the
	-- representations apart, and a link telling agents the Markdown one exists.
	---------------------------------------------------------------------------- */

	public function run( array $s ): void {

		add_action( 'template_redirect', [ $this, 'negotiate' ], 0 );
		add_filter( 'wp_headers', [ $this, 'filter_vary_header' ] );
		add_action( 'wp_head', [ $this, 'print_alternate_link' ], 2 );

		foreach ( [ 'save_post', 'deleted_post', 'trashed_post', 'untrashed_post', 'switch_theme' ] as $hook ) {

			add_action( $hook, [ $this, 'flush_cache' ] );

		}

	}

	/*
	NEGOTIATE
	-- The entry point. Runs before the template loader so a Markdown request
	-- can be answered without the HTML template ever being chosen, and returns
	-- silently for every request that is not asking for Markdown, which is
	-- almost all of them.
	---------------------------------------------------------------------------- */

	public function negotiate(): void {

		if ( ! $this->request_is_negotiable() || ! $this->request_wants_markdown() ) {

			return;

		}

		// Page caches key on the URL and mostly ignore Vary. Left cacheable, the
		// Markdown answer gets stored against this address and then handed to
		// whoever asks for it next, browsers included. Every full-page cache in
		// common use honours this constant, so the variant is never stored.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {

			define( 'DONOTCACHEPAGE', true );

		}

		$url = $this->current_url();

		if ( is_404() ) {

			$this->emit( ( new Octave_Addons_Markdown_Document() )->not_found( $url ), 404 );

		}

		if ( is_singular() ) {

			$this->negotiate_singular( $url );

			return;

		}

		$this->negotiate_index( $url );

	}

	/*
	NEGOTIATE SINGULAR
	-- Serves one entry. The template is rendered and converted rather than
	-- read from post_content, because a page built in Breakdance, assembled
	-- from blocks or driven by shortcodes only exists once it has been
	-- rendered. Capturing the template is the only way to reach that content
	-- without knowing which builder produced it.
	---------------------------------------------------------------------------- */

	protected function negotiate_singular( string $url ): void {

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post || ! is_post_type_viewable( $post->post_type ) ) {

			return;

		}

		$permalink = (string) get_permalink( $post );
		$permalink = '' !== $permalink ? $permalink : $url;

		if ( post_password_required( $post ) ) {

			$this->emit( ( new Octave_Addons_Markdown_Document() )->password_protected( $post, $permalink ) );

		}

		$cached = $this->cache_get( $url );

		if ( null !== $cached ) {

			$this->emit( $cached['markdown'], 200, $cached['tokens'] );

		}

		// The bar is chrome around the page rather than part of it, and it only
		// renders for a logged-in editor previewing the Markdown in any case.
		add_filter( 'show_admin_bar', '__return_false' );

		add_filter( 'template_include', function ( $template ) use ( $post, $url, $permalink ) {

			$html = '';

			if ( is_string( $template ) && '' !== $template && file_exists( $template ) ) {

				ob_start();

				// load_template() rather than a bare include: a theme template
				// expects $wp_query, $posts and the rest of the loop globals to
				// be in scope, and core's own loader is what puts them there.
				load_template( $template, false );

				$html = (string) ob_get_clean();

			}

			$markdown = ( new Octave_Addons_Markdown_Document() )->singular( $post, $html, $permalink );
			$tokens   = Octave_Addons_Markdown_Negotiator::estimate_tokens( $html );

			$this->cache_set( $url, $markdown, $tokens );
			$this->emit( $markdown, 200, $tokens );

			return $template;

		}, PHP_INT_MAX );

	}

	/*
	NEGOTIATE INDEX
	-- Serves a listing as a link index built from the query. An archive's
	-- value to an agent is the list of what is on it, so the card markup a
	-- theme wraps that list in is not worth converting.
	---------------------------------------------------------------------------- */

	protected function negotiate_index( string $url ): void {

		global $wp_query;

		if ( ! $wp_query instanceof WP_Query ) {

			return;

		}

		$cached = $this->cache_get( $url );

		if ( null !== $cached ) {

			$this->emit( $cached['markdown'] );

		}

		$markdown = ( new Octave_Addons_Markdown_Document() )->index( $wp_query, $url );

		$this->cache_set( $url, $markdown );
		$this->emit( $markdown );

	}

	/*
	REQUEST IS NEGOTIABLE
	-- Everything that is already its own representation — a feed, the REST
	-- API, an oEmbed, robots.txt — is left alone, as is anything that is not a
	-- plain read of a page.
	---------------------------------------------------------------------------- */

	protected function request_is_negotiable(): bool {

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {

			return false;

		}

		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {

			return false;

		}

		if ( is_feed() || is_embed() || is_trackback() || is_preview() || is_customize_preview() ) {

			return false;

		}

		if ( function_exists( 'is_robots' ) && is_robots() ) {

			return false;

		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitised -- compared against a fixed list.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';

		return in_array( $method, [ 'GET', 'HEAD' ], true );

	}

	/*
	REQUEST WANTS MARKDOWN
	-- Either the Accept header asked for it, or an editor is previewing the
	-- variant in a browser. The preview is capability-gated so the Markdown
	-- never becomes a second public URL for the same content.
	---------------------------------------------------------------------------- */

	protected function request_wants_markdown(): bool {

		if ( Octave_Addons_Markdown_Negotiator::wants_markdown( Octave_Addons_Markdown_Negotiator::request_accept() ) ) {

			return true;

		}

		return $this->preview_requested();

	}

	/*
	PREVIEW REQUESTED
	-- ?format=markdown for anyone who can edit posts, so the output can be
	-- checked without reaching for curl.
	---------------------------------------------------------------------------- */

	protected function preview_requested(): bool {

		if ( ! current_user_can( 'edit_posts' ) ) {

			return false;

		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only representation switch.
		$format = isset( $_GET[ self::PREVIEW_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::PREVIEW_ARG ] ) ) : '';

		return in_array( $format, [ 'md', 'markdown' ], true );

	}

	/*
	EMIT
	-- Sends the Markdown response and stops. The token headers report the
	-- Markdown and the HTML it was made from, which is the pair an agent uses
	-- to judge whether asking for Markdown was worth it.
	---------------------------------------------------------------------------- */

	protected function emit( string $markdown, int $status = 200, int $original_tokens = 0 ): void {

		if ( ! headers_sent() ) {

			status_header( $status );

			header( 'Content-Type: ' . Octave_Addons_Markdown_Negotiator::CONTENT_TYPE, true );
			header( 'Vary: ' . $this->vary_value(), true );
			header( 'X-Content-Type-Options: nosniff', true );

			// Belt and braces alongside DONOTCACHEPAGE, for the caches that read
			// headers rather than constants.
			header( 'Cache-Control: private, no-store, max-age=0', true );

			header( 'x-markdown-tokens: ' . Octave_Addons_Markdown_Negotiator::estimate_tokens( $markdown ), true );

			if ( $original_tokens > 0 ) {

				header( 'x-original-tokens: ' . $original_tokens, true );

			}

		}

		echo $markdown; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown body, not HTML.

		// exit runs the shutdown hooks, and a plugin on one of them may append
		// to the page — an object cache printing its stats comment is the usual
		// culprit. That belongs to the HTML response, not this one, so anything
		// printed from here on is swallowed rather than sent.
		ob_start( static function (): string {

			return '';

		} );

		exit;

	}

	/*
	VARY VALUE
	-- Accept is added to whatever the response already varies on. Losing an
	-- existing Accept-Encoding here would let a cache hand a gzipped body to a
	-- client that never asked for one, so the existing values are read back
	-- rather than replaced.
	---------------------------------------------------------------------------- */

	protected function vary_value(): string {

		$values = [];

		foreach ( headers_list() as $header ) {

			if ( 0 !== stripos( $header, 'vary:' ) ) {

				continue;

			}

			foreach ( explode( ',', substr( $header, 5 ) ) as $value ) {

				$values[] = trim( $value );

			}

		}

		$values[] = 'Accept';

		return $this->join_vary( $values );

	}

	/*
	FILTER VARY HEADER
	-- The same addition on the HTML side. Without it a shared cache can store
	-- the HTML response and then serve it to an agent that asked for Markdown.
	---------------------------------------------------------------------------- */

	public function filter_vary_header( $headers ) {

		if ( ! is_array( $headers ) ) {

			return $headers;

		}

		$values = isset( $headers['Vary'] ) ? explode( ',', (string) $headers['Vary'] ) : [];

		$values[] = 'Accept';

		$headers['Vary'] = $this->join_vary( $values );

		return $headers;

	}

	/*
	JOIN VARY
	-- Deduplicates a Vary list case-insensitively while keeping the order the
	-- values arrived in.
	---------------------------------------------------------------------------- */

	protected function join_vary( array $values ): string {

		$seen = [];

		foreach ( $values as $value ) {

			$value = trim( $value );

			if ( '' === $value ) {

				continue;

			}

			$seen[ strtolower( $value ) ] = $value;

		}

		return implode( ', ', array_values( $seen ) );

	}

	/*
	PRINT ALTERNATE LINK
	-- Advertises that the same address also answers in Markdown. The href is
	-- the page's own URL because the representations share one URL — the
	-- Accept header is what picks between them.
	---------------------------------------------------------------------------- */

	public function print_alternate_link(): void {

		if ( is_singular() ) {

			$post = get_queried_object();

			if ( ! $post instanceof WP_Post || ! is_post_type_viewable( $post->post_type ) ) {

				return;

			}

		}

		printf(
			'<link rel="alternate" type="text/markdown" href="%s" />' . "\n",
			esc_url( $this->current_url() )
		);

	}

	/*
	CURRENT URL
	-- The address that was requested, with the preview argument removed so a
	-- previewed document still names its real URL.
	---------------------------------------------------------------------------- */

	protected function current_url(): string {

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitised -- passed through esc_url_raw below.
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitised -- passed through esc_url_raw below.
		$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$url = ( is_ssl() ? 'https://' : 'http://' ) . $host . $path;

		return (string) esc_url_raw( remove_query_arg( self::PREVIEW_ARG, $url ) );

	}

	/*
	CACHE KEY
	-- Keyed on the URL and a generation stamp. Bumping the stamp retires every
	-- stored document at once without having to find and delete them.
	---------------------------------------------------------------------------- */

	protected function cache_key( string $url ): string {

		return 'oa_md_' . md5( $url . '|' . get_option( self::GENERATION_OPTION, 0 ) );

	}

	/*
	CACHE GET
	-- Cached documents are for anonymous visitors only. A logged-in reader can
	-- see drafts, private entries and personalised output, none of which
	-- belongs in a store keyed on the URL alone.
	---------------------------------------------------------------------------- */

	protected function cache_get( string $url ): ?array {

		if ( is_user_logged_in() ) {

			return null;

		}

		$cached = get_transient( $this->cache_key( $url ) );

		if ( ! is_array( $cached ) || ! isset( $cached['markdown'] ) || ! is_string( $cached['markdown'] ) ) {

			return null;

		}

		return [
			'markdown' => $cached['markdown'],
			'tokens'   => (int) ( $cached['tokens'] ?? 0 ),
		];

	}

	/*
	CACHE SET
	-- The HTML token count is stored beside the document, so a cache hit still
	-- reports what the original page would have cost to read.
	---------------------------------------------------------------------------- */

	protected function cache_set( string $url, string $markdown, int $original_tokens = 0 ): void {

		if ( is_user_logged_in() ) {

			return;

		}

		set_transient(
			$this->cache_key( $url ),
			[ 'markdown' => $markdown, 'tokens' => $original_tokens ],
			self::CACHE_TTL
		);

	}

	/*
	FLUSH CACHE
	-- Moves the generation stamp on, which orphans every stored document. The
	-- orphans expire on their own rather than being hunted down, so publishing
	-- a post never turns into a table scan.
	---------------------------------------------------------------------------- */

	public function flush_cache(): void {

		update_option( self::GENERATION_OPTION, (string) time(), false );

	}

	/*
	RENDER SETTINGS
	-- Nothing to configure: the switch is the setting. This states what the
	-- module does while it is on, so the panel is not simply blank.
	---------------------------------------------------------------------------- */

	public function render_settings( array $s ): void {

		$behaviours = [
			__( 'Every page, post and custom post type with a public URL answers in Markdown, and archives, taxonomy pages and search results answer as a link index.', 'octave-addons' ),
			__( 'Each document opens with YAML frontmatter — title, description, canonical URL, dates, author, image and terms — and carries any structured data the page publishes.', 'octave-addons' ),
			__( 'The page is converted as it renders, so Breakdance sections, blocks and shortcodes all come through as text.', 'octave-addons' ),
			__( 'Browsers are unaffected. They ask for HTML and receive exactly what they did before.', 'octave-addons' ),
		];

		?>

		<ul class="oa-help oa-md-summary">
			<?php

			foreach ( $behaviours as $behaviour ) {

				?>

				<li><?= esc_html( $behaviour ); ?></li>

				<?php

			}

			?>
		</ul>

		<?php

	}

}

return new Octave_Addons_Module_Markdown_Negotiation();
