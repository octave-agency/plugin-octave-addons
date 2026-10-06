# Adding a new module

Octave Addons auto-discovers every subdirectory of `/modules/`. To add a
new add-on, create a folder with a `class-module.php` file that extends
`Octave_Addons_Module` and `return`s an instance.

```
modules/
└── my-new-module/
    ├── class-module.php     ← required
    └── assets/               ← optional
```

## Areas

A folder with no `class-module.php` of its own is an area. Discovery looks
one level inside it, and the area's folder name is the admin group every
module in it joins: one accordion in the sidebar, one shared settings page.

```
modules/
├── ai-agents/          ← AI Agents
├── breakdance/         ← Breakdance
├── content/            ← Content
├── design/             ← Design
├── engagement/         ← Engagement
└── performance/        ← Performance
```

So to add a module to a group, drop its folder into that area — no
`get_group()` needed. A module can still return its own id from
`get_group()` to sit in a different group than its folder. A new area gets
its title from the folder name; give it a description and icon in
`group_config()` and `module_icon()` in `includes/class-admin.php`.

Inside an area, drop the area's name from the folder: the path already
carries it, so `breakdance/spacing/` rather than
`breakdance/breakdance-spacing/`.

Module ids are a different matter. They live in one flat namespace across
every area — the manager keys modules by `get_id()` alone — so an id has to
stand on its own and keeps its prefix: the module in `breakdance/spacing/`
still returns `breakdance-spacing`. The id is also the key its settings are
stored under, so renaming a folder costs nothing while renaming an id
discards what every site has saved.

## Minimum boilerplate

```php
<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Octave_Addons_Module_My_New_Module extends Octave_Addons_Module {

    public function get_id(): string {
        return 'my-new-module';
    }

    public function get_title(): string {
        return 'My New Module';
    }

    public function get_description(): string {
        return 'Short one-line description of what this add-on does.';
    }

    public function get_defaults(): array {
        return [
            'enabled' => false,
            // add your own keys here
        ];
    }

    public function sanitize( $input ): array {
        $clean            = $this->get_defaults();
        $clean['enabled'] = ! empty( $input['enabled'] );
        // sanitize your own keys here
        return $clean;
    }

    public function render_settings( array $s ): void {
        // Output <table class="form-table">…</table> with your fields.
        // Use $this->field_name('your_key') for input names.
    }

    public function run( array $s ): void {
        // Called on `init` only if the module is enabled. Register
        // your hooks here.
    }
}

return new Octave_Addons_Module_My_New_Module();
```

## Hooks

A tab appears automatically in the admin once the folder is in place —
no registration elsewhere is needed.

You can also register modules from another plugin by hooking into the
`octave_addons_register_modules` filter:

```php
add_filter( 'octave_addons_register_modules', function ( array $modules ) {
    $mine                = new My_External_Module();
    $modules[ $mine->get_id() ] = $mine;
    return $modules;
} );
```

## Tests

`tests/` holds a dependency-free suite, excluded from the release package:

```
tests/fetch-wp-core.sh   # once: downloads WordPress's HTML API into tests/.wp-core
php tests/run.php        # PHP tests; pass a name fragment to filter
node tests/js/run.js     # frontend script tests
```

## Performance services

Modules under `performance/` share the services in `performance/services/`
(that folder has no `class-module.php`, so discovery skips it). A module that
rewrites page markup registers a transformer with
`Octave_Addons_Perf_Html::register()` instead of starting its own output
buffer, and checks `Octave_Addons_Perf_Context::can_optimize()` for anything
it does outside that pipeline. `register()` takes an optional fourth argument,
a callable returning false when the transformer has nothing to do on the
request (for instance because `Octave_Addons_Perf::handled_elsewhere()` names
another plugin), so no buffer starts when nothing needs it.

Frontend assets are enqueued through `Octave_Addons_Module::asset()`, which
serves the committed `.min.js` / `.min.css` copy unless `SCRIPT_DEBUG` is on.
After editing a frontend asset, run `tests/build-assets.sh`; the JS tests fail
while a minified copy is missing or stale.
