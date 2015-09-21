+ function  ($) {
    $('div.widget-gog[data-defer="true"]').each(function () {
        var key = $(this).data('key');
        $.get('/googleplus/' + key).then(function (response) {
            $('#widget-gog-' + key).html(response);
        }, function () {
            console.log('failed to get widget');
        });
    });
}(jQuery);