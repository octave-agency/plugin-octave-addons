<?php

/*
FIELD RENDERERS
-- Centralised HTML output helpers for every input type used in module settings.
-- Call these static methods from render_settings() in any module so all field
-- markup lives in one place and can be updated globally from here.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

    exit;

}

class Octave_Addons_Fields {


    /**
     * Wraps a field in a standard <tr><th scope="row"><td> row.
     *
     * @param array $args {

     *     @type string   $label  Label text (escaped with esc_html).
     *     @type callable $field  Callable that outputs the field HTML.
     *     @type string   $id     Optional row id attribute.
     *     @type string   $for    Optional: renders <label for=""> in the <th>.
     * }
     */
    public static function row( array $args ): void {

        $id    = $args['id']    ?? '';
        $label = $args['label'] ?? '';
        $for   = $args['for']   ?? '';
        $field = $args['field'] ?? null;
        ?>

        <tr<?= $id ? ' id="' . esc_attr( $id ) . '"' : ''; ?>>
            <th scope="row">
                <?php

                if ( $for ) :

                ?>

                    <label for="<?= esc_attr( $for ); ?>"><?= esc_html( $label ); ?></label>
                <?php

                else :

                ?>

                    <?= esc_html( $label ); ?>
                <?php

                endif;

                ?>

            </th>
            <td>
                <?php

                if ( is_callable( $field ) ) {

                    ( $field )();

                }

                ?>

            </td>
        </tr>
        <?php

    }

    /**
     * Renders a full-width section-heading row.
     *
     * @param array $args {

     *     @type string $label  Heading text.
     *     @type bool   $first  When true, omits the top border (first section).
     * }
     */
    public static function section( array $args ): void {

        $label = $args['label'] ?? '';
        $first = ! empty( $args['first'] );
        ?>

        <tr>
            <th colspan="2" class="oa-section-heading<?= $first ? ' oa-section-heading--first' : ''; ?>">
                <?= esc_html( $label ); ?>
            </th>
        </tr>
        <?php

    }

    /**
     * iOS-style toggle switch (checkbox + .oa-switch-slider).
     *
     * @param array $args {

     *     @type string $name     Field name attribute (required).
     *     @type string $id       Optional id attribute.
     *     @type bool   $checked  Whether the toggle is on.
     *     @type array  $data     Optional data-* attributes keyed by suffix,
     *                            e.g. ['controls-row' => 'row-id'].
     *     @type string $help     Optional description as .oa-help span.
     * }
     */
    public static function switch_field( array $args ): void {

        $name    = $args['name']    ?? '';
        $id      = $args['id']      ?? '';
        $checked = ! empty( $args['checked'] );
        $data    = $args['data']    ?? [];
        $help    = $args['help']    ?? '';

        $attrs = $id ? ' id="' . esc_attr( $id ) . '"' : '';
        foreach ( $data as $key => $val ) {

            $attrs .= ' data-' . esc_attr( $key ) . '="' . esc_attr( $val ) . '"';

        }
        ?>

        <label class="oa-switch">
            <input type="checkbox"<?= $attrs; ?>
                   name="<?= esc_attr( $name ); ?>"
                   value="1"<?php checked( $checked ); ?>>
            <span class="oa-switch-slider"></span>
        </label>
        <?php

        if ( $help ) :

        ?>

        <span class="oa-help"><?= esc_html( $help ); ?></span>
        <?php

        endif;

    }

    /**
     * Text input.
     *
     * @param array $args {

     *     @type string $name        Field name (required).
     *     @type string $id          Optional id.
     *     @type string $value       Current value.
     *     @type string $placeholder Placeholder text.
     *     @type string $class       CSS class (default: regular-text).
     *     @type string $help        Optional description as .oa-help span.
     * }
     */
    public static function text( array $args ): void {

        $name        = $args['name']        ?? '';
        $id          = $args['id']          ?? '';
        $value       = $args['value']       ?? '';
        $placeholder = $args['placeholder'] ?? '';
        $class       = $args['class']       ?? 'regular-text';
        $help        = $args['help']        ?? '';
        ?>

        <input type="text"<?= $id ? ' id="' . esc_attr( $id ) . '"' : ''; ?>
               name="<?= esc_attr( $name ); ?>"
               value="<?= esc_attr( $value ); ?>"
               class="<?= esc_attr( $class ); ?>"<?= $placeholder ? ' placeholder="' . esc_attr( $placeholder ) . '"' : ''; ?>>
        <?php

        if ( $help ) :

        ?>

        <span class="oa-help"><?= esc_html( $help ); ?></span>
        <?php

        endif;

    }

    /**
     * URL input.
     *
     * @param array $args  Same as ::text().
     */
    public static function url( array $args ): void {

        $name        = $args['name']        ?? '';
        $id          = $args['id']          ?? '';
        $value       = $args['value']       ?? '';
        $placeholder = $args['placeholder'] ?? '';
        $class       = $args['class']       ?? 'regular-text';
        $help        = $args['help']        ?? '';
        ?>

        <input type="url"<?= $id ? ' id="' . esc_attr( $id ) . '"' : ''; ?>
               name="<?= esc_attr( $name ); ?>"
               value="<?= esc_attr( $value ); ?>"
               class="<?= esc_attr( $class ); ?>"<?= $placeholder ? ' placeholder="' . esc_attr( $placeholder ) . '"' : ''; ?>>
        <?php

        if ( $help ) :

        ?>

        <span class="oa-help"><?= esc_html( $help ); ?></span>
        <?php

        endif;

    }

    /**
     * URL prefix widget — a read-only site prefix span followed by a slug input.
     *
     * @param array $args {

     *     @type string $name      Field name (required).
     *     @type string $id        Optional id.
     *     @type string $value     Current slug value.
     *     @type string $prefix    The URL prefix shown before the input (e.g. "https://example.com/").
     *     @type string $help      Optional plain-text description (escaped).
     *     @type string $help_html Optional pre-escaped HTML description (used instead of $help).
     * }
     */
    public static function url_prefix( array $args ): void {

        $name      = $args['name']      ?? '';
        $id        = $args['id']        ?? '';
        $value     = $args['value']     ?? '';
        $prefix    = $args['prefix']    ?? '';
        $help      = $args['help']      ?? '';
        $help_html = $args['help_html'] ?? '';
        ?>

        <div class="oa-url-prefix-wrap">
            <span class="oa-url-prefix"><?= esc_html( $prefix ); ?></span>
            <input type="text"
                   class="oa-url-slug-input"
                   <?= $id ? 'id="' . esc_attr( $id ) . '" ' : ''; ?>name="<?= esc_attr( $name ); ?>"
                   value="<?= esc_attr( $value ); ?>">
        </div>
        <?php

        if ( $help_html ) :

        ?>

        <span class="oa-help"><?php echo $help_html; // Caller is responsible for escaping. ?></span>
        <?php

        elseif ( $help ) :

        ?>

        <span class="oa-help"><?= esc_html( $help ); ?></span>
        <?php

        endif;

    }

    /**
     * Email input.
     *
     * @param array $args  Same as ::text().
     */
    public static function email( array $args ): void {

        $name        = $args['name']        ?? '';
        $id          = $args['id']          ?? '';
        $value       = $args['value']       ?? '';
        $placeholder = $args['placeholder'] ?? '';
        $class       = $args['class']       ?? 'regular-text';
        $help        = $args['help']        ?? '';
        ?>

        <input type="email"<?= $id ? ' id="' . esc_attr( $id ) . '"' : ''; ?>
               name="<?= esc_attr( $name ); ?>"
               value="<?= esc_attr( $value ); ?>"
               class="<?= esc_attr( $class ); ?>"<?= $placeholder ? ' placeholder="' . esc_attr( $placeholder ) . '"' : ''; ?>>
        <?php

        if ( $help ) :

        ?>

        <span class="oa-help"><?= esc_html( $help ); ?></span>
        <?php

        endif;

    }

    /**
     * Textarea.
     *
     * @param array $args {
     *     @type string $name        Field name (required).
     *     @type string $id          Optional id.
     *     @type string $value       Current value.
     *     @type string $placeholder Placeholder text.
     *     @type int    $rows        Row count (default: 3).
     *     @type string $class       CSS class (default: regular-text).
     *     @type bool   $spellcheck  Set false to add spellcheck="false" (omitted by default).
     *     @type string $help        Optional description as .oa-help span.
     * }
     */
    public static function textarea( array $args ): void {

        $name        = $args['name']        ?? '';
        $id          = $args['id']          ?? '';
        $value       = $args['value']       ?? '';
        $placeholder = $args['placeholder'] ?? '';
        $rows        = isset( $args['rows'] ) ? (int) $args['rows'] : 3;
        $class       = $args['class']       ?? 'regular-text';
        $spellcheck  = $args['spellcheck']  ?? null;
        $help        = $args['help']        ?? '';
        ?>

        <textarea<?= $id ? ' id="' . esc_attr( $id ) . '"' : ''; ?>
                  name="<?= esc_attr( $name ); ?>"
                  class="<?= esc_attr( $class ); ?>"
                  rows="<?= $rows; ?>"<?php
                  echo $placeholder ? ' placeholder="' . esc_attr( $placeholder ) . '"' : '';
                  echo ( false === $spellcheck ) ? ' spellcheck="false"' : '';
                  ?>><?= esc_textarea( $value ); ?></textarea>
        <?php

        if ( $help ) :

        ?>

        <span class="oa-help"><?= esc_html( $help ); ?></span>
        <?php

        endif;

    }

    /**
     * Native HTML5 colour picker with hex value display.
     *
     * @param array $args {

     *     @type string $name   Field name (required).
     *     @type string $id     Optional id.
     *     @type string $value  Current hex value (default: #000000).
     *     @type string $help   Optional description as .oa-help span.
     * }
     */
    public static function color( array $args ): void {

        $name  = $args['name']  ?? '';
        $id    = $args['id']    ?? '';
        $value = $args['value'] ?? '#000000';
        $help  = $args['help']  ?? '';
        ?>

        <div class="oa-color-picker-wrap">
            <input type="color"<?= $id ? ' id="' . esc_attr( $id ) . '"' : ''; ?>
                   name="<?= esc_attr( $name ); ?>"
                   value="<?= esc_attr( $value ); ?>"
                   class="oa-color-input">
            <code class="oa-color-value"><?= esc_html( $value ); ?></code>
        </div>
        <?php

        if ( $help ) :

        ?>

        <span class="oa-help"><?= esc_html( $help ); ?></span>
        <?php

        endif;

    }

}
