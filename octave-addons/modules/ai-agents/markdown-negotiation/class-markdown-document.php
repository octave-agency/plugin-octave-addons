<?php

/*
MARKDOWN DOCUMENT
-- Assembles the Markdown an agent receives: YAML frontmatter carrying the
-- page metadata, the body, and any JSON-LD the page published, kept in a
-- fenced block so the structured data survives the conversion.
-- A single entry is built from the rendered HTML, because that is the only
-- representation that contains what a builder, a shortcode or a block
-- actually produced. A listing is built from the query instead, since what
-- an agent wants from an archive is the index, not the card markup.
---------------------------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Markdown_Document {


	/** Words kept when an entry has to have its excerpt generated. */
	protected const EXCERPT_WORDS = 40;

	protected array $settings;

	public function __construct( array $settings ) {

		$this->settings = $settings;

	}

	/*
	SINGULAR
	-- Builds the document for one entry out of the HTML the theme rendered.
	---------------------------------------------------------------------------- */

	public function singular( WP_Post $post, string $html, string $url ): string {

		$parser = new Octave_Addons_Html_To_Markdown( $html, $url );
		$body   = $parser->is_parsed() ? $parser->markdown( $this->content_selectors() ) : '';
		$title  = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );

		$description = $parser->is_parsed() ? $parser->meta_description() : '';

		if ( '' === $description ) {

			$description = $this->excerpt_for( $post );

		}

		$image = (string) get_the_post_thumbnail_url( $post, 'full' );

		if ( '' === $image && $parser->is_parsed() ) {

			$image = $parser->main_image();

		}

		$fields = [
			'title'       => $title,
			'description' => $description,
			'url'         => $url,
			'type'        => $post->post_type,
			'published'   => (string) get_post_time( 'c', true, $post ),
			'modified'    => (string) get_post_modified_time( 'c', true, $post ),
			'author'      => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
			'image'       => $image,
			'categories'  => $this->term_names( $post, 'category' ),
			'tags'        => $this->term_names( $post, 'post_tag' ),
		];

		$json_ld = $parser->is_parsed() ? $parser->json_ld() : [];

		return $this->assemble( $fields, $this->with_heading( $body, $title ), $json_ld );

	}

	/*
	INDEX
	-- Builds the document for a listing: the archive's own name and copy, then
	-- one line per entry. Pagination is stated in words as well as in the
	-- frontmatter, because an agent reading the body alone still needs to know
	-- there is more of the archive than the page it is holding.
	---------------------------------------------------------------------------- */

	public function index( WP_Query $query, string $url ): string {

		$title       = $this->index_title();
		$description = $this->index_description();

		$page  = max( 1, (int) $query->get( 'paged' ) );
		$pages = max( 1, (int) $query->max_num_pages );

		$fields = [
			'title'       => $title,
			'description' => $description,
			'url'         => $url,
			'type'        => 'index',
			'page'        => $page,
			'pages'       => $pages,
			'results'     => (int) $query->found_posts,
		];

		$body = $this->with_heading( '', $title );

		if ( '' !== $description ) {

			$body .= "\n\n" . $this->escape_inline( $description );

		}

		$items = [];

		foreach ( $query->posts as $post ) {

			if ( ! $post instanceof WP_Post ) {

				continue;

			}

			$entry_title = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );
			$entry_title = '' !== trim( $entry_title ) ? $entry_title : __( '(untitled)', 'octave-addons' );
			$excerpt     = $this->excerpt_for( $post );

			$line = '- [' . $this->escape_inline( $entry_title ) . '](' . get_permalink( $post ) . ')';

			if ( '' !== $excerpt ) {

				$line .= ' — ' . $this->escape_inline( $excerpt );

			}

			$items[] = $line;

		}

		if ( $items ) {

			$body .= "\n\n" . implode( "\n", $items );

		} else {

			$body .= "\n\n" . __( 'No entries were found.', 'octave-addons' );

		}

		if ( $pages > 1 ) {

			$body .= "\n\n" . sprintf(
				/* translators: 1: current page number, 2: total number of pages. */
				esc_html__( 'Page %1$d of %2$d.', 'octave-addons' ),
				$page,
				$pages
			);

			$next = $page < $pages ? get_pagenum_link( $page + 1 ) : '';

			if ( '' !== $next ) {

				$body .= ' ' . sprintf(
					/* translators: %s: URL of the next page of results. */
					esc_html__( 'Next page: %s', 'octave-addons' ),
					$next
				);

			}

		}

		return $this->assemble( $fields, $body, [] );

	}

	/*
	NOT FOUND
	-- A 404 still gets a well-formed document, so an agent reads a stated
	-- absence rather than having to infer one from an empty body.
	---------------------------------------------------------------------------- */

	public function not_found( string $url ): string {

		$title = __( 'Not found', 'octave-addons' );

		$fields = [
			'title' => $title,
			'url'   => $url,
			'type'  => 'error',
			'error' => 404,
		];

		return $this->assemble(
			$fields,
			'# ' . $title . "\n\n" . __( 'No content exists at this address.', 'octave-addons' ),
			[]
		);

	}

	/*
	PASSWORD PROTECTED
	-- The metadata of a protected entry is public, its content is not, so the
	-- document states the restriction and stops there.
	---------------------------------------------------------------------------- */

	public function password_protected( WP_Post $post, string $url ): string {

		$title = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );

		$fields = [
			'title'     => $title,
			'url'       => $url,
			'type'      => $post->post_type,
			'protected' => 'true',
		];

		return $this->assemble(
			$fields,
			'# ' . $this->escape_inline( $title ) . "\n\n" . __( 'This entry is password protected. Its content is only available to a reader holding the password.', 'octave-addons' ),
			[]
		);

	}

	/*
	ASSEMBLE
	-- Joins the three parts of the document, each one switchable from the
	-- module settings.
	---------------------------------------------------------------------------- */

	protected function assemble( array $fields, string $body, array $json_ld ): string {

		$document = '';

		if ( ! empty( $this->settings['frontmatter'] ) ) {

			$document .= $this->frontmatter( $fields ) . "\n";

		}

		$document .= trim( $body );

		if ( ! empty( $this->settings['jsonld'] ) && $json_ld ) {

			$document .= "\n\n## " . __( 'Structured data', 'octave-addons' ) . "\n";

			foreach ( $json_ld as $block ) {

				$document .= "\n```json\n" . $block . "\n```\n";

			}

		}

		/**
		 * The finished Markdown document, immediately before it is sent.
		 *
		 * @param string $document  Markdown source.
		 * @param array  $fields    Frontmatter fields the document was built with.
		 */
		$document = (string) apply_filters( 'octave_addons_markdown_document', trim( $document ) . "\n", $fields );

		return $document;

	}

	/*
	FRONTMATTER
	-- Writes the YAML block. Empty values are dropped rather than emitted as
	-- blanks, so a consumer can treat a present key as a real value.
	---------------------------------------------------------------------------- */

	protected function frontmatter( array $fields ): string {

		$lines = [ '---' ];

		foreach ( $fields as $key => $value ) {

			if ( is_array( $value ) ) {

				if ( ! $value ) {

					continue;

				}

				$lines[] = $key . ': [' . implode( ', ', array_map( [ $this, 'yaml_scalar' ], $value ) ) . ']';

				continue;

			}

			if ( is_int( $value ) ) {

				$lines[] = $key . ': ' . $value;

				continue;

			}

			$value = trim( (string) $value );

			if ( '' === $value ) {

				continue;

			}

			$lines[] = $key . ': ' . $this->yaml_scalar( $value );

		}

		$lines[] = '---';

		return implode( "\n", $lines ) . "\n";

	}

	/*
	YAML SCALAR
	-- Every string is double-quoted. Quoting unconditionally is what keeps a
	-- title reading "yes", "12:30" or "- draft" from being parsed as a
	-- boolean, a sexagesimal or a list item.
	---------------------------------------------------------------------------- */

	public function yaml_scalar( $value ): string {

		if ( is_int( $value ) || is_float( $value ) ) {

			return (string) $value;

		}

		$value = (string) preg_replace( '/\s+/u', ' ', (string) $value );
		$value = str_replace( [ '\\', '"' ], [ '\\\\', '\\"' ], trim( $value ) );

		return '"' . $value . '"';

	}

	/*
	WITH HEADING
	-- Themes usually render the entry title as the first heading inside the
	-- content region, so a heading is only added when the body did not already
	-- open with one. That keeps every document to a single H1.
	---------------------------------------------------------------------------- */

	protected function with_heading( string $body, string $title ): string {

		$body  = trim( $body );
		$title = trim( $title );

		if ( '' === $title ) {

			return $body;

		}

		if ( '' !== $body && preg_match( '/^#{1,6}\s/', $body ) ) {

			return $body;

		}

		$heading = '# ' . $this->escape_inline( $title );

		return '' !== $body ? $heading . "\n\n" . $body : $heading;

	}

	/*
	ESCAPE INLINE
	-- Neutralises the Markdown syntax characters that can appear in a title or
	-- an excerpt coming from the database.
	---------------------------------------------------------------------------- */

	protected function escape_inline( string $text ): string {

		return str_replace(
			[ '\\', '`', '*', '_', '[', ']' ],
			[ '\\\\', '\\`', '\\*', '\\_', '\\[', '\\]' ],
			trim( (string) preg_replace( '/\s+/u', ' ', $text ) )
		);

	}

	/*
	EXCERPT FOR
	-- The hand-written excerpt when there is one, otherwise a trimmed run of
	-- the post content. A page built entirely in a builder stores nothing in
	-- post_content, so this legitimately comes back empty and the caller is
	-- expected to cope with that rather than substitute filler.
	---------------------------------------------------------------------------- */

	protected function excerpt_for( WP_Post $post ): string {

		if ( post_password_required( $post ) ) {

			return '';

		}

		$excerpt = trim( (string) $post->post_excerpt );

		if ( '' === $excerpt ) {

			$excerpt = wp_trim_words(
				wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ),
				self::EXCERPT_WORDS,
				'…'
			);

		}

		$excerpt = html_entity_decode( wp_strip_all_tags( $excerpt ), ENT_QUOTES, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $excerpt ) );

	}

	/*
	TERM NAMES
	-- Flat list of term names for a taxonomy, or an empty array when the
	-- taxonomy does not apply to this post type.
	---------------------------------------------------------------------------- */

	protected function term_names( WP_Post $post, string $taxonomy ): array {

		if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {

			return [];

		}

		$terms = get_the_terms( $post, $taxonomy );

		if ( ! is_array( $terms ) ) {

			return [];

		}

		return array_values( wp_list_pluck( $terms, 'name' ) );

	}

	/*
	INDEX TITLE
	-- Names the listing using the same source WordPress would have used for
	-- the archive heading, falling back to the site name for the blog index.
	---------------------------------------------------------------------------- */

	protected function index_title(): string {

		if ( is_search() ) {

			return sprintf(
				/* translators: %s: search term. */
				esc_html__( 'Search results for %s', 'octave-addons' ),
				get_search_query()
			);

		}

		$title = wp_strip_all_tags( (string) get_the_archive_title() );

		if ( '' !== trim( $title ) ) {

			return html_entity_decode( $title, ENT_QUOTES, 'UTF-8' );

		}

		$page_for_posts = (int) get_option( 'page_for_posts' );

		if ( is_home() && $page_for_posts ) {

			return html_entity_decode( wp_strip_all_tags( get_the_title( $page_for_posts ) ), ENT_QUOTES, 'UTF-8' );

		}

		return html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' );

	}

	/*
	INDEX DESCRIPTION
	-- The term or post type description where the archive has one, and the
	-- site tagline for the blog index.
	---------------------------------------------------------------------------- */

	protected function index_description(): string {

		$description = wp_strip_all_tags( (string) get_the_archive_description() );

		if ( '' === trim( $description ) && is_home() ) {

			$description = (string) get_bloginfo( 'description' );

		}

		return html_entity_decode( trim( (string) preg_replace( '/\s+/u', ' ', $description ) ), ENT_QUOTES, 'UTF-8' );

	}

	/*
	CONTENT SELECTORS
	-- The extra XPath expression built from the module's content container
	-- setting, tried ahead of the standard landmarks.
	---------------------------------------------------------------------------- */

	protected function content_selectors(): array {

		$selector = trim( (string) ( $this->settings['selector'] ?? '' ) );

		if ( '' === $selector ) {

			return [];

		}

		$expressions = [];

		foreach ( explode( ',', $selector ) as $candidate ) {

			$xpath = $this->css_to_xpath( trim( $candidate ) );

			if ( '' !== $xpath ) {

				$expressions[] = $xpath;

			}

		}

		return $expressions;

	}

	/*
	CSS TO XPATH
	-- Translates the small slice of CSS a content container is ever written
	-- in — a tag, an id, one or more classes, and descendant combinators —
	-- into XPath. Anything more elaborate is rejected rather than half
	-- understood, so a typo falls back to the standard landmarks instead of
	-- silently matching the wrong element.
	---------------------------------------------------------------------------- */

	protected function css_to_xpath( string $selector ): string {

		if ( '' === $selector ) {

			return '';

		}

		$xpath = '';

		foreach ( preg_split( '/\s+/', $selector ) as $part ) {

			if ( ! preg_match( '/^([a-zA-Z][\w-]*)?((?:[#.][\w-]+)*)$/', $part, $match ) ) {

				return '';

			}

			$tag       = '' !== ( $match[1] ?? '' ) ? strtolower( $match[1] ) : '*';
			$predicate = '';

			preg_match_all( '/([#.])([\w-]+)/', $match[2] ?? '', $tokens, PREG_SET_ORDER );

			foreach ( $tokens as $token ) {

				$predicate .= '#' === $token[1]
					? '[@id="' . $token[2] . '"]'
					: '[contains(concat(" ", normalize-space(@class), " "), " ' . $token[2] . ' ")]';

			}

			if ( '*' === $tag && '' === $predicate ) {

				return '';

			}

			$xpath .= '//' . $tag . $predicate;

		}

		return $xpath;

	}

}
