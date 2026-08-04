<?php

/*
ELEMENT: OA COUNTDOWN
-- Generic countdown timer with three modes: a fixed date, an evergreen
-- per-visitor duration, and a recurring daily/weekly deadline.
-- Deliberately library-generic: no site-specific copy or styling.
---------------------------------------------------------- */

namespace OctaveCustomElements;

use function Breakdance\Elements\c;
use function Breakdance\Elements\PresetSections\getPresetSection;

\Breakdance\ElementStudio\registerElementForEditing(
    "OctaveCustomElements\\OaCountdown",
    \Breakdance\Util\getdirectoryPathRelativeToPluginFolder(__DIR__)
);

class OaCountdown extends \Breakdance\Elements\Element
{

    static function uiIcon()
    {

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2"/><path d="M9 2h6"/><path d="m19 5 1.5 1.5"/></svg>';

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

        return 'OA Countdown';

    }

    static function className()
    {

        return 'oa_countdown';

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
                'timer' => [
                    'mode' => 'fixed',
                    'datetime' => date('Y-m-d', strtotime('+30 days')) . ' 09:00',
                    'timezone' => 'local',
                    'evergreen_hours' => 24,
                    'evergreen_minutes' => 0,
                    'evergreen_restart' => false,
                    'recur_every' => 'day',
                    'recur_weekday' => '1',
                    'recur_time' => '09:00',
                ],
                'units' => [
                    'show_days' => true,
                    'show_hours' => true,
                    'show_minutes' => true,
                    'show_seconds' => true,
                    'show_labels' => true,
                    'pad' => true,
                    'label_days' => 'Days',
                    'label_hours' => 'Hours',
                    'label_minutes' => 'Minutes',
                    'label_seconds' => 'Seconds',
                ],
                'expiry' => [
                    'action' => 'message',
                    'message' => 'This offer has ended.',
                ],
            ],
            'design' => [
                'layout' => [
                    'align' => 'center',
                    'separator' => 'none',
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
            "timer",
            "Timer",
            [c(
                "mode",
                "Mode",
                [],
                ['type' => 'dropdown', 'layout' => 'vertical', 'items' => [
                    ['value' => 'fixed', 'text' => 'Fixed date'],
                    ['value' => 'evergreen', 'text' => 'Evergreen (per visitor)'],
                    ['value' => 'recurring', 'text' => 'Recurring'],
                ]],
                false,
                false,
                []
            ), c(
                "datetime",
                "Date & time",
                [],
                ['type' => 'text', 'layout' => 'vertical', 'placeholder' => 'YYYY-MM-DD HH:MM', 'condition' => ['path' => 'content.timer.mode', 'operand' => 'equals', 'value' => 'fixed']],
                false,
                false,
                []
            ), c(
                "timezone",
                "Interpret as",
                [],
                ['type' => 'dropdown', 'layout' => 'vertical', 'items' => [
                    ['value' => 'local', 'text' => "Visitor's local time"],
                    ['value' => 'utc', 'text' => 'UTC'],
                    ['value' => 'offset', 'text' => 'Fixed UTC offset'],
                ], 'condition' => ['path' => 'content.timer.mode', 'operand' => 'equals', 'value' => 'fixed']],
                false,
                false,
                []
            ), c(
                "utc_offset",
                "UTC offset (hours)",
                [],
                ['type' => 'number', 'layout' => 'inline', 'rangeOptions' => ['min' => -12, 'max' => 14, 'step' => 0.5], 'condition' => ['path' => 'content.timer.timezone', 'operand' => 'equals', 'value' => 'offset']],
                false,
                false,
                []
            ), c(
                "evergreen_hours",
                "Hours",
                [],
                ['type' => 'number', 'layout' => 'inline', 'rangeOptions' => ['min' => 0, 'max' => 720, 'step' => 1], 'condition' => ['path' => 'content.timer.mode', 'operand' => 'equals', 'value' => 'evergreen']],
                false,
                false,
                []
            ), c(
                "evergreen_minutes",
                "Minutes",
                [],
                ['type' => 'number', 'layout' => 'inline', 'rangeOptions' => ['min' => 0, 'max' => 59, 'step' => 1], 'condition' => ['path' => 'content.timer.mode', 'operand' => 'equals', 'value' => 'evergreen']],
                false,
                false,
                []
            ), c(
                "evergreen_restart",
                "Restart when it ends",
                [],
                ['type' => 'toggle', 'layout' => 'inline', 'condition' => ['path' => 'content.timer.mode', 'operand' => 'equals', 'value' => 'evergreen']],
                false,
                false,
                []
            ), c(
                "recur_every",
                "Repeat",
                [],
                ['type' => 'dropdown', 'layout' => 'vertical', 'items' => [
                    ['value' => 'day', 'text' => 'Every day'],
                    ['value' => 'week', 'text' => 'Every week'],
                ], 'condition' => ['path' => 'content.timer.mode', 'operand' => 'equals', 'value' => 'recurring']],
                false,
                false,
                []
            ), c(
                "recur_weekday",
                "Day",
                [],
                ['type' => 'dropdown', 'layout' => 'vertical', 'items' => [
                    ['value' => '1', 'text' => 'Monday'],
                    ['value' => '2', 'text' => 'Tuesday'],
                    ['value' => '3', 'text' => 'Wednesday'],
                    ['value' => '4', 'text' => 'Thursday'],
                    ['value' => '5', 'text' => 'Friday'],
                    ['value' => '6', 'text' => 'Saturday'],
                    ['value' => '0', 'text' => 'Sunday'],
                ], 'condition' => ['path' => 'content.timer.recur_every', 'operand' => 'equals', 'value' => 'week']],
                false,
                false,
                []
            ), c(
                "recur_time",
                "Time",
                [],
                ['type' => 'text', 'layout' => 'vertical', 'placeholder' => 'HH:MM', 'condition' => ['path' => 'content.timer.mode', 'operand' => 'equals', 'value' => 'recurring']],
                false,
                false,
                []
            )],
            ['type' => 'section', 'layout' => 'vertical', 'sectionOptions' => ['type' => 'accordion']],
            false,
            false,
            []
        ), c(
            "units",
            "Units",
            [c(
                "show_days",
                "Days",
                [],
                ['type' => 'toggle', 'layout' => 'inline'],
                false,
                false,
                []
            ), c(
                "show_hours",
                "Hours",
                [],
                ['type' => 'toggle', 'layout' => 'inline'],
                false,
                false,
                []
            ), c(
                "show_minutes",
                "Minutes",
                [],
                ['type' => 'toggle', 'layout' => 'inline'],
                false,
                false,
                []
            ), c(
                "show_seconds",
                "Seconds",
                [],
                ['type' => 'toggle', 'layout' => 'inline'],
                false,
                false,
                []
            ), c(
                "pad",
                "Leading zeros",
                [],
                ['type' => 'toggle', 'layout' => 'inline'],
                false,
                false,
                []
            ), c(
                "show_labels",
                "Show labels",
                [],
                ['type' => 'toggle', 'layout' => 'inline'],
                false,
                false,
                []
            ), c(
                "label_days",
                "Days label",
                [],
                ['type' => 'text', 'layout' => 'vertical', 'condition' => ['path' => 'content.units.show_labels', 'operand' => 'equals', 'value' => true]],
                false,
                false,
                []
            ), c(
                "label_hours",
                "Hours label",
                [],
                ['type' => 'text', 'layout' => 'vertical', 'condition' => ['path' => 'content.units.show_labels', 'operand' => 'equals', 'value' => true]],
                false,
                false,
                []
            ), c(
                "label_minutes",
                "Minutes label",
                [],
                ['type' => 'text', 'layout' => 'vertical', 'condition' => ['path' => 'content.units.show_labels', 'operand' => 'equals', 'value' => true]],
                false,
                false,
                []
            ), c(
                "label_seconds",
                "Seconds label",
                [],
                ['type' => 'text', 'layout' => 'vertical', 'condition' => ['path' => 'content.units.show_labels', 'operand' => 'equals', 'value' => true]],
                false,
                false,
                []
            )],
            ['type' => 'section', 'layout' => 'vertical', 'sectionOptions' => ['type' => 'accordion']],
            false,
            false,
            []
        ), c(
            "expiry",
            "When it ends",
            [c(
                "action",
                "Action",
                [],
                ['type' => 'dropdown', 'layout' => 'vertical', 'items' => [
                    ['value' => 'message', 'text' => 'Show a message'],
                    ['value' => 'zeros', 'text' => 'Stay at zero'],
                    ['value' => 'hide', 'text' => 'Hide the timer'],
                    ['value' => 'redirect', 'text' => 'Redirect'],
                ]],
                false,
                false,
                []
            ), c(
                "message",
                "Message",
                [],
                ['type' => 'text', 'layout' => 'vertical', 'condition' => ['path' => 'content.expiry.action', 'operand' => 'equals', 'value' => 'message']],
                false,
                false,
                []
            ), c(
                "redirect_url",
                "Redirect to",
                [],
                ['type' => 'url', 'layout' => 'vertical', 'condition' => ['path' => 'content.expiry.action', 'operand' => 'equals', 'value' => 'redirect']],
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
            "EssentialElements\\typography",
            "Digits",
            "digits",
            ['type' => 'popout']
        ), getPresetSection(
            "EssentialElements\\typography",
            "Labels",
            "labels",
            ['type' => 'popout']
        ), c(
            "layout",
            "Layout",
            [c(
                "align",
                "Align",
                [],
                ['type' => 'button_bar', 'layout' => 'vertical', 'items' => [
                    ['value' => 'flex-start', 'text' => 'Left'],
                    ['value' => 'center', 'text' => 'Center'],
                    ['value' => 'flex-end', 'text' => 'Right'],
                ]],
                true,
                false,
                []
            ), c(
                "gap",
                "Gap",
                [],
                ['type' => 'unit', 'layout' => 'inline', 'rangeOptions' => ['min' => 0, 'max' => 120, 'step' => 1], 'unitOptions' => ['types' => ['px', 'em', 'rem'], 'defaultType' => 'px']],
                true,
                false,
                []
            ), c(
                "separator",
                "Separator",
                [],
                ['type' => 'dropdown', 'layout' => 'inline', 'items' => [
                    ['value' => 'none', 'text' => 'None'],
                    ['value' => 'colon', 'text' => 'Colon'],
                    ['value' => 'slash', 'text' => 'Slash'],
                    ['value' => 'dot', 'text' => 'Dot'],
                ]],
                false,
                false,
                []
            ), c(
                "separator_color",
                "Separator color",
                [],
                ['type' => 'color', 'layout' => 'inline', 'condition' => ['path' => 'design.layout.separator', 'operand' => 'not equals', 'value' => 'none']],
                false,
                false,
                []
            ), c(
                "label_gap",
                "Label gap",
                [],
                ['type' => 'unit', 'layout' => 'inline', 'rangeOptions' => ['min' => 0, 'max' => 40, 'step' => 1], 'unitOptions' => ['types' => ['px', 'em', 'rem'], 'defaultType' => 'px']],
                false,
                false,
                []
            )],
            ['type' => 'section', 'layout' => 'vertical', 'sectionOptions' => ['type' => 'accordion']],
            false,
            false,
            []
        ), c(
            "box",
            "Unit box",
            [c(
                "background",
                "Background",
                [],
                ['type' => 'color', 'layout' => 'inline'],
                false,
                false,
                []
            ), c(
                "padding",
                "Padding",
                [],
                ['type' => 'unit', 'layout' => 'inline', 'rangeOptions' => ['min' => 0, 'max' => 80, 'step' => 1], 'unitOptions' => ['types' => ['px', 'em', 'rem'], 'defaultType' => 'px']],
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
                "min_width",
                "Min width",
                [],
                ['type' => 'unit', 'layout' => 'inline', 'rangeOptions' => ['min' => 0, 'max' => 300, 'step' => 1], 'unitOptions' => ['types' => ['px', 'em', 'rem'], 'defaultType' => 'px']],
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

        $url = defined('OCTAVE_ADDONS_URL') ? OCTAVE_ADDONS_URL : plugin_dir_url(dirname(__DIR__, 3) . '/octave-addons.php');
        $version = defined('OCTAVE_ADDONS_VERSION') ? OCTAVE_ADDONS_VERSION : '1.0.0';

        return [
            [
                'title' => 'OA Countdown',
                'scripts' => [$url . 'modules/breakdance-custom-elements/library/OA_Countdown/countdown.js?ver=' . $version],
            ],
            [
                'title' => 'OA Countdown - init',
                'inlineScripts' => ['window.oaCountdownInit && window.oaCountdownInit();'],
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
            'onPropertyChange' => [['script' => 'window.oaCountdownInit && window.oaCountdownInit();']],
            'onCreatedElement' => [['script' => 'window.oaCountdownInit && window.oaCountdownInit();']],
            'onMountedElement' => [['script' => 'window.oaCountdownInit && window.oaCountdownInit();']],
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
