<?php

/*
ELEMENT: OA COPY TEXT
-- Click-to-copy button for any value: an email address, a phone number,
-- a coupon code, an API key or the text of another node on the page.
---------------------------------------------------------- */

namespace OctaveCustomElements;

use function Breakdance\Elements\c;
use function Breakdance\Elements\PresetSections\getPresetSection;

\Breakdance\ElementStudio\registerElementForEditing(
    "OctaveCustomElements\\OaCopyText",
    \Breakdance\Util\getdirectoryPathRelativeToPluginFolder(__DIR__)
);

class OaCopyText extends \Breakdance\Elements\Element
{

    static function uiIcon()
    {

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';

    }

    static function tag()
    {

        return 'div';

    }

    static function tagOptions()
    {

        return [];

    }

    static function tagControlPath()
    {

        return false;

    }

    static function name()
    {

        return 'Copy Text';

    }

    static function className()
    {

        return 'oa_copy_text';

    }

    static function category()
    {

        return 'oa_custom_elements';

    }

    static function badge()
    {

        return ['label' => 'OA', 'backgroundColor' => 'var(--white-fixed)', 'textColor' => 'var(--white)'];

    }

    static function slug()
    {

        return __CLASS__;

    }

    static function template()
    {

        return file_get_contents(__DIR__ . '/html.twig');

    }

    static function defaultCss()
    {

        return file_get_contents(__DIR__ . '/default.css');

    }

    static function cssTemplate()
    {

        return file_get_contents(__DIR__ . '/css.twig');

    }

    static function defaultProperties()
    {

        return [
            'content' => [
                'copy' => [
                    'source' => 'value',
                    'value' => 'hello@example.com',
                    'show_value' => true,
                ],
                'button' => [
                    'label' => 'Copy',
                    'label_copied' => 'Copied!',
                    'show_icon' => true,
                    'reset_after' => 2,
                ],
            ],
            'design' => [
                'layout' => [
                    'direction' => 'row',
                    'align' => 'flex-start',
                ],
            ],
        ];

    }

    static function defaultChildren()
    {

        return false;

    }

    static function contentControls()
    {

        return [c(
            "copy",
            "Value",
            [c(
                "source",
                "Copy from",
                [],
                ['type' => 'dropdown', 'layout' => 'vertical', 'items' => [
                    ['value' => 'value', 'text' => 'This value'],
                    ['value' => 'selector', 'text' => 'Another element on the page'],
                ]],
                false,
                false,
                []
            ), c(
                "value",
                "Text to copy",
                [],
                ['type' => 'text', 'layout' => 'vertical', 'condition' => ['path' => 'content.copy.source', 'operand' => 'equals', 'value' => 'value']],
                false,
                false,
                []
            ), c(
                "selector",
                "CSS selector",
                [],
                ['type' => 'text', 'layout' => 'vertical', 'placeholder' => '#my-code', 'condition' => ['path' => 'content.copy.source', 'operand' => 'equals', 'value' => 'selector']],
                false,
                false,
                []
            ), c(
                "show_value",
                "Show the value",
                [],
                ['type' => 'toggle', 'layout' => 'inline', 'condition' => ['path' => 'content.copy.source', 'operand' => 'equals', 'value' => 'value']],
                false,
                false,
                []
            )],
            ['type' => 'section', 'layout' => 'vertical', 'sectionOptions' => ['type' => 'accordion']],
            false,
            false,
            []
        ), c(
            "button",
            "Button",
            [c(
                "label",
                "Label",
                [],
                ['type' => 'text', 'layout' => 'vertical'],
                false,
                false,
                []
            ), c(
                "label_copied",
                "Label after copying",
                [],
                ['type' => 'text', 'layout' => 'vertical'],
                false,
                false,
                []
            ), c(
                "show_icon",
                "Show icon",
                [],
                ['type' => 'toggle', 'layout' => 'inline'],
                false,
                false,
                []
            ), c(
                "icon",
                "Custom icon",
                [],
                ['type' => 'icon', 'layout' => 'vertical', 'condition' => ['path' => 'content.button.show_icon', 'operand' => 'equals', 'value' => true]],
                false,
                false,
                []
            ), c(
                "reset_after",
                "Reset after (seconds)",
                [],
                ['type' => 'number', 'layout' => 'inline', 'rangeOptions' => ['min' => 1, 'max' => 30, 'step' => 1]],
                false,
                false,
                []
            )],
            ['type' => 'section', 'layout' => 'vertical', 'sectionOptions' => ['type' => 'accordion']],
            false,
            false,
            []
        )];

    }

    static function designControls()
    {

        return [c(
            "layout",
            "Layout",
            [c(
                "direction",
                "Direction",
                [],
                ['type' => 'button_bar', 'layout' => 'vertical', 'items' => [
                    ['value' => 'row', 'text' => 'Row'],
                    ['value' => 'column', 'text' => 'Column'],
                ]],
                true,
                false,
                []
            ), c(
                "align",
                "Align",
                [],
                ['type' => 'button_bar', 'layout' => 'vertical', 'items' => [
                    ['value' => 'flex-start', 'text' => 'Start'],
                    ['value' => 'center', 'text' => 'Center'],
                    ['value' => 'flex-end', 'text' => 'End'],
                ]],
                true,
                false,
                []
            ), c(
                "gap",
                "Gap",
                [],
                ['type' => 'unit', 'layout' => 'inline', 'rangeOptions' => ['min' => 0, 'max' => 80, 'step' => 1], 'unitOptions' => ['types' => ['px', 'em', 'rem'], 'defaultType' => 'px']],
                true,
                false,
                []
            )],
            ['type' => 'section', 'layout' => 'vertical', 'sectionOptions' => ['type' => 'accordion']],
            false,
            false,
            []
        ), getPresetSection(
            "EssentialElements\\typography",
            "Value typography",
            "value_typography",
            ['type' => 'popout']
        ), getPresetSection(
            "EssentialElements\\typography",
            "Button typography",
            "button_typography",
            ['type' => 'popout']
        ), c(
            "button",
            "Button",
            [c(
                "background",
                "Background",
                [],
                ['type' => 'color', 'layout' => 'inline'],
                false,
                false,
                []
            ), c(
                "background_hover",
                "Background (hover)",
                [],
                ['type' => 'color', 'layout' => 'inline'],
                false,
                false,
                []
            ), c(
                "border_color",
                "Border color",
                [],
                ['type' => 'color', 'layout' => 'inline'],
                false,
                false,
                []
            ), c(
                "padding",
                "Padding",
                [],
                ['type' => 'spacing_complex', 'layout' => 'vertical'],
                true,
                false,
                []
            ), c(
                "radius",
                "Radius",
                [],
                ['type' => 'unit', 'layout' => 'inline', 'rangeOptions' => ['min' => 0, 'max' => 100, 'step' => 1], 'unitOptions' => ['types' => ['px', '%'], 'defaultType' => 'px']],
                false,
                false,
                []
            ), c(
                "icon_size",
                "Icon size",
                [],
                ['type' => 'unit', 'layout' => 'inline', 'rangeOptions' => ['min' => 8, 'max' => 48, 'step' => 1], 'unitOptions' => ['types' => ['px', 'em'], 'defaultType' => 'px']],
                false,
                false,
                []
            )],
            ['type' => 'section', 'layout' => 'vertical', 'sectionOptions' => ['type' => 'accordion']],
            false,
            false,
            []
        ), getPresetSection(
            "EssentialElements\\spacing_margin_y",
            "Spacing",
            "spacing",
            ['type' => 'popout']
        )];

    }

    static function settingsControls()
    {

        return [];

    }

    static function dependencies()
    {

        $url = defined('OCTAVE_ADDONS_URL') ? OCTAVE_ADDONS_URL : plugin_dir_url(dirname(__DIR__, 3) . '/octave-addons.php');
        $version = defined('OCTAVE_ADDONS_VERSION') ? OCTAVE_ADDONS_VERSION : '1.0.0';

        return [
            [
                'title' => 'OA Copy Text',
                'scripts' => [$url . 'modules/breakdance-custom-elements/library/OA_Copy_Text/copy-text.js?ver=' . $version],
            ],
            [
                'title' => 'OA Copy Text - init',
                'inlineScripts' => ['window.oaCopyTextInit && window.oaCopyTextInit();'],
                'builderCondition' => 'return true;',
                'frontendCondition' => 'return true;',
            ],
        ];

    }

    static function settings()
    {

        return false;

    }

    static function addPanelRules()
    {

        return false;

    }

    static public function actions()
    {

        return [
            'onPropertyChange' => [['script' => 'window.oaCopyTextInit && window.oaCopyTextInit();']],
            'onCreatedElement' => [['script' => 'window.oaCopyTextInit && window.oaCopyTextInit();']],
            'onMountedElement' => [['script' => 'window.oaCopyTextInit && window.oaCopyTextInit();']],
        ];

    }

    static function nestingRule()
    {

        return ['type' => 'final'];

    }

    static function spacingBars()
    {

        return false;

    }

    static function attributes()
    {

        return false;

    }

    static function experimental()
    {

        return false;

    }

    static function availableIn()
    {

        return ['breakdance'];

    }

    static function order()
    {

        return 0;

    }

    static function dynamicPropertyPaths()
    {

        return [['accepts' => 'string', 'path' => 'content.copy.value']];

    }

    static function additionalClasses()
    {

        return false;

    }

    static function projectManagement()
    {

        return false;

    }

    static function propertyPathsToWhitelistInFlatProps()
    {

        return false;

    }

    static function propertyPathsToSsrElementWhenValueChanges()
    {

        return false;

    }

}
