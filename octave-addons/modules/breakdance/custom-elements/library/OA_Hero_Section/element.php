<?php

/*
ELEMENT: OA HERO SECTION
-- Section-level hero with a featured-image background and editable children.
-- Reuses Breakdance's native Section controls for site presets and styling.
---------------------------------------------------------- */

namespace OctaveCustomElements;

use function Breakdance\Elements\c;

\Breakdance\ElementStudio\registerElementForEditing(
    "OctaveCustomElements\\OaHeroSection",
    \Breakdance\Util\getdirectoryPathRelativeToPluginFolder( __DIR__ )
);

class OaHeroSection extends \Breakdance\Elements\Element {

    static function uiIcon() {

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 16 5-5 4 4 3-3 8 8"/><path d="M16 8h.01"/></svg>';

    }

    static function tag() {

        return 'section';

    }

    static function tagOptions() {

        return ['div', 'header', 'footer', 'article', 'main', 'aside', 'section'];

    }

    static function tagControlPath() {

        return false;

    }

    static function name() {

        return 'Hero Section';

    }

    static function className() {

        return 'bde-section';

    }

    static function category() {

        return 'oa_custom_elements';

    }

    static function badge() {

        return ['label' => 'OA', 'backgroundColor' => 'var(--white-fixed)', 'textColor' => 'var(--white)'];

    }

    static function slug() {

        return __CLASS__;

    }

    static function template() {

        return file_get_contents( __DIR__ . '/html.twig' );

    }

    static function defaultCss() {

        $native_css = class_exists( '\\EssentialElements\\Section' )
            ? \EssentialElements\Section::defaultCss()
            : '';

        return $native_css . "\n" . file_get_contents( __DIR__ . '/default.css' );

    }

    static function cssTemplate() {

        $native_template = class_exists( '\\EssentialElements\\Section' )
            ? \EssentialElements\Section::cssTemplate()
            : '';

        return $native_template . "\n" . file_get_contents( __DIR__ . '/css.twig' );

    }

    static function defaultProperties() {

        return [
            'content' => [
                'hero' => [
                    'background_source' => 'featured',
                ],
            ],
            'design' => [
                'layout_v2' => [
                    'layout' => 'vertical',
                ],
                'background' => [
                    'lazy_load' => false,
                    'image_settings' => [
                        'size'     => 'cover',
                        'repeat'   => 'no-repeat',
                        'position' => 'center center',
                    ],
                    'overlay' => [
                        'color'     => '#00000066',
                        'lazy_load' => false,
                    ],
                ],
                'text_colors' => [
                    'headings' => '#FFFFFFFF',
                    'text'     => '#FFFFFFFF',
                ],
            ],
        ];

    }

    static function defaultChildren() {

        $title_shortcode   = "[breakdance_dynamic field='post_title']";
        $excerpt_shortcode = "[breakdance_dynamic field='post_excerpt']";

        return [
            [
                'slug'              => 'EssentialElements\\Div',
                'defaultProperties' => [
                    'design' => [
                        'layout_v2' => [
                            'layout' => 'vertical',
                        ],
                        'container' => [
                            'width' => [
                                'breakpoint_base' => [
                                    'number' => 100,
                                    'unit'   => '%',
                                    'style'  => '100%',
                                ],
                            ],
                        ],
                    ],
                ],
                'children' => [
                    [
                        'slug'              => 'EssentialElements\\Heading',
                        'defaultProperties' => [
                            'content' => [
                                'content' => [
                                    'text'              => $title_shortcode,
                                    'text_dynamic_meta' => ['shortcode' => $title_shortcode],
                                    'tags'              => 'h1',
                                ],
                            ],
                        ],
                        'children' => [],
                    ],
                    [
                        'slug'              => 'EssentialElements\\Text',
                        'defaultProperties' => [
                            'content' => [
                                'content' => [
                                    'text'              => $excerpt_shortcode,
                                    'text_dynamic_meta' => ['shortcode' => $excerpt_shortcode],
                                ],
                            ],
                        ],
                        'children' => [],
                    ],
                ],
            ],
        ];

    }

    /*
    CONTENT CONTROLS
    -- Hero-specific content stays limited to the featured-image source.
    -- Copy, buttons and custom content are normal editable child elements.
    ---------------------------------------------------------- */

    static function contentControls() {

        return [c(
            'hero',
            'Hero',
            [c(
                'background_source',
                'Background Source',
                [],
                ['type' => 'button_bar', 'layout' => 'vertical', 'items' => [
                    ['value' => 'featured', 'text' => 'Featured Image'],
                    ['value' => 'breakdance', 'text' => 'Breakdance Background'],
                ]],
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

    /*
    NATIVE SECTION BEHAVIOUR
    -- Keeps the Hero Section aligned with Breakdance's Section implementation.
    ---------------------------------------------------------- */

    static function designControls() {

        return class_exists( '\\EssentialElements\\Section' )
            ? \EssentialElements\Section::designControls()
            : [];

    }

    static function settingsControls() {

        return [];

    }

    static function dependencies() {

        return class_exists( '\\EssentialElements\\Section' )
            ? \EssentialElements\Section::dependencies()
            : false;

    }

    static function actions() {

        return class_exists( '\\EssentialElements\\Section' )
            ? \EssentialElements\Section::actions()
            : false;

    }

    static function nestingRule() {

        return ['type' => 'section'];

    }

    static function spacingBars() {

        return [
            ['cssProperty' => 'padding-top', 'location' => 'inside-top', 'affectedPropertyPath' => 'design.spacing.padding.%%BREAKPOINT%%.top'],
            ['cssProperty' => 'padding-bottom', 'location' => 'inside-bottom', 'affectedPropertyPath' => 'design.spacing.padding.%%BREAKPOINT%%.bottom'],
        ];

    }

    static function attributes() {

        return [
            ['name' => 'data-no-lazy', 'template' => '1'],
            ['name' => 'data-skip-lazy', 'template' => '1'],
        ];

    }

    static function settings() {

        return false;

    }

    static function addPanelRules() {

        return false;

    }

    static function experimental() {

        return false;

    }

    static function availableIn() {

        return ['breakdance'];

    }

    static function order() {

        return 0;

    }

    static function dynamicPropertyPaths() {

        return [
            ['accepts' => 'video', 'path' => 'design.background.video'],
            ['accepts' => 'image_url', 'path' => 'design.background.video_settings.fallback_image'],
            ['accepts' => 'image_url', 'path' => 'design.background.image'],
            ['accepts' => 'gallery', 'path' => 'design.background.slideshow'],
            ['accepts' => 'image_url', 'path' => 'design.background.overlay.image'],
        ];

    }

    static function additionalClasses() {

        return [['name' => 'bde-oa-hero-section', 'template' => 'yes']];

    }

    static function projectManagement() {

        return false;

    }

    static function propertyPathsToWhitelistInFlatProps() {

        $paths = method_exists( '\\EssentialElements\\Section', 'propertyPathsToWhitelistInFlatProps' )
            ? \EssentialElements\Section::propertyPathsToWhitelistInFlatProps()
            : [];

        return array_merge( $paths, ['content.hero.background_source'] );

    }

    static function propertyPathsToSsrElementWhenValueChanges() {

        return ['content.hero.background_source'];

    }

}
