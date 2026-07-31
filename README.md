# Octave Addons

A modular WordPress plugin by Octave Agency. One settings screen, many
toggleable add-ons, and an external update channel that picks up new
builds as soon as you publish a new zip.

## What's inside (v1.0.0)

| Add-on | Description |
| --- | --- |
| **Empty Link Highlighter** | Flags frontend `<a>` tags with empty / `#` hrefs. Style, colour, visibility and admin-bar counter are all configurable. |
| **Disable Comments** | Removes comment support from every post type, kills the comments REST endpoint, hides the admin menu and admin-bar icon, and force-closes comments on every post. Each piece is individually toggleable. |
| **Scroll Animations** | Enqueues the Octave fade/slide-in CSS and IntersectionObserver JS on the frontend. The CSS *and* the JS can each be overridden per-site via a textarea in the admin. |

## Folder layout

```
octave-addons/
├── octave-addons.php              Plugin bootstrap + constants
├── readme.txt                     WordPress-style readme
├── update.json.example            Manifest format for external updates
├── README.md                      (this file)
├── assets/
│   └── admin.css                  Admin UI styling
├── includes/
│   ├── class-module.php           Abstract base class for modules
│   ├── class-module-manager.php   Auto-discovery + dispatch
│   ├── class-admin.php            Menu, tabs, settings API wiring
│   ├── class-updater.php          External update checker
│   └── class-octave-addons.php    Main singleton
└── modules/
    ├── README.md                  How to add a new module
    ├── empty-link-highlighter/
    │   └── class-module.php
    ├── disable-comments/
    │   └── class-module.php
    └── animations/
        ├── class-module.php
        └── assets/
            ├── animation.css       ← your uploaded CSS
            └── animation.js        ← your uploaded JS
```

## External hosting + auto-updates

The plugin is hosted on **octaveagency.com**. Two files live there:

| File | Public URL |
| --- | --- |
| The plugin zip | `https://octaveagency.com/plugins/octave-addons/octave-addons.zip` |
| The update manifest | `https://octaveagency.com/plugins/octave-addons/update.json` |

The plugin's `OCTAVE_ADDONS_UPDATE_URL` constant points at
`update.json` and WordPress polls it on the normal update cron.

### The "just overwrite the zip" workflow

The hosted side ships with a small `update.php` + `.htaccess` pair
(see the `octaveagency-hosting/` folder that came with this plugin).
The `.htaccess` rewrites `update.json` → `update.php`, and `update.php`
reads the zip's filemtime on every request and returns a manifest
whose `last_updated` and version reflect that timestamp.

That means your release workflow is:

1. Build a new `octave-addons.zip`.
2. Upload it to `https://octaveagency.com/plugins/octave-addons/`, overwriting the old zip.
3. Wait up to one WP cron cycle on the target site, or click *Dashboard → Updates → Check again* for an instant update.

No JSON to edit. No version to bump. Changing the file is enough.

### If you prefer a static manifest

Delete `update.php` and `.htaccess` on the server and drop a static
`update.json` next to the zip instead (copy
`update.json.example` from this plugin). In that case you'll need to
manually bump `last_updated` (and/or `version`) every time you replace
the zip.

### Manifest format (see `update.json.example`)

```json
{
  "name":          "Octave Addons",
  "slug":          "octave-addons",
  "version":       "1.0.0",
  "last_updated":  "2026-04-23 10:00:00",
  "download_url":  "https://octaveagency.com/plugins/octave-addons/octave-addons.zip",
  "requires":      "5.8",
  "tested":        "6.5",
  "requires_php":  "7.4",
  "author":        "Octave Agency",
  "homepage":      "https://octaveagency.com",
  "sections": {
    "description": "...",
    "changelog":   "= 1.0.0 =\n* ..."
  }
}
```

### Forcing an immediate check

Any admin can trigger a re-check by hitting *Dashboard → Updates →
Check again*, or visiting:

```
/wp-admin/?octave_addons_check_update=1&_wpnonce=<nonce>
```

## Adding new modules

See `modules/README.md` — drop a folder in, ship a class that extends
`Octave_Addons_Module`, done. No registry edits, no bootstrap changes.
