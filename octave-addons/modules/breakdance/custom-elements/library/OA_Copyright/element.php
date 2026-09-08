<?php

/*
ELEMENT: OA COPYRIGHT
-- Footer copyright line whose year is generated at render time, so no site
-- ever ships a stale year. Optional start year produces a "2019 - 2026" range.
---------------------------------------------------------- */

namespace OctaveCustomElements;

use function Breakdance\Elements\c;
use function Breakdance\Elements\PresetSections\getPresetSection;

\Breakdance\ElementStudio\registerElementForEditing(
    "OctaveCustomElements\\OaCopyright",
    \Breakdance\Util\getdirectoryPathRelativeToPluginFolder(__DIR__)
);

class OaCopyright extends \Breakdance\Elements\Element
{

    static function uiIcon()
    {

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M14.7 9.3a3.5 3.5 0 1 0 0 5.4"/></svg>';

    }

    static function tag()
    {

        return 'div';

    }

    static function tagOptions()
    {

        return ['div', 'p', 'span', 'footer'];

    }

    static function tagControlPath()
    {

        return false;

    }

    static function name()
    {

        return 'Copyright';

    }

    static function className()
    {

        return 'oa_copyright';

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
                'copyright' => [
                    'symbol' => true,
                    'prefix' => 'Copyright',
                    'start_year' => '',
                    'name' => '',
                    'suffix' => 'All rights reserved.',
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
            "copyright",
            "Copyright",
            [c(
                "prefix",
                "Prefix",
                [],
                ['type' => 'text', 'layout' => 'vertical'],
                false,
                false,
                []
            ), c(
                "symbol",
                "Show © symbol",
                [],
                ['type' => 'toggle', 'layout' => 'inline'],
                false,
                false,
                []
            ), c(
                "start_year",
                "Start year",
                [],
                ['type' => 'text', 'layout' => 'inline', 'placeholder' => '2019'],
                false,
                false,
                []
            ), c(
                "name",
                "Name",
                [],
                ['type' => 'text', 'layout' => 'vertical', 'placeholder' => 'Leave empty to use the site title'],
                false,
                false,
                []
            ), c(
                "link",
                "Link the name",
                [],
                ['type' => 'link', 'layout' => 'vertical'],
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
            "link",
            "Link",
            [c(
                "color",
                "Color",
                [],
                ['type' => 'color', 'layout' => 'inline'],
                false,
                false,
                []
            ), c(
                "color_hover",
                "Color (hover)",
                [],
                ['type' => 'color', 'layout' => 'inline'],
                false,
                false,
                []
            ), c(
                "underline",
                "Underline",
                [],
                ['type' => 'toggle', 'layout' => 'inline'],
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

        return [['accepts' => 'string', 'path' => 'content.copyright.name']];

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
