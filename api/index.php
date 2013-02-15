<?php

require_once( dirname(__FILE__) . '/../../../../wp-load.php' );
wp();
require_once( ABSPATH . WPINC . '/template-loader.php' );

status_header(200);


class WPInstagramAPIHandler {

    protected $api;
    protected $access_token;

    public function __construct() {
        $this->access_token = get_option(WPInstagram::ACCESS_TOKEN_KEY);
        $this->api = WPInstagramAPI::withAccessToken($this->access_token);

        $this->run();
    }

    /**
     * Runs the API handler
     * Redirects all URL:s the Instagram API and returns the results to the client
     * @return void
     */
    protected function run() {
        $qs = $_SERVER['QUERY_STRING'];
        parse_str($qs, $params);

        if (isset($params['action'])) {
            $action = $params['action'];
            unset($params['action']);

            $url = $this->api->endpointUrl($action, array(), $params);
            $data = $this->api->fetchData($url);

            $this->send_response($data);
        }
    }

    /**
     * Outputs the supplied JSON data to client
     * @param  string $data JSON formatted data
     * @return void
     */
    protected function send_response($data = null)
    {

        header("Content-Type: application/json; charset=utf-8");
        header("Cache-Control: no-cache, must-revalidate");
        header("Expires: " . date('r', time() + 60));
        header("Content-Length: " . strlen($data));

        echo $data;
        exit(0);
    }
}

$api_handler = new WPInstagramAPIHandler();
