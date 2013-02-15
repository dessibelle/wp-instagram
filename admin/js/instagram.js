jQuery(document).ready(function($) {

    $('#instagram_deauth').click(function(e) {
        e.preventDefault();

        $('#' + WPAdminInstagram.access_token_field_id).val('');
        $('#submit').click();
    });

});
