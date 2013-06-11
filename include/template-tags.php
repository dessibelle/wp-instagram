<?php

include_once(dirname(__FILE__) . '/defines.php');


function wp_instagram_images_shortcode( $atts ) {

    extract( shortcode_atts( array(
            'numberposts' => null,
            'meta_components' => null,
        ), $atts ) );

    if ($meta_components) {
        $meta_components = explode(" ", $meta_components);
    }

    return wp_instagram_slider( array_filter( array(
        'echo' => false,
        'numberposts' => $numberposts,
        'meta_components' => $meta_components,
    ) ) );
}
add_shortcode( 'wp_instagram_images', 'wp_instagram_images_shortcode' );


function wp_instagram_slider($args = null, $query_args = null) {

    $args = wp_parse_args( $args, array(
        'size' => WPIG_IMAGE_SIZE_LOW,
        'numberposts' => 20,
        'meta_components' => array('caption', 'username', 'date'),
        'echo' => true,
    ) );

    $query_args = wp_parse_args( $query_args, array(
        'numberposts'   =>    20,
        'orderby'       =>    'post_date',
        'order'         =>    'DESC',
        'post_status'   =>    'publish',
    ) );
    $query_args['post_type'] = WPIG_IMAGE_TYPE;

    $images = get_posts( $query_args );
    $args['images'] = $images;

    return wp_instagram_feed($args);
}



function wp_instagram_feed($args)
{
    $args = wp_parse_args( $args, array_filter( array(
        'numberposts' => 20,
        'images' => array(),
        'size' => null,
        'container_class' => array('flexslider', 'carousel'),
        'list_class' => 'slides',
        'item_class' => 'slide',
        'dateformat' => null,
        'display_meta' => null,
        'meta_components' => array(),
        'echo' => false
    ) ) );
    extract($args);

    $container_class = implode(' ', (array)$container_class);
    $list_class = implode(' ', (array)$list_class);
    $item_class = implode(' ', (array)$item_class);

    $markup = array();
    foreach ($images as $image) {
        $markup[] = wp_instagram_feed_element($image, array('class' => $item_class));
    }

    if (count($markup)) {
        $markup = sprintf('<div class="wp-instagram-images %s" data-item-width="100"><ul class="%s">%s</ul></div>', esc_attr( $container_class ), esc_attr( $list_class ), implode("", $markup));

        if ($echo) {
            echo $markup;
        }

        return $markup;
    }

    return null;
}

function wp_instagram_feed_element($post, $args = null)
{
    if (class_exists('WPIGImage')) {

        $args = wp_parse_args( $args, array(
            'class' => null,
            'date_format' => 'l j F, Y',
            'display_meta' => true,
            'meta_components' => array('caption', 'date', 'username'),
            'size' => WPIG_IMAGE_SIZE_LOW,
        ) );
        extract($args);

        $image = new WPIGImage($post);
        $markup = $image->getImageMarkup($size);

        if ($markup) {
            $ig_url = $image->getInstagramURL();
            if ($ig_url) {
                $markup = sprintf('<a href="%s">%s</a>', esc_attr($ig_url), $markup);
            }

            if ($display_meta && count($meta_components)) {
                $ts = strtotime($post->post_date);
                $date = date_i18n($date_format, $ts);
                $isodate = date_i18n('c', $ts);
                $caption = apply_filters( 'the_title', $post->post_title );
                $username = $image->getInstagramUser();

                $meta = null;

                foreach ($meta_components as $m) {
                    switch ($m) {
                        case 'caption':
                            if ($caption) {
                                $meta .= sprintf('<p class="wp-instagram-caption">%s</p>', esc_html($caption));
                            }
                            break;
                        case 'date':
                            if ($date) {
                                $meta .= sprintf('<p class="wp-instagram-time"><time datetime="%s">%s</time></p>', esc_attr($isodate), esc_html($date));
                            }
                            break;
                        case 'username':
                            if ($username) {
                                $meta .= sprintf('<p class="wp-instagram-user"><a href="http://instagram.com/%s">@%s</a></p>', esc_attr($username), esc_html($username));
                            }
                            break;
                    }
                }

                if (!empty($meta)) {
                    $markup .= sprintf('<figcaption class="wp-instagram-meta">%s</figcaption>', $meta);
                }
            }

            $class = (array)$class;
            $class[] = $size;
            $class = implode(' ', $class);

            return sprintf('<li class="wp-instagram-image %s"><figure>%s</figure></li>', esc_attr($class), $markup);
        }
    }

    return null;
}
