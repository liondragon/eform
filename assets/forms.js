document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form input[name="js_ok"]').forEach(function (jsField) {
        let form = jsField.form;
        jsField.value = '1';
        let invalid = form.querySelector('[aria-invalid="true"]');
        if (invalid) {
            invalid.focus();
        }
        form.addEventListener('submit', function () {
            jsField.value = '1';
        });
    });
});