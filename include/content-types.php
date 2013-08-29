<?php

include_once( dirname(__FILE__) . '/defines.php');

class WPIGImage {

    protected $post;

    public function __construct($post)
    {
        $this->post = get_post($post);
    }


    /* ==============================
     * SETUP METHODS
     * ============================== */

    public static function setup_type($value='')
    {
        self::__register_post_type();
        self::__register_taxonomies();
    }

    public static function __register_post_type()
    {
        register_post_type( WPIG_IMAGE_TYPE, apply_filters('wpig_image_type_args', array(
            'labels' => array(
                'name' => __('Instagram images', 'wpig'), // general name for the post type, usually plural. The same as, and overridden by $post_type_object->label
                'singular_name' => __('Instagram image', 'wpig'), // name for one object of this post type. Defaults to value of name
                'menu_name' => __('Instagram posts', 'wpig'), // the menu name text. This string is the name to give menu items. Defaults to value of name
                'all_items' => __('All images', 'wpig'), // the all items text used in the menu. Default is the Name label
                'add_new' => __('Add image', 'wpig'), // the add new text. The default is Add New for both hierarchical and non-hierarchical types. When internationalizing this string, please use a gettext context matching your post type. Example: _x('Add New', 'product');
                'add_new_item' => __('Add new image', 'wpig'), // the add new item text. Default is Add New Post/Add New Page
                'edit_item' => __('Edit image', 'wpig'), // the edit item text. Default is Edit Post/Edit Page
                'new_item' => __('New image', 'wpig'), // the new item text. Default is New Post/New Page
                'view_item' => __('View image', 'wpig'), // the view item text. Default is View Post/View Page
                'search_items' => __('Search images', 'wpig'), // the search items text. Default is Search Posts/Search Pages
                'not_found' => __('No images found', 'wpig'), // the not found text. Default is No posts found/No pages found
                'not_found_in_trash' => __('No images found in trash', 'wpig'), // the not found in trash text. Default is No posts found in Trash/No pages found in Trash
            ),
            'description' => __('Post type used for storing and manaing instagram images downloaded via the Instagram API.'),
            'public' => false,
            'exclude_from_search' => true,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_nav_menus' => false,
            'show_in_menu' => true,
            'show_in_admin_bar' => true,
            'menu_position' => 20,
            'menu_icon' => plugins_url( 'icons/content-type.png', dirname(__FILE__)),
            'capability_type' => 'post', // TODO: Use private capability type
            'map_meta_cap' => false, // TODO: Use private capability type
            'hierarchical' => false,
            'supports' => array(
                'title'
            ),
            'register_meta_box_cb' => array(__CLASS__, '__setup_metaboxes'),
            // 'taxonomies' => array(WPIG_IMAGE_TAG),
            'has_archive' => true,
            'permalink_epmask' => EP_PERMALINK,
            'rewrite' => true,
            'query_var' => WPIG_IMAGE_TYPE,
            'can_export' => false,
        ) ) );
    }

    public static function __register_taxonomies()
    {
        register_taxonomy(WPIG_IMAGE_TAG, WPIG_IMAGE_TYPE, apply_filters('wpig_image_tag_args', array(
            'labels' => array(
                'name' => __('Tags', 'wpig'), // general name for the taxonomy, usually plural. The same as and overridden by $tax->label. Default is _x( 'Post Tags', 'taxonomy general name' ) or _x( 'Categories', 'taxonomy general name' ). When internationalizing this string, please use a gettext context matching your post type. Example: _x('Writers', 'taxonomy general name');
                'singular_name' => __('Tag', 'wpig'), // name for one object of this taxonomy. Default is _x( 'Post Tag', 'taxonomy singular name' ) or _x( 'Category', 'taxonomy singular name' ). When internationalizing this string, please use a gettext context matching your post type. Example: _x('Writer', 'taxonomy singular name');
                'menu_name' => __('Tags', 'wpig'), // the menu name text. This string is the name to give menu items. Defaults to value of name
                'all_items' => __('All tags', 'wpig'), // the all items text. Default is __( 'All Tags' ) or __( 'All Categories' )
                'edit_item' => __('Edit tag', 'wpig'), // the edit item text. Default is __( 'Edit Tag' ) or __( 'Edit Category' )
                'view_item' => __('View tag', 'wpig'), // the view item text, Default is __( 'View Tag' ) or __( 'View Category' )
                'update_item' => __('Update tag', 'wpig'), // the update item text. Default is __( 'Update Tag' ) or __( 'Update Category' )
                'add_new_item' => __('Add new tag', 'wpig'), // the add new item text. Default is __( 'Add New Tag' ) or __( 'Add New Category' )
                'new_item_name' => __('New tag', 'wpig'), // the new item name text. Default is __( 'New Tag Name' ) or __( 'New Category Name' )
                'search_items' => __('Search tags', 'wpig'), // the search items text. Default is __( 'Search Tags' ) or __( 'Search Categories' )
                'popular_items' => __('Popular tags', 'wpig'), // the popular items text. Default is __( 'Popular Tags' ) or null
                'separate_items_with_commas' => __('Separate tags with commas', 'wpig'), // the separate item with commas text used in the taxonomy meta box. This string isn't used on hierarchical taxonomies. Default is __( 'Separate tags with commas' ), or null
                'add_or_remove_items' => __('Add or remove tags', 'wpig'), // the add or remove items text and used in the meta box when JavaScript is disabled. This string isn't used on hierarchical taxonomies. Default is __( 'Add or remove tags' ) or null
                'choose_from_most_used' => __('Choose from the most used tags', 'wpig'), // the choose from most used text used in the taxonomy meta box. This string isn't used on hierarchical taxonomies. Default is __( 'Choose from the most used tags' ) or null
                'not_found' => __('No tags found.', 'wpig'), // (3.6+) the text displayed via clicking 'Choose from the most used tags' in the taxonomy meta box when no tags are available. This string isn't used on hierarchical taxonomies. Default is __( 'No tags found.' ) or null
            ),
            'public' => true,
            'show_ui' => true,
            'show_in_nav_menus' => false,
            'show_tagcloud' => true,
            'show_in_admin_bar' => true,
            'show_admin_column' => true,
            'hierarchical' => false,
            'query_var' => WPIG_IMAGE_TAG,
            'rewrite' => true,
            // 'capabilities' => array(),
            'sort' => true,
        ) ) );
    }


    public static function __setup_metaboxes()
    {
        add_meta_box( 'instagram_image_meta', __('Image data', 'wpig'), array(__CLASS__, '__render_meta_box'), WPIG_IMAGE_TYPE, 'advanced', 'high', array('metabox' => 'instagram_image_meta') );
    }


    public static function __render_meta_box($post = null, $metabox = null)
    {
        return;
    }


    /* ==============================
     * ACCESSORS
     * ============================== */

    public function getImageData($size = WPIG_IMAGE_SIZE_STANDARD)
    {
        $data = null;

        switch ($size) {
            case WPIG_IMAGE_SIZE_THUMBNAIL:
            case 'thumbnail':
                $data = get_post_meta( $this->post->ID, WPIG_IMAGE_SIZE_THUMBNAIL, true );
                break;
            case WPIG_IMAGE_SIZE_LOW:
            case 'low_resolution':
                $data = get_post_meta( $this->post->ID, WPIG_IMAGE_SIZE_LOW, true );
                break;
            case WPIG_IMAGE_SIZE_STANDARD:
            case 'standard_resolution':
                $data = get_post_meta( $this->post->ID, WPIG_IMAGE_SIZE_STANDARD, true );
                break;
        }

        if ($data) {
            $data = unserialize($data);
        }

        return $data;
    }


    public function getImageMarkup($size = WPIG_IMAGE_SIZE_STANDARD, $args = null)
    {
        $data = $this->getImageData($size);

        if ($data && array_key_exists('url', $data)) {
            $url = $width = $height = $attributes = null;
            extract($data);
            $alt = get_the_title($this->post->ID);

            extract((array)$args);

            $attrs = '';
            if ($attributes) {
                foreach ($attributes as $key => $value) {
                    $attrs .= sprintf('%s="%s" ', esc_attr($key), esc_attr($value));
                }
            }

            return sprintf(
                '<img src="%s" width="%d" height="%d" alt="%s" %s>',
                esc_attr( $url ),
                esc_attr( $width ),
                esc_attr( $height ),
                esc_attr( $alt ),
                $attrs
            );
        }

        return null;
    }

    public function getImageURL($size = WPIG_IMAGE_SIZE_STANDARD)
    {
        $data = $this->getImageData($size);

        if ($data && array_key_exists('url', $data)) {
            return $data['url'];
        }

        return null;
    }


    public function getInstagramURL()
    {
        return get_post_meta( $this->post->ID, WPIG_IMAGE_URL, true );
    }

    public function getInstagramUser()
    {
        return get_post_meta( $this->post->ID, WPIG_IMAGE_USERNAME, true );
    }

    public  function getInstagramUserURL()
    {
        $user = $this->getInstagramUser();

        if ($user) {
            $title = empty($title) ? '@' . $user : $title;

            return esc_url('http://instagram.com/' . trailingslashit( $user ));
        }

        return null;
    }

    public function getInstagramUserLink($title = null)
    {
        $user = $this->getInstagramUser();
        $url = $this->getInstagramUserURL();

        if ($url) {

            $title = empty($title) ? '@' . $user : $title;
            return sprintf('<a href="%s">%s</a>', $url, $title);
        }

        return null;
    }
}
