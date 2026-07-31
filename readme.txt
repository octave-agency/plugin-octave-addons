=== Octave Addons ===
Contributors:      octaveagency
Tags:              addons, animations, comments, accessibility, debug
Requires at least: 5.8
Tested up to:      6.5
Stable tag:        1.0.0
Requires PHP:      7.4
License:           GPL-2.0+
License URI:       https://www.gnu.org/licenses/gpl-2.0.txt

A modular collection of Octave site add-ons. Each add-on can be toggled
on or off from a single admin page, and new add-ons can be dropped in
as self-contained modules.

== Description ==

Octave Addons ships with three add-ons out of the box:

*   **Empty Link Highlighter** – visually flags `<a>` tags with empty
    `href` or `href="#"` so editors can spot broken navigation.
*   **Disable Comments** – removes comment support site-wide
    (post types, REST endpoint, admin menus, admin bar).
*   **Scroll Animations** – enqueues Octave's fade/slide-in CSS and
    IntersectionObserver JS, with an editable override for each file.

Each add-on has its own tab under *Octave Addons* in the WordPress
admin. Adding a new add-on later is a drop-in operation — create a
folder under `/modules/` containing a `class-module.php` file that
extends `Octave_Addons_Module`, and it will appear automatically.

== External updates ==

This plugin is designed to be hosted outside of wordpress.org. It checks
a remote JSON manifest for updates and will notify WordPress whenever
the hosted zip changes, even without a version bump — see
`update.json.example` in the plugin folder for the manifest format.

Point the `OCTAVE_ADDONS_UPDATE_URL` constant at your hosted manifest.

== Installation ==

1. Upload the `octave-addons` folder to `/wp-content/plugins/`.
2. Activate **Octave Addons** from the Plugins screen.
3. Visit *Octave Addons* in the admin sidebar to turn add-ons on.

== Changelog ==

= 1.0.0 =
* Initial release — Empty Link Highlighter, Disable Comments, Scroll Animations, modular architecture and external updater.
