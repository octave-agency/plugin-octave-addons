#!/usr/bin/env bash

# BUILD ASSETS
# -- Writes the .min.js / .min.css copy beside each frontend asset that
# -- Octave_Addons_Module::asset() serves. Run after editing any of them;
# -- tests/js/run.js fails while a minified copy is missing or stale
# -- Needs Node; esbuild is fetched by npx on first use

set -euo pipefail

cd "$( dirname "$0" )/.."

ESBUILD="npx --yes esbuild@0.24.0"

while IFS= read -r source; do

	case "$source" in
		*.js)  target="${source%.js}.min.js";   options="--target=es2017" ;;
		*.css) target="${source%.css}.min.css"; options="" ;;
	esac

	$ESBUILD "$source" --minify --legal-comments=none $options --log-level=warning > "$target.tmp"

	# Records which source version the copy was built from, for the staleness test.
	printf '/*oa:%s*/\n' "$( node tests/js/assets.js --hash "$source" )" >> "$target.tmp"

	mv "$target.tmp" "$target"

	echo "$target"

done < <( node tests/js/assets.js )
