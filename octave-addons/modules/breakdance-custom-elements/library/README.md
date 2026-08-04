# Octave element library

Generic Breakdance elements that ship with Octave Addons and are meant to be
reused on every site. They register under the `OctaveCustomElements` namespace
and appear in the **Octave Elements** category in the builder.

## What belongs here

Elements with no client-specific copy, branding or layout assumptions — the
kind of thing Breakdance itself does not ship. If an element only makes sense
on one site, it does not belong here.

## Switching elements on and off

**Octave Addons → Breakdance Elements** lists every element found in the
library and in the site locations, each with its own switch and its builder
icon. The list is built by reflection over the loaded element classes, so a new
element folder appears on the next page load with no registration step, and
starts switched on.

Switching an element off removes it from Breakdance's add panel via the
`breakdance_builder_elements` filter. Pages that already use the element keep
rendering it — the switch controls what can be *inserted*, not what already
exists, so turning one off is never destructive.

## Editing a shipped element

`Octave_Addons_Elements_Manifest` fingerprints every library element while the
files are pristine. Any element whose files later diverge is flagged
**Customised** in the admin and is copied aside before a plugin update, then
restored over the newly installed files. In other words, once you edit a
shipped element on a site, that site keeps your version and stops receiving
upstream changes for it. Revert the files to their shipped state and the flag
clears, and updates resume.

## Where site-specific elements go

This folder is registered as **read-only** in Element Studio, so the builder
will never save into it and a plugin update cannot destroy site work. Element
Studio offers these writable locations instead:

| Location | Path | Survives plugin updates |
| --- | --- | --- |
| Octave Elements (Site) | `wp-content/plugins/octave-elements/` | Yes |
| Octave Elements (Plugin) | `octave-addons/modules/breakdance-custom-elements/elements/` | No |

Breakdance only loads elements from inside `wp-content/plugins`, so the
external location has to live there too. Create the folder and it is picked up
automatically:

```
mkdir wp-content/plugins/octave-elements
```

Each element sits in its own subfolder — `octave-elements/My_Element/element.php`.
No plugin header is needed; WordPress ignores the folder and Breakdance scans it.

Extra locations can be registered with the
`octave_addons_breakdance_save_locations` filter, which receives an array of
`path relative to wp-content/plugins => Element Studio label`.

## Element anatomy

| File | Purpose |
| --- | --- |
| `element.php` | Class, controls, defaults, dependencies |
| `html.twig` | Markup; `%%SSR%%` defers to `ssr.php` |
| `css.twig` | Styles driven by design controls, `%%SELECTOR%%` scoped |
| `default.css` | Static base styles |
| `ssr.php` | Optional server render, receives `$propertiesData` |
| `*.js` | Optional behaviour, exposed as a re-runnable `window.oa*Init()` |

Elements that need JavaScript expose a global init function and re-run it from
`actions()` so the builder canvas stays live while properties change.

## Current elements

| Element | What it does |
| --- | --- |
| OA Countdown | Fixed-date, evergreen per-visitor, or recurring daily/weekly timer |
| OA Copy Text | Click-to-copy button for a value or another node on the page |
| OA Copyright | Footer line with a year generated at render time |
| OA Logo Marquee | Seamless CSS logo strip, no slider library |
| OA Reading Time | Reading estimate and word count for the current post |
