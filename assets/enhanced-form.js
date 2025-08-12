document.addEventListener('DOMContentLoaded', function () {
    // Only target forms that include the JS validation field
    document.querySelectorAll('form .enhanced_js_check').forEach(function (jsField) {
        let form = jsField.closest('form');

        // Set JS check right away (in case form was prefilled)
        jsField.value = 'valid';

        // Focus helper: if the server rendered any invalid fields,
        // move focus to the first one for easier accessibility.
        let invalid = form.querySelector('[aria-invalid="true"]');
        if (invalid) {
            invalid.focus();
        }

        form.addEventListener('submit', function () {
            jsField.value = 'valid';
        });

        let honeypot = form.querySelector('[name="enhanced_url"]');
        if (honeypot) {
            honeypot.addEventListener('focus', function () {
                console.warn('Honeypot field focused. Possible bot.');
            });
        }
    });
});