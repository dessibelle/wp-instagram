jQuery(window).load(function() {

    $ = jQuery;

    var itemWidth = 306;
    if (typeof WPInstagram !== "undefined" && WPInstagram !== null) {
        itemWidth = parseInt(WPInstagram.itemWidth, 10);
    }

    $('.wp-instagram-images.flexslider').flexslider({
        'animation': 'slide',
        'smoothHeight': true,
        'itemWidth': itemWidth
    }); // .css('height', itemWidth);

});
