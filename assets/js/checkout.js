
jQuery(function ($) {
    var form = $('form.checkout, form#order_review');

    renderLock();
    renderContainer();

    // Intercept wc form handler (fired on checkout.js, line 480), and submit for order review
    $('form.checkout').on('checkout_place_order_mobbex_subs', executePayment);
    $('form#order_review').on('submit', executePayment);

    /**
     * Try to execute the payment.
     */
    function executePayment() {
        // If it is not mobbex, continue event propagation
        if ($('[name=payment_method]:checked').val() != 'mobbex_subs')
            return;

        processOrder(
            response => response.redirect ? redirect(response) : openModal(response)
        );

        // Stop event propagation
        return false;
    }

    /**
     * Process the order and create a mobbex checkout.
     * 
     * @param {CallableFunction} callback
     */
    function processOrder(callback) {
        lockForm();

        $.ajax({
            dataType: 'json',
            method: 'POST',
            url: mobbex_data.is_pay_for_order ? form[0].action : wc_checkout_params.checkout_url,
            data: form.serializeArray(),

            success: (response) => {
                response.result == 'success' ? callback(response) && unlockForm() : handleErrorResponse(response);
            },
            error: () => {
                handleErrorResponse({
                    result: 'errors',
                    reload: false,
                    messages: ['Se produjo un error al procesar la transacción. Intente nuevamente']
                });
            }
        });
    }

    /**
     * Redirect to subscriber source panel.
     * 
     * @param {array} response Mobbex Subscriber response.
     */
    function redirect(response) {
        window.top.location = response.redirect;
    }

    /**
     * Open subscriber source panel on modal.
     * 
     * @param {array} response Mobbex checkout response.
     */
    function openModal(response) {
        let options = {
            id: response.data.id,
            sid: response.data.sid,
            type: 'subscriber_source',

            onResult: (data) => {
                location.href = response.return_url + '&status=' + data.status.code;
            },
            onClose: (cancelled) => {
                if (cancelled === true)
                    location.reload();
            },
            onError: () => {
                handleErrorResponse({
                    result: 'errors',
                    reload: false,
                    messages: ['Se produjo un error al procesar la transacción. Intente nuevamente']
                });
            },
        };

        let mobbexEmbed = window.MobbexEmbed.init(options);
        mobbexEmbed.open();
        unlockForm();
    }

    /**
     * Render form loader element.
     */
    function renderLock() {
        if (!$('#mbbx-loader-modal').length)
            $('body').append('<div id="mbbx-loader-modal" style="display: none;"><div id="mbbx-spinner"></div></div>');
    }

    /**
     * Render container for embed modal.
     */
    function renderContainer() {
        if (!$('#mbbx-container').length)
            $('body').append('<div id="mbbx-container"></div>');
    }

    function lockForm() {
        document.getElementById("mbbx-loader-modal").style.display = 'grid'
    }

    function unlockForm() {
        document.getElementById("mbbx-loader-modal").style.display = 'none'
    }

    // Shows any errors we encountered
    function handleErrorResponse(response) {
        // Note: This error handling code is copied from the woocommerce checkout.js file
        if (response.reload === 'true') {
            window.location.reload();
            return;
        }

        // Add new errors
        if (response.messages) {
            var checkout_form = $(".woocommerce-checkout"); 
            //Remove old errors
            $(".woocommerce-checkout .woocommerce-error").remove()
            //Show errors
            if (typeof (response.messages) === 'string') {
                checkout_form.prepend(response.messages);
            } else {
                for (var message of response.messages) {
                    checkout_form.prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><ul class="woocommerce-error" role="alert"><li>'+message+'</li></ul></div>')
                }
            }
        }

        unlockForm();

        // Lose focus for all fields
        form.find('.input-text, select').blur();

        // Scroll to top
        $('html, body').animate({
            scrollTop: (form.offset().top - 100)
        }, 1000);

        if (response.nonce) {
            form.find('#_wpnonce').val(response.nonce);
        }

        // Trigger update in case we need a fresh nonce
        if (response.refresh === 'true') {
            $('body').trigger('update_checkout');
        }
    }
});
