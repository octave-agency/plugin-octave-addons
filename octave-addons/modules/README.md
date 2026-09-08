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

A folder with no `class-module.php` of its own is an area: a place to keep
related modules together as the plugin grows. Discovery looks one level
inside it.

```
modules/
├── ai-agents/
│   └── markdown-negotiation/
│       └── class-module.php
├── breakdance/
│   ├── ajax-filtering/
│   ├── custom-elements/
│   └── spacing/
└── animations/
    └── class-module.php
```

An area is filing only. It has no effect on the admin — what collapses
modules onto one page is still the group id each module returns from
`get_group()`, so a module can be filed in one place and presented in
another, and either can change without the other. Nesting is optional:
a module folder at the top level keeps working exactly as before.

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
