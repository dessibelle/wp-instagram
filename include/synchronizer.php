<?php

include_once( dirname(__FILE__) . '/defines.php');

class WPIGSynchronizer {

    const SYNC_MAX_PAGINATION_KEY = 'wpig_%s_max_pagination';
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
        $this->setUsers($users);
        $this->setLocations($locations);
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
     * Getter for users
     * @return array Array of user names
     */
    public function getUsers()
    {
        return $this->users;
    }

    /**
     * Setter for users
     * @param array $users Array of user names
     */
    public function setUsers($users)
    {
        $this->users = $users;
    }


    /**
     * Getter for locations
     * @return array Array of location names
     */
    public function getLocations()
    {
        return $this->locations;
    }

    /**
     * Setter for locations
     * @param array $locations Array of location names
     */
    public function setLocations($locations)
    {
        $this->locations = $locations;
    }


    /**
     * Gets the max id for a given tag
     * @param  string $endpoint Tag name
     * @return string      Max tag id
     */
    protected function sync_endpoint_max_id($endpoint, $value)
    {
        return get_option( sanitize_key( sprintf(self::SYNC_MAX_PAGINATION_KEY, $endpoint) . '_' . $value), 0 );
    }


    /**
     * Sets the max id for a given tag
     * @param string $endpoint Tag name
     * @param string $id  Max tag id
     */
    protected function set_sync_endpoint_max_id($endpoint, $value, $id)
    {
        update_option( sanitize_key( sprintf(self::SYNC_MAX_PAGINATION_KEY, $endpoint) . '_' . $value), $id );
    }

    public static function endpoints()
    {
        return array('tags', 'users', 'locations');
    }

    public function symbolsForEndpoint($endpoint)
    {
        $endpoints = self::endpoints();

        if (in_array($endpoint, $endpoints)) {
            return $this->$endpoint;
        }

        return array();
    }

    public function apiMethodForEndpoint($endpoint)
    {
        $method = false;

        switch ($endpoint) {
            case 'tags':
                $method = 'recentPhotosForTag';
                break;
            case 'users':
                $method = 'recentPhotosForUser';
                break;
            case 'locations':
                $method = 'recentPhotosForLocation';
                break;
        }

        return $method;
    }

    /**
     * Downloads images using the Instagram API
     * @param  string $type API endpoint
     * @return array Array of image objects
     */
    public function downloadImagesForEndpoint($endpoint)
    {
        $symbols = $this->symbolsForEndpoint($endpoint);
        $method = $this->apiMethodForEndpoint($endpoint);

        switch ($endpoint) {
            case 'tags':
                $next_max_prop = 'next_max_tag_id';
                $max_prop = 'max_tag_id';
                break;
            default:
                $next_max_prop = 'next_max_id';
                $max_prop = 'max_id';
                break;
        }

        if ($this->api) {
            foreach ($symbols as $symbol) {

                if ($endpoint == 'users') {
                    $uid = $this->api->userIdForName($symbol);

                    if (empty($uid)) {
                        continue;
                    }

                    $symbol = $uid;
                }

                // Get max tag id
                $last_max_id = $this->sync_endpoint_max_id($endpoint, $symbol);
                $new_max_id = 0;
                $current_page_max_id = 0;

                $result = null;

                $depth = 0;
                while (($current_page_max_id == 0 || floatval($current_page_max_id) > floatval($last_max_id)) && $depth < self::SYNC_MAX_PAGINATION) {

                    $params = array();
                    // Check for pagination info

                    if ($result && property_exists($result, 'pagination')) {

                        if (property_exists($result->pagination, $next_max_prop)) {
                            $params[$max_prop] = $result->pagination->$next_max_prop;

                            $current_page_max_id = $result->pagination->$next_max_prop;

                            // Store max tag id
                            if ($current_page_max_id > $new_max_id) {
                                $new_max_id = $current_page_max_id;
                            }
                        }
                    }

                    if ($depth == 0 || ($depth > 0 && count($params))) {
                        $result = $this->api->$method($symbol, $params);

                        echo "${symbol}: ${depth}/" . self::SYNC_MAX_PAGINATION . " - ";
                        print_r($params);
                        echo "\n${current_page_max_id} / ${new_max_id} / ${last_max_id}";
                        echo "\n\n";

                        if ($result && property_exists($result, 'data')) {
                            $this->images = array_merge($this->images, $result->data);
                        }
                    }

                    $depth++;
                }

                // Store max tag id
                if ($new_max_id) {
                    $this->set_sync_endpoint_max_id($endpoint, $symbol, $new_max_id);
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
        $caption = property_exists($image, 'caption') && property_exists($image->caption, 'text') ? $image->caption->text : '';
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

        var_dump($func);

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
        $this->images = array();

        $endpoints = self::endpoints();

        foreach ($endpoints as $endpoint) {
            $this->downloadImagesForEndpoint($endpoint);
        }

        wp_defer_term_counting( true );

        var_dump(sprintf("Storing %d images", count($this->images)));

        foreach ($this->images as $image) {
            self::storeImage($image);
        }

        wp_defer_term_counting( false );
    }
}



