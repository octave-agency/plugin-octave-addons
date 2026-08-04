=== Octave Addons ===
Contributors:      octaveagency
Tags:              addons, animations, comments, accessibility, debug
Requires at least: 5.8
Tested up to:      6.5
Stable tag:        1.8.1
Requires PHP:      7.4
License:           GPL-2.0+
License URI:       https://www.gnu.org/licenses/gpl-2.0.txt

A modular collection of Octave site add-ons. Each add-on can be toggled
on or off from a single admin page, and new add-ons can be dropped in
as self-contained modules.

== Description ==

Octave Addons ships with a growing collection of focused modules:

*   **Empty Link Highlighter** – visually flags `<a>` tags with empty
    `href` or `href="#"` so editors can spot broken navigation.
*   **Disable Comments** – removes comment support site-wide
    (post types, REST endpoint, admin menus, admin bar).
*   **Scroll Animations** – enqueues Octave's fade/slide-in CSS and
    IntersectionObserver JS, with an editable override for each file.
*   **Breakdance AJAX Filtering** – adds server-backed filtering and
    pagination to Breakdance loops with a Filter Bar.
*   **Custom Post Types** – adds configurable content types, beginning
    with Landing Pages at clean root-level URLs.

Each add-on has its own tab under *Octave Addons* in the WordPress
admin. Adding a new add-on later is a drop-in operation — create a
folder under `/modules/` containing a `class-module.php` file that
extends `Octave_Addons_Module`, and it will appear automatically.

== GitHub updates ==

This plugin uses its published GitHub Releases as a native WordPress update
source. A release with a tag newer than the installed plugin version appears
in the standard Plugins and Dashboard Updates screens.

== Installation ==

1. Upload the `octave-addons` folder to `/wp-content/plugins/`.
2. Activate **Octave Addons** from the Plugins screen.
3. Visit *Octave Addons* in the admin sidebar to turn add-ons on.

== Changelog ==

= 1.8.1 =
* Recoloured the admin to the Octave brand palette, replacing the violet and sky accents with brand green on near-black.
* Regenerated the plugin banner and icon artwork in brand colours.

= 1.8.0 =
* Renamed the Breakdance Custom Elements module to Breakdance Elements and gave it its own admin screen.
* Added an on/off switch for every custom element, listed automatically with its builder icon, so new elements appear with no code changes.
* Switching an element off hides it from the builder's add panel without affecting pages that already use it.
* Elements edited on a site are now detected and carried across plugin updates instead of being overwritten.
* Added plugin banner and icon artwork for the WordPress update and details screens.

= 1.7.0 =
* Added a shared Breakdance element library with OA Countdown, OA Copy Text, OA Copyright, OA Logo Marquee and OA Reading Time.
* Split element storage: the shipped library is read-only in Element Studio, and site-specific elements save to wp-content/plugins/octave-elements/ when that folder exists.
* Added the `octave_addons_breakdance_save_locations` filter for registering extra Element Studio save locations.

= 1.6.0 =
* Added a confirmation dialog on the Plugins screen warning that deactivating Octave Addons disables every module and can break the site.
* Covered both the row "Deactivate" link and the bulk deactivate action.

= 1.5.0 =
* Added a dedicated addon dashboard with the full hero, status cards, icons, and quick links to every settings area.
* Removed the hero from individual addon settings views.
* Changed Breakdance AJAX Filtering to be disabled by default for new configurations.

= 1.4.5 =
* Reduced GitHub release caching from six hours to five minutes for faster update detection.

= 1.4.4 =
* Automated GitHub release publishing when a new plugin version is synced to the main branch.

= 1.4.3 =
* Prevented WordPress core styles from overriding admin notice paragraphs.

= 1.4.2 =
* Reduced the admin hero to a compact, responsive summary header.

= 1.4.1 =
* Packaged WordPress releases from the dedicated `octave-addons` plugin directory.

= 1.4.0 =
* Added an optional Landing Page URL slug while retaining root-level URLs by default.

= 1.3.0 =
* Added configurable Case Studies, archive routing, and optional Case Study Categories.

= 1.2.0 =
* Added configurable Landing Pages with page-style editing and root-level URLs.

= 1.1.0 =
* Redesigned the admin experience with a modern dashboard, responsive navigation, live module totals, and save-state feedback.

= 1.0.2 =
* Added global Breakdance AJAX filtering with Breakdance availability checks.

= 1.0.1 =
* Replaced the legacy manifest updater with GitHub Releases integration.

= 1.0.0 =
* Initial release — Empty Link Highlighter, Disable Comments, Scroll Animations, modular architecture and GitHub updater.
