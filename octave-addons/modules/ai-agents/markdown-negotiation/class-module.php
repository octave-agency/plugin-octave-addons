<?php

/*
MODULE: MARKDOWN FOR AGENTS
-- Answers a request carrying Accept: text/markdown with a Markdown
-- representation of the same page, while every browser continues to receive
-- the normal HTML. One URL, two representations, chosen by the header the
-- client sends — which is what content negotiation is for.
-- An agent asking for a page today has to scrape a builder's nested markup
-- and spend most of its context window on layout. This hands it the text.
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

	/** How long one rendered document is kept. */
	protected const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/** Query argument that previews the Markdown variant in a browser. */
	protected const PREVIEW_ARG = 'format';

	/** @var array Settings for the current request, stored so the hooks can read them. */
	protected array $settings = [];

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

		return [
			'enabled'           => true,
			'all_post_types'    => true,
			'post_types'        => [ 'post', 'page' ],
			'archives'          => true,
			'frontmatter'       => true,
			'jsonld'            => true,
			'token_headers'     => true,
			'vary_html'         => true,
			'alternate_link'    => true,
			'content_signal'    => true,
			'signal_ai_train'   => true,
			'signal_search'     => true,
			'signal_ai_input'   => true,
			'cache'             => true,
			'preview'           => true,
			'selector'          => '',
		];

	}

	public function sanitize( $input ): array {

		$clean = $this->get_defaults();

		foreach ( [ 'enabled', 'all_post_types', 'archives', 'frontmatter', 'jsonld', 'token_headers', 'vary_html', 'alternate_link', 'content_signal', 'signal_ai_train', 'signal_search', 'signal_ai_input', 'cache', 'preview' ] as $key ) {

			$clean[ $key ] = ! empty( $input[ $key ] );

		}

		$types = $input['post_types'] ?? [];
		$types = is_array( $types ) ? array_filter( array_map( 'sanitize_key', $types ) ) : [];

		$clean['post_types'] = array_values( array_unique( $types ) );
		$clean['selector']   = $this->sanitize_selector( $input['selector'] ?? '' );

		// Saving changes what a cached document would say, so the stored ones go.
		$this->flush_cache();

		return $clean;

	}

	/*
	SANITIZE SELECTOR
	-- The container setting only ever holds a short CSS selector, so anything
	-- outside the characters a selector is written in is stripped rather than
	-- stored and later rejected by the translator.
	---------------------------------------------------------------------------- */

	protected function sanitize_selector( $value ): string {

		$value = is_string( $value ) ? $value : '';
		$value = (string) preg_replace( '/[^A-Za-z0-9_\-.#, ]/', '', $value );

		return trim( (string) preg_replace( '/\s+/', ' ', $value ) );

	}

	/*
	RUN
	-- Registers the negotiation itself plus the two things that make the HTML
	-- variant behave correctly alongside it: a Vary header so caches keep the
	-- representations apart, and a link telling agents the Markdown one exists.
	---------------------------------------------------------------------------- */

	public function run( array $s ): void {

		$this->settings = $s;

		add_action( 'template_redirect', [ $this, 'negotiate' ], 0 );

		if ( ! empty( $s['vary_html'] ) ) {

			add_filter( 'wp_headers', [ $this, 'filter_vary_header' ] );

		}

		if ( ! empty( $s['alternate_link'] ) ) {

			add_action( 'wp_head', [ $this, 'print_alternate_link' ], 2 );

		}

		if ( ! empty( $s['cache'] ) ) {

			foreach ( [ 'save_post', 'deleted_post', 'trashed_post', 'untrashed_post', 'switch_theme' ] as $hook ) {

				add_action( $hook, [ $this, 'flush_cache' ] );

			}

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

		$url = $this->current_url();

		if ( is_404() ) {

			$this->emit( $this->document()->not_found( $url ), 404 );

		}

		if ( is_singular() ) {

			$this->negotiate_singular( $url );

			return;

		}

		if ( empty( $this->settings['archives'] ) ) {

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

		if ( ! $post instanceof WP_Post || ! $this->post_type_included( $post->post_type ) ) {

			return;

		}

		$permalink = (string) get_permalink( $post );
		$permalink = '' !== $permalink ? $permalink : $url;

		if ( post_password_required( $post ) ) {

			$this->emit( $this->document()->password_protected( $post, $permalink ) );

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

			$markdown = $this->document()->singular( $post, $html, $permalink );
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

		$markdown = $this->document()->index( $wp_query, $url );

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

		if ( empty( $this->settings['preview'] ) || ! current_user_can( 'edit_posts' ) ) {

			return false;

		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only representation switch.
		$format = isset( $_GET[ self::PREVIEW_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::PREVIEW_ARG ] ) ) : '';

		return in_array( $format, [ 'md', 'markdown' ], true );

	}

	/*
	POST TYPE INCLUDED
	-- Post types are resolved here rather than at registration time, because
	-- modules boot early on init and a custom post type may not exist yet.
	---------------------------------------------------------------------------- */

	protected function post_type_included( string $post_type ): bool {

		if ( ! is_post_type_viewable( $post_type ) ) {

			return false;

		}

		if ( ! empty( $this->settings['all_post_types'] ) ) {

			return true;

		}

		return in_array( $post_type, (array) ( $this->settings['post_types'] ?? [] ), true );

	}

	/*
	DOCUMENT
	-- The document builder for the current settings.
	---------------------------------------------------------------------------- */

	protected function document(): Octave_Addons_Markdown_Document {

		return new Octave_Addons_Markdown_Document( $this->settings );

	}

	/*
	EMIT
	-- Sends the Markdown response and stops. The token headers report the
	-- Markdown and the HTML it was made from, which is the pair an agent uses
	-- to judge whether asking for Markdown was worth it.
	-- Caching is left exactly as it would have been for the HTML, so a CDN can
	-- hold the Markdown variant on the same terms. Only a logged-in reader is
	-- marked uncacheable, because what they were shown may not be public.
	---------------------------------------------------------------------------- */

	protected function emit( string $markdown, int $status = 200, int $original_tokens = 0 ): void {

		if ( ! headers_sent() ) {

			status_header( $status );

			if ( is_user_logged_in() ) {

				nocache_headers();

			}

			header( 'Content-Type: ' . Octave_Addons_Markdown_Negotiator::CONTENT_TYPE, true );
			header( 'Vary: ' . $this->vary_value(), true );
			header( 'X-Content-Type-Options: nosniff', true );

			if ( ! empty( $this->settings['token_headers'] ) ) {

				header( 'x-markdown-tokens: ' . Octave_Addons_Markdown_Negotiator::estimate_tokens( $markdown ), true );

				if ( $original_tokens > 0 ) {

					header( 'x-original-tokens: ' . $original_tokens, true );

				}

			}

			if ( ! empty( $this->settings['content_signal'] ) ) {

				header( 'content-signal: ' . $this->content_signal(), true );

			}

		}

		echo $markdown; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown body, not HTML.

		exit;

	}

	/*
	CONTENT SIGNAL
	-- States what the site permits this representation to be used for, in the
	-- format the content-signal header is defined in.
	---------------------------------------------------------------------------- */

	protected function content_signal(): string {

		$signals = [
			'ai-train' => ! empty( $this->settings['signal_ai_train'] ),
			'search'   => ! empty( $this->settings['signal_search'] ),
			'ai-input' => ! empty( $this->settings['signal_ai_input'] ),
		];

		$parts = [];

		foreach ( $signals as $name => $allowed ) {

			$parts[] = $name . '=' . ( $allowed ? 'yes' : 'no' );

		}

		return implode( ', ', $parts );

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

		if ( ! is_singular() && empty( $this->settings['archives'] ) ) {

			return;

		}

		if ( is_singular() ) {

			$post = get_queried_object();

			if ( ! $post instanceof WP_Post || ! $this->post_type_included( $post->post_type ) ) {

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
	-- Keyed on the URL, the settings that change what a document says, and a
	-- generation stamp. Bumping the stamp retires every stored document at
	-- once without having to find and delete them.
	---------------------------------------------------------------------------- */

	protected function cache_key( string $url ): string {

		$signature = wp_json_encode( [
			$this->settings['frontmatter'] ?? true,
			$this->settings['jsonld'] ?? true,
			$this->settings['selector'] ?? '',
			get_option( self::GENERATION_OPTION, 0 ),
		] );

		return 'oa_md_' . md5( $url . '|' . $signature );

	}

	/*
	CACHE GET
	-- Cached documents are for anonymous visitors only. A logged-in reader can
	-- see drafts, private entries and personalised output, none of which
	-- belongs in a store keyed on the URL alone.
	---------------------------------------------------------------------------- */

	protected function cache_get( string $url ): ?array {

		if ( empty( $this->settings['cache'] ) || is_user_logged_in() ) {

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

		if ( empty( $this->settings['cache'] ) || is_user_logged_in() ) {

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
	---------------------------------------------------------------------------- */

	public function render_settings( array $s ): void {

		$sample = home_url( '/' );

		?>

		<div class="notice notice-info inline oa-inline-notice">
			<p><strong><?php esc_html_e( 'Check it from a terminal', 'octave-addons' ); ?></strong></p>
			<p><code>curl -H "Accept: text/markdown" <?= esc_html( $sample ); ?></code></p>
			<p><?php esc_html_e( 'The same address returns HTML in a browser and Markdown to a client that asks for it. Editors can also add ?format=markdown to any URL while the preview setting below is on.', 'octave-addons' ); ?></p>
			<p><?php esc_html_e( 'If a full-page cache sits in front of WordPress, check that it varies on the Accept header. A cache that serves its stored HTML without reading Accept answers agents before this module ever runs.', 'octave-addons' ); ?></p>
		</div>

		<table class="form-table oa-form-table" role="presentation">

			<?php Octave_Addons_Fields::section( [ 'label' => __( 'What responds', 'octave-addons' ), 'first' => true ] ); ?>

			<?php Octave_Addons_Fields::row( [
				'label' => __( 'Every public post type', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'id'      => $this->field_id( 'all_post_types' ),
						'name'    => $this->field_name( 'all_post_types' ),
						'checked' => ! empty( $s['all_post_types'] ),
						'data'    => [ 'controls-row-hide' => 'oaMdRowPostTypes' ],
						'help'    => __( 'Answer for anything with a public URL, including post types added later. Turn this off to choose them by hand.', 'octave-addons' ),
					] );

				},
			] ); ?>

			<?php Octave_Addons_Fields::row( [
				'id'    => 'oaMdRowPostTypes',
				'label' => __( 'Post types', 'octave-addons' ),
				'field' => function () use ( $s ) {

					$this->render_post_types( $s );

				},
			] ); ?>

			<?php Octave_Addons_Fields::row( [
				'label' => __( 'Archives and search', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'id'      => $this->field_id( 'archives' ),
						'name'    => $this->field_name( 'archives' ),
						'checked' => ! empty( $s['archives'] ),
						'help'    => __( 'Answer listings with a link index — one line per entry with its excerpt — rather than a conversion of the card layout.', 'octave-addons' ),
					] );

				},
			] ); ?>

			<?php Octave_Addons_Fields::section( [ 'label' => __( 'Document', 'octave-addons' ) ] ); ?>

			<?php Octave_Addons_Fields::row( [
				'label' => __( 'YAML frontmatter', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'id'      => $this->field_id( 'frontmatter' ),
						'name'    => $this->field_name( 'frontmatter' ),
						'checked' => ! empty( $s['frontmatter'] ),
						'help'    => __( 'Open each document with the title, description, canonical URL, dates, author, image and terms.', 'octave-addons' ),
					] );

				},
			] ); ?>

			<?php Octave_Addons_Fields::row( [
				'label' => __( 'Structured data', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'id'      => $this->field_id( 'jsonld' ),
						'name'    => $this->field_name( 'jsonld' ),
						'checked' => ! empty( $s['jsonld'] ),
						'help'    => __( 'Carry any JSON-LD the page publishes through to the end of the document in a fenced code block.', 'octave-addons' ),
					] );

				},
			] ); ?>

			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'selector' ),
				'label' => __( 'Content container', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::text( [
						'id'          => $this->field_id( 'selector' ),
						'name'        => $this->field_name( 'selector' ),
						'value'       => $s['selector'],
						'placeholder' => '.entry-content',
						'help'        => __( 'Optional. A CSS selector naming the element holding the page content — a tag, an id or a class. Leave it empty to use main, article and the other standard landmarks, which is right for most themes.', 'octave-addons' ),
					] );

				},
			] ); ?>

			<?php Octave_Addons_Fields::section( [ 'label' => __( 'Response headers', 'octave-addons' ) ] ); ?>

			<?php Octave_Addons_Fields::row( [
				'label' => __( 'Vary on HTML responses', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'id'      => $this->field_id( 'vary_html' ),
						'name'    => $this->field_name( 'vary_html' ),
						'checked' => ! empty( $s['vary_html'] ),
						'help'    => __( 'Adds Accept to the Vary header so a CDN keeps the HTML and Markdown versions apart. Leave this on unless a cache in front of the site handles it already.', 'octave-addons' ),
					] );

				},
			] ); ?>

			<?php Octave_Addons_Fields::row( [
				'label' => __( 'Token count headers', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'id'      => $this->field_id( 'token_headers' ),
						'name'    => $this->field_name( 'token_headers' ),
						'checked' => ! empty( $s['token_headers'] ),
						'help'    => __( 'Send x-markdown-tokens and x-original-tokens so an agent can see what the conversion saved it.', 'octave-addons' ),
					] );

				},
			] ); ?>

			<?php Octave_Addons_Fields::row( [
				'label' => __( 'Alternate link', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'id'      => $this->field_id( 'alternate_link' ),
						'name'    => $this->field_name( 'alternate_link' ),
						'checked' => ! empty( $s['alternate_link'] ),
						'help'    => __( 'Advertise the Markdown representation in the page head, so a crawler reading the HTML learns the same URL will answer in Markdown.', 'octave-addons' ),
					] );

				},
			] ); ?>

			<?php Octave_Addons_Fields::row( [
				'label' => __( 'Content signal', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'id'      => $this->field_id( 'content_signal' ),
						'name'    => $this->field_name( 'content_signal' ),
						'checked' => ! empty( $s['content_signal'] ),
						'data'    => [ 'controls-row' => 'oaMdRowSignals' ],
						'help'    => __( 'State what the content may be used for on every Markdown response. It is a declaration of preference, not an enforcement.', 'octave-addons' ),
					] );

				},
			] ); ?>

			<?php Octave_Addons_Fields::row( [
				'id'    => 'oaMdRowSignals',
				'label' => __( 'Permitted uses', 'octave-addons' ),
				'field' => function () use ( $s ) {

					$this->render_signals( $s );

				},
			] ); ?>

			<?php Octave_Addons_Fields::section( [ 'label' => __( 'Performance', 'octave-addons' ) ] ); ?>

			<?php Octave_Addons_Fields::row( [
				'label' => __( 'Cache converted pages', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'id'      => $this->field_id( 'cache' ),
						'name'    => $this->field_name( 'cache' ),
						'checked' => ! empty( $s['cache'] ),
						'help'    => __( 'Keep each converted document for twelve hours for logged-out requests. Publishing, updating or deleting anything clears the lot.', 'octave-addons' ),
					] );

				},
			] ); ?>

			<?php Octave_Addons_Fields::row( [
				'label' => __( 'Editor preview', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'id'      => $this->field_id( 'preview' ),
						'name'    => $this->field_name( 'preview' ),
						'checked' => ! empty( $s['preview'] ),
						'help'    => __( 'Let anyone who can edit posts add ?format=markdown to a URL to read the Markdown in a browser. Logged-out visitors never get it, so it stays one public URL per page.', 'octave-addons' ),
					] );

				},
			] ); ?>

		</table>

		<?php

	}

	/*
	RENDER POST TYPES
	-- The hand-picked list, shown only while the catch-all above is off.
	---------------------------------------------------------------------------- */

	protected function render_post_types( array $s ): void {

		$chosen = (array) ( $s['post_types'] ?? [] );
		$types  = get_post_types( [ 'public' => true ], 'objects' );

		?>

		<div class="oa-assignment-grid">
			<?php

			foreach ( $types as $type ) {

				if ( ! is_post_type_viewable( $type ) ) {

					continue;

				}

				$label = isset( $type->labels->name ) ? $type->labels->name : $type->name;

				?>

				<label class="oa-assignment-option">
					<input type="checkbox"
					       name="<?= esc_attr( $this->field_name( 'post_types' ) ); ?>[]"
					       value="<?= esc_attr( $type->name ); ?>"<?php checked( in_array( $type->name, $chosen, true ) ); ?>>
					<span class="oa-assignment-check" aria-hidden="true"></span>
					<span class="oa-assignment-copy"><strong><?= esc_html( $label ); ?></strong><small><?= esc_html( $type->name ); ?></small></span>
				</label>

				<?php

			}

			?>
		</div>

		<?php

	}

	/*
	RENDER SIGNALS
	-- The three uses the content-signal header covers.
	---------------------------------------------------------------------------- */

	protected function render_signals( array $s ): void {

		$signals = [
			'signal_search'   => __( 'Search indexing', 'octave-addons' ),
			'signal_ai_input' => __( 'AI input — answering with the page and citing it', 'octave-addons' ),
			'signal_ai_train' => __( 'AI training', 'octave-addons' ),
		];

		?>

		<div class="oa-assignment-grid">
			<?php

			foreach ( $signals as $key => $label ) {

				?>

				<label class="oa-assignment-option">
					<input type="checkbox"
					       name="<?= esc_attr( $this->field_name( $key ) ); ?>"
					       value="1"<?php checked( ! empty( $s[ $key ] ) ); ?>>
					<span class="oa-assignment-check" aria-hidden="true"></span>
					<span class="oa-assignment-copy"><strong><?= esc_html( $label ); ?></strong></span>
				</label>

				<?php

			}

			?>
		</div>

		<?php

	}

}

return new Octave_Addons_Module_Markdown_Negotiation();
