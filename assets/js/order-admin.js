/**
 * Toggle an element display depending the value of a select.
 */
function toggleElementDependigSelect(elementToToggle, select, valueToShow) {
    var value = select.options[select.selectedIndex].value;

    // If current value is the value to show
    if (value && value == valueToShow) {
        elementToToggle.style.display = 'inline-block';
    } else {
        elementToToggle.style.display = 'none';
    }
}

jQuery(function ($) {

    window.addEventListener('load', function () {
        // Get payment retry buttons
        var retries = document.querySelectorAll('.mbbx_retry_btn');

        retries.forEach(function (btn) {
            btn.onclick = function () {
                // Prevent woocommerce default refresh
                event.preventDefault();
                console.log(btn.id);

                // Get Order Id from global mobbex_data and execution id from btn id
                var data = {
                    'order_id': mobbex_data.order_id,
                    'execution_id': btn.id
                };

                // Call to plugin endpoint with data to retry payment
                $.ajax({
                    dataType: "json",
                    method: "POST",
                    url: mobbex_data.retry_url,
                    data: data,
                    success: function (response) {
                        if (response.result) {
                            window.location.reload();
                        } else {
                            // Plugin will normally send the error contents in a response
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

        // Get actions panel elements
        var actionsPanel  = document.getElementById('actions');
        var actionsSelect = document.getElementsByName('wc_order_action')[0];

        // Insert "new subscription total" field in actions panel
        var modifyTotalField    = '<input type="text" name="mbbxs_new_total" id="mbbxs_new_total" value="' + mobbex_data.order_total + '"></input>';
        actionsPanel.innerHTML += '<div class="mbbxs_action_field"><label>New total</label>' + modifyTotalField + '</div>';

        // Get "new subscription total field" container
        var fieldContainer = document.querySelector('.mbbxs_action_field');

        // Only show new total field when "Modify Subscription Total" action is selected
        toggleElementDependigSelect(fieldContainer, actionsSelect, 'mbbxs_modify_total');
        actionsSelect.onchange = function() {
            toggleElementDependigSelect(fieldContainer, actionsSelect, 'mbbxs_modify_total')
        };
    });
});