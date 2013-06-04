<?php

include_once( dirname(__FILE__) . '/defines.php');

class WPIGSynchronizer {

    const SYNC_TAG_MAX_ID_KEY = 'wpig_tag_max_id';
    const SYNC_MAX_PAGINATION = 10;

    protected $api;

    protected $tags;
    protected $users;
    protected $locations;

    protected $images;

    public function __construct($api, $query = null)
    {
        $this->api = $api;

        $query = wp_parse_args( $query, array(
            'tags' => array(),
            'users' => array(),
            'locations' => array(),
        ) );
        extract($query);

        $this->setTags($tags);
    }

    /**
     * Getter for tags
     * @return array Array of tag names
     */
    public function getTags()
    {
        return $this->tags;
    }

    /**
     * Setter for tags
     * @param array $tags Array of tag names
     */
    public function setTags($tags)
    {
        $this->tags = $tags;
    }

    /**
     * Gets the max id for a given tag
     * @param  string $tag Tag name
     * @return string      Max tag id
     */
    protected function sync_tag_max_id($tag)
    {
        return get_option( sanitize_key( self::SYNC_TAG_MAX_ID_KEY . '_' . $tag), 0 );
    }


    /**
     * Sets the max id for a given tag
     * @param string $tag Tag name
     * @param string $id  Max tag id
     */
    protected function set_sync_tag_max_id($tag, $id)
    {
        update_option( sanitize_key( self::SYNC_TAG_MAX_ID_KEY . '_' . $tag), $id );
    }

    /**
     * Downloads images using the Instagram API
     * @return array Array of image objects
     */
    public function downloadImagesFromTags()
    {
        $this->images = array();

        // round(microtime(true) * 1000)

        if ($this->api) {
            foreach ($this->tags as $tag) {

                // Get max tag id
                $last_max_id = $this->sync_tag_max_id($tag);
                $new_max_id = 0;
                $current_page_max_id = 0;


                $result = null;

                $depth = 0;
                while (($current_page_max_id == 0 || floatval($current_page_max_id) > floatval($last_max_id)) && $depth < self::SYNC_MAX_PAGINATION) {

                    $params = array();
                    // Check for pagination info
                    if ($result && property_exists($result, 'pagination') && property_exists($result->pagination, 'next_max_tag_id')) {
                        $params['max_tag_id'] = $result->pagination->next_max_tag_id;

                        $current_page_max_id = $result->pagination->next_max_tag_id;

                        // Store max tag id
                        if ($current_page_max_id > $new_max_id) {
                            $new_max_id = $current_page_max_id;
                        }
                    }


                    if ($depth == 0 || ($depth > 0 && count($params))) {
                        $result = $this->api->recentPhotosForTag($tag, $params);

                        // echo "${tag}: ${depth}/" . self::SYNC_MAX_PAGINATION . " - ";
                        // print_r($params);
                        // echo "\n${current_page_max_id} / ${new_max_id} / ${last_max_id}";
                        // echo "\n\n";

                        if ($result && property_exists($result, 'data')) {
                            $this->images = array_merge($this->images, $result->data);
                        }
                    }

                    $depth++;
                }

                // Store max tag id
                if ($new_max_id) {
                    $this->set_sync_tag_max_id($tag, $new_max_id);
                }
            }
        }

        return $this->images;
    }


    protected static function postmetaForImage($image)
    {
        $id = $image->id;

        $location = $image->location;
        $link = $image->link;

        $sizes = $image->images;

        // thumbnail
        // low_resolution
        // standard_resolution

        $username = $image->user->username;
        $full_name = $image->user->full_name;
        $user_id = $image->user->id;

        return array(
            WPIG_IMAGE_ID => $id,
            WPIG_IMAGE_URL => $link,
            WPIG_IMAGE_LOCATION => $location,
            WPIG_IMAGE_SIZE_THUMBNAIL => serialize(get_object_vars($sizes->thumbnail)),
            WPIG_IMAGE_SIZE_LOW => serialize(get_object_vars($sizes->low_resolution)),
            WPIG_IMAGE_SIZE_STANDARD => serialize(get_object_vars($sizes->standard_resolution)),
            WPIG_IMAGE_USERNAME => $username,
            WPIG_IMAGE_FULL_NAME => $full_name,
            WPIG_IMAGE_USER_ID => $user_id,
        );
    }

    public static function storeImage($image)
    {
        $meta_data = self::postmetaForImage($image);

        $id = $image->id;
        $caption = $image->caption->text;
        $created_time = $image->created_time;
        $guid = sprintf('http://instagram.com/i/%s/', $id );

        $tags = $image->tags;

        global $wpdb;
        $post = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->posts WHERE guid = %s", $guid ), ARRAY_A );

        if (!$post) {
            $post = array();
        }

        $post['post_content'] = self::encodeImageData($image);
        $post['post_date'] = date_i18n('Y-m-d H:i:s', $created_time, false);
        $post['post_date_gmt'] = date_i18n('Y-m-d H:i:s', $created_time, true);
        $post['post_modified'] = date_i18n('Y-m-d H:i:s', false, false);
        $post['post_modified_gmt'] = date_i18n('Y-m-d H:i:s', false, true);
        $post['post_title'] = $caption;
        $post['comment_status'] = 'closed';
        $post['ping_status'] = 'closed';
        $post['post_type'] = WPIG_IMAGE_TYPE;
        $post['guid'] = $guid;

        if (!array_key_exists('post_author', $post)) {
            $post['post_author'] = WPInstagram::photo_owner();
        }

        if (!array_key_exists('post_status', $post)) {
            $post['post_status'] = WPInstagram::import_status();
        }

        /*
         * We deliberately avoid setting the _wp_trash_meta_status and
         * _wp_trash_meta_time meta values as this is what triggers
         * the auto deletion of posts. Instead we delete these values
         * below.
         */

        if (!array_key_exists('post_name', $post)) {
            $post['post_name'] = sanitize_title( $caption, 'instagram_' . $id );
        }

        $func = array_key_exists('ID', $post) ? 'wp_update_post' : 'wp_insert_post';
        $post_id = $func( $post );

        if ($post_id) {
            // Update tags tags
            wp_set_post_terms( $post_id, $tags, WPIG_IMAGE_TAG, $append = false );

            delete_post_meta($post_id, '_wp_trash_meta_status');
            delete_post_meta($post_id, '_wp_trash_meta_time');

            // Update post meta
            foreach ($meta_data as $key => $value) {
                update_post_meta( $post_id, $key, $value );
            }
        }
    }

    public static function encodeImageData($image)
    {
        return gzencode(serialize($image));
    }

    public static function decodeImageData($data)
    {
        return unserialize(gzdecode($image));
    }

    public function syncImages()
    {
        // TODO: Add pagination up to given point in time (or max recursion depth)

        $this->downloadImagesFromTags();

        wp_defer_term_counting( true );

        foreach ($this->images as $image) {
            self::storeImage($image);
        }

        wp_defer_term_counting( false );
    }
}



