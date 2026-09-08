<?php

/*
HTML TO MARKDOWN
-- Parses a rendered HTML page once and reads three things out of it: the
-- document metadata an agent wants in frontmatter, any JSON-LD the page
-- carries, and a Markdown rendering of the main content region.
-- Working from rendered HTML rather than from post_content is deliberate —
-- it means page builders, shortcodes, blocks and template parts all convert,
-- because whatever a browser would have been shown is what gets converted.
-- Nothing here is Octave or Breakdance specific.
---------------------------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Html_To_Markdown {


	/** Elements that always open a new block in the Markdown output. */
	protected const BLOCK_TAGS = [
		'address', 'article', 'aside', 'blockquote', 'details', 'div', 'dl', 'dd', 'dt',
		'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4',
		'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section',
		'summary', 'table', 'ul',
	];

	/** Elements removed from every page, whatever the content root turned out to be. */
	protected const DROP_TAGS = [
		'script', 'style', 'noscript', 'template', 'svg', 'canvas', 'object', 'embed',
		'audio', 'video', 'select', 'textarea', 'button', 'input', 'dialog', 'link', 'meta',
	];

	/** Landmarks removed only when the whole body had to serve as the content root. */
	protected const LANDMARK_TAGS = [ 'nav', 'header', 'footer', 'aside', 'form' ];

	/** Class names used for content that is present for assistive tech alone. */
	protected const HIDDEN_CLASSES = [ 'screen-reader-text', 'sr-only', 'visually-hidden', 'skip-link' ];

	protected ?DOMDocument $doc = null;

	protected ?DOMXPath $xpath = null;

	protected string $base_url = '';

	/** True once the content root has been located and cleaned. */
	protected bool $prepared = false;

	protected ?DOMNode $root = null;

	/*
	CONSTRUCT
	-- Loads the document up front so metadata and body share one parse.
	-- The XML declaration is prepended because libxml otherwise guesses the
	-- encoding from the first bytes and mangles anything non-ASCII.
	---------------------------------------------------------------------------- */

	public function __construct( string $html, string $base_url = '' ) {

		$this->base_url = $base_url;

		if ( '' === trim( $html ) ) {

			return;

		}

		$previous = libxml_use_internal_errors( true );

		$doc = new DOMDocument();

		if ( $doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET ) ) {

			$this->doc   = $doc;
			$this->xpath = new DOMXPath( $doc );

		}

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

	}

	/*
	IS PARSED
	-- Whether the document loaded well enough to read anything out of it.
	---------------------------------------------------------------------------- */

	public function is_parsed(): bool {

		return null !== $this->doc;

	}

	/*
	DOCUMENT TITLE
	-- Prefers the Open Graph title, because a theme usually appends the site
	-- name to <title> while og:title carries the page's own name.
	---------------------------------------------------------------------------- */

	public function document_title(): string {

		$og = $this->meta_property( 'og:title' );

		if ( '' !== $og ) {

			return $og;

		}

		$nodes = $this->query( '//title' );

		return $nodes && $nodes->length ? $this->clean_text( $nodes->item( 0 )->textContent ) : '';

	}

	/*
	META DESCRIPTION
	-- Falls back through the two names a description is normally published
	-- under, so an SEO plugin's output is picked up either way.
	---------------------------------------------------------------------------- */

	public function meta_description(): string {

		$description = $this->meta_name( 'description' );

		return '' !== $description ? $description : $this->meta_property( 'og:description' );

	}

	/*
	MAIN IMAGE
	-- The page's own social image, already absolute in practice but resolved
	-- anyway so a relative og:image still leaves as a usable URL.
	---------------------------------------------------------------------------- */

	public function main_image(): string {

		$image = $this->meta_property( 'og:image' );

		return '' !== $image ? $this->absolute_url( $image ) : '';

	}

	/*
	JSON LD
	-- Returns every structured data block on the page, re-encoded so the
	-- Markdown carries readable JSON rather than a minified single line.
	-- Blocks that do not parse are passed through untouched rather than
	-- dropped, since malformed JSON-LD is still information about the page.
	---------------------------------------------------------------------------- */

	public function json_ld(): array {

		$nodes = $this->query( '//script[@type="application/ld+json"]' );

		if ( ! $nodes ) {

			return [];

		}

		$blocks = [];

		foreach ( $nodes as $node ) {

			$raw = trim( $node->textContent );

			if ( '' === $raw ) {

				continue;

			}

			$decoded = json_decode( $raw, true );

			if ( null === $decoded ) {

				$blocks[] = $raw;

				continue;

			}

			$blocks[] = (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		}

		return $blocks;

	}

	/*
	MARKDOWN
	-- Converts the content root to Markdown. The selector list is tried in
	-- order and the first hit wins, so a site can name its own container
	-- without losing the standard landmarks as a fallback.
	---------------------------------------------------------------------------- */

	public function markdown( array $selectors = [] ): string {

		$this->prepare( $selectors );

		if ( ! $this->root ) {

			return '';

		}

		return $this->normalize( $this->render_children( $this->root ) );

	}

	/*
	PREPARE
	-- Locates the content root and strips everything that is furniture rather
	-- than content. Landmarks survive only inside a root that is itself a
	-- content landmark, because a <header> inside a located <article> belongs
	-- to that article. A root found by id or class carries no such promise —
	-- a builder wrapper can hold the site header too — so there they go.
	---------------------------------------------------------------------------- */

	protected function prepare( array $selectors ): void {

		if ( $this->prepared || ! $this->doc ) {

			return;

		}

		$this->prepared = true;

		$body = $this->query( '//body' );
		$body = $body && $body->length ? $body->item( 0 ) : null;

		$root       = null;
		$candidates = array_merge( $selectors, $this->default_selectors() );

		foreach ( $candidates as $selector ) {

			$found = $this->query( $selector );

			if ( $found && $found->length ) {

				$root = $found->item( 0 );

				break;

			}

		}

		$this->root = $root ?: $body;

		if ( ! $this->root ) {

			return;

		}

		$this->unwrap_embeds();
		$this->drop( self::DROP_TAGS );
		$this->drop_hidden();

		if ( ! $this->is_content_landmark( $this->root ) ) {

			$this->drop( self::LANDMARK_TAGS );

		}

	}

	/*
	IS CONTENT LANDMARK
	-- Whether an element declares itself to be the page's content region, as
	-- opposed to having merely been recognised by its id or class.
	---------------------------------------------------------------------------- */

	protected function is_content_landmark( DOMNode $node ): bool {

		if ( XML_ELEMENT_NODE !== $node->nodeType ) {

			return false;

		}

		if ( in_array( strtolower( $node->nodeName ), [ 'main', 'article' ], true ) ) {

			return true;

		}

		return 'main' === strtolower( (string) $node->getAttribute( 'role' ) );

	}

	/*
	DEFAULT SELECTORS
	-- The landmarks a content region is normally published under, ordered from
	-- the most explicit to the loosest. Filterable so a site with an unusual
	-- template can add its own container without editing the plugin.
	---------------------------------------------------------------------------- */

	protected function default_selectors(): array {

		$selectors = [
			'//main',
			'//*[@role="main"]',
			'//article',
			'//*[@id="content"]',
			'//*[@id="main"]',
			'//*[@id="primary"]',
			$this->class_selector( 'entry-content' ),
			$this->class_selector( 'breakdance' ),
		];

		/**
		 * XPath expressions used to find the content region of a page.
		 *
		 * @param string[] $selectors  Ordered list, first match wins.
		 */
		$selectors = apply_filters( 'octave_addons_markdown_content_xpath', $selectors );

		return is_array( $selectors ) ? $selectors : [];

	}

	/*
	UNWRAP EMBEDS
	-- An iframe carries nothing convertible, but the thing it points at is
	-- often the point of the page, so each one is left behind as a link
	-- rather than silently disappearing with the rest of the furniture.
	---------------------------------------------------------------------------- */

	protected function unwrap_embeds(): void {

		$nodes = $this->query( './/iframe[@src]', $this->root );

		if ( ! $nodes ) {

			return;

		}

		foreach ( iterator_to_array( $nodes ) as $node ) {

			if ( ! $node->parentNode ) {

				continue;

			}

			$src   = $this->absolute_url( (string) $node->getAttribute( 'src' ) );
			$title = trim( (string) $node->getAttribute( 'title' ) );

			if ( '' === $src ) {

				$node->parentNode->removeChild( $node );

				continue;

			}

			$paragraph = $this->doc->createElement( 'p' );
			$link      = $this->doc->createElement( 'a' );

			$link->setAttribute( 'href', $src );
			$link->appendChild( $this->doc->createTextNode( '' !== $title ? $title : __( 'Embedded content', 'octave-addons' ) ) );
			$paragraph->appendChild( $link );

			$node->parentNode->replaceChild( $paragraph, $node );

		}

	}

	/*
	DROP
	-- Removes every element with one of the given tag names from the root.
	---------------------------------------------------------------------------- */

	protected function drop( array $tags ): void {

		foreach ( $tags as $tag ) {

			$nodes = $this->query( './/' . $tag, $this->root );

			if ( ! $nodes ) {

				continue;

			}

			foreach ( iterator_to_array( $nodes ) as $node ) {

				if ( $node->parentNode ) {

					$node->parentNode->removeChild( $node );

				}

			}

		}

	}

	/*
	DROP HIDDEN
	-- Removes anything a browser would not have shown: aria-hidden branches,
	-- the hidden attribute, inline display:none, and the class names themes
	-- use to park text for screen readers alone.
	---------------------------------------------------------------------------- */

	protected function drop_hidden(): void {

		$expressions = [
			'.//*[@aria-hidden="true"]',
			'.//*[@hidden]',
			'.//*[contains(translate(@style, " ", ""), "display:none")]',
			'.//*[@id="wpadminbar"]',
		];

		foreach ( self::HIDDEN_CLASSES as $class ) {

			$expressions[] = '.' . $this->class_selector( $class );

		}

		foreach ( $expressions as $expression ) {

			$nodes = $this->query( $expression, $this->root );

			if ( ! $nodes ) {

				continue;

			}

			foreach ( iterator_to_array( $nodes ) as $node ) {

				if ( $node->parentNode ) {

					$node->parentNode->removeChild( $node );

				}

			}

		}

	}

	/*
	RENDER CHILDREN
	-- Walks a node's children, gathering inline runs into paragraphs and
	-- handing block elements to render_block. Inside a list item a nested list
	-- is joined to the line above it with a single newline, which is what
	-- keeps a nested list attached to its parent item instead of splitting the
	-- list into two.
	---------------------------------------------------------------------------- */

	protected function render_children( DOMNode $node ): string {

		$blocks = [];
		$inline = '';
		$tighten = 'li' === strtolower( $node->nodeName );

		foreach ( $node->childNodes as $child ) {

			if ( ! $this->is_block( $child ) ) {

				$inline .= $this->render_inline( $child );

				continue;

			}

			if ( '' !== trim( $inline ) ) {

				$blocks[] = [ 'type' => 'text', 'text' => trim( $inline ) ];

			}

			$inline = '';
			$text   = $this->render_block( $child );

			if ( '' !== trim( $text ) ) {

				$blocks[] = [ 'type' => $this->block_type( $child ), 'text' => $text ];

			}

		}

		if ( '' !== trim( $inline ) ) {

			$blocks[] = [ 'type' => 'text', 'text' => trim( $inline ) ];

		}

		$out = '';

		foreach ( $blocks as $index => $block ) {

			if ( 0 === $index ) {

				$out = $block['text'];

				continue;

			}

			$tight = $tighten && 'list' === $block['type'] && 'text' === $blocks[ $index - 1 ]['type'];

			$out .= ( $tight ? "\n" : "\n\n" ) . $block['text'];

		}

		return $out;

	}

	/*
	BLOCK TYPE
	-- Names a block for the joining rule above. Only lists need telling apart.
	---------------------------------------------------------------------------- */

	protected function block_type( DOMNode $node ): string {

		return in_array( strtolower( $node->nodeName ), [ 'ul', 'ol' ], true ) ? 'list' : 'text';

	}

	/*
	RENDER BLOCK
	-- Turns one block-level element into its Markdown equivalent. Anything
	-- without a Markdown counterpart — a div, a section, a wrapper a builder
	-- emitted — falls through to its children, so layout markup disappears
	-- while the content inside it survives.
	---------------------------------------------------------------------------- */

	protected function render_block( DOMNode $node ): string {

		$tag = strtolower( $node->nodeName );

		switch ( $tag ) {

			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$text = trim( $this->render_inline_children( $node ) );

				return '' !== $text ? str_repeat( '#', (int) substr( $tag, 1 ) ) . ' ' . $text : '';

			case 'hr':
				return '---';

			case 'br':
				return '';

			case 'pre':
				return $this->render_pre( $node );

			case 'blockquote':
				return $this->prefix_lines( $this->render_children( $node ), '> ' );

			case 'ul':
			case 'ol':
				return $this->render_list( $node );

			case 'table':
				return $this->render_table( $node );

			case 'figcaption':
			case 'summary':
				$text = trim( $this->render_inline_children( $node ) );

				return '' !== $text ? '**' . $text . '**' : '';

			case 'dt':
				$text = trim( $this->render_inline_children( $node ) );

				return '' !== $text ? '**' . $text . '**' : '';

			case 'p':
			case 'dd':
				return trim( $this->render_inline_children( $node ) );

			default:
				return $this->render_children( $node );

		}

	}

	/*
	RENDER PRE
	-- Code keeps its own whitespace, so the text content is taken raw. The
	-- fence is widened past any run of backticks inside the block, which is
	-- what stops a snippet containing a fence from closing its own block.
	---------------------------------------------------------------------------- */

	protected function render_pre( DOMNode $node ): string {

		$code = rtrim( (string) $node->textContent );

		if ( '' === trim( $code ) ) {

			return '';

		}

		$language = '';
		$child    = $this->query( './/code', $node );

		if ( $child && $child->length ) {

			$classes = (string) $child->item( 0 )->getAttribute( 'class' );

			if ( preg_match( '/(?:language|lang|brush)[-:]([\w+#-]+)/i', $classes, $match ) ) {

				$language = strtolower( $match[1] );

			}

		}

		$fence = '```';

		if ( preg_match_all( '/`{3,}/', $code, $runs ) ) {

			$longest = max( array_map( 'strlen', $runs[0] ) );
			$fence   = str_repeat( '`', $longest + 1 );

		}

		return $fence . $language . "\n" . $code . "\n" . $fence;

	}

	/*
	RENDER LIST
	-- Renders one list, indenting continuation lines to the width of their own
	-- marker so nested lists and multi-paragraph items stay inside their item.
	---------------------------------------------------------------------------- */

	protected function render_list( DOMNode $node ): string {

		$ordered = 'ol' === strtolower( $node->nodeName );
		$number  = max( 1, (int) $node->getAttribute( 'start' ) );
		$items   = [];

		foreach ( $node->childNodes as $child ) {

			if ( XML_ELEMENT_NODE !== $child->nodeType || 'li' !== strtolower( $child->nodeName ) ) {

				continue;

			}

			$content = trim( $this->render_children( $child ) );

			if ( '' === $content ) {

				$number++;

				continue;

			}

			$marker = $ordered ? $number . '. ' : '- ';
			$pad    = str_repeat( ' ', strlen( $marker ) );
			$lines  = explode( "\n", $content );
			$first  = array_shift( $lines );
			$item   = $marker . $first;

			foreach ( $lines as $line ) {

				$item .= "\n" . ( '' === $line ? '' : $pad . $line );

			}

			$items[] = $item;
			$number++;

		}

		return implode( "\n", $items );

	}

	/*
	RENDER TABLE
	-- Produces a GitHub-flavoured pipe table. A table without a header row
	-- still gets one, because the delimiter row is what makes the rest parse
	-- as a table at all.
	---------------------------------------------------------------------------- */

	protected function render_table( DOMNode $node ): string {

		$rows  = [];
		$cells = $this->query( './/tr', $node );

		if ( ! $cells ) {

			return '';

		}

		foreach ( $cells as $row ) {

			$line = [];

			foreach ( $row->childNodes as $cell ) {

				if ( XML_ELEMENT_NODE !== $cell->nodeType || ! in_array( strtolower( $cell->nodeName ), [ 'td', 'th' ], true ) ) {

					continue;

				}

				$text = trim( $this->render_inline_children( $cell ) );
				$text = str_replace( [ "\n", '|' ], [ ' ', '\|' ], $text );

				$line[] = $text;

			}

			if ( $line ) {

				$rows[] = $line;

			}

		}

		if ( ! $rows ) {

			return '';

		}

		$width = max( array_map( 'count', $rows ) );

		foreach ( $rows as $index => $row ) {

			$rows[ $index ] = array_pad( $row, $width, '' );

		}

		$header    = array_shift( $rows );
		$delimiter = array_fill( 0, $width, '---' );

		$lines = [
			'| ' . implode( ' | ', $header ) . ' |',
			'| ' . implode( ' | ', $delimiter ) . ' |',
		];

		foreach ( $rows as $row ) {

			$lines[] = '| ' . implode( ' | ', $row ) . ' |';

		}

		return implode( "\n", $lines );

	}

	/*
	RENDER INLINE CHILDREN
	-- Flattens a node's children to inline Markdown, used where a block can
	-- only hold a single line: headings, table cells, paragraphs.
	---------------------------------------------------------------------------- */

	protected function render_inline_children( DOMNode $node ): string {

		$out = '';

		foreach ( $node->childNodes as $child ) {

			$out .= $this->render_inline( $child );

		}

		return $out;

	}

	/*
	RENDER INLINE
	-- Converts one inline node. Emphasis wrappers holding nothing but spaces
	-- are dropped rather than emitted, because ** ** is not emphasis in any
	-- Markdown parser and shows up as literal asterisks.
	---------------------------------------------------------------------------- */

	protected function render_inline( DOMNode $node ): string {

		if ( XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType ) {

			return $this->escape( $this->collapse( $node->nodeValue ) );

		}

		if ( XML_ELEMENT_NODE !== $node->nodeType ) {

			return '';

		}

		$tag = strtolower( $node->nodeName );

		if ( 'br' === $tag ) {

			return "\\\n";

		}

		if ( 'img' === $tag ) {

			return $this->render_image( $node );

		}

		if ( 'a' === $tag ) {

			return $this->render_link( $node );

		}

		if ( $this->is_block( $node ) ) {

			return ' ' . trim( str_replace( "\n", ' ', $this->render_block( $node ) ) ) . ' ';

		}

		$inner = $this->render_inline_children( $node );

		switch ( $tag ) {

			case 'strong':
			case 'b':
				return $this->wrap( $inner, '**' );

			case 'em':
			case 'i':
				return $this->wrap( $inner, '*' );

			case 'del':
			case 's':
			case 'strike':
				return $this->wrap( $inner, '~~' );

			case 'code':
			case 'kbd':
			case 'samp':
			case 'var':
				return $this->render_code( $node );

			case 'q':
				return '' !== trim( $inner ) ? '"' . trim( $inner ) . '"' : '';

			default:
				return $inner;

		}

	}

	/*
	RENDER CODE
	-- Inline code is taken from the raw text so escaping applied to prose does
	-- not leak backslashes into a snippet.
	---------------------------------------------------------------------------- */

	protected function render_code( DOMNode $node ): string {

		$code = $this->collapse( (string) $node->textContent );

		if ( '' === trim( $code ) ) {

			return '';

		}

		$ticks = '`';

		if ( preg_match_all( '/`+/', $code, $runs ) ) {

			$ticks = str_repeat( '`', max( array_map( 'strlen', $runs[0] ) ) + 1 );

		}

		$pad = 0 === strpos( $code, '`' ) || substr( $code, -1 ) === '`' ? ' ' : '';

		return $ticks . $pad . $code . $pad . $ticks;

	}

	/*
	RENDER LINK
	-- Anchors with no destination, and the empty anchors builders leave behind
	-- as scroll targets, contribute their text alone.
	---------------------------------------------------------------------------- */

	protected function render_link( DOMNode $node ): string {

		$inner = trim( $this->render_inline_children( $node ) );
		$href  = trim( (string) $node->getAttribute( 'href' ) );

		if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === strpos( $href, 'javascript:' ) ) {

			return '' !== $inner ? $inner : '';

		}

		$href = $this->absolute_url( $href );

		if ( '' === $inner ) {

			$inner = $href;

		}

		$title = trim( (string) $node->getAttribute( 'title' ) );
		$title = '' !== $title ? ' "' . str_replace( '"', '\"', $title ) . '"' : '';

		return '[' . $inner . '](' . $this->escape_url( $href ) . $title . ')';

	}

	/*
	RENDER IMAGE
	-- Decorative images carry an empty alt by definition, so they are dropped
	-- rather than left as an unlabelled marker in the text.
	---------------------------------------------------------------------------- */

	protected function render_image( DOMNode $node ): string {

		$src = trim( (string) $node->getAttribute( 'src' ) );
		$alt = trim( (string) $node->getAttribute( 'alt' ) );

		if ( '' === $src || '' === $alt ) {

			return '';

		}

		return '![' . $this->escape( $alt ) . '](' . $this->escape_url( $this->absolute_url( $src ) ) . ')';

	}

	/*
	WRAP
	-- Applies an emphasis marker while keeping the surrounding spaces outside
	-- it, since Markdown will not open emphasis on a space.
	---------------------------------------------------------------------------- */

	protected function wrap( string $inner, string $marker ): string {

		if ( '' === trim( $inner ) ) {

			return $inner;

		}

		$lead  = 0 === strpos( $inner, ' ' ) ? ' ' : '';
		$trail = substr( $inner, -1 ) === ' ' ? ' ' : '';

		return $lead . $marker . trim( $inner ) . $marker . $trail;

	}

	/*
	IS BLOCK
	-- Whether a node starts its own block in the output.
	---------------------------------------------------------------------------- */

	protected function is_block( DOMNode $node ): bool {

		return XML_ELEMENT_NODE === $node->nodeType
			&& in_array( strtolower( $node->nodeName ), self::BLOCK_TAGS, true );

	}

	/*
	COLLAPSE
	-- HTML treats every run of whitespace as one space, so the text nodes are
	-- normalised the same way before anything is measured or trimmed.
	---------------------------------------------------------------------------- */

	protected function collapse( string $text ): string {

		return (string) preg_replace( '/\s+/u', ' ', $text );

	}

	/*
	CLEAN TEXT
	-- Collapse plus trim, for values that go into frontmatter.
	---------------------------------------------------------------------------- */

	protected function clean_text( string $text ): string {

		return trim( $this->collapse( $text ) );

	}

	/*
	ESCAPE
	-- Neutralises the characters that would otherwise be read as Markdown
	-- syntax. Deliberately narrow: over-escaping prose is worse for an agent
	-- reading it than the occasional stray asterisk.
	---------------------------------------------------------------------------- */

	protected function escape( string $text ): string {

		return str_replace(
			[ '\\', '`', '*', '_', '[', ']' ],
			[ '\\\\', '\\`', '\\*', '\\_', '\\[', '\\]' ],
			$text
		);

	}

	/*
	ESCAPE URL
	-- A URL holding brackets or spaces has to be wrapped in angle brackets or
	-- the link target ends early.
	---------------------------------------------------------------------------- */

	protected function escape_url( string $url ): string {

		return preg_match( '/[\s()<>]/', $url ) ? '<' . $url . '>' : $url;

	}

	/*
	PREFIX LINES
	-- Puts a marker in front of every line of a multi-line block, used for
	-- blockquotes. Blank lines keep the marker so the quote is not broken in
	-- two by the gap between its paragraphs.
	---------------------------------------------------------------------------- */

	protected function prefix_lines( string $text, string $prefix ): string {

		$lines = explode( "\n", trim( $text ) );

		foreach ( $lines as $index => $line ) {

			$lines[ $index ] = rtrim( $prefix . $line );

		}

		return implode( "\n", $lines );

	}

	/*
	NORMALIZE
	-- Final tidy: no trailing spaces, no run of more than one blank line, and
	-- no leading or trailing whitespace on the document as a whole.
	---------------------------------------------------------------------------- */

	protected function normalize( string $markdown ): string {

		$markdown = str_replace( "\r\n", "\n", $markdown );
		$markdown = (string) preg_replace( '/[ \t]+$/m', '', $markdown );
		$markdown = (string) preg_replace( '/\n{3,}/', "\n\n", $markdown );

		return trim( $markdown );

	}

	/*
	ABSOLUTE URL
	-- Relative links are useless once the Markdown has been handed to an agent
	-- that no longer knows where it came from, so each one is resolved against
	-- the page URL.
	---------------------------------------------------------------------------- */

	protected function absolute_url( string $url ): string {

		$url = trim( $url );

		if ( '' === $url || '' === $this->base_url ) {

			return $url;

		}

		if ( preg_match( '#^(?:[a-z][a-z0-9+.-]*:|//|data:)#i', $url ) ) {

			return $url;

		}

		$base = wp_parse_url( $this->base_url );

		if ( empty( $base['scheme'] ) || empty( $base['host'] ) ) {

			return $url;

		}

		$origin = $base['scheme'] . '://' . $base['host'] . ( isset( $base['port'] ) ? ':' . $base['port'] : '' );

		if ( 0 === strpos( $url, '/' ) ) {

			return $origin . $url;

		}

		$path = isset( $base['path'] ) ? preg_replace( '#/[^/]*$#', '/', $base['path'] ) : '/';

		return $origin . $path . $url;

	}

	/*
	CLASS SELECTOR
	-- XPath has no class operator, so a class match is spelled out as a
	-- padded substring test against the whole attribute.
	---------------------------------------------------------------------------- */

	protected function class_selector( string $class ): string {

		return '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]';

	}

	/*
	META NAME
	-- Reads a <meta name="..."> value.
	---------------------------------------------------------------------------- */

	protected function meta_name( string $name ): string {

		return $this->meta_content( '//meta[@name="' . $name . '"]/@content' );

	}

	/*
	META PROPERTY
	-- Reads a <meta property="..."> value, which is how Open Graph tags are
	-- written even though the attribute is not part of the HTML spec.
	---------------------------------------------------------------------------- */

	protected function meta_property( string $property ): string {

		return $this->meta_content( '//meta[@property="' . $property . '"]/@content' );

	}

	protected function meta_content( string $expression ): string {

		$nodes = $this->query( $expression );

		return $nodes && $nodes->length ? $this->clean_text( $nodes->item( 0 )->nodeValue ) : '';

	}

	/*
	QUERY
	-- Thin guard around DOMXPath so every caller can treat a failed expression
	-- and an unparsed document the same way.
	---------------------------------------------------------------------------- */

	protected function query( string $expression, ?DOMNode $context = null ) {

		if ( ! $this->xpath ) {

			return null;

		}

		$nodes = $context ? $this->xpath->query( $expression, $context ) : $this->xpath->query( $expression );

		return false === $nodes ? null : $nodes;

	}

}
