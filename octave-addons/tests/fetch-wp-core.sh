#!/usr/bin/env bash

# FETCH WP CORE
# -- Downloads the WordPress HTML API files the tests need into tests/.wp-core

set -euo pipefail

dir="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
tmp="$( mktemp -d )"

trap 'rm -rf "$tmp"' EXIT

curl -sSL -o "$tmp/wp.zip" https://wordpress.org/latest.zip
unzip -q -o "$tmp/wp.zip" 'wordpress/wp-includes/html-api/*' 'wordpress/wp-includes/class-wp-token-map.php' -d "$tmp"

rm -rf "$dir/.wp-core"
mkdir -p "$dir/.wp-core"
mv "$tmp/wordpress/wp-includes" "$dir/.wp-core/wp-includes"

echo "WordPress HTML API installed in tests/.wp-core"
