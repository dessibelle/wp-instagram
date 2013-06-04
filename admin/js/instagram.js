jQuery(function($) {

    var preview_img = new Image();
    preview_img.width = 300;
    preview_img.height = 300;

    $(preview_img).addClass('instagram-preview').hide();
    $('body').append(preview_img);

    $(document).mousemove(function(e){

        var offset = 10;

        $(preview_img).filter(':visible').css({
            top: e.pageY + offset,
            left: e.pageX + offset
        });
    });

    $('#the-list .column-instagram_image img.instagram-image').hover(
        function (e) {
            var url = $(this).data('url');
            preview_img.src = url;

            $(preview_img).show();
        },
        function (e) {
            $(preview_img).hide();
        }
    );


    $('#the-list .column-instagram_actions .instagram-action').click(function(e) {

        e.preventDefault();

        var id = $(this).data('id');
        var action = $(this).data('action');

        var data = {
            action: action,
            id: id
        };

        jQuery.post(ajaxurl, data, function(response) {
            if (response.result) {
                window.location.reload();
            }
        });
    });


    $('#instagram_deauth').click(function(e) {
        e.preventDefault();

        $('#' + WPAdminInstagram.access_token_field_id).val('');
        $('#submit').click();
    });

});
