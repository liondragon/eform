(function () {
    'use strict';

    if (window.eformsSettingsHelpReady) {
        return;
    }
    window.eformsSettingsHelpReady = true;

    function closeHelp(except) {
        document.querySelectorAll('.eforms-setting-help[open]').forEach(function (node) {
            if (node !== except) {
                node.removeAttribute('open');
            }
        });
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        var help = target.closest ? target.closest('.eforms-setting-help') : null;
        var dismiss = target.closest ? target.closest('.eforms-setting-help-dismiss') : null;
        closeHelp(help);
        if (dismiss && help) {
            help.removeAttribute('open');
            var summary = help.querySelector('summary');
            if (summary) {
                summary.focus();
            }
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeHelp(null);
        }
    });
}());

(function () {
    'use strict';

    if (window.eformsContentTermsReady) {
        return;
    }
    window.eformsContentTermsReady = true;

    var edgePattern = null;
    try {
        edgePattern = new RegExp('^[^\\p{L}\\p{N}]+|[^\\p{L}\\p{N}]+$', 'gu');
    } catch (error) {}

    function normalizeTerm(value) {
        value = String(value || '').toLowerCase().replace(/\s+/g, ' ').trim();
        value = edgePattern ? value.replace(edgePattern, '') : value.replace(/^[^A-Za-z0-9]+|[^A-Za-z0-9]+$/g, '');
        return value.replace(/\s+/g, ' ').trim();
    }

    function sourceTerms(value) {
        var out = [];
        String(value || '').split(/\r\n|\r|\n/).forEach(function (part) {
            var term = normalizeTerm(part);
            if (term) {
                out.push(term);
            }
        });
        return out;
    }

    function initEditor(editor) {
        var source = document.getElementById(editor.getAttribute('data-source'));
        if (!source) {
            return;
        }
        var list = editor.querySelector('[data-eforms-content-list]');
        var input = editor.querySelector('[data-eforms-content-entry]');
        var add = editor.querySelector('[data-eforms-content-add]');
        var message = editor.querySelector('[data-eforms-content-message]');
        var form = source.form;
        var terms = [];

        function setMessage(text) {
            if (message) {
                message.textContent = text;
            }
        }

        function sync() {
            source.value = terms.join('\n');
        }

        function render() {
            list.innerHTML = '';
            if (!terms.length) {
                var emptyItem = document.createElement('li');
                emptyItem.className = 'eforms-content-terms-editor__empty';
                emptyItem.textContent = 'No blocked phrases yet.';
                list.appendChild(emptyItem);
                return;
            }
            terms.forEach(function (term, index) {
                var item = document.createElement('li');
                item.className = 'eforms-content-terms-editor__item';
                var text = document.createElement('span');
                text.className = 'eforms-content-terms-editor__term';
                text.textContent = term;
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'button-link eforms-content-terms-editor__remove';
                remove.setAttribute('aria-label', 'Remove blocked phrase: ' + term);
                remove.textContent = 'x';
                remove.addEventListener('click', function () {
                    terms.splice(index, 1);
                    sync();
                    render();
                    setMessage('');
                });
                item.appendChild(text);
                item.appendChild(remove);
                list.appendChild(item);
            });
        }

        function addTerm(value) {
            var term = normalizeTerm(value);
            if (!term) {
                return false;
            }
            if (terms.indexOf(term) !== -1) {
                setMessage('Already added.');
                return false;
            }
            terms.push(term);
            return true;
        }

        function commitEntry() {
            var added = false;
            sourceTerms(input && input.value).forEach(function (term) {
                if (addTerm(term)) {
                    added = true;
                }
            });
            if (added) {
                sync();
                render();
                input.value = '';
                setMessage('');
            }
            return added;
        }

        terms = sourceTerms(source.value);
        sync();
        render();
        editor.hidden = false;
        source.hidden = true;
        source.setAttribute('aria-hidden', 'true');
        if (add && input) {
            add.addEventListener('click', function () {
                commitEntry();
                input.focus();
            });
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    commitEntry();
                }
            });
            if (form) {
                form.addEventListener('submit', function () {
                    commitEntry();
                });
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-eforms-content-terms-editor]').forEach(initEditor);
    });
}());
