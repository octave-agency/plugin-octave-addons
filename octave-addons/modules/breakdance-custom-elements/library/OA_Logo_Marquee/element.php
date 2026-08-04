<?php

/*
ELEMENT: OA LOGO MARQUEE
-- Seamless infinite logo strip for client, partner or press rows.
-- CSS-driven, so there is no JavaScript and no slider library to load.
---------------------------------------------------------- */

namespace OctaveCustomElements;

use function Breakdance\Elements\c;
use function Breakdance\Elements\PresetSections\getPresetSection;

\Breakdance\ElementStudio\registerElementForEditing(
    "OctaveCustomElements\\OaLogoMarquee",
    \Breakdance\Util\getdirectoryPathRelativeToPluginFolder(__DIR__)
);

class OaLogoMarquee extends \Breakdance\Elements\Element
{

    static function uiIcon()
    {

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="8" width="6" height="8" rx="1"/><rect x="10" y="8" width="6" height="8" rx="1"/><path d="M19 8h3v8h-3"/></svg>';

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

        return 'Logo Marquee';

    }

    static function className()
    {

        return 'oa_logo_marquee';

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
                'marquee' => [
                    'speed' => 30,
                    'direction' => 'left',
                    'pause_on_hover' => true,
                    'fade_edges' => true,
                ],
            ],
            'design' => [
                'logos' => [
                    'grayscale' => false,
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
            "logos",
            "Logos",
            [c(
                "items",
                "Logos",
                [c(
                    "image",
                    "Image",
                    [],
                    ['type' => 'wpmedia', 'layout' => 'vertical', 'mediaOptions' => ['multiple' => false]],
                    false,
                    false,
                    []
                ), c(
                    "size",
                    "Size",
                    [],
                    ['type' => 'media_size_dropdown', 'layout' => 'vertical'],
                    false,
                    false,
                    []
                ), c(
                    "alt",
                    "Alt text",
                    [],
                    ['type' => 'text', 'layout' => 'vertical'],
                    false,
                    false,
                    []
                ), c(
                    "link",
                    "Link",
                    [],
                    ['type' => 'link', 'layout' => 'vertical'],
                    false,
                    false,
                    []
                )],
                ['type' => 'repeater', 'layout' => 'vertical', 'repeaterOptions' => ['titleTemplate' => 'Logo', 'defaultTitle' => 'Logo', 'buttonName' => 'Add logo', 'galleryMode' => true, 'galleryMediaPath' => 'image']],
                false,
                false,
                []
            )],
            ['type' => 'section', 'layout' => 'vertical', 'sectionOptions' => ['type' => 'accordion']],
            false,
            false,
            []
        ), c(
            "marquee",
            "Motion",
            [c(
                "speed",
                "Duration (seconds)",
                [],
                ['type' => 'number', 'layout' => 'inline', 'rangeOptions' => ['min' => 5, 'max' => 200, 'step' => 1]],
                false,
                false,
                []
            ), c(
                "direction",
                "Direction",
                [],
                ['type' => 'button_bar', 'layout' => 'vertical', 'items' => [
                    ['value' => 'left', 'text' => 'Left'],
                    ['value' => 'right', 'text' => 'Right'],
                ]],
                false,
                false,
                []
            ), c(
                "pause_on_hover",
                "Pause on hover",
                [],
                ['type' => 'toggle', 'layout' => 'inline'],
                false,
                false,
                []
            ), c(
                "fade_edges",
                "Fade edges",
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
        )];

    }

    static function designControls()
    {

        return [c(
            "logos",
            "Logos",
            [c(
                "height",
                "Height",
                [],
                ['type' => 'unit', 'layout' => 'inline', 'rangeOptions' => ['min' => 10, 'max' => 300, 'step' => 1], 'unitOptions' => ['types' => ['px', 'em', 'rem'], 'defaultType' => 'px']],
                true,
                false,
                []
            ), c(
                "gap",
                "Gap",
                [],
                ['type' => 'unit', 'layout' => 'inline', 'rangeOptions' => ['min' => 0, 'max' => 200, 'step' => 1], 'unitOptions' => ['types' => ['px', 'em', 'rem'], 'defaultType' => 'px']],
                true,
                false,
                []
            ), c(
                "opacity",
                "Opacity",
                [],
                ['type' => 'number', 'layout' => 'inline', 'rangeOptions' => ['min' => 0, 'max' => 1, 'step' => 0.05]],
                false,
                false,
                []
            ), c(
                "opacity_hover",
                "Opacity (hover)",
                [],
                ['type' => 'number', 'layout' => 'inline', 'rangeOptions' => ['min' => 0, 'max' => 1, 'step' => 0.05]],
                false,
                false,
                []
            ), c(
                "grayscale",
                "Grayscale until hover",
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
        ), c(
            "fade",
            "Edge fade",
            [c(
                "width",
                "Width",
                [],
                ['type' => 'unit', 'layout' => 'inline', 'rangeOptions' => ['min' => 0, 'max' => 400, 'step' => 4], 'unitOptions' => ['types' => ['px', '%'], 'defaultType' => 'px'], 'condition' => ['path' => 'content.marquee.fade_edges', 'operand' => 'equals', 'value' => true]],
                true,
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

        return false;

    }

}
