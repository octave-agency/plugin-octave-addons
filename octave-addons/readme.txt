=== Octave Addons ===
Contributors:      octaveagency
Tags:              addons, animations, comments, accessibility, debug
Requires at least: 5.8
Tested up to:      6.5
Stable tag:        2.36.0
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
*   **Breakdance Default Spacing** – sets the default bottom margin for
    Breakdance headings and content elements, per breakpoint, from two
    shared spacing tokens, replacing the hand-written spacing stylesheet
    a site would otherwise need.
*   **Breakdance Lazy Load** – always on and hidden from the admin. Keeps
    every Breakdance Lazy Load toggle off so images, backgrounds and
    embeds are left to the site's third-party performance plugin.
*   **Breakdance Launcher Styles** – always on and hidden from the admin.
    Declares Breakdance's launcher stylesheet as a block editor style so it
    reaches the block editor canvas iframe, which an admin stylesheet cannot.
*   **Post Types** – can display Posts as Blogs and provides separate managers for
    post types, reusable taxonomies, and typed post fields. Fields use registered
    WordPress post meta and are available in Breakdance Dynamic Data.

Each add-on has its own tab under *Octave Addons* in the WordPress
admin, and closely related add-ons share one page — the Breakdance
modules sit together on a single Breakdance screen. Adding a new add-on
later is a drop-in operation — create a folder under `/modules/`
containing a `class-module.php` file that extends
`Octave_Addons_Module`, and it will appear automatically. Return a group
id from `get_group()` to place it on a shared page.

== Modern WordPress admin ==

The complete WordPress dashboard can receive an Octave visual refresh without
changing its familiar navigation or workflows. One isolated site-wide switch
on the plugin dashboard controls the refresh, which is disabled by default.
Each browser starts with its operating-system light or dark preference and can
save an override from the admin bar. The override is stored in a cookie on that
device, so accounts shared between people keep separate appearance choices.
User profiles also support a job title and a Media Library profile picture; a
valid custom attachment is used before Gravatar and the configured WordPress
fallback.

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

= 2.36.0 =
* Added Breakdance Launcher Styles, an always-on hidden module that declares Breakdance's launcher stylesheet as a block editor style. Breakdance registers its launcher block in JavaScript only and enqueues that sheet on admin_enqueue_scripts, which reaches the editor page but not the canvas iframe, so the "Edit in Breakdance" block rendered with browser default buttons and no card.

= 2.35.1 =
* Made the WooCommerce settings screen content well transparent in both themes so the page canvas shows through it.

= 2.35.0 =
* Added a disabled-module callback to the module API so a module can clear what it leaves on the page, and used it to remove the Empty Link Highlighter admin bar counter when the module is switched off, including the node left by the standalone plugin it was ported from.
* Kept the Octave Addons logo mark on its dark chip with the white icon in light mode, matching dark mode.
* Corrected light mode for the surfaces appended outside the workspace: action toasts, the confirmation dialog, and the icon picker dialog.
* Corrected light mode for the colour picker trigger, popover, swatch and slider handles, and for the media image field plate.

= 2.34.0 =
* Moved the admin light and dark mode override from user meta to a per-device cookie so shared accounts keep separate appearance preferences.
* Removed the theme save AJAX endpoint now the toggle stores the choice in the browser.
* Extended WooCommerce dark mode to the WooPayments onboarding modal, DataViews list tables, Blueprint import and export, jQuery UI widgets, the log viewer, and the react-dates single date and input variants.

= 2.33.1 =
* Completed the WooCommerce dark mode pass against a live store, covering the settings shell, payments, home screen, inbox, activity panel, analytics placeholders, marketplace, email preview, order edit, and the classic product editor chrome.
* Matched WooCommerce select2 controls and list table toolbars to native WordPress control heights and corner radii.
* Corrected the classic editor title placeholder alignment.

= 2.33.0 =
* Added WooCommerce dark mode coverage across settings, analytics, orders, products, shipping, tax, status, logs, importers, and the marketplace, loaded only while WooCommerce is active.
* Removed the admin experience override of the WordPress #wpcontent padding so plugin layouts are no longer squeezed.
* Removed the wrap inset on the classic editor so its columns and meta box gutters lay out at full width.

= 2.32.6 =
* Extended the deactivation confirmation dialog to cover the Octave client specific plugin.

= 2.32.5 =
* Registered current GitHub release metadata with WordPress so native plugin auto-update controls remain available.

= 2.32.4 =
* Replaced the admin appearance Save control with a dedicated Octave green button.

= 2.32.3 =
* Made the plugin sidebar surface, labels, hover states, and brand icon respond to light and dark mode.

= 2.32.2 =
* Removed the card treatment from module settings panels in the themed plugin workspace.

= 2.32.1 =
* Restored the Octave green accent palette and neutral navigation labels in the themed plugin workspace.

= 2.32.0 =
* Integrated the Octave Addons workspace with the optional light and dark WordPress admin themes.
* Reworked the plugin dashboard as a native admin layout without the enclosing app card.

= 2.31.3 =
* Themed the block inserter search band, quick inserter, panel header, and tab list in dark mode.
* Themed the canvas add-block button, its icon, and the quick inserter's Browse all bar in dark mode.

= 2.31.2 =
* Moved the profile picture and job title fields into the About Yourself section above the biography.
* Rebuilt dashboard widget spacing so core, plugin, and future widgets share one padding box, with full-bleed rows opting out.
* Remapped the WordPress 7.x design system tokens in dark mode so Gutenberg's hashed component classes theme correctly.
* Corrected dark mode colours core does not tokenise across the block breadcrumb, inspector card, inserter sidebars, media router tabs, and disabled buttons.
* Fixed the Gutenberg select chevron tiling from the top left in dark mode.
* Removed the custom admin bar site icon now that WordPress renders one natively.

= 2.31.1 =
* Corrected Gutenberg's empty block-inspector tool and no-selection states in dark mode.

= 2.31.0 =
* Added modern light and dark dropdown chevrons to native, AJAX-loaded, Gutenberg, Customizer, Select2, and Choices select controls without replacing accessible native behaviour.

= 2.30.7 =
* Standardised table-navigation buttons and selects at 36px with vertically centred button labels.

= 2.30.6 =
* Removed the redundant legacy WordPress pointer from active admin-menu items in both themes.

= 2.30.5 =
* Matched the active admin-menu pointer to the admin canvas in dark mode instead of using WordPress white.

= 2.30.4 =
* Corrected dark-mode hover, focus, selected, disabled, and opened-menu colours for native, Gutenberg, Select2, and Choices select controls.

= 2.30.3 =
* Corrected the Gutenberg document-bar post-type label colour in dark mode.

= 2.30.2 =
* Corrected the Gutenberg document-bar keyboard shortcut colour in dark mode.

= 2.30.1 =
* Completed dark-mode coverage for Gutenberg confirmation dialogs, component popovers, legacy WordPress dialogs, ThickBox and plugin-details windows, link pickers, pointers, and modal overlays.

= 2.30.0 =
* Completed a full core WordPress dark-mode regression pass across lists, Appearance, block editors, the Customizer, plugin and theme installers, privacy, Site Health, import, personal-data, and About screens.

= 2.29.19 =
* Completed an expanded Dashboard-card dark-mode audit for Rank Math, WP Mail SMTP, Activity, Events, Wordfence, CookieYes, Object Cache Pro, OneTap, and native WordPress widgets.

= 2.29.18 =
* Restored Featured Image action labels, panel-header titles and icons, and remaining Gutenberg sidebar-control contrast in dark mode.

= 2.29.17 =
* Restored Gutenberg header and block-toolbar icon contrast and standardised hover, focus, active, pressed, disabled, and separator states in both themes.

= 2.29.16 =
* Completed Gutenberg dark mode across editor chrome, sidebars, inspectors, menus, dialogs, and publishing controls without styling the post canvas or front end.

= 2.29.15 =
* Removed the visible Media Library search label, added an accessible placeholder, and right-aligned the search input.

= 2.29.14 =
* Completed a site-wide light and dark admin audit, correcting remaining dashboard widgets, labels, sortable tables, navigation management, Site Health, Gravity Forms, Rank Math, and Wordfence surfaces.

= 2.29.13 =
* Corrected Imagify sortable file-list header links and sorting indicators in dark mode.

= 2.29.12 =
* Replaced the legacy WordPress loading image with a modern CSS spinner and rebuilt the Media Library toolbar alignment across desktop and responsive layouts.

= 2.29.11 =
* Restored visible taxonomy field labels across dark-mode term add and edit screens.

= 2.29.10 =
* Added native tag names and tag checklist labels to the dark-mode text system.

= 2.29.9 =
* Removed the redundant WordPress admin colour scheme and Gravatar profile row, and simplified the custom Job Title and Profile Picture fields into the native profile form.

= 2.29.8 =
* Aligned Screen Options and Help controls, made admin avatars circular, used the site icon in place of the house when available, replaced the appearance label with sun and moon icons, removed admin-bar search, and completed dark Media Library and Plugins table surfaces.

= 2.29.7 =
* Scoped the blue WordPress admin refresh away from the green Octave Addons workspace so its branded interface remains unchanged.

= 2.29.6 =
* Adopted a refined WordPress-blue accent and neutral blue-grey surfaces across the general dashboard while retaining Octave green inside the Octave Addons workspace.

= 2.29.5 =
* Simplified the refreshed dashboard with compact controls, small corner radii, flat surfaces, restrained shadows, quieter navigation states, and clearer dark-mode table text after auditing the live WordPress interface.

= 2.29.4 =
* Kept the optional WordPress admin refresh off by default until it is explicitly enabled from the Octave Addons dashboard.

= 2.29.3 =
* Isolated the optional admin refresh behind one site-wide switch on the main Octave Addons settings screen, making the feature straightforward to test, disable, or remove later.

= 2.29.2 =
* Added a quick per-user admin-bar action to disable or restore the custom admin styling and theme script without affecting functional plugin assets.

= 2.29.1 =
* Organised shared styles, scripts, and image artwork into dedicated asset folders without changing their behaviour.

= 2.29.0 =
* Added a per-user light and dark admin mode toggle that follows the operating-system preference until the user chooses a mode.
* Added job title and Media Library avatar fields to user profiles, storing the custom avatar as an attachment ID and using it ahead of Gravatar and the normal fallback.
* Adopted a broader native system font stack so each operating system gets typography suited to its interface.

= 2.28.0 =
* Refreshed the complete WordPress admin experience with an always-on Octave visual system for navigation, forms, tables, editors, dashboard panels, plugins, themes, and the media library, without changing core workflows.

= 2.27.2 =
* Removed the WordPress logo menu from the admin bar everywhere, with no setting required.

= 2.27.1 =
* Kept notices raised by other plugins above the interface instead of letting WordPress drop them inside a module's header, by giving the notice wrap its own heading and the core wp-header-end marker.

= 2.27.0 =
* Unlocked a saved post type or category key behind an edit button, with a confirmation that says what a rename costs, so a key can be corrected without deleting and rebuilding the definition.
* Carried category and field assignments across a renamed post type key instead of dropping them as unknown, and kept a focused category editor pointed at the definition after its key changed.
* Printed WordPress settings notices in their own wrap above the plugin interface, so they keep their native styling instead of half-matching the dark panels.

= 2.26.0 =
* Returned to the category or field overview after deleting the definition you were editing, instead of leaving you on an editor for something that no longer exists, and confirmed the deletion there.
* Reported definitions the save refused to store, naming how many and what each one needs, rather than dropping incomplete or duplicated rows in silence.
* Confirmed adding and removing post types, categories, fields, and item fields, and enabling or disabling a module, in a message that also states the change is not stored until the settings are saved.
* Explained a save the browser blocked, opening the tab or card holding the first control that needs attention and focusing it.
* Lifted the required flag off controls hidden by a switch or a disabled module, which could previously block a save with a message nobody could see.
* Coloured success, warning, and failure notices apart, and scrolled a failed save into view.

= 2.25.0 =
* Moved a category's URL slug below its Public archives switch and hid it while archives are off, since the slug is unused then; the required flag is lifted with it so an unseen field can no longer block a save.
* Labelled a custom category's admin submenu with its own name instead of a fixed "Categories", dropping the owning post type's name from the front so "Properties Listing Types" reads as "Listing Types".

= 2.24.1 =
* Spaced the content type tab strip away from the panel below it.
* Dropped the Manage categories button from the Categories tab, leaving New category as its only header action.
* Sat each listing row's key directly beside its title at the width of the key itself, rather than stretched across the row.

= 2.24.0 =
* Fixed the post type, category, and field key fields swallowing an underscore as it was typed; a trailing separator is now kept until the field is left.
* Split the custom content area into Post Types, Categories, and Content Management tabs, with the open tab restored after saving.
* Expanded the Categories and Content Management views into full searchable listings showing every key, type, assignment, and state instead of a capped preview.
* Saved only the module group currently on screen, leaving every other module's stored settings untouched.

= 2.23.0 =
* Added a Publicly queryable switch beside Public on each custom post type, so a type can stay listed in Breakdance query and template pickers while its own frontend URLs stay unavailable.
* Registered publicly_queryable, query_var, rewrite rules, archives, and search exclusion from the new switch instead of the Public switch.
* Moved the URLs group directly beneath Visibility so it is obvious the Public and Publicly queryable switches control it.

= 2.22.0 =
* Redesigned the field-only CPT canvas: the fields now sit in the normal editor content column beside the post title, behind a single header line instead of the icon and two-sentence notice.
* Applied Octave's brand green to the canvas accents, focus rings, and header rule.
* Marked required canvas controls with the native required attribute and flagged empty ones inline.
* Held post saving shut while a required field is empty, naming the outstanding fields in an editor notice.
* Rejected REST saves that would publish a field-only post with required fields empty, returning the same explanation.

= 2.21.0 =
* Rebuilt the field-only CPT canvas: Gutenberg now shows a "custom content is disabled" notice with every assigned Content Field beneath it, in the content area.
* Bound the canvas fields straight to registered post meta with useEntityProp, so values load, edit, and save through Gutenberg's own post save.
* Replaced the raw inputs with native block editor controls, including toggles, tick lists, media pickers, and reorderable repeater rows.
* Declared the canvas stylesheet as the block's editor style and split it out of the meta box stylesheet, clearing the "octave-post-fields-css was added to the iframe incorrectly" console warning.
* Stopped loading meta box assets, TinyMCE, and the media library on field-only CPT editors that never use them.

= 2.20.2 =
* Loaded structured Content Field styles through WordPress's supported block asset lifecycle so they render correctly inside Gutenberg's iframe.
* Kept outer editor styling separate and restricted both assets to Octave field-only CPT screens.

= 2.20.1 =
* Replaced the mixed Content field inventory with clear Reusable Fields and per-post-type Fields destinations.
* Added field counts and direct links to each focused content schema editor.

= 2.20.0 =
* Rendered field-only CPT schemas as native controls directly inside the locked Gutenberg content block.
* Removed the Octave meta-box card from field-only CPT editors while retaining registered REST post meta and sparse storage.
* Restored the no-content-editor message exclusively for enabled CPTs registered by Octave.

= 2.19.1 =
* Restricted the structured-content Gutenberg launcher to enabled post types successfully registered by Octave.
* Prevented the Octave launcher script from running globally or replacing Breakdance and standard Page content.
* Moved structured Content Fields into a dedicated main-editor section before the Meta boxes area while retaining WordPress's reliable save transport.

= 2.19.0 =
* Replaced the Post Control overview with stacked Post Types, Categories, and Content boxes.
* Kept category and content inventories compact, with six-item previews and focused editors for larger collections.
* Avoided a second full category and field normalization pass during ordinary settings saves.

= 2.18.2 =
* Positioned only the Octave field panel immediately below the structured-content Gutenberg launcher.
* Kept moved and dynamically added field controls associated with WordPress's native meta-box form for reliable saving.
* Removed the redundant launcher button so the notice flows directly into the editable fields.

= 2.18.1 =
* Registered the structured-content launcher before Gutenberg initialization using Breakdance's block-editor lifecycle.
* Added the real octave/block-octave-launcher content block and restored it whenever a field-only CPT editor does not contain it.

= 2.18.0 =
* Added a guaranteed Breakdance-style message inside Gutenberg for structured-only post types.
* Positioned the complete normal meta-box form directly after the editor canvas while preserving its native save boundary.
* Removed the structured field intro and meta-box chrome, and removed Gutenberg's trailing 40vh editor space.

= 2.17.0 =
* Replaced the structured-content canvas workaround with a native Gutenberg launcher block modelled on Breakdance's implementation.
* Kept Octave content inputs in the supported meta-box form and added a launcher action that takes editors directly to them.

= 2.16.1 =
* Listed configured content fields directly in Breakdance's Octave Dynamic Data category.
* Positioned Octave immediately after Post, sorted its fields by label, and preserved typed image and repeater options.

= 2.16.0 =
* Kept the Gutenberg editing shell for post types that disable the standard content editor.
* Replaced the block content canvas with the assigned Octave fields for structured-only post types.

= 2.15.1 =
* Replaced step and part labels with direct Post Types, Categories, and Content Fields boxes.
* Added explicit Manage Post Types, Manage Categories, and Manage Fields actions for clearer navigation.

= 2.15.0 =
* Split the main Post Types screen into Post Control and Content Management sections.
* Grouped post types with reusable categories, then placed content fields below the post type controls for a clearer workflow.

= 2.14.2 =
* Changed Add Reusable Field on a single post type’s Content Fields editor into a searchable picker of definitions already created in Reusable Content Fields.
* Kept reusable field creation exclusive to the global reusable field library.

= 2.14.1 =
* Made custom field storage sparse: empty and default-equivalent values now remove their postmeta row, while intentional empty overrides of non-empty defaults remain stored.
* Removed completely blank repeater rows before saving and retained one postmeta row per populated group or repeater.

= 2.14.0 =
* Added a per-post-type Content Editor toggle so metadata-only types can remove the standard WordPress editor while keeping the Octave content fields panel prominent.

= 2.13.0 =
* Renamed Custom Posts to Post Types throughout the current admin interface.
* Added reusable content fields that can be assigned to Posts, Pages, and multiple custom post types.
* Added post-type-specific content fields that remain owned by one custom post type.
* Moved reusable and specific field creation, editing, and assignment onto each post type’s Content Fields page.
* Made reusable fields editable inline in the content schema library and opened overview field links directly at their editor.

= 2.12.7 =
* Hid custom post type URL settings whenever the post type is not public.

= 2.12.6 =
* Expanded the custom post type admin menu icon picker to every Dashicon available in the installed WordPress version.
* Added icon search by name or Dashicon class.

= 2.12.5 =
* Hid the Breakdance AJAX Filtering page path while the module applies to all Breakdance areas.
* Posts per page now stays empty by default and follows the current Settings → Reading value until explicitly overridden.

= 2.12.4 =
* Fixed Scroll Animation CSS and JavaScript overrides losing backslashes when saved through the WordPress Settings API.

= 2.12.3 =
* Scroll Animation CSS and JavaScript overrides now replace the bundled assets and load on the frontend even when their corresponding load toggles are disabled.

= 2.12.2 =
* Fixed Breakdance Default Spacing having no effect: Breakdance prints its stylesheets into a placeholder echoed on wp_head at priority 1000000, so an enqueued inline style always landed before its element defaults and lost to `.breakdance .bde-heading { margin: 0 }` on source order.
* The compiled stylesheet is now appended to Breakdance's global settings slot, placing it after the element defaults but before presets, global selectors and per-element CSS.

= 2.12.1 =
* Breakdance Default Spacing now emits plain scoped selectors such as `.breakdance .bde-text` instead of wrapping them in `:where()`.
* Spacing unit pickers use the standard Octave select design rather than a plain native dropdown.
* Removed the "follows the token" note from token-bound spacing rows.

= 2.12.0 =
* Added Breakdance Default Spacing: a visual editor for the default bottom margin of Breakdance headings and content elements, with a value per Breakdance breakpoint and no CSS to write.
* Added two shared spacing tokens, --default-element-gap and --default-heading-margin, that any element row can follow and any site CSS can reference.
* Added an optional last-element reset that drops the bottom margin on the last spaced element in a container.
* Added a read-only preview of the generated stylesheet that updates as values change.

= 2.11.0 =
* Added a shared Octave icon kit and replaced the mixed Dashicons on the module dashboard with matching stroke icons.
* Brought every SVG the plugin draws itself onto the same 24px stroke style, including the contact popup, deactivation dialog, icon picker and select chevrons.
* Titlecased Octave admin button labels.
* Fixed the save button text turning white on hover, focus and active where WordPress repainted it.

= 2.10.0 =
* Added a Custom Posts content overview that summarises post types, categories and fields, creates each one, and links straight to the area that edits it.
* Added optional starter content to new post types so one save creates the post type, its first category and its first content field together.
* Categories and fields created from a post type now arrive pre-assigned to it and return to that post type when closed.
* Replaced the Pages category shortcut links with the single category dropdown in the list toolbar.
* Simplified the admin footer credit to plain text with a link, and removed the forced Octave Addons page margin.

= 2.9.5 =
* Removed Dynamic Data subcategory headings so custom fields appear directly under Octave, with nested fields retaining clear parent-qualified labels.

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
