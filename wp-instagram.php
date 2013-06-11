<?php
/*
Plugin Name: WP Instagram
Plugin URI: http://dessibelle.se
Description: WordPress plugin for interacting with the Instagram API
Author: Simon Fransson
Version: 1.0b3
Author URI: http://dessibelle.se
*/

include_once( dirname(__FILE__) . '/include/defines.php');
include_once( dirname(__FILE__) . '/include/api.php');
include_once( dirname(__FILE__) . '/include/content-types.php');
include_once( dirname(__FILE__) . '/include/synchronizer.php');
include_once( dirname(__FILE__) . '/include/template-tags.php');

class WPInstagram {

    const PLUGIN_VERSION = '1.0b3';
    const FLEXSLIDER_VERSION = '2.1';

    const SETTINGS_SECTION_KEY = 'wp_instagram';

    const CLIENT_ID_KEY = 'wp_instagram_client_id';
    const CLIENT_SECRET_KEY = 'wp_instagram_client_secret';
    const ACCESS_TOKEN_KEY = 'wp_instagram_access_token';
    const AUTH_DATA_KEY = 'wp_instagram_auth_data';
    const USERNAME_KEY = 'wp_instagram_username';
    const USER_ID_KEY = 'wp_instagram_user_id';

    const IMPORT_DIRECTIVES = 'wp_instagram_tags';
    const IMPORT_OWNER_KEY = 'wp_instagram_instructions';
    const IMPORT_POST_STATUS = 'wp_instagram_import_post_status';


    protected static $instance;
    protected static $plugin_slug;
    protected static $auth_redirect_uri;

    /**
     * Constructor. Don't call directly, @see instance() instead.
     *
     * @see instance()
     * @return void
     * @author Simon Fransson
     **/
    public function __construct() {

        self::$plugin_slug = dirname( plugin_basename( __FILE__ ) );

        load_plugin_textdomain( 'wpig', false, self::$plugin_slug . '/languages/' );

        register_activation_hook(__FILE__, array(&$this, 'activation_hook'));

        add_action('admin_menu', array(&$this, 'admin_menu'));
        add_action('admin_init', array(&$this, 'init_settings'));

        add_action('init', array(&$this, 'setup'));

        $name = WPIG_IMAGE_TYPE;
        add_filter( "manage_{$name}_posts_columns", array(&$this, 'manage_posts_columns') );
        add_action( "manage_{$name}_posts_custom_column", array(&$this, 'manage_posts_custom_column'), 10, 2 );

        add_action("wp_ajax_{$name}_publish", array(&$this, 'ajax_change_status'));
        add_action("wp_ajax_{$name}_trash", array(&$this, 'ajax_change_status'));

        add_filter("views_edit-{$name}", array(&$this, 'admin_views'));
        add_filter('admin_body_class', array(&$this, 'admin_body_class'));

        add_filter('the_content', array(__CLASS__, 'the_content'), 1, 10);

        // add_action('admin_footer', array(&$this, 'setup_instagram'));
        add_action('wp_ajax_instagram_sync', array(__CLASS__, 'wp_ajax_instagram_sync'));
        add_action('wp_ajax_nopriv_instagram_sync', array(__CLASS__, 'wp_ajax_nopriv_instagram_sync'));

        // add_action('admin_init', array(&$this, 'force_sync'), 20);

        self::$auth_redirect_uri = admin_url('admin.php?page=' . self::$plugin_slug);
    }


    // TODO: Remove this function
    public function force_sync()
    {
        $ig = new WPIGSynchronizer(self::get_api(), self::filter_symbols_by_type());
        $ig->syncImages();

        die();
    }


    /**
     * Singleton accessor, returns the instance
     *
     * @return void
     * @author Simon Fransson
     **/
    public static function instance() {
        if (!isset(self::$instance)) {
            $c = __CLASS__;
            self::$instance = new $c();
        }

        return self::$instance;
    }


    /**
     * Plugin activation hook
     * @return void
     */
    public function activation_hook()
    {
        //
    }

    /**
     * Returns the required capability required to use the plugin
     * @return string Capability name. Defaults to 'administrator'.
     */
    protected static function required_capability()
    {
        $capability = apply_filters('wpig_required_capability', 'administrator');

        return $capability;
    }

    /**
     * Enqueues scripts in admin section
     * @return void
     */
    public function setup()
    {
        WPIGImage::setup_type();

        if (!is_admin()) {
            wp_enqueue_script( 'wpig.flexslider', plugins_url( 'js/flexslider/jquery.flexslider-min.js', __FILE__ ), array('jquery'), self::FLEXSLIDER_VERSION, true );
            wp_enqueue_style( 'wpig.flexslider', plugins_url( 'js/flexslider/flexslider.css', __FILE__ ), array(), self::FLEXSLIDER_VERSION );

            wp_enqueue_script( 'wpig.main', plugins_url( 'js/wp-instagram.js', __FILE__ ), array('jquery', 'wpig.flexslider'), self::PLUGIN_VERSION, true );
            wp_enqueue_style( 'wpig.main', plugins_url( 'css/wp-instagram.css', __FILE__ ), array(), self::PLUGIN_VERSION );

        }

        if (!is_admin() && self::import_status() == 'publish') {
            wp_enqueue_script( 'wpig.public', plugins_url( 'js/instagram.js', __FILE__ ), array('jquery'), self::PLUGIN_VERSION, true );
            wp_localize_script( 'wpig.public', 'WPInstagram', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
            ) );
        } else if (is_admin()) {
            wp_enqueue_script( 'wpig.admin', plugins_url( 'js/instagram.js', __FILE__ ), array('jquery'), self::PLUGIN_VERSION, true );
        }
    }

    /**
     * Register admin menus
     * @return void
     */
    public function admin_menu() {

        $capability = $this->required_capability();

        $menu_title = __('WP Instagram', 'wpig'); //get_bloginfo('name'
        $icon_url = plugins_url('icons/instagram.png', __FILE__);
        add_menu_page( sprintf('%s settings', $menu_title), $menu_title, $capability, self::$plugin_slug, array(&$this, 'admin_menu_page'), $icon_url );

        /*
        add_submenu_page($utilities_location, __('Tools', 'wpig'), $utilities_title, SE_TENANT_ROLE, 'se-tools', array(&$this, 'admin_menu_page_tools_public'));
        */

        add_action( 'load-toplevel_page_'. self::$plugin_slug, array(&$this, 'authorize_instagram') );
    }

    /**
     * Renders the admin menu page
     * @return void
     */
    public function admin_menu_page()
    {
        if ( !current_user_can( $this->required_capability() ) )  {
            wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
        }


        $access_token = get_option(self::ACCESS_TOKEN_KEY);
        $authenticated = !empty($access_token);

        $active_tab = 'auth';
        if ($authenticated)
            $active_tab = array_key_exists('tab', $_GET) ? $_GET['tab'] : 'filter';

        ?>


        <div class="wrap">

            <?php screen_icon('options-general'); ?>
            <?php //screen_icon(self::$plugin_slug); ?>

            <h2><?php printf(__('%s settings', 'wpig'), 'Instagram'); ?></h2>

            <h2 class="nav-tab-wrapper">
                <a href="?page=<?php echo self::$plugin_slug; ?>&amp;tab=filter" class="nav-tab <?php echo $active_tab == 'filter' ? 'nav-tab-active' : ''; ?>"><?php _e('Filter', 'wpig'); ?></a>
                <a href="?page=<?php echo self::$plugin_slug; ?>&amp;tab=auth" class="nav-tab <?php echo $active_tab == 'auth' ? 'nav-tab-active' : ''; ?>"><?php _e('Authentication', 'wpig'); ?></a>
            </h2>


            <form method="post" action="options.php">

            <div class="tool-box">
            <?php

                do_settings_sections( self::$plugin_slug . $active_tab );
                settings_fields( self::$plugin_slug  . $active_tab );

                submit_button();
            ?>
            </div>


            </form>
        </div>

        <?php
    }



    /**
     * Ininitalizes admin section setting fields
     *
     * @return void
     * @author Simon Fransson
     **/
    public function init_settings()
    {
        wp_enqueue_style( 'wp-instagram.admin', plugins_url( 'admin/css/style.css', __FILE__ ), array(), self::PLUGIN_VERSION );
        wp_enqueue_script( 'wp-instagram.admin', plugins_url( 'admin/js/instagram.js', __FILE__ ), array('jquery'), self::PLUGIN_VERSION, true );

        $section_id = self::$plugin_slug . '-auth';
        $settings_section = self::$plugin_slug . 'auth';

        add_settings_section($section_id,
            __('Authorization', 'wpig'),
            array(&$this, 'auth_section_header'),
            $settings_section);

        $auth_field_classes = array('code', 'regular-text');

        $client_id = get_option(self::CLIENT_ID_KEY);
        $client_secret = get_option(self::CLIENT_SECRET_KEY);
        $access_token = get_option(self::ACCESS_TOKEN_KEY);

        $authenticated = !empty($access_token);

        $auth_info = null;
        if ($client_id && $client_secret) {
            if (!$access_token) {
                $auth_url = WPInstagramAPI::authUrl($client_id, self::$auth_redirect_uri);
                $auth_info = sprintf('<a href="%s" class="button">%s</a>', $auth_url, __('Authenticate with instagram', 'wpig'));
            } else {
                $auth_info = sprintf('<a href="#" class="button" id="instagram_deauth">%s</a>', __('Deauthenticate', 'wpig'));
            }
        } else {
            $auth_info = sprintf('<span class="description">%s</span>', __('Please enter your client ID and client secret before authorizing with Instagram.', 'wpig'));
        }

        add_settings_field(self::CLIENT_ID_KEY,
            __('Client ID', 'wpig'),
            array(&$this, 'render_settings_field'),
            $settings_section,
            $section_id,
            array(
                'field' => self::CLIENT_ID_KEY,
                'class' => $auth_field_classes,
            ));
        add_settings_field(self::CLIENT_SECRET_KEY,
            __('Client secret', 'wpig'),
            array(&$this, 'render_settings_field'),
            $settings_section,
            $section_id,
            array(
                'field' => self::CLIENT_SECRET_KEY,
                'class' => $auth_field_classes,
            ));
        add_settings_field(self::ACCESS_TOKEN_KEY,
            __('Access token', 'wpig'),
            array(&$this, 'render_settings_field'),
            $settings_section,
            $section_id,
            array(
                'field' => self::ACCESS_TOKEN_KEY,
                'class' => $auth_field_classes,
                'attributes' => array('readonly'),
                'after' => " " . $auth_info,
                'type' => $authenticated ? 'text' : 'none',
            ));
        if ($authenticated) {
            add_settings_field(self::USERNAME_KEY,
                __('Username', 'wpig'),
                array(&$this, 'render_settings_field'),
                $settings_section,
                $section_id,
                array(
                    'field' => self::USERNAME_KEY,
                    'class' => $auth_field_classes,
                    'attributes' => array('readonly'),
                ));
            add_settings_field(self::USER_ID_KEY,
                __('User ID', 'wpig'),
                array(&$this, 'render_settings_field'),
                $settings_section,
                $section_id,
                array(
                    'field' => self::USER_ID_KEY,
                    'class' => $auth_field_classes,
                    'attributes' => array('readonly'),
                ));
        }

        register_setting($settings_section, self::CLIENT_ID_KEY);
        register_setting($settings_section, self::CLIENT_SECRET_KEY);

        register_setting($settings_section, self::ACCESS_TOKEN_KEY);
        register_setting($settings_section, self::USERNAME_KEY);
        register_setting($settings_section, self::USER_ID_KEY);


        $section_id = self::$plugin_slug . '-filter';
        $settings_section = self::$plugin_slug . 'filter';

        add_settings_section($section_id,
            __('Filter', 'wpig'),
            array(&$this, 'filter_section_header'),
            $settings_section);


        // Settings field
        add_settings_field(
            self::IMPORT_DIRECTIVES,
            __('Tags, users and locations to import from', 'wpig'),
            array(&$this, 'render_settings_field'),
            $settings_section,
            $section_id,
            array(
                'field' => self::IMPORT_DIRECTIVES,
                'type' => 'textarea',
                'description' => sprintf(__("Separate %s, %s and %s with spaces.", 'wpig'),
                    sprintf('<code>@%s</code>', __('tags', 'wpig')),
                    sprintf('<code>#%s</code>', __('users', 'wpig')),
                    sprintf('<code>*%s</code>', __('location_ids', 'wpig'))
                )
            )
        );

        // Settings field
        add_settings_field(
            self::IMPORT_OWNER_KEY,
            __('Owner of imported photos', 'wpig'),
            array(&$this, 'instagram_import_owner_dropdown'),
            $settings_section,
            $section_id
        );

        // Settings field
        add_settings_field(
            self::IMPORT_POST_STATUS,
            __('Status of imported photos', 'wpig'),
            array(&$this, 'instagram_import_status_dropdown'),
            $settings_section,
            $section_id
        );


        // Register the settings fields
        register_setting($settings_section, self::IMPORT_DIRECTIVES, array(&$this, 'sanitize_tag_list_setting'));
        register_setting($settings_section, self::IMPORT_OWNER_KEY, 'intval');
        register_setting($settings_section, self::IMPORT_POST_STATUS, 'strval');

    }




    /**
     * Renders the instagram authorization settings section header
     *
     * @return void
     * @author Simon Fransson
     **/
    public function auth_section_header()
    {
        echo wpautop(sprintf(__('%s before you do anything else, then enter your authentication details below. Instagram API value <code>redirect_uri</code> should be set up to point at <code>%s</code>.', 'wpig'),
            sprintf('<a href="%s">%s</a>', 'http://instagram.com/developer/clients/register/', __('Register your application here', 'wpig')),
            self::$auth_redirect_uri));
    }


    /**
     * Renders the instagram authorization settings section header
     *
     * @return void
     * @author Simon Fransson
     **/
    public function filter_section_header()
    {
        // echo wpautop(sprintf(__('Instagram API value <code>redirect_uri</code> should be set up to point at <code>%s</code>.', 'wpig'), self::$auth_redirect_uri));
    }


    /**
     * Renders the specified settings field
     *
     * @param array $args Array of arguments (field, name, id, value)
     * @return void
     * @author Simon Fransson
     **/
    public function render_settings_field($args = null) {
        $defaults = array(
            'field' => null,
            'type' => null,
            'name' => null,
            'id' => null,
            'value' => null,
            'class' => array('regular-text'),
            'description' => null,
            'after' => null,
            'before' => null,
            'attributes' => array(),
        );
        $args = wp_parse_args($args, $defaults);
        extract($args);

        if (!$field) {
            return null;
        }

        if (!$value) {
            $value = get_option($field);
        }

        if (!$name) {
            $name = $field;
        }

        if (!$id) {
            $id = $field;
        }

        $extra_attrs = array();
        foreach ($attributes as $attr => $attr_val) {
            if (is_numeric($attr)) {
                $extra_attrs[] = $attr_val;
            } else {
                $extra_attrs[] = "${attr}=\"${attr_val}\"";
            }
        }


        if ($before) {
            echo $before;
        }

        switch ($type) {
            case 'page':
                return $this->render_page_setting(array(
                    'name' => $name,
                    'id' => $id,
                    'selected' => $value,
                ));
            break;
            case 'none':
                break;
            case 'textarea':
                $class[] = 'large-text';
                printf('<textarea type="text" name="%s" id="%s" class="%s" %s>%s</textarea>', $name, $id, implode(" ", (array)$class), implode(" ", $extra_attrs), $value);
                break;
            default:
                printf('<input type="text" name="%s" id="%s" value="%s" class="%s" %s>', $name, $id, $value, implode(" ", (array)$class), implode(" ", $extra_attrs));
            break;
        }

        $description = strval($description);
        if (!empty($description)) {
            printf(' <span class="description">%s</span>', $description);
        }

        if ($after) {
            echo $after;
        }
    }


    /**
     * Authorizes instagram. Do not call directly.
     * @return void
     */
    public function authorize_instagram() {

        wp_enqueue_script('wpig.admin.instagram', plugins_url('admin/js/instagram.js', __FILE__), array('jquery'), self::PLUGIN_VERSION, true);
        wp_localize_script('wpig.admin.instagram', 'WPAdminInstagram', array(
            'access_token_field_id' => self::ACCESS_TOKEN_KEY,
            //'instagram_api_url' => self::instagram_api_url(),
        ));

        if (isset($_GET['code'])) {
            $code = $_GET['code'];

            $client_id = get_option(self::CLIENT_ID_KEY);
            $client_secret = get_option(self::CLIENT_SECRET_KEY);
            $redirect_uri = self::$auth_redirect_uri;

            if ($code && $client_id && $client_secret && $redirect_uri) {
                $api = WPInstagramAPI::withClientData($client_id, $client_secret, $code, $redirect_uri);

                if ($api->authWasSuccessful()) {
                    $access_token = $api->accessToken();
                    if ($access_token) {
                        update_option(self::ACCESS_TOKEN_KEY, $access_token);
                    }

                    $auth_data = $api->authData();
                    if ($auth_data) {
                        update_option(self::AUTH_DATA_KEY, maybe_serialize($auth_data));

                        if (isset($auth_data->user->username)) {
                            update_option(self::USERNAME_KEY, $auth_data->user->username);
                        }

                        if (isset($auth_data->user->id)) {
                            update_option(self::USER_ID_KEY, $auth_data->user->id);
                        }
                    }

                    header("Location: $redirect_uri");
                    die();
                } else {
                    $message = $api->errorMessage();
                    if (empty($message)) {
                        $message = __('An unknown error occured', 'wpig');
                    }
                    $message = sprintf('<div class="error"><p>%s</p></div>', $message);

                    add_action('admin_notices', create_function('', sprintf('echo "%s;"', addslashes($message))));
                }
            }
        }
    }

    public static function get_api()
    {
        return WPInstagramAPI::withAccessToken(get_option(self::ACCESS_TOKEN_KEY));
    }

    /**
     * Returns an URL to the instagram API endpoint
     * @return string API URL
     */
    public static function instagram_api_url()
    {
        return plugins_url('api/', __FILE__);
    }


    /* ==============================
     * ADMIN ADJUSTMENTS
     * ============================== */

    public function manage_posts_columns($defaults)
    {
        $featured_col = array_slice($defaults, 0, 1);
        array_shift($defaults);

        $featured_col['instagram_image'] = __('Image', 'wpig');
        $featured_col['instagram_actions'] = __('Actions', 'wpig');


        $title_col = array_slice($defaults, 0, 1);
        array_shift($defaults);
        $title_col['instagram_username'] = __('User', 'wpig');

        $defaults = array_merge($featured_col, $title_col, $defaults);

        return $defaults;
    }


    public function manage_posts_custom_column($column_name, $post_id)
    {
        $image = new WPIGImage($post_id);

        if ($column_name == 'instagram_image') {

            $attrs = array(
                'data-url' => $image->getImageURL(WPIG_IMAGE_SIZE_LOW),
                'class' => 'instagram-image',
            );

            echo $image->getImageMarkup(WPIG_IMAGE_SIZE_THUMBNAIL, array('width' => 64, 'height' => 64, 'attributes' => $attrs));
        }

        if ($column_name == 'instagram_actions') {

            $post = get_post($post_id);

            echo '<ul class="buttons">';

            if ($post->post_status != 'publish') {
                printf('<li><a href="#" class="instagram-action instagram-publish button button-primary" data-id="%d" data-action="%s_publish">%s</a></li>', $post_id, WPIG_IMAGE_TYPE, __('Publish', 'wpig'));
            }

            if ($post->post_status != 'trash') {
                printf('<li><a href="#" class="instagram-action instagram-trash button" data-id="%d" data-action="%s_trash">%s</a></li>', $post_id, WPIG_IMAGE_TYPE, __('Hide', 'wpig'));
            }

            echo '</ul>';
        }

        if ($column_name == 'instagram_username') {
            echo $image->getInstagramUserLink();
        }
    }

    public function admin_body_class($class)
    {
        global $pagenow, $typenow;

        $name = WPIG_IMAGE_TYPE;

        if ($pagenow == 'edit.php' && $typenow == $name) {
            $class .= " edit-{$name}";
        }

        return $class;
    }

    public function admin_views($views)
    {

        global $wp_post_statuses;

        if (array_key_exists('trash', $views)) {

            $label = trim(strip_tags(__($wp_post_statuses['trash']->label_count[0])), " (%s)");
            $views['trash'] = str_replace($label, __('Hidden', 'wpig'), $views['trash']);
        }

        return $views;
    }




    /* ==============================
     * SETTING ACCESSORS
     * ============================== */


    public static function import_status()
    {
        return get_option( self::IMPORT_POST_STATUS, "pending" );
    }


    /**
     * Returns an array of Instagram tags to use for fan photod
     * @return array Array of tags
     */
    public static function filter_symbols()
    {
        return self::tag_list_to_array(get_option(self::IMPORT_DIRECTIVES, ''));
    }

    public static function filter_symbols_by_type()
    {
        $symbols = self::filter_symbols();

        $sorted_symbols = array();
        foreach ($symbols as $symbol) {
            $initial = $symbol{0};
            switch ($initial) {
                case '@':
                    $sorted_symbols['users'][] = substr($symbol, 1);
                    break;
                case '#':
                    $sorted_symbols['tags'][] = substr($symbol, 1);
                    break;
                case '*':
                    $sorted_symbols['locations'][] = substr($symbol, 1);
                    break;
            }
        }

        return $sorted_symbols;
    }

    /**
     * Returns the user ID of the imported photos owner
     * @return int User id of selected user
     */
    public static function photo_owner()
    {
        return get_option(self::IMPORT_OWNER_KEY, 1);
    }



    public static function instagram_import_owner_dropdown()
    {
        wp_dropdown_users(array(
            'name' => self::IMPORT_OWNER_KEY,
            'id' => self::IMPORT_OWNER_KEY,
            'selected' => get_option(self::IMPORT_OWNER_KEY),
        ));
    }

    public static function instagram_import_status_dropdown()
    {

        $status = self::import_status();

        ?>

        <select name="<?php echo self::IMPORT_POST_STATUS; ?>" id="<?php echo self::IMPORT_POST_STATUS; ?>">
            <option<?php selected( $status, "publish" ); ?> value="publish"><?php _e("Published") ?></option>
            <option<?php selected( $status, "private" ); ?> value="publish"><?php _e("Privately Published") ?></option>
            <option<?php selected( $status, "pending" ); ?> value="pending"><?php _e("Pending Review") ?></option>
            <option<?php selected( $status, "draft" ); ?> value="draft"><?php _e("Draft") ?></option>
        </select>

        <?php
    }

    /**
     * Sanitizes the value entered in the instagram tags field,
     * and clears the update transient if the value changed
     * @param  string $tags User submitted tag list
     * @return string       Sanitized tag list
     */
    public function sanitize_tag_list_setting($tags)
    {
        $tags = $this->sanitize_tag_list($tags);

        $old_tags = self::filter_symbols();
        if ($tags != implode(" ", $old_tags)) {
            delete_transient( 'wp_instagram_api_call' );
        }

        return $tags;
    }

    /**
     * Sanitizes the a list of tags
     * @param  string $tags User submitted tag list
     * @return string       Sanitized tag list
     */
    public function sanitize_tag_list($tags)
    {
        $sanitized_tags = self::tag_list_to_array($tags);

        if (count($sanitized_tags)) {
            $tags = implode(' ', $sanitized_tags);
        } else {
            $tags = '';
        }

        return $tags;
    }

    /**
     * Filters out restricted characters from a list of tag names and return the tags as an array
     * @param  string $taglist Comma separated list of tags
     * @return array           Array of tags
     */
    public static function tag_list_to_array($taglist)
    {
        $tags = array();

        if (strlen($taglist)) {

            $symbols = array();
            preg_match_all('/[@#*][A-Za-z0-9_]+/i', $taglist, $symbols);

            if (count($symbols)) {
                $tags = array_shift($symbols);
            }

            // $taglist = preg_replace('/[^A-Za-z0-9_]+/i', '', $taglist);
            // $tags = array_unique(array_filter(explode(',', $taglist)));

            return $tags;
        }

        return $tags;
    }




    /* ==============================
     * AJAX CALLBACKS
     * ============================== */

    public function ajax_change_status()
    {
        header("Content-Type: application/json; charset=utf8");

        $modified = false;
        $name = WPIG_IMAGE_TYPE;

        if (is_admin()) {
            $id = $_POST['id'];
            $action = $_POST['action'];

            $post = get_post($id);
            switch ($action) {
                case "{$name}_publish":
                    $post->post_status = 'publish';
                    $modified = true;
                    break;
                case "{$name}_trash":
                    $post->post_status = 'trash';
                    $modified = true;
                    break;
            }

            wp_update_post( $post );
        }

        echo json_encode(array('result' => $modified, 'action' => $action, 'id' => $id));

        die();
    }

    public static function wp_ajax_instagram_sync() {
        return self::perform_ajax_sync(true);
    }

    public static function wp_ajax_nopriv_instagram_sync() {
        return self::perform_ajax_sync(false);
    }

    protected static function perform_ajax_sync($admin = true)
    {
        header("Content-Type: application/json; charset=utf8");

        if (!$admin) {
            $response = array(
                'error' => __('Not implemented.', 'wpig'),
                'instagram_sync' => false,
            );

            echo json_encode($response);
            die();
        }

        $did_sync = false;
        if ( false === ( $perform_api_call = get_transient( 'wp_instagram_api_call' ) ) ) {

            $query = self::filter_symbols_by_type();

            $ig = new WPIGSynchronizer(self::get_api(), $query);
            $ig->syncImages();

            $did_sync = true;

            set_transient( 'wp_instagram_api_call', $perform_api_call, 10 * MINUTE_IN_SECONDS );
        }

        $response = array(
            'instagram_sync' => $did_sync,
        );

        echo json_encode($response);

        die();
    }

    /* ==============================
     * FRONT END ADJUSTMENTS
     * ============================== */

    public static function the_content($content)
    {
        global $post;

        if ($post->post_type == WPIG_IMAGE_TYPE) {

            $size = apply_filters( 'wp_instagram_content_image_size', WPIG_IMAGE_SIZE_STANDARD );

            $image = new WPIGImage($post);

            return $image->getInstagramURL();

            return $image->getImageMarkup($size);
        }

        return $content;
    }

}

$wpig = WPInstagram::instance();
