<?php

/*
ELEMENT: OA READING TIME
-- Estimated reading time for the post being rendered, for blog and resource
-- templates. Word count is measured server side from the post content.
---------------------------------------------------------- */

namespace OctaveCustomElements;

use function Breakdance\Elements\c;
use function Breakdance\Elements\PresetSections\getPresetSection;

\Breakdance\ElementStudio\registerElementForEditing(
    "OctaveCustomElements\\OaReadingTime",
    \Breakdance\Util\getdirectoryPathRelativeToPluginFolder(__DIR__)
);

class OaReadingTime extends \Breakdance\Elements\Element
{

    static function uiIcon()
    {

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5.5A2.5 2.5 0 0 1 5.5 3H11v16H5.5A2.5 2.5 0 0 0 3 21.5z"/><path d="M21 5.5A2.5 2.5 0 0 0 18.5 3H13v16h5.5a2.5 2.5 0 0 1 2.5 2.5z"/></svg>';

    }

    static function tag()
    {

        return 'div';

    }

    static function tagOptions()
    {

        return ['div', 'span', 'p'];

    }

    static function tagControlPath()
    {

        return false;

    }

    static function name()
    {

        return 'OA Reading Time';

    }

    static function className()
    {

        return 'oa_reading_time';

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
                'reading' => [
                    'wpm' => 220,
                    'display' => 'time',
                    'prefix' => '',
                    'suffix' => 'min read',
                    'minimum' => 1,
                    'show_icon' => false,
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
            "reading",
            "Reading time",
            [c(
                "display",
                "Show",
                [],
                ['type' => 'dropdown', 'layout' => 'vertical', 'items' => [
                    ['value' => 'time', 'text' => 'Reading time'],
                    ['value' => 'words', 'text' => 'Word count'],
                    ['value' => 'both', 'text' => 'Both'],
                ]],
                false,
                false,
                []
            ), c(
                "wpm",
                "Words per minute",
                [],
                ['type' => 'number', 'layout' => 'inline', 'rangeOptions' => ['min' => 50, 'max' => 600, 'step' => 10]],
                false,
                false,
                []
            ), c(
                "minimum",
                "Minimum minutes",
                [],
                ['type' => 'number', 'layout' => 'inline', 'rangeOptions' => ['min' => 0, 'max' => 30, 'step' => 1]],
                false,
                false,
                []
            ), c(
                "prefix",
                "Prefix",
                [],
                ['type' => 'text', 'layout' => 'vertical'],
                false,
                false,
                []
            ), c(
                "suffix",
                "Suffix",
                [],
                ['type' => 'text', 'layout' => 'vertical'],
                false,
                false,
                []
            ), c(
                "words_suffix",
                "Word count suffix",
                [],
                ['type' => 'text', 'layout' => 'vertical', 'condition' => ['path' => 'content.reading.display', 'operand' => 'not equals', 'value' => 'time']],
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
                ['type' => 'icon', 'layout' => 'vertical', 'condition' => ['path' => 'content.reading.show_icon', 'operand' => 'equals', 'value' => true]],
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

        return [getPresetSection(
            "EssentialElements\\typography_with_align",
            "Typography",
            "typography",
            ['type' => 'popout']
        ), c(
            "icon",
            "Icon",
            [c(
                "color",
                "Color",
                [],
                ['type' => 'color', 'layout' => 'inline'],
                false,
                false,
                []
            ), c(
                "size",
                "Size",
                [],
                ['type' => 'unit', 'layout' => 'inline', 'rangeOptions' => ['min' => 8, 'max' => 48, 'step' => 1], 'unitOptions' => ['types' => ['px', 'em'], 'defaultType' => 'px']],
                false,
                false,
                []
            ), c(
                "gap",
                "Gap",
                [],
                ['type' => 'unit', 'layout' => 'inline', 'rangeOptions' => ['min' => 0, 'max' => 40, 'step' => 1], 'unitOptions' => ['types' => ['px', 'em'], 'defaultType' => 'px']],
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

        return false;

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

        return false;

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

        return false;

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

        return ['content.reading.wpm', 'content.reading.display', 'content.reading.prefix', 'content.reading.suffix', 'content.reading.words_suffix', 'content.reading.minimum'];

    }

}
