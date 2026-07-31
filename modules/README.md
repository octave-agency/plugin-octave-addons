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
