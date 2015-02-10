Google+ Bolt Extension
======================

Display your public Google+ activity and a profile information on your website.

Edit the `config.yml` file so it contains the correct 'developer-key'.

#### Loading widgets
If you want to load the widgets in your frontend theme, make sure you address the `data-defer`
parameter like below in your application Javascript (on DOMready).

```js
$('div.widget[data-defer="true"]').each(function () {
    var key = $(this).data('key');
    $.get('/async/widget/' + key).then(function (response) {
        $('#widget-' + key).html(response);
    }, function () {
        console.log('failed to get widget');
    });
});
```