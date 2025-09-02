document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form input[name="js_ok"]').forEach(function (jsField) {
        let form = jsField.form;
        jsField.value = '1';
        let invalids = Array.from(form.querySelectorAll('[aria-invalid="true"]'));
        if (invalids.length) {
            let summary = form.querySelector('.form-errors');
            if (summary) {
                summary.querySelectorAll('li').forEach(function (li) {
                    li.style.display = 'none';
                });
                let handled = new Set();
                invalids.forEach(function (field) {
                    let target = field.closest('fieldset') || field;
                    let id = target.id;
                    if (!id || handled.has(id)) {
                        return;
                    }
                    handled.add(id);
                    let anchor = summary.querySelector('a[href="#' + id + '"]');
                    if (anchor) {
                        let error = form.querySelector('#' + field.getAttribute('aria-describedby'));
                        let label = target.tagName.toLowerCase() === 'fieldset'
                            ? target.querySelector('legend')
                            : form.querySelector('label[for="' + id + '"]');
                        let text = '';
                        if (label) {
                            text += label.textContent.trim();
                        }
                        if (error) {
                            text += (text ? ': ' : '') + error.textContent.trim();
                        }
                        anchor.textContent = text;
                        anchor.parentElement.style.display = '';
                    }
                });
                summary.setAttribute('tabindex', '-1');
                summary.removeAttribute('hidden');
                summary.focus();
                setTimeout(function () {
                    invalids[0].focus();
                }, 0);
            } else if (invalids[0]) {
                invalids[0].focus();
            }
        }
        form.addEventListener('submit', function () {
            jsField.value = '1';
        });
    });
});
