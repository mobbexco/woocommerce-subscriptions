jQuery(function ($) {

    window.addEventListener('load', function () {
        var retries = document.querySelectorAll('.mbbx_retry_btn');

        retries.forEach(function (btn) {
            
            btn.onclick = function () {
                event.preventDefault();
                console.log(btn.id);

                var data = {
                    'order_id': mobbex_data.order_id,
                    'execution_id': btn.id
                };

                $.ajax({
                    dataType: "json",
                    method: "POST",
                    url: mobbex_data.retry_url,
                    data: data,
                    success: function (response) {
                        // WC will send the error contents in a normal request
                        if (response.result) {
                            window.location.reload();
                        } else {
                            alert('Error: ' + response.msg);
                        }
                    },
                    error: function () {
                        // We got a 500 or something if we hit here. Shouldn't normally happen
                        alert('Error on execution');
                    }
                });
            }
        });
    });
});