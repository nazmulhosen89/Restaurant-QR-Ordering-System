$(document).on('change', '.restaurant-selector-dropdown', function() {
    var res_id = $(this).val();
    $.post(qrrs_vars.ajax_url, {
        action: 'qrrs_set_active_restaurant',
        res_id: res_id
    }, function(response) {
        if(response.success) {
            window.location.reload();
        }
    });
});