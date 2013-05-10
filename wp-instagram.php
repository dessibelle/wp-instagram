<?php
/*
Plugin Name: WP Instagram
Plugin URI: http://dessibelle.se
Description: WordPress plugin for interacting with the Instagram API
Author: Simon Fransson
Version: 1.0b1
Author URI: http://dessibelle.se
*/


include_once( dirname(__FILE__) . '/include/api.php');


class WPInstagram {

    const PLUGIN_VERSION = '1.0a2';

    const SETTINGS_SECTION_KEY = 'wp_instagram';

    const CLIENT_ID_KEY = 'wp_instagram_client_id';
    const CLIENT_SECRET_KEY = 'wp_instagram_client_secret';
    const ACCESS_TOKEN_KEY = 'wp_instagram_access_token';
    const AUTH_DATA_KEY = 'wp_instagram_auth_data';
    const USERNAME_KEY = 'wp_instagram_username';
    const USER_ID_KEY = 'wp_instagram_user_id';

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

        add_action('wp_ajax_my_ajax', array(&$this, 'my_ajax'));
        add_action('wp_ajax_nopriv_my_ajax', array(&$this, 'my_ajax'));
        add_action('init', array(&$this, 'setup'));


        self::$auth_redirect_uri = admin_url('admin.php?page=' . self::$plugin_slug);
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


    protected static function required_capability()
    {
        $capability = apply_filters('wpig_required_capability', 'administrator');

        return $capability;
    }

    public function setup()
    {
        wp_enqueue_script( 'wp-instagram', plugins_url( 'js/instagram.js', __FILE__ ), array('jquery'), self::PLUGIN_VERSION, true );
        wp_localize_script( 'wp-instagram', 'WPInstagram', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
        ) );
    }

    /**
     * Register admin menus
     * @return void
     */
    public function admin_menu() {

        $capability = $this->required_capability();

        $menu_title = __('Instagram', 'wpig'); //get_bloginfo('name'
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

        ?>


        <div class="wrap">

            <?php screen_icon('options-general'); ?>
            <?php //screen_icon(self::$plugin_slug); ?>

            <h2><?php printf(__('%s settings', 'wpig'), 'Instagram'); ?></h2>

            <form method="post" action="options.php">


            <div class="tool-box">
            <?php

                do_settings_sections( self::$plugin_slug );
                settings_fields( self::$plugin_slug );

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
        $section_id = self::$plugin_slug; // . '-settings';
        $settings_section = self::$plugin_slug; //'general';

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
    }




    /**
     * Renders the instagram authorization settings section header
     *
     * @return void
     * @author Simon Fransson
     **/
    public function auth_section_header()
    {
        echo wpautop(sprintf(__('Instagram API value <code>redirect_uri</code> should be set up to point at <code>%s</code>.', 'wpig'), self::$auth_redirect_uri));
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

    public function get_api()
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


    public function my_ajax()
    {
        $r = new WP_Ajax_Response(array(
            'what' => 'hello',
            'action' => 'poo',
        ));
        $r->send();

        header('Content-Type: application/json; charset=utf8');
        echo json_encode(array('poo' => 'moo'));
        die();
    }
}

$wpig = WPInstagram::instance();
