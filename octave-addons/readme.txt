=== Octave Addons ===
Contributors:      octaveagency
Tags:              addons, animations, comments, accessibility, debug
Requires at least: 5.8
Tested up to:      6.5
Stable tag:        2.9.4
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
*   **Breakdance Lazy Load** – always on and hidden from the admin. Keeps
    every Breakdance Lazy Load toggle off so images, backgrounds and
    embeds are left to the site's third-party performance plugin.
*   **Custom Posts** – can display Posts as Blogs and provides separate managers for
    post types, reusable taxonomies, and typed post fields. Fields use registered
    WordPress post meta and are available in Breakdance Dynamic Data.

Each add-on has its own tab under *Octave Addons* in the WordPress
admin, and closely related add-ons share one page — the Breakdance
modules sit together on a single Breakdance screen. Adding a new add-on
later is a drop-in operation — create a folder under `/modules/`
containing a `class-module.php` file that extends
`Octave_Addons_Module`, and it will appear automatically. Return a group
id from `get_group()` to place it on a shared page.

== GitHub updates ==

This plugin uses its published GitHub Releases as a native WordPress update
source. A release with a tag newer than the installed plugin version appears
in the standard Plugins and Dashboard Updates screens.

== Branded status pages ==

Octave Addons installs self-contained WordPress maintenance and PHP error
drop-ins. When Custom Login URL is enabled, they use its configured logo,
background colour and primary colour; otherwise they use a neutral generic
scheme. Drop-ins managed elsewhere are never replaced, and the pages appear
automatically during updates and critical errors.

== Installation ==

1. Upload the `octave-addons` folder to `/wp-content/plugins/`.
2. Activate **Octave Addons** from the Plugins screen.
3. Visit *Octave Addons* in the admin sidebar to turn add-ons on.

== Changelog ==

= 2.9.4 =
* Redesigned post-type assignment checkboxes as accessible selection cards with clearer selected, current, hover, and keyboard-focus states.

= 2.9.3 =
* Opened the dedicated taxonomy definition body by default while preserving saved-definition key protection.

= 2.9.2 =
* Applied the Octave admin button Dashicon line height directly to the icon element for reliable WordPress admin alignment.

= 2.9.1 =
* Added an optional Built-in Content toggle for displaying WordPress Posts as Blogs.
* Removed the Page Categories management shortcut from the module settings.

= 2.9.0 =
* Added a reusable Content Schema Library with compact category and field summaries, type badges, permanent keys, and assignment labels.
* Replaced large per-post-type definition editors with simple assignment screens for attaching shared categories and fields to multiple content types.
* Added dedicated single-definition editors so only the category or field being updated exposes its full settings, including group and repeater children.
* Definitions can now remain safely unassigned in the library until they are ready to be attached to a content type.

= 2.8.0 =
* Added a validated visual Dashicons picker for every custom post type and now uses the selected icon in WordPress admin menus and focused content editors.
* Added a prominent post-type identity banner to focused category and content-field screens so the current editing context remains clear.
* Normalised Dashicon line height across Octave admin buttons to prevent WordPress load-styles overrides.

= 2.7.3 =
* Separated built-in Posts and Pages enhancements from the custom post type workflow with a dedicated WordPress content area inside Custom Posts.

= 2.7.2 =
* Redesigned the global WordPress admin footer credit as a friendly, accessible Octave-branded pill with a prominent agency link.

= 2.7.1 =
* Restored the standard disabled-module state for Custom Posts so every configurable module displays the enable prompt before exposing its settings.

= 2.7.0 =
* Replaced the long Custom Posts setup page with a post type overview and focused category and content-field editors for each saved post type.
* Added Group and Repeater content fields with nested typed controls, polished repeatable rows in the post editor, and one structured meta value per top-level field.
* Added native Breakdance repeater data and group/repeater child fields under Octave Dynamic Data while retaining existing scalar and image field support.

= 2.6.4 =
* Fixed a PHP boundary error that caused the Custom Posts settings page to fail before rendering its editor and save controls.

= 2.6.3 =
* Prevented the Custom Posts admin page from depending on a newly added cross-module settings method, keeping its configuration visible without risking a render-time fatal.
* Made Breakdance Dynamic Data classes load only when enabled fields exist and their matching Breakdance base class is available, preventing partial builder APIs from causing a 500 error.

= 2.6.2 =
* Kept Custom Posts on its stable `tab=custom-post-types` module id so existing settings, integrations, and bookmarks continue to work.
* Made the Post Types, Post Categories, and Post Fields configuration available before activation; the module switch now controls runtime registration without hiding its setup tools.

= 2.6.1 =
* Replaced the default WordPress admin footer credit with a linked Octave Agency message.

= 2.6.0 =
* Renamed Custom Post Types to Custom Posts and split its settings into Post Types, Post Categories, and Post Fields.
* Added reusable hierarchical or tag-style taxonomies assignable to multiple built-in and custom post types.
* Added typed custom post fields with a polished post-editor meta box, Media Library controls, WYSIWYG editing, defaults, choices, instructions, and required indicators.
* Registered custom values as namespaced WordPress post meta and exposed typed fields under Octave in Breakdance Dynamic Data.
* Existing embedded custom post type taxonomies migrate without changing their stored identifiers or terms.

= 2.5.0 =
* Added the always-on Breakdance Lazy Load module, which keeps every Breakdance Lazy Load toggle off so lazy loading stays with the site's third-party performance plugin.
* New elements are now added with Lazy Load already off, and saved pages have any remaining Lazy Load toggle ignored at render time.

= 2.4.3 =
* Set the Custom Post Types module to off by default on sites without saved settings.
* Removed the WordPress admin checkbox checkmark from the disabled Always on switch.

= 2.4.2 =
* Removed the administrator status-page preview query parameter now that the branded pages are ready for production.

= 2.4.1 =
* Changed branded status pages to inherit the enabled Custom Login URL module's logo, background colour and primary colour.
* Added an adaptive generic light scheme with no Octave branding when Custom Login URL is disabled.

= 2.4.0 =
* Added branded, responsive maintenance and critical-error pages using the Octave colour palette and the site logo when available.

= 2.3.5 =
* Prevented WordPress core anchor focus styles from overriding Octave navigation items and module cards while retaining the branded keyboard focus ring.

= 2.3.4 =
* Replaced default WordPress admin blue text links in the Octave settings area with accessible Octave green link, hover and focus states.

= 2.3.3 =
* Added a reusable accessible confirmation dialog for destructive or confirmable admin actions.
* Replaced the browser confirmation used when removing a saved custom post type with the new styled dialog.

= 2.3.2 =
* Collapsed custom post type settings by default and added an accessible top-bar disclosure control.
* Moved Enabled into the card header so disabling a type immediately hides and locks its remaining options.
* Replaced the Add post type Dashicon with a plugin-scoped icon to prevent WordPress core button styles from misaligning it.

= 2.3.1 =
* Reorganised each custom post type into clear Identity, Visibility, URLs and Taxonomy groups.
* Restyled the Add post type button and fixed the Has Archive and Has Taxonomy toggles so their related fields hide when disabled.

= 2.3.0 =
* Renamed the built-in Posts area to Blogs without changing its underlying `post` database key.
* Replaced the fixed Case Studies option with a sortable custom post type manager for names, keys, public visibility, single and archive URLs, and one optional custom taxonomy.
* Added drag-and-drop and keyboard ordering for custom post types so their order controls their placement in the WordPress admin menu.
* Existing saved Case Studies settings migrate to the new format without changing their post type or taxonomy identifiers; new installations start with no predefined custom post types.

= 2.2.1 =
* Removed the "OA" prefix from Breakdance element display names while retaining their unique internal identifiers and classes.

= 2.2.0 =
* Replaced native settings selects with accessible custom dropdowns, including option search when a list contains more than five items.
* Added consistent green focus states and a disabled spinner state while settings are being saved.
* Replaced native colour inputs with a custom saturation, brightness, hue and hex colour picker.
* Replaced the custom login logo URL field with a WordPress Media Library picker, including preview, replace and remove actions.

= 2.1.0 =
* Breakdance AJAX Filtering and Breakdance Elements now share a single **Breakdance** page in the admin, one navigation item and one dashboard card, while keeping their own switches and settings.
* The Breakdance page checks once whether the builder is active: if it is not, the whole page is locked behind a single notice instead of each module warning separately. Saved values are left untouched.
* Links to the old per-module tabs redirect to the Breakdance page.
* Page Categories are now admin-only: removed the category slug option, and the taxonomy no longer has public archives, rewrite rules, or nav menu entries, so visitors never see a category link. Admin filtering, Quick Edit, the block editor and builder query loops are unaffected.
* Added a screen-reader label to the page category filter in the Pages toolbar.

= 2.0.0 =
* Added Page Categories: a hierarchical taxonomy on the built-in Pages post type for grouping campaigns such as PPC or Landing, with its own on/off switch and configurable archive slug.
* Page categories appear as one-click filter links above the Pages list, as a toolbar dropdown, as a list column, in Quick Edit, and in the block editor, all driven by standard WordPress filtering.
* Breaking: removed the Landing Pages post type. Existing landing page content stays in the database but is no longer registered, so it will not appear in the admin.

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
