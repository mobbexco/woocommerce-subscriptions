(function (window) {

    function mbbxsToggleOptions(optionToCheck, valueToShow, optionsToToggle) {
        // Works with multiple elements
        for (var option of optionsToToggle) {
            if (optionToCheck.checked === valueToShow) {
                option.classList.remove("hidden");
            } else {
                option.classList.add("hidden");
            }
        }
    }

    function mbbxsRenderOptions(optionValues, select) {
        // Clear current options
        var selectValue = select.value;
        select.innerHTML = '';

        // Add new options
        for (var value of optionValues) {
            var option = document.createElement('option');
            option.text = value;
            option.value = value;

            if (value == selectValue) {
                option.selected = true;
            }

            select.add(option);
        }
    }

    window.addEventListener('load', function () {
        var isSubscription = document.querySelector('#mbbxs_subscription_mode');
        var options = [
            document.querySelector('.mbbxs_charge_interval_field'),
            document.querySelector('.mbbxs_free_trial_field'),
            document.querySelector('.mbbxs_signup_fee_field'),
            document.querySelector('.mbbxs_test_mode_field')
        ];

        // Show all subscription options when subscription mode is checked
        mbbxsToggleOptions(isSubscription, true, options);
        isSubscription.onclick = function () {
            mbbxsToggleOptions(isSubscription, true, options);
        }

        // Get charge interval and period
        var chargeInterval = document.querySelector('select#mbbxs_charge_interval_interval');
        var chargePeriod = document.querySelector('select#mbbxs_charge_interval_period');

        // This select only exists when the subscription type is dynamic
        if (chargeInterval) {
            // The intervals in dynamic subscriptions are limited depending on the period
            var intervals = {
                d: [7, 15],
                m: [1, 2, 3, 6],
                y: [1],
            };

            var periodIntervals = intervals[chargePeriod.value];
            mbbxsRenderOptions(periodIntervals, chargeInterval);
            chargePeriod.onchange = function () {
                // Render intervals of selected period
                var periodIntervals = intervals[chargePeriod.value];
                mbbxsRenderOptions(periodIntervals, chargeInterval);
            }
        }
    });
}(window));