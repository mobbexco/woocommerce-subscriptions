(function (window) {
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

    function createNewTotalField() {
        // Get woocommerce actions panel
        var actionsPanel = document.getElementById('actions');

        // Create field
        var input = document.createElement('input');
        input.setAttribute('type', 'text');
        input.setAttribute('name', 'mbbxs_new_total');
        input.setAttribute('id', 'mbbxs_new_total');
        input.setAttribute('value', mobbex_data.order_total);

        var label = document.createElement('label');
        label.appendChild(document.createTextNode('New Total'));

        var container = document.createElement('div');
        container.setAttribute('class', 'mbbxs_action_field mbbxs_new_total_field');
        container.appendChild(label);
        container.appendChild(input);

        // Add to actions panel
        actionsPanel.appendChild(container);
    }

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
                jQuery.ajax({
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

        // Create subscription 'New total' field
        createNewTotalField();

        // Get actions select and 'New total' field container
        var actionsSelect  = document.getElementsByName('wc_order_action')[0];
        var fieldContainer = document.querySelector('.mbbxs_new_total_field');

        // Only show while its action is selected
        toggleElementDependigSelect(fieldContainer, actionsSelect, 'mbbxs_modify_total');
        actionsSelect.addEventListener('change', function() {
            toggleElementDependigSelect(fieldContainer, actionsSelect, 'mbbxs_modify_total')
        });
    });
}) (window);