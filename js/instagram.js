jQuery(function($) {
    var data = {
        action: 'instagram_sync'
    };

    var url = false;
    if (typeof ajaxurl !== "undefined") {
        url = ajaxurl;
    } else if (typeof WPInstagram !== "undefined") {
        url = WPInstagram.ajaxurl;
    }

    jQuery.post(url, data, function(response) { return; });
});
