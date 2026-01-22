(function () {
    'use strict';

    function forEachNode(list, callback) {
        if (!list || !callback) {
            return;
        }
        for (var i = 0; i < list.length; i += 1) {
            callback(list[i]);
        }
    }

    function setJsOk(form) {
        var input = form.querySelector('input[name="js_ok"]');
        if (input) {
            input.value = '1';
        }
    }

    function focusErrors(form) {
        // Focus summary once, then first invalid control to guide keyboard users.
        var summary = form.querySelector('.eforms-error-summary');
        if (summary) {
            summary.focus();
        }

        var firstInvalid = form.querySelector('[aria-invalid="true"]');
        if (firstInvalid && typeof firstInvalid.focus === 'function') {
            firstInvalid.focus();
        }
    }

    function addSubmitLock(form) {
        form.addEventListener('submit', function () {
            var buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
            forEachNode(buttons, function (button) {
                if (button.disabled) {
                    return;
                }
                button.disabled = true;
                if (button.tagName.toLowerCase() !== 'button') {
                    return;
                }
                if (button.querySelector('.eforms-spinner')) {
                    return;
                }
                var spinner = document.createElement('span');
                spinner.className = 'eforms-spinner';
                spinner.setAttribute('aria-hidden', 'true');
                button.appendChild(spinner);
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var forms = document.querySelectorAll('form.eforms-form');
        forEachNode(forms, function (form) {
            setJsOk(form);
            focusErrors(form);
            addSubmitLock(form);
        });
    });
})();
