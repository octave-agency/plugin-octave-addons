<?php

/*
OA HERO SECTION — SERVER RENDER
-- Resolves the current post's featured image as the hero background layer.
-- Editable hero content is rendered by Breakdance through %%CHILDREN%%.
---------------------------------------------------------- */

/**
 * @var array $propertiesData
 */

$oa_hero              = $propertiesData['content']['hero'] ?? [];
$oa_background_source = (string) ( $oa_hero['background_source'] ?? 'featured' );
$oa_post              = get_post();
$oa_featured_image    = '';

if ( 'featured' === $oa_background_source && $oa_post ) {

    $oa_featured_image = (string) get_the_post_thumbnail_url( $oa_post, 'full' );

}

if ( '' !== $oa_featured_image ) :

?>

<div class="oa_hero_section-featured" style="background-image: url('<?= esc_url( $oa_featured_image ); ?>');" aria-hidden="true"></div>

<?php

endif;

