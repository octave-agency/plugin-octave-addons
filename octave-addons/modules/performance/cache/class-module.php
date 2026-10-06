<?php

/*
PERFORMANCE: CACHE
-- Always on and hidden from the Performance page, because it only manages
-- the plugin's own cache directory and the purge coordination other layers
-- hook into. It passes purges on to whichever page cache the site has,
-- Cloudways Varnish included, and refills it in the background. Octave's
-- own page cache is the separate Page Cache module
-- While any Performance feature is on, it also stops Breakdance's view and
-- session counting when no condition needs it, so pages can be cached
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

require_once dirname( __DIR__ ) . '/services/bootstrap.php';

class Octave_Addons_Module_Performance_Cache extends Octave_Addons_Module {

	public function get_id(): string {

		return 'performance-cache';

	}

	public function get_title(): string {

		return __( 'Performance Cache', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Coordinates cache purges across the minified files and any connected layers.', 'octave-addons' );

	}

	public function get_order(): int {

		return 0;

	}

	public function is_always_enabled(): bool {

		return true;

	}

	public function get_defaults(): array {

		return [ 'enabled' => true ];

	}

	/*
	SHOW IN ADMIN
	-- Nothing to configure: the admin bar's Clear cache appears for
	-- administrators while a Performance feature is on, and logged-in
	-- visitors always see unoptimised pages, so editors and shop staff are
	-- never the first to meet a compatibility problem
	---------------------------------------------------------- */

	public function show_in_admin(): bool {

		return false;

	}

	public function run( array $s ): void {

		Octave_Addons_Perf_Cache::register_invalidation();
		Octave_Addons_Perf_Page_Cache::boot();
		Octave_Addons_Perf_Sessions::boot();

	}

}

return new Octave_Addons_Module_Performance_Cache();
