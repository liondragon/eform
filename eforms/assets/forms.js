(function () {
    'use strict';

    var MINT_ENDPOINT = '/eforms/mint';
    var MINT_ERROR_MESSAGE = 'This form is temporarily unavailable. Please reload the page.';

    function forEachNode(list, callback) {
        if (!list || !callback) {
            return;
        }
        for (var i = 0; i < list.length; i += 1) {
            callback(list[i]);
        }
    }

    function nowSeconds() {
        return Math.floor(Date.now() / 1000);
    }

    function getFormId(form) {
        if (!form) {
            return '';
        }

        var classes = typeof form.className === 'string' ? form.className.split(/\s+/) : [];
        for (var i = 0; i < classes.length; i += 1) {
            var name = classes[i];
            if (name.indexOf('eforms-form-') === 0 && name !== 'eforms-form') {
                return name.slice('eforms-form-'.length);
            }
        }

        return '';
    }

    function getFormMode(form) {
        var input = form.querySelector('input[name="eforms_mode"]');
        var attr = form.getAttribute('data-eforms-mode');
        // Prefer server-provided data attribute to keep mixed-mode pages consistent.
        if (attr === 'js' || attr === 'hidden') {
            return attr;
        }
        if (input && typeof input.value === 'string') {
            return input.value;
        }
        return '';
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

    function getTokenFields(form) {
        return {
            token: form.querySelector('input[name="eforms_token"]'),
            instance: form.querySelector('input[name="instance_id"]'),
            timestamp: form.querySelector('input[name="timestamp"]')
        };
    }

    function isEmpty(value) {
        return typeof value !== 'string' || value === '';
    }

    function readFieldValue(field) {
        return field && typeof field.value === 'string' ? field.value : '';
    }

    function areFieldsEmpty(fields) {
        return isEmpty(readFieldValue(fields.token)) &&
            isEmpty(readFieldValue(fields.instance)) &&
            isEmpty(readFieldValue(fields.timestamp));
    }

    function areFieldsComplete(fields) {
        return !isEmpty(readFieldValue(fields.token)) &&
            !isEmpty(readFieldValue(fields.instance)) &&
            !isEmpty(readFieldValue(fields.timestamp));
    }

    function setFieldIfEmpty(field, value) {
        if (!field || typeof value !== 'string' || value === '') {
            return;
        }

        if (field.value === '') {
            field.value = value;
        }
    }

    function storageAvailable() {
        if (!window.sessionStorage) {
            return false;
        }
        try {
            var testKey = 'eforms_storage_test';
            window.sessionStorage.setItem(testKey, '1');
            window.sessionStorage.removeItem(testKey);
            return true;
        } catch (error) {
            return false;
        }
    }

    function storageKey(formId) {
        return 'eforms:token:' + formId;
    }

    function tokenTtlMax(form) {
        var raw = form.getAttribute('data-eforms-token-ttl-max');
        if (!raw) {
            return 0;
        }
        var parsed = parseInt(raw, 10);
        return isNaN(parsed) ? 0 : parsed;
    }

    function readCachedToken(formId, ttlMax) {
        if (!storageAvailable()) {
            return null;
        }

        var raw = window.sessionStorage.getItem(storageKey(formId));
        if (!raw) {
            return null;
        }

        try {
            var payload = JSON.parse(raw);
            if (!payload || typeof payload !== 'object') {
                window.sessionStorage.removeItem(storageKey(formId));
                return null;
            }

            var token = typeof payload.token === 'string' ? payload.token : '';
            var instanceId = typeof payload.instance_id === 'string' ? payload.instance_id : '';
            var timestamp = parseInt(payload.timestamp, 10);
            var expires = parseInt(payload.expires, 10);
            if (!token || !instanceId || isNaN(timestamp) || isNaN(expires)) {
                window.sessionStorage.removeItem(storageKey(formId));
                return null;
            }

            var now = nowSeconds();
            if (expires <= now) {
                window.sessionStorage.removeItem(storageKey(formId));
                return null;
            }

            if (ttlMax > 0 && expires - timestamp > ttlMax) {
                window.sessionStorage.removeItem(storageKey(formId));
                return null;
            }

            return {
                token: token,
                instance_id: instanceId,
                timestamp: String(timestamp),
                expires: expires
            };
        } catch (error) {
            window.sessionStorage.removeItem(storageKey(formId));
            return null;
        }
    }

    function writeCachedToken(formId, payload) {
        if (!storageAvailable() || !payload) {
            return;
        }

        try {
            window.sessionStorage.setItem(storageKey(formId), JSON.stringify(payload));
        } catch (error) {
            // Ignore storage failures (private mode or quota) to avoid breaking UX.
        }
    }

    function clearCachedToken(formId) {
        if (!storageAvailable()) {
            return;
        }

        try {
            window.sessionStorage.removeItem(storageKey(formId));
        } catch (error) {
            // Ignore storage failures; remint flow will continue.
        }
    }

    function disableSubmitButtons(form) {
        var buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        forEachNode(buttons, function (button) {
            if (button.disabled) {
                return;
            }
            button.disabled = true;
            button.setAttribute('data-eforms-mint-disabled', '1');
        });
    }

    function enableSubmitButtons(form) {
        var buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        forEachNode(buttons, function (button) {
            if (button.getAttribute('data-eforms-mint-disabled') !== '1') {
                return;
            }
            button.disabled = false;
            button.removeAttribute('data-eforms-mint-disabled');
        });
    }

    function ensureErrorSummary(form) {
        var summary = form.querySelector('.eforms-error-summary');
        if (!summary) {
            summary = document.createElement('div');
            summary.className = 'eforms-error-summary';
            summary.setAttribute('role', 'alert');
            summary.setAttribute('tabindex', '-1');
            var list = document.createElement('ul');
            summary.appendChild(list);
            form.insertBefore(summary, form.firstChild);
        }

        var listNode = summary.querySelector('ul');
        if (!listNode) {
            listNode = document.createElement('ul');
            summary.appendChild(listNode);
        }

        return listNode;
    }

    function showMintError(form) {
        var listNode = ensureErrorSummary(form);
        if (listNode.querySelector('[data-eforms-js-error="1"]')) {
            return;
        }

        var item = document.createElement('li');
        item.setAttribute('data-eforms-js-error', '1');
        item.textContent = MINT_ERROR_MESSAGE;
        listNode.appendChild(item);
    }

    function parseMintPayload(payload) {
        if (!payload || typeof payload !== 'object') {
            return null;
        }

        var token = typeof payload.token === 'string' ? payload.token : '';
        var instanceId = typeof payload.instance_id === 'string' ? payload.instance_id : '';
        var timestamp = parseInt(payload.timestamp, 10);
        var expires = parseInt(payload.expires, 10);
        if (!token || !instanceId || isNaN(timestamp) || isNaN(expires)) {
            return null;
        }

        return {
            token: token,
            instance_id: instanceId,
            timestamp: String(timestamp),
            expires: expires
        };
    }

    function mintToken(formId, callback) {
        var body = 'f=' + encodeURIComponent(formId);

        if (window.fetch) {
            fetch(MINT_ENDPOINT, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: body,
                credentials: 'same-origin'
            })
                .then(function (response) {
                    return response
                        .json()
                        .then(function (payload) {
                            return { status: response.status, payload: payload };
                        })
                        .catch(function () {
                            return { status: response.status, payload: null };
                        });
                })
                .then(function (result) {
                    if (!result || result.status !== 200) {
                        callback(false, null);
                        return;
                    }
                    callback(true, parseMintPayload(result.payload));
                })
                .catch(function () {
                    callback(false, null);
                });
            return;
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', MINT_ENDPOINT, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }
            if (xhr.status !== 200) {
                callback(false, null);
                return;
            }
            try {
                callback(true, parseMintPayload(JSON.parse(xhr.responseText)));
            } catch (error) {
                callback(false, null);
            }
        };
        xhr.send(body);
    }

    function setMintState(form, state) {
        if (state) {
            form.setAttribute('data-eforms-mint-state', state);
        } else {
            form.removeAttribute('data-eforms-mint-state');
        }
    }

    function addMintGuard(form) {
        if (form.getAttribute('data-eforms-mint-guard') === '1') {
            return;
        }
        form.setAttribute('data-eforms-mint-guard', '1');
        form.addEventListener('submit', function (event) {
            if (form.getAttribute('data-eforms-mint-state') === 'ready') {
                return;
            }
            event.preventDefault();
            if (form.getAttribute('data-eforms-mint-state') === 'failed') {
                showMintError(form);
            }
        });
    }

    function injectMintedToken(fields, payload) {
        setFieldIfEmpty(fields.token, payload.token);
        setFieldIfEmpty(fields.instance, payload.instance_id);
        setFieldIfEmpty(fields.timestamp, payload.timestamp);
    }

    function handleJsMintedForm(form) {
        var formId = getFormId(form);
        if (!formId) {
            return;
        }

        var fields = getTokenFields(form);
        if (!fields.token || !fields.instance || !fields.timestamp) {
            return;
        }

        var needsRemint = form.getAttribute('data-eforms-remint') === '1';
        var ttlMax = tokenTtlMax(form);
        var mixedFields = !areFieldsEmpty(fields) && !areFieldsComplete(fields);

        addMintGuard(form);

        if (mixedFields) {
            setMintState(form, 'failed');
            disableSubmitButtons(form);
            showMintError(form);
            return;
        }

        if (needsRemint && !areFieldsEmpty(fields)) {
            setMintState(form, 'failed');
            disableSubmitButtons(form);
            showMintError(form);
            return;
        }

        if (areFieldsComplete(fields) && !needsRemint) {
            setMintState(form, 'ready');
            return;
        }

        disableSubmitButtons(form);
        setMintState(form, 'pending');

        if (needsRemint) {
            clearCachedToken(formId);
        }

        if (!needsRemint) {
            var cached = readCachedToken(formId, ttlMax);
            if (cached) {
                injectMintedToken(fields, cached);
                setMintState(form, 'ready');
                enableSubmitButtons(form);
                return;
            }
        }

        // Educational note: JS-minted forms block submit until the mint call succeeds.
        mintToken(formId, function (ok, payload) {
            if (!ok || !payload) {
                setMintState(form, 'failed');
                showMintError(form);
                return;
            }

            injectMintedToken(fields, payload);
            writeCachedToken(formId, payload);
            if (needsRemint) {
                form.removeAttribute('data-eforms-remint');
            }
            setMintState(form, 'ready');
            enableSubmitButtons(form);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var forms = document.querySelectorAll('form.eforms-form');
        forEachNode(forms, function (form) {
            setJsOk(form);
            focusErrors(form);
            addSubmitLock(form);
            if (getFormMode(form) === 'js') {
                handleJsMintedForm(form);
            }
        });
    });
})();
