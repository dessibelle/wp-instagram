<?php

include_once(dirname(__FILE__) . '/defines.php');


// [bartag foo="foo-value"]
function wp_instagram_images_shortcode( $atts ) {

    return wp_instagram_images();
}
add_shortcode( 'wp_instagram_images', 'wp_instagram_images_shortcode' );


function wp_instagram_images($args = null) {

    $args = wp_parse_args( $args, array(
        'size' => WPIG_IMAGE_SIZE_LOW,
        'numberposts' => 20,
        'query_args' => array(),
        'echo' => false,
    ) );
    extract($args);

    $query_args = wp_parse_args( $query_args, array(
        'numberposts'   =>    $numberposts,
        'orderby'       =>    'post_date',
        'order'         =>    'DESC',
        'post_status'   =>    'publish',
    ) );
    $query_args['post_type'] = WPIG_IMAGE_TYPE;

    $images = get_posts( $query_args );
    $markup = array();

    foreach ($images as $image) {
        $wpig_img = new WPIGImage($image);

        $markup[] = sprintf('<figure class="wp-instagram-image">%s <figcaption>%s</figcaption></figure>', $wpig_img->getImageMarkup($size), $image->post_title);
    }

    $output = implode(" ", $markup);
    if (count($markup)) {
        $output = sprintf('<div class="wp-instagram-images"><div class="wp-instagram-inner">%s</div></div>', $output);
    }

    if ($echo)
        echo $output;

    return $output;
}
