# Octave Addons

A modular WordPress plugin by Octave Agency. One settings screen, many
toggleable add-ons, and native WordPress updates powered by GitHub Releases.

## What's inside (v1.0.1)

| Add-on | Description |
| --- | --- |
| **Empty Link Highlighter** | Flags frontend `<a>` tags with empty / `#` hrefs. Style, colour, visibility and admin-bar counter are configurable. |
| **Disable Comments** | Removes comment support, the comments REST endpoint, admin menus, and the admin-bar icon. |
| **Scroll Animations** | Enqueues fade/slide-in CSS and IntersectionObserver JS, with per-site overrides. |
| **Custom Login** | Provides a configurable branded WordPress login screen. |
| **Mobile Contact Popup** | Adds a configurable contact popup for mobile visitors. |
| **Breakdance Custom Elements** | Provides a persistent location for locally saved Breakdance elements. |

## Folder layout

```text
octave-addons/
├── octave-addons.php              Plugin bootstrap and constants
├── readme.txt                     WordPress-style readme
├── assets/                        Shared admin assets
├── includes/
│   ├── class-module.php           Abstract module base class
│   ├── class-module-manager.php   Module discovery and dispatch
│   ├── class-admin.php            Settings interface
│   ├── class-updater.php          GitHub Releases update checker
│   └── class-octave-addons.php    Main singleton
└── modules/                       Self-contained add-ons
```

## GitHub-powered WordPress updates

WordPress checks the latest published release from
`octave-agency/plugin-octave-addons`. If its tag version is newer than
`OCTAVE_ADDONS_VERSION`, the release appears in the standard Plugins and
Dashboard Updates screens.

The release workflow packages the plugin as `octave-addons.zip`, publishes the
GitHub release, and makes that ZIP the preferred WordPress update download.

### Publishing a release

1. Update the `Version` plugin header in `octave-addons.php`.
2. Update `Stable tag` and the changelog in `readme.txt`.
3. Commit and push the changes to `main`.
4. Create and push a matching tag, such as `v1.1.0`.

```bash
git tag v1.1.0
git push origin v1.1.0
```

The GitHub Actions workflow validates that the tag matches the plugin version,
builds an installable ZIP, and publishes a GitHub Release. Draft releases and
prereleases are ignored by GitHub's latest-release endpoint.

### Forcing an immediate check

Use **Dashboard → Updates → Check again**. The updater caches GitHub responses
for six hours during normal WordPress update checks.

## Adding new modules

See `modules/README.md`. Add a folder containing a `class-module.php` class that
extends `Octave_Addons_Module`; the module manager discovers it automatically.
