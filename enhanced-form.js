document.addEventListener('DOMContentLoaded', function() {
    // Loop through all forms on the page
    var forms = document.querySelectorAll('form');
    forms.forEach(function(form) {
        // Add submit event listener to each form
        form.addEventListener('submit', function() {
            // Find the JavaScript check field within this form using the data attribute
            var jsCheckField = form.querySelector('[data-enhanced-js-check]');
            if (jsCheckField) {
                jsCheckField.value = 'valid';
            }
        });

        // Monitoring focus on the honeypot field
        var honeypotField = form.querySelector('[name="enhanced_url"]');
        if (honeypotField) {
            honeypotField.addEventListener('focus', function() {
                console.log('Honeypot field focused: possible bot');
            });
        }
    });
});
