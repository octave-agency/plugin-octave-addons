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
     * Custom colour picker with a swatch, saturation control and hex input.
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

        $value = sanitize_hex_color( $value ) ?: '#000000';
        ?>

        <div class="oa-color-picker-wrap" data-value="<?= esc_attr( $value ); ?>">
            <input type="hidden"<?= $id ? ' id="' . esc_attr( $id . '-value' ) . '"' : ''; ?>
                   name="<?= esc_attr( $name ); ?>"
                   value="<?= esc_attr( $value ); ?>"
                   class="oa-color-input">
            <button type="button"<?= $id ? ' id="' . esc_attr( $id ) . '"' : ''; ?> class="oa-color-trigger" aria-haspopup="dialog" aria-expanded="false">
                <span class="oa-color-swatch" style="background-color: <?= esc_attr( $value ); ?>;"></span>
                <code class="oa-color-value"><?= esc_html( strtoupper( $value ) ); ?></code>
                <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                <span class="screen-reader-text"><?php esc_html_e( 'Choose colour', 'octave-addons' ); ?></span>
            </button>
            <div class="oa-color-popover" role="dialog" aria-label="<?php esc_attr_e( 'Colour picker', 'octave-addons' ); ?>" hidden>
                <div class="oa-color-saturation" role="slider" tabindex="0" aria-label="<?php esc_attr_e( 'Colour saturation and brightness', 'octave-addons' ); ?>">
                    <span class="oa-color-saturation-white" aria-hidden="true"></span>
                    <span class="oa-color-saturation-black" aria-hidden="true"></span>
                    <span class="oa-color-thumb" aria-hidden="true"></span>
                </div>
                <label class="oa-color-control">
                    <span><?php esc_html_e( 'Hue', 'octave-addons' ); ?></span>
                    <input type="range" class="oa-color-hue" min="0" max="359" step="1">
                </label>
                <label class="oa-color-control oa-color-hex-control">
                    <span><?php esc_html_e( 'Hex', 'octave-addons' ); ?></span>
                    <input type="text" class="oa-color-hex" value="<?= esc_attr( strtoupper( $value ) ); ?>" maxlength="7" spellcheck="false">
                </label>
            </div>
        </div>
        <?php

        if ( $help ) :

        ?>

        <span class="oa-help"><?= esc_html( $help ); ?></span>
        <?php

        endif;

    }

    /**
     * WordPress Media Library image field with preview and replace/remove actions.
     *
     * @param array $args {
     *
     *     @type string $name   Field name (required).
     *     @type string $id     Field id (required).
     *     @type string $value  Current image URL.
     *     @type string $help   Optional description as .oa-help span.
     * }
     */
    public static function media_image( array $args ): void {

        $name      = $args['name']  ?? '';
        $id        = $args['id']    ?? '';
        $value     = $args['value'] ?? '';
        $help      = $args['help']  ?? '';
        $has_image = '' !== $value;
        ?>

        <div class="oa-media-field<?= $has_image ? ' has-image' : ''; ?>">
            <input type="hidden"
                   id="<?= esc_attr( $id ); ?>"
                   name="<?= esc_attr( $name ); ?>"
                   value="<?= esc_attr( $value ); ?>"
                   class="oa-media-url">
            <div class="oa-media-preview">
                <img src="<?= esc_url( $value ); ?>" alt=""<?= $has_image ? '' : ' hidden'; ?>>
                <span class="oa-media-placeholder"<?= $has_image ? ' hidden' : ''; ?>>
                    <span class="dashicons dashicons-format-image" aria-hidden="true"></span>
                    <?php esc_html_e( 'No image selected', 'octave-addons' ); ?>
                </span>
            </div>
            <div class="oa-media-actions">
                <button type="button" id="<?= esc_attr( $id . '-select' ); ?>" class="button oa-media-select">
                    <?= $has_image ? esc_html__( 'Replace image', 'octave-addons' ) : esc_html__( 'Select image', 'octave-addons' ); ?>
                </button>
                <button type="button" class="button oa-media-remove"<?= $has_image ? '' : ' hidden'; ?>>
                    <?php esc_html_e( 'Remove', 'octave-addons' ); ?>
                </button>
            </div>
        </div>
        <?php

        if ( $help ) :

        ?>

        <span class="oa-help"><?= esc_html( $help ); ?></span>
        <?php

        endif;

    }


    /**
     * Date input.
     *
     * @param array $args {

     *     @type string $name   Field name (required).
     *     @type string $id     Optional id.
     *     @type string $value  Current value as YYYY-MM-DD.
     *     @type string $min    Optional earliest selectable date.
     *     @type string $max    Optional latest selectable date.
     *     @type string $class  CSS class (default: oa-date-input).
     *     @type string $help   Optional description as .oa-help span.
     * }
     */
    public static function date( array $args ): void {

        $name  = $args['name']  ?? '';
        $id    = $args['id']    ?? '';
        $value = $args['value'] ?? '';
        $min   = $args['min']   ?? '';
        $max   = $args['max']   ?? '';
        $class = $args['class'] ?? 'oa-date-input';
        $help  = $args['help']  ?? '';
        ?>

        <input type="date"<?= $id ? ' id="' . esc_attr( $id ) . '"' : ''; ?>
               name="<?= esc_attr( $name ); ?>"
               value="<?= esc_attr( $value ); ?>"
               class="<?= esc_attr( $class ); ?>"<?php
               echo $min ? ' min="' . esc_attr( $min ) . '"' : '';
               echo $max ? ' max="' . esc_attr( $max ) . '"' : '';
               ?>>
        <?php

        if ( $help ) :

        ?>

        <span class="oa-help"><?= esc_html( $help ); ?></span>
        <?php

        endif;

    }

    /**
     * Number input.
     *
     * @param array $args {

     *     @type string $name   Field name (required).
     *     @type string $id     Optional id.
     *     @type int    $value  Current value.
     *     @type int    $min    Smallest accepted value.
     *     @type int    $max    Largest accepted value.
     *     @type int    $step   Increment (default: 1).
     *     @type string $suffix Optional unit shown after the input.
     *     @type string $class  CSS class (default: small-text).
     *     @type string $help   Optional description as .oa-help span.
     * }
     */
    public static function number( array $args ): void {

        $name   = $args['name']   ?? '';
        $id     = $args['id']     ?? '';
        $value  = $args['value']  ?? '';
        $step   = $args['step']   ?? 1;
        $suffix = $args['suffix'] ?? '';
        $class  = $args['class']  ?? 'small-text';
        $help   = $args['help']   ?? '';
        ?>

        <span class="oa-number-field">
            <input type="number"<?= $id ? ' id="' . esc_attr( $id ) . '"' : ''; ?>
                   name="<?= esc_attr( $name ); ?>"
                   value="<?= esc_attr( (string) $value ); ?>"
                   class="<?= esc_attr( $class ); ?>"
                   step="<?= esc_attr( (string) $step ); ?>"<?php
                   echo isset( $args['min'] ) ? ' min="' . esc_attr( (string) $args['min'] ) . '"' : '';
                   echo isset( $args['max'] ) ? ' max="' . esc_attr( (string) $args['max'] ) . '"' : '';
                   ?>>
            <?php

            if ( $suffix ) :

            ?>

            <span class="oa-number-suffix"><?= esc_html( $suffix ); ?></span>
            <?php

            endif;

            ?>

        </span>
        <?php

        if ( $help ) :

        ?>

        <span class="oa-help"><?= esc_html( $help ); ?></span>
        <?php

        endif;

    }

    /*
    BACKGROUND
    -- One control covering a solid colour and a two-stop linear gradient.
    -- The type select decides which half of the control is shown, and the
    -- swatch previews the compiled CSS value while it is being edited.
    -- Values submit as an array under the supplied base name, so a caller
    -- reads $input[<key>]['type'], ['color'], ['from'], ['to'] and ['angle'].
    ---------------------------------------------------------- */

    public static function background( array $args ): void {

        $name  = $args['name']  ?? '';
        $id    = $args['id']    ?? '';
        $value = is_array( $args['value'] ?? null ) ? $args['value'] : [];
        $help  = $args['help']  ?? '';

        $type  = 'gradient' === ( $value['type'] ?? '' ) ? 'gradient' : 'solid';
        $color = sanitize_hex_color( $value['color'] ?? '' ) ?: '#111827';
        $from  = sanitize_hex_color( $value['from'] ?? '' ) ?: '#111827';
        $to    = sanitize_hex_color( $value['to'] ?? '' ) ?: '#2563EB';
        $angle = isset( $value['angle'] ) ? max( 0, min( 360, (int) $value['angle'] ) ) : 135;

        $preview = 'gradient' === $type
            ? sprintf( 'linear-gradient(%ddeg, %s, %s)', $angle, $from, $to )
            : $color;
        ?>

        <div class="oa-background-field" data-oa-background>
            <div class="oa-background-head">
                <span class="oa-background-preview" style="background: <?= esc_attr( $preview ); ?>;" aria-hidden="true"></span>
                <select class="oa-background-type"<?= $id ? ' id="' . esc_attr( $id ) . '"' : ''; ?>
                        name="<?= esc_attr( $name . '[type]' ); ?>"
                        aria-label="<?php esc_attr_e( 'Background type', 'octave-addons' ); ?>">
                    <option value="solid"<?php selected( $type, 'solid' ); ?>><?php esc_html_e( 'Solid colour', 'octave-addons' ); ?></option>
                    <option value="gradient"<?php selected( $type, 'gradient' ); ?>><?php esc_html_e( 'Gradient', 'octave-addons' ); ?></option>
                </select>
            </div>

            <div class="oa-background-solid<?= 'solid' === $type ? '' : ' oa-hidden'; ?>">
                <div class="oa-background-stop">
                    <span><?php esc_html_e( 'Colour', 'octave-addons' ); ?></span>
                    <?php

                    self::color( [
                        'name'  => $name . '[color]',
                        'value' => $color,
                    ] );

                    ?>

                </div>
            </div>

            <div class="oa-background-gradient<?= 'gradient' === $type ? '' : ' oa-hidden'; ?>">
                <div class="oa-background-stop">
                    <span><?php esc_html_e( 'From', 'octave-addons' ); ?></span>
                    <?php

                    self::color( [
                        'name'  => $name . '[from]',
                        'value' => $from,
                    ] );

                    ?>

                </div>
                <div class="oa-background-stop">
                    <span><?php esc_html_e( 'To', 'octave-addons' ); ?></span>
                    <?php

                    self::color( [
                        'name'  => $name . '[to]',
                        'value' => $to,
                    ] );

                    ?>

                </div>
                <label class="oa-background-stop oa-background-angle">
                    <span><?php esc_html_e( 'Angle', 'octave-addons' ); ?></span>
                    <?php

                    self::number( [
                        'name'   => $name . '[angle]',
                        'value'  => $angle,
                        'min'    => 0,
                        'max'    => 360,
                        'step'   => 5,
                        'suffix' => 'deg',
                    ] );

                    ?>

                </label>
            </div>
        </div>
        <?php

        if ( $help ) :

        ?>

        <span class="oa-help"><?= esc_html( $help ); ?></span>
        <?php

        endif;

    }

    /*
    BACKGROUND CSS
    -- Compiles a value stored by ::background() into one CSS background value.
    -- Returns an empty string when the array carries nothing usable, so a
    -- caller can fall back to its own default rather than print "background: ".
    ---------------------------------------------------------- */

    public static function background_css( $value ): string {

        if ( ! is_array( $value ) ) {

            return '';

        }

        if ( 'gradient' === ( $value['type'] ?? '' ) ) {

            $from = sanitize_hex_color( $value['from'] ?? '' );
            $to   = sanitize_hex_color( $value['to'] ?? '' );

            if ( ! $from || ! $to ) {

                return '';

            }

            return sprintf(
                'linear-gradient(%ddeg, %s, %s)',
                max( 0, min( 360, (int) ( $value['angle'] ?? 135 ) ) ),
                $from,
                $to
            );

        }

        return (string) ( sanitize_hex_color( $value['color'] ?? '' ) ?: '' );

    }

    /*
    SANITIZE BACKGROUND
    -- Normalises a submitted background array against a set of defaults, so a
    -- module's sanitize() can hand the raw input straight through.
    ---------------------------------------------------------- */

    public static function sanitize_background( $input, array $defaults ): array {

        $input = is_array( $input ) ? $input : [];

        return [
            'type'  => 'gradient' === ( $input['type'] ?? '' ) ? 'gradient' : 'solid',
            'color' => sanitize_hex_color( $input['color'] ?? '' ) ?: $defaults['color'],
            'from'  => sanitize_hex_color( $input['from'] ?? '' )  ?: $defaults['from'],
            'to'    => sanitize_hex_color( $input['to'] ?? '' )    ?: $defaults['to'],
            'angle' => max( 0, min( 360, (int) ( $input['angle'] ?? $defaults['angle'] ) ) ),
        ];

    }


}
