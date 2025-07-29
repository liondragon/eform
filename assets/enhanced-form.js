document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form').forEach(function (form) {
        let jsField = form.querySelector('[name=\"enhanced_js_check\"]');

        // Set JS check right away (in case form was prefilled)
        if (jsField) jsField.value = 'valid';

        form.addEventListener('submit', function (e) {
            if (jsField) jsField.value = 'valid';
        });

        let honeypot = form.querySelector('[name=\"enhanced_url\"]');
        if (honeypot) {
            honeypot.addEventListener('focus', function () {
                console.warn('Honeypot field focused. Possible bot.');
            });
        }
    });
});
