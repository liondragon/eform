(function () {
    'use strict';

    var DEFAULT_MINT_ENDPOINT = '/eforms/mint';
    var MINT_ERROR_MESSAGE = 'This form is temporarily unavailable. Please reload the page.';
    var DEFAULT_PROTOCOL = {
        hiddenFields: {
            mode: 'eforms_mode',
            token: 'eforms_token',
            instance_id: 'instance_id',
            timestamp: 'timestamp',
            js_ok: 'js_ok'
        },
        dataAttributes: {
            mode: 'data-eforms-mode',
            token_ttl_max: 'data-eforms-token-ttl-max'
        },
        mint: {
            formParam: 'f',
            response: {
                token: 'token',
                instance_id: 'instance_id',
                timestamp: 'timestamp',
                expires: 'expires'
            }
        },
        storageTokenPrefix: 'eforms:token:'
    };

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

    function mintEndpoint() {
        var settings = window.eformsSettings;
        if (settings && typeof settings.mintEndpoint === 'string' && settings.mintEndpoint !== '') {
            return settings.mintEndpoint;
        }

        return DEFAULT_MINT_ENDPOINT;
    }

    function uploadEndpoint() {
        var settings = window.eformsSettings;
        if (settings && typeof settings.uploadBatchEndpoint === 'string' && settings.uploadBatchEndpoint !== '') {
            return settings.uploadBatchEndpoint;
        }
        return '';
    }

    function protocol() {
        var settings = window.eformsSettings;
        if (settings && settings.protocol && typeof settings.protocol === 'object') {
            return settings.protocol;
        }

        return DEFAULT_PROTOCOL;
    }

    function hiddenFieldName(key) {
        var names = protocol().hiddenFields;
        if (names && typeof names[key] === 'string' && names[key] !== '') {
            return names[key];
        }

        return DEFAULT_PROTOCOL.hiddenFields[key];
    }

    function dataAttributeName(key) {
        var attrs = protocol().dataAttributes;
        if (attrs && typeof attrs[key] === 'string' && attrs[key] !== '') {
            return attrs[key];
        }

        return DEFAULT_PROTOCOL.dataAttributes[key];
    }

    function mintResponseKey(key) {
        var mint = protocol().mint;
        var response = mint && mint.response ? mint.response : null;
        if (response && typeof response[key] === 'string' && response[key] !== '') {
            return response[key];
        }

        return DEFAULT_PROTOCOL.mint.response[key];
    }

    function mintFormParam() {
        var mint = protocol().mint;
        if (mint && typeof mint.formParam === 'string' && mint.formParam !== '') {
            return mint.formParam;
        }

        return DEFAULT_PROTOCOL.mint.formParam;
    }

    function uploadProtocol() {
        var configured = protocol().upload;
        if (!configured || typeof configured !== 'object') {
            return null;
        }
        var names = ['batchSecretHeader', 'formParam', 'fieldParam', 'fileParam', 'ordinalParam'];
        var batchFields = ['root', 'batch_id', 'batch_secret'];
        var dataAttributes = ['mount', 'picker', 'pickerId', 'field', 'accept', 'maxFiles', 'maxFileBytes', 'maxTotalBytes'];
        var responseFields = ['batchId', 'state', 'acceptUntil', 'deleteAfter', 'items', 'uploadId', 'ordinal', 'displayName', 'bytes'];
        var runtimeValues = ['batchIdChars', 'batchSecretBytes', 'uploadIdBytes', 'uploadIdMaxChars', 'concurrency', 'displayNameMaxChars'];
        var complete = names.every(function (key) {
            return typeof configured[key] === 'string' && configured[key] !== '';
        }) && configured.batchFields && batchFields.every(function (key) {
            return typeof configured.batchFields[key] === 'string' && configured.batchFields[key] !== '';
        }) && configured.dataAttributes && dataAttributes.every(function (key) {
            return typeof configured.dataAttributes[key] === 'string' && configured.dataAttributes[key] !== '';
        }) && configured.response && responseFields.every(function (key) {
            return typeof configured.response[key] === 'string' && configured.response[key] !== '';
        }) && configured.runtime && runtimeValues.every(function (key) {
            return Number.isInteger(configured.runtime[key]) && configured.runtime[key] > 0;
        });
        return complete ? configured : null;
    }

    function uploadValue(group, key) {
        var managed = uploadProtocol();
        return managed && managed[group] ? managed[group][key] : '';
    }

    function uploadName(key) {
        var managed = uploadProtocol();
        return managed ? managed[key] : '';
    }

    function uploadResponseName(key) {
        var managed = uploadProtocol();
        return managed ? managed.response[key] : '';
    }

    function uploadRuntimeValue(key) {
        var managed = uploadProtocol();
        return managed ? managed.runtime[key] : 0;
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
        var input = form.querySelector('input[name="' + hiddenFieldName('mode') + '"]');
        var attr = form.getAttribute(dataAttributeName('mode'));
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
        var input = form.querySelector('input[name="' + hiddenFieldName('js_ok') + '"]');
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
        form.addEventListener('submit', function (event) {
            if (event.defaultPrevented) {
                return;
            }
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
            token: form.querySelector('input[name="' + hiddenFieldName('token') + '"]'),
            instance: form.querySelector('input[name="' + hiddenFieldName('instance_id') + '"]'),
            timestamp: form.querySelector('input[name="' + hiddenFieldName('timestamp') + '"]')
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
        try {
            if (!window.sessionStorage) {
                return false;
            }
            var testKey = 'eforms_storage_test';
            window.sessionStorage.setItem(testKey, '1');
            window.sessionStorage.removeItem(testKey);
            return true;
        } catch (error) {
            return false;
        }
    }

    function storageKey(formId) {
        var prefix = protocol().storageTokenPrefix;
        if (typeof prefix !== 'string' || prefix === '') {
            prefix = DEFAULT_PROTOCOL.storageTokenPrefix;
        }

        return prefix + formId;
    }

    function tokenTtlMax(form) {
        var raw = form.getAttribute(dataAttributeName('token_ttl_max'));
        if (!raw) {
            return 0;
        }
        var parsed = parseInt(raw, 10);
        return isNaN(parsed) ? 0 : parsed;
    }

    function removeCachedToken(formId) {
        try {
            if (!window.sessionStorage) {
                return;
            }
            window.sessionStorage.removeItem(storageKey(formId));
        } catch (error) {
            // Ignore unavailable browser storage; the mint path still proceeds.
        }
    }

    function canReuseCachedToken(form) {
        var uploadState = form ? form.__eformsUploadState : null;
        return !uploadState || !uploadState.runtimes || uploadState.runtimes.length === 0;
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
                removeCachedToken(formId);
                return null;
            }

            var token = typeof payload[mintResponseKey('token')] === 'string' ? payload[mintResponseKey('token')] : '';
            var instanceId = typeof payload[mintResponseKey('instance_id')] === 'string' ? payload[mintResponseKey('instance_id')] : '';
            var timestamp = parseInt(payload[mintResponseKey('timestamp')], 10);
            var expires = parseInt(payload[mintResponseKey('expires')], 10);
            if (!token || !instanceId || isNaN(timestamp) || isNaN(expires)) {
                removeCachedToken(formId);
                return null;
            }

            var now = nowSeconds();
            if (expires <= now) {
                removeCachedToken(formId);
                return null;
            }

            if (ttlMax > 0 && expires - timestamp > ttlMax) {
                removeCachedToken(formId);
                return null;
            }

            return {
                token: token,
                instance_id: instanceId,
                timestamp: String(timestamp),
                expires: expires
            };
        } catch (error) {
            removeCachedToken(formId);
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

        var token = typeof payload[mintResponseKey('token')] === 'string' ? payload[mintResponseKey('token')] : '';
        var instanceId = typeof payload[mintResponseKey('instance_id')] === 'string' ? payload[mintResponseKey('instance_id')] : '';
        var timestamp = parseInt(payload[mintResponseKey('timestamp')], 10);
        var expires = parseInt(payload[mintResponseKey('expires')], 10);
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
        var body = encodeURIComponent(mintFormParam()) + '=' + encodeURIComponent(formId);

        if (window.fetch) {
            fetch(mintEndpoint(), {
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
        xhr.open('POST', mintEndpoint(), true);
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

    function announceTokenReady(form) {
        var event;
        if (typeof window.CustomEvent === 'function') {
            event = new CustomEvent('eforms:token-ready');
        } else {
            event = document.createEvent('Event');
            event.initEvent('eforms:token-ready', false, false);
        }
        form.dispatchEvent(event);
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

        var ttlMax = tokenTtlMax(form);
        var mixedFields = !areFieldsEmpty(fields) && !areFieldsComplete(fields);

        addMintGuard(form);

        if (mixedFields) {
            setMintState(form, 'failed');
            disableSubmitButtons(form);
            showMintError(form);
            return;
        }

        if (areFieldsComplete(fields)) {
            setMintState(form, 'ready');
            announceTokenReady(form);
            return;
        }

        disableSubmitButtons(form);
        setMintState(form, 'pending');

        var reuseCached = canReuseCachedToken(form);
        // Staged batch secrets intentionally live only in the current document.
        // A fresh render must therefore mint a new token instead of reusing a
        // token that may already own a batch under a lost secret.
        if (!reuseCached) {
            removeCachedToken(formId);
        }
        var cached = reuseCached ? readCachedToken(formId, ttlMax) : null;
        if (cached) {
            injectMintedToken(fields, cached);
            setMintState(form, 'ready');
            enableSubmitButtons(form);
            announceTokenReady(form);
            return;
        }

        // Educational note: JS-minted forms block submit until the mint call succeeds.
        mintToken(formId, function (ok, payload) {
            if (!ok || !payload) {
                setMintState(form, 'failed');
                showMintError(form);
                return;
            }

            injectMintedToken(fields, payload);
            if (reuseCached) {
                writeCachedToken(formId, payload);
            }
            setMintState(form, 'ready');
            enableSubmitButtons(form);
            announceTokenReady(form);
        });
    }

    function integerAttribute(node, name) {
        var value = node ? parseInt(node.getAttribute(name), 10) : 0;
        return isNaN(value) || value < 0 ? 0 : value;
    }

    function extensionAttribute(node, name) {
        var value = node ? node.getAttribute(name) : '';
        var entries = typeof value === 'string' ? value.split(',').map(function (entry) {
            return entry.trim().toLowerCase();
        }) : [];
        return entries.filter(function (entry) {
            return /^\.[a-z0-9]+$/.test(entry);
        }).map(function (entry) {
            return entry.slice(1);
        });
    }

    function fileExtension(name) {
        var match = typeof name === 'string' ? name.toLowerCase().match(/\.([a-z0-9]+)$/) : null;
        return match ? match[1] : '';
    }

    function base64Url(bytes) {
        var binary = '';
        for (var i = 0; i < bytes.length; i += 1) {
            binary += String.fromCharCode(bytes[i]);
        }
        return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function randomId(byteCount) {
        if (!window.crypto || typeof window.crypto.getRandomValues !== 'function') {
            return '';
        }
        var bytes = new Uint8Array(byteCount);
        window.crypto.getRandomValues(bytes);
        return base64Url(bytes);
    }

    function validManagedId(value, maxChars, exact) {
        return typeof value === 'string'
            && /^[A-Za-z0-9_-]+$/.test(value)
            && (exact ? value.length === maxChars : value.length <= maxChars);
    }

    function managedUrl(suffix) {
        var endpoint = uploadEndpoint();
        if (!endpoint) {
            return '';
        }
        var url;
        try {
            url = new URL(endpoint, window.location.href);
        } catch (error) {
            return '';
        }
        suffix = typeof suffix === 'string' ? suffix : '';
        if (url.searchParams.has('rest_route')) {
            var route = url.searchParams.get('rest_route').replace(/\/+$/, '');
            url.searchParams.set('rest_route', route + suffix);
        } else {
            url.pathname = url.pathname.replace(/\/+$/, '') + suffix;
        }
        return url.toString();
    }

    function safeFileName(name) {
        var value = typeof name === 'string' ? name.replace(/\\/g, '/').split('/').pop() : 'Photo';
        value = value.replace(/[\u0000-\u001f\u007f]/g, '').replace(/\s+/g, ' ').trim();
        if (!value) {
            value = 'Photo';
        }
        var maxChars = uploadRuntimeValue('displayNameMaxChars');
        var chars = Array.from(value);
        if (chars.length <= maxChars) {
            return value;
        }
        var dot = value.lastIndexOf('.');
        var suffix = dot > 0 ? value.slice(dot) : '';
        var suffixChars = Array.from(suffix);
        if (!suffix || suffixChars.length >= maxChars) {
            return chars.slice(0, maxChars).join('');
        }
        return Array.from(value.slice(0, dot)).slice(0, maxChars - suffixChars.length).join('') + suffix;
    }

    function validServerDisplayName(name) {
        return typeof name === 'string'
            && name.trim() !== ''
            && Array.from(name).length <= uploadRuntimeValue('displayNameMaxChars');
    }

    function stateLabel(item) {
        if (item.state === 'uploading') {
            return 'Uploading';
        }
        if (item.state === 'processing') {
            return 'Processing';
        }
        if (item.state === 'uploaded') {
            return item.previewUnavailable ? '\u2713 Uploaded (preview unavailable)' : '\u2713 Uploaded';
        }
        if (item.state === 'failed') {
            return item.error || 'Upload failed';
        }
        if (item.state === 'removing') {
            return 'Removing';
        }
        return item.state === 'queued' ? 'Queued' : '';
    }

    function fieldAnnouncement(runtime, text) {
        if (runtime.live && runtime.lastAnnouncement !== text) {
            runtime.live.textContent = text;
            runtime.lastAnnouncement = text;
        }
    }

    function renderItem(runtime, item) {
        if (!item.card) {
            var card = document.createElement('article');
            card.className = 'eforms-upload-item';
            card.setAttribute('data-eforms-upload-id', item.id);
            card.setAttribute('tabindex', '-1');

            var media = document.createElement('div');
            media.className = 'eforms-upload-media';
            var image = document.createElement('img');
            image.className = 'eforms-upload-preview';
            image.alt = item.previewUnavailable ? 'Preview unavailable' : '';
            if (item.objectUrl) {
                image.src = item.objectUrl;
            }
            media.appendChild(image);

            var progress = document.createElement('div');
            progress.className = 'eforms-upload-progress';
            progress.setAttribute('role', 'progressbar');
            progress.setAttribute('aria-label', 'Upload progress for ' + item.name);
            progress.setAttribute('aria-valuemin', '0');
            progress.setAttribute('aria-valuemax', '100');
            media.appendChild(progress);
            card.appendChild(media);

            var details = document.createElement('div');
            details.className = 'eforms-upload-details';
            var name = document.createElement('span');
            name.className = 'eforms-upload-name';
            name.textContent = item.name;
            name.title = item.name;
            details.appendChild(name);
            var status = document.createElement('span');
            status.className = 'eforms-upload-status';
            details.appendChild(status);
            card.appendChild(details);

            var actions = document.createElement('div');
            actions.className = 'eforms-upload-actions';
            var retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'eforms-upload-retry';
            retry.textContent = 'Retry';
            retry.addEventListener('click', function () {
                retryItem(runtime, item);
            });
            actions.appendChild(retry);
            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'eforms-upload-remove';
            remove.textContent = 'Remove';
            remove.setAttribute('aria-label', 'Remove ' + item.name);
            remove.addEventListener('click', function () {
                removeItem(runtime, item);
            });
            actions.appendChild(remove);
            card.appendChild(actions);
            runtime.grid.appendChild(card);

            item.card = card;
            item.image = image;
            item.nameNode = name;
            item.progressNode = progress;
            item.statusNode = status;
            item.retryButton = retry;
            item.removeButton = remove;
        }

        item.card.setAttribute('data-eforms-upload-state', item.state);
        if (item.previewUnavailable) {
            item.card.setAttribute('data-eforms-upload-preview', 'unavailable');
        } else {
            item.card.removeAttribute('data-eforms-upload-preview');
        }
        item.image.alt = item.previewUnavailable ? 'Preview unavailable' : '';
        item.nameNode.textContent = item.name;
        item.nameNode.title = item.name;
        item.progressNode.setAttribute('aria-label', 'Upload progress for ' + item.name);
        item.removeButton.setAttribute('aria-label', 'Remove ' + item.name);
        item.statusNode.textContent = stateLabel(item);
        item.progressNode.textContent = item.progress + '%';
        item.progressNode.setAttribute('aria-valuenow', String(item.progress));
        item.progressNode.style.background = 'conic-gradient(var(--eforms-upload-accent) ' + (item.progress * 3.6) + 'deg, var(--eforms-upload-track) 0)';
        item.progressNode.hidden = item.state !== 'uploading' && item.state !== 'processing';
        item.retryButton.hidden = item.state !== 'failed' || runtime.frozen || runtime.expired || runtime.unavailable || !item.file;
        item.removeButton.hidden = item.state === 'removed' || runtime.frozen || runtime.unavailable;
        item.removeButton.disabled = item.state === 'removing';
    }

    function setItemState(runtime, item, state, error) {
        if (item.state === 'removed') {
            return;
        }
        var changed = item.state !== state;
        item.state = state;
        item.error = typeof error === 'string' ? error.slice(0, 160) : '';
        if (state === 'processing') {
            item.progress = 100;
        }
        renderItem(runtime, item);
        if (changed && state !== 'queued') {
            var announcement = state === 'uploading' ? 'Upload started' : stateLabel(item);
            fieldAnnouncement(runtime, item.name + ': ' + announcement);
        }
        updateFormUploadState(runtime.form);
    }

    function captureSubmitLabels(formState) {
        if (formState.submitLabels.length) {
            return;
        }
        forEachNode(formState.form.querySelectorAll('button[type="submit"], input[type="submit"]'), function (button) {
            formState.submitLabels.push({
                node: button,
                value: button.tagName.toLowerCase() === 'button' ? button.textContent : button.value
            });
        });
    }

    function showWaitingLabel(formState, waiting) {
        captureSubmitLabels(formState);
        forEachNode(formState.submitLabels, function (entry) {
            if (entry.node.tagName.toLowerCase() === 'button') {
                entry.node.textContent = waiting ? 'WAITING FOR PHOTOS' : entry.value;
            } else {
                entry.node.value = waiting ? 'WAITING FOR PHOTOS' : entry.value;
            }
        });
    }

    function unresolvedItem(runtime) {
        for (var i = 0; i < runtime.items.length; i += 1) {
            var state = runtime.items[i].state;
            if (state !== 'uploaded' && state !== 'removed') {
                return runtime.items[i];
            }
        }
        return null;
    }

    function restoreBlocked(runtime) {
        return runtime.restoreState === 'restoring' || runtime.restoreState === 'retry';
    }

    function updateFormUploadState(form) {
        var formState = form.__eformsUploadState;
        if (!formState) {
            return;
        }
        var waiting = false;
        forEachNode(formState.runtimes, function (runtime) {
            var selected = runtime.items.filter(function (item) {
                return item.state !== 'removed';
            }).length;
            if (unresolvedItem(runtime) || restoreBlocked(runtime) || runtime.expired || runtime.unavailable) {
                waiting = true;
            }
            runtime.countStatus.textContent = selected > 0 ? selected + ' of ' + runtime.maxFiles + ' photos selected' : '';
            runtime.clearButton.hidden = restoreBlocked(runtime) || runtime.frozen || runtime.unavailable || runtime.items.every(function (item) {
                return item.state === 'removed';
            });
        });
        showWaitingLabel(formState, waiting);
    }

    function credentialName(runtime, key) {
        return uploadValue('batchFields', 'root') + '[' + runtime.fieldKey + '][' + uploadValue('batchFields', key) + ']';
    }

    function credentialInput(runtime, key) {
        return runtime.form.querySelector('input[name="' + credentialName(runtime, key) + '"]');
    }

    function readRerenderCredentials(runtime) {
        var idInput = credentialInput(runtime, 'batch_id');
        var secretInput = credentialInput(runtime, 'batch_secret');
        if (!idInput || !secretInput || !idInput.value || !secretInput.value) {
            return false;
        }
        runtime.batchId = idInput.value;
        runtime.secret = secretInput.value;
        runtime.hiddenInputs = [idInput, secretInput];
        runtime.rerenderRestore = true;
        idInput.setAttribute('data-eforms-upload-owned', '1');
        secretInput.setAttribute('data-eforms-upload-owned', '1');
        return true;
    }

    function writeCredentials(runtime) {
        if (!runtime.batchId || !runtime.secret) {
            return false;
        }
        var pairs = [
            ['batch_id', runtime.batchId],
            ['batch_secret', runtime.secret]
        ];
        runtime.hiddenInputs = [];
        forEachNode(pairs, function (pair) {
            var input = credentialInput(runtime, pair[0]);
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = credentialName(runtime, pair[0]);
                runtime.form.appendChild(input);
            }
            input.value = pair[1];
            input.setAttribute('data-eforms-upload-owned', '1');
            runtime.hiddenInputs.push(input);
        });
        return true;
    }

    function uploadHeaders(runtime) {
        var headers = {};
        headers[uploadName('batchSecretHeader')] = runtime.secret;
        return headers;
    }

    function expireRuntime(runtime) {
        if (runtime.expired || runtime.destroyed) {
            return;
        }
        runtime.expired = true;
        runtime.restoreState = 'terminal';
        runtime.mount.removeAttribute('data-eforms-upload-restoring');
        runtime.mount.removeAttribute('data-eforms-upload-restore-failed');
        runtime.picker.disabled = true;
        runtime.chooseButton.disabled = true;
        runtime.chooseButton.textContent = 'Choose photos';
        runtime.mount.setAttribute('data-eforms-upload-expired', '1');
        runtime.fieldStatus.textContent = 'Form expired\u2014reload and select your photos again.';
        fieldAnnouncement(runtime, 'Form expired\u2014reload and select your photos again.');
        updateFormUploadState(runtime.form);
    }

    function unavailableRuntime(runtime) {
        if (runtime.unavailable || runtime.destroyed) {
            return;
        }
        runtime.unavailable = true;
        runtime.restoreState = 'terminal';
        runtime.mount.removeAttribute('data-eforms-upload-restoring');
        runtime.mount.removeAttribute('data-eforms-upload-restore-failed');
        runtime.picker.disabled = true;
        runtime.chooseButton.disabled = true;
        runtime.chooseButton.textContent = 'Choose photos';
        runtime.mount.setAttribute('data-eforms-upload-unavailable', '1');
        runtime.fieldStatus.textContent = 'Photos are unavailable\u2014reload and select them again.';
        forEachNode(runtime.items, function (item) {
            renderItem(runtime, item);
        });
        fieldAnnouncement(runtime, runtime.fieldStatus.textContent);
        updateFormUploadState(runtime.form);
    }

    function setRuntimeExpiry(runtime, timestamp) {
        if (!timestamp) {
            return;
        }
        var delay = (timestamp - nowSeconds()) * 1000;
        if (delay <= 0) {
            expireRuntime(runtime);
            return;
        }
        window.clearTimeout(runtime.expiryTimer);
        runtime.expiryTimer = window.setTimeout(function () {
            expireRuntime(runtime);
        }, Math.min(delay, 2147483647));
    }

    function setAcceptUntil(runtime, timestamp) {
        runtime.acceptUntil = parseInt(timestamp, 10) || 0;
        setRuntimeExpiry(runtime, runtime.acceptUntil);
    }

    function setRecoveryUntil(runtime, timestamp) {
        runtime.recoveryUntil = parseInt(timestamp, 10) || 0;
        setRuntimeExpiry(runtime, runtime.recoveryUntil);
    }

    function ensureBatch(runtime, callback) {
        if (runtime.batchId) {
            callback(true);
            return;
        }
        if (runtime.createPending) {
            runtime.createCallbacks.push(callback);
            return;
        }
        if (!runtime.secret) {
            runtime.secret = randomId(uploadRuntimeValue('batchSecretBytes'));
        }
        if (!runtime.secret) {
            callback(false);
            return;
        }
        var fields = getTokenFields(runtime.form);
        if (!areFieldsComplete(fields)) {
            callback(false);
            return;
        }
        runtime.createPending = true;
        runtime.createCallbacks = [callback];
        var body = [];
        body.push(encodeURIComponent(uploadName('formParam')) + '=' + encodeURIComponent(getFormId(runtime.form)));
        body.push(encodeURIComponent(hiddenFieldName('token')) + '=' + encodeURIComponent(readFieldValue(fields.token)));
        body.push(encodeURIComponent(hiddenFieldName('instance_id')) + '=' + encodeURIComponent(readFieldValue(fields.instance)));
        body.push(encodeURIComponent(uploadName('fieldParam')) + '=' + encodeURIComponent(runtime.fieldKey));
        var headers = uploadHeaders(runtime);
        headers['Content-Type'] = 'application/x-www-form-urlencoded';
        fetch(managedUrl(''), {
            method: 'POST',
            headers: headers,
            body: body.join('&'),
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (payload) {
                return { status: response.status, payload: payload };
            });
        }).then(function (result) {
            // Teardown clears credentials while create may still be in flight; ignore a late response completely.
            if (runtime.destroyed) {
                finishBatchCreate(runtime, false);
                return;
            }
            var batchIdName = uploadResponseName('batchId');
            var acceptUntilName = uploadResponseName('acceptUntil');
            if (result.status === 410) {
                expireRuntime(runtime);
                finishBatchCreate(runtime, false);
                return;
            }
            var ok = result.status === 200
                && result.payload
                && validManagedId(result.payload[batchIdName], uploadRuntimeValue('batchIdChars'), true);
            if (ok) {
                runtime.batchId = result.payload[batchIdName];
                setAcceptUntil(runtime, result.payload[acceptUntilName]);
            }
            finishBatchCreate(runtime, ok);
        }).catch(function () {
            finishBatchCreate(runtime, false);
        });
    }

    function finishBatchCreate(runtime, ok) {
        runtime.createPending = false;
        var callbacks = runtime.createCallbacks.slice();
        runtime.createCallbacks = [];
        forEachNode(callbacks, function (callback) {
            callback(ok);
        });
    }

    function releaseUploadSlot(runtime, item) {
        if (item.slotActive) {
            item.slotActive = false;
            runtime.active = Math.max(0, runtime.active - 1);
            scheduleUploads(runtime);
        }
    }

    function reconcileItem(runtime, item, removal) {
        if (!runtime.batchId || runtime.destroyed) {
            setItemState(runtime, item, 'failed', removal ? 'Could not remove photo.' : 'Upload failed. Retry.');
            return;
        }
        fetch(managedUrl('/' + encodeURIComponent(runtime.batchId)), {
            method: 'GET',
            headers: uploadHeaders(runtime),
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (payload) {
                return { status: response.status, payload: payload };
            });
        }).then(function (result) {
            if (item.state === 'removed' || (item.state === 'removing' && !removal)) {
                return;
            }
            if (result.status === 410) {
                unavailableRuntime(runtime);
                setItemState(runtime, item, 'failed', 'Upload unavailable. Reload the form.');
                return;
            }
            var itemsName = uploadResponseName('items');
            if (result.status !== 200 || !result.payload || !Array.isArray(result.payload[itemsName])) {
                setItemState(runtime, item, 'failed', removal ? 'Could not remove photo.' : 'Upload failed. Retry.');
                return;
            }
            setAcceptUntil(runtime, result.payload[uploadResponseName('acceptUntil')]);
            if (result.payload[uploadResponseName('state')] === 'finalizing') {
                runtime.frozen = true;
                setItemState(runtime, item, 'failed', 'Photos are being submitted.');
                return;
            }
            var found = result.payload[itemsName].find(function (serverItem) {
                return serverItem && serverItem[uploadResponseName('uploadId')] === item.id;
            });
            if (removal) {
                if (found) {
                    setItemState(runtime, item, 'failed', 'Could not remove photo.');
                } else {
                    finishLocalRemoval(runtime, item);
                }
                return;
            }
            var displayName = found && found[uploadResponseName('displayName')];
            if (found && validServerDisplayName(displayName)) {
                item.name = displayName;
                setItemState(runtime, item, 'uploaded');
                return;
            }
            setItemState(runtime, item, 'failed', 'Upload failed. Retry.');
        }).catch(function () {
            setItemState(runtime, item, 'failed', removal ? 'Could not remove photo.' : 'Upload failed. Retry.');
        });
    }

    function startUpload(runtime, item) {
        if (runtime.frozen || runtime.expired || runtime.unavailable || runtime.destroyed || !item.file || item.state !== 'queued' || item.starting) {
            return;
        }
        item.starting = true;
        runtime.starting += 1;
        ensureBatch(runtime, function (ok) {
            item.starting = false;
            runtime.starting = Math.max(0, runtime.starting - 1);
            if (!ok || runtime.expired || runtime.unavailable || item.state !== 'queued') {
                setItemState(runtime, item, 'failed', 'Upload could not start. Retry.');
                scheduleUploads(runtime);
                return;
            }
            var xhr = new XMLHttpRequest();
            item.xhr = xhr;
            item.slotActive = true;
            runtime.active += 1;
            item.progress = 0;
            setItemState(runtime, item, 'uploading');
            var url = managedUrl('/' + encodeURIComponent(runtime.batchId) + '/items/' + encodeURIComponent(item.id));
            xhr.open('POST', url, true);
            xhr.withCredentials = true;
            xhr.setRequestHeader(uploadName('batchSecretHeader'), runtime.secret);
            xhr.upload.onprogress = function (event) {
                if (item.state !== 'uploading' || !event.lengthComputable) {
                    return;
                }
                item.progress = Math.max(0, Math.min(100, Math.floor((event.loaded / event.total) * 100)));
                if (item.progress >= 100) {
                    setItemState(runtime, item, 'processing');
                } else {
                    renderItem(runtime, item);
                }
            };
            xhr.onload = function () {
                releaseUploadSlot(runtime, item);
                if (item.state === 'removed' || item.state === 'removing') {
                    return;
                }
                if (xhr.status === 200) {
                    var payload = null;
                    try {
                        payload = JSON.parse(xhr.responseText);
                    } catch (error) {
                        payload = null;
                    }
                    var displayName = payload && payload[uploadResponseName('displayName')];
                    if (!validServerDisplayName(displayName)) {
                        setItemState(runtime, item, 'failed', 'Upload rejected. Retry or remove.');
                        return;
                    }
                    item.name = displayName;
                    item.progress = 100;
                    setItemState(runtime, item, 'uploaded');
                    return;
                }
                if (xhr.status === 410) {
                    unavailableRuntime(runtime);
                    setItemState(runtime, item, 'failed', 'Upload unavailable. Reload the form.');
                    return;
                }
                if (xhr.status === 0 || xhr.status >= 500) {
                    reconcileItem(runtime, item);
                    return;
                }
                if (xhr.status === 409) {
                    reconcileItem(runtime, item);
                    return;
                }
                setItemState(runtime, item, 'failed', 'Upload rejected. Retry or remove.');
            };
            xhr.onerror = function () {
                releaseUploadSlot(runtime, item);
                if (item.state !== 'removed' && item.state !== 'removing') {
                    reconcileItem(runtime, item);
                }
            };
            xhr.onabort = function () {
                releaseUploadSlot(runtime, item);
            };
            var data = new FormData();
            data.append(uploadName('fileParam'), item.file, item.name);
            data.append(uploadName('ordinalParam'), String(item.ordinal));
            xhr.send(data);
        });
    }

    function scheduleUploads(runtime) {
        if (runtime.frozen || runtime.expired || runtime.unavailable || runtime.destroyed) {
            return;
        }
        for (var i = 0; i < runtime.items.length && runtime.active + runtime.starting < uploadRuntimeValue('concurrency'); i += 1) {
            if (runtime.items[i].state === 'queued') {
                startUpload(runtime, runtime.items[i]);
            }
        }
    }

    function retryItem(runtime, item) {
        if (runtime.frozen || runtime.expired || runtime.unavailable || runtime.destroyed || item.state !== 'failed' || !item.file) {
            return;
        }
        item.progress = 0;
        setItemState(runtime, item, 'queued');
        scheduleUploads(runtime);
    }

    function finishLocalRemoval(runtime, item) {
        item.state = 'removed';
        if (item.objectUrl) {
            URL.revokeObjectURL(item.objectUrl);
            item.objectUrl = '';
        }
        if (item.card && item.card.parentNode) {
            item.card.parentNode.removeChild(item.card);
        }
        fieldAnnouncement(runtime, item.name + ': removed');
        updateFormUploadState(runtime.form);
    }

    function removeItem(runtime, item) {
        if (runtime.frozen || runtime.unavailable || runtime.destroyed || item.state === 'removed' || item.state === 'removing') {
            return;
        }
        if (item.xhr && (item.state === 'uploading' || item.state === 'processing')) {
            item.xhr.abort();
        }
        if (!runtime.batchId) {
            finishLocalRemoval(runtime, item);
            return;
        }
        setItemState(runtime, item, 'removing');
        fetch(managedUrl('/' + encodeURIComponent(runtime.batchId) + '/items/' + encodeURIComponent(item.id)), {
            method: 'DELETE',
            headers: uploadHeaders(runtime),
            credentials: 'same-origin'
        }).then(function (response) {
            if (response.status === 200) {
                finishLocalRemoval(runtime, item);
                return;
            }
            if (response.status === 410) {
                unavailableRuntime(runtime);
                setItemState(runtime, item, 'failed', 'Upload unavailable. Reload the form.');
                return;
            }
            if (response.status === 409) {
                reconcileItem(runtime, item, true);
                return;
            }
            setItemState(runtime, item, 'failed', 'Could not remove photo.');
        }).catch(function () {
            reconcileItem(runtime, item, true);
        });
    }

    function addFiles(runtime, files) {
        if (restoreBlocked(runtime) || runtime.frozen || runtime.expired || runtime.unavailable || runtime.destroyed) {
            return;
        }
        var activeItems = runtime.items.filter(function (item) { return item.state !== 'removed'; });
        var totalBytes = activeItems.reduce(function (total, item) { return total + item.bytes; }, 0);
        for (var i = 0; i < files.length; i += 1) {
            var file = files[i];
            if (activeItems.length >= runtime.maxFiles || file.size > runtime.maxFileBytes || totalBytes + file.size > runtime.maxTotalBytes || runtime.acceptedExtensions.indexOf(fileExtension(file.name)) === -1) {
                runtime.fieldStatus.textContent = 'One or more photos exceed the allowed type, count, or size.';
                fieldAnnouncement(runtime, runtime.fieldStatus.textContent);
                continue;
            }
            var id = randomId(uploadRuntimeValue('uploadIdBytes'));
            if (!id) {
                runtime.fieldStatus.textContent = 'Photo upload is unavailable in this browser.';
                continue;
            }
            var item = {
                id: id,
                ordinal: runtime.nextOrdinal,
                file: file,
                name: safeFileName(file.name),
                bytes: file.size,
                objectUrl: URL.createObjectURL(file),
                previewUnavailable: false,
                state: 'queued',
                progress: 0,
                error: '',
                xhr: null,
                slotActive: false,
                starting: false
            };
            runtime.nextOrdinal += 1;
            runtime.items.push(item);
            activeItems.push(item);
            totalBytes += file.size;
            renderItem(runtime, item);
        }
        runtime.picker.value = '';
        updateFormUploadState(runtime.form);
        scheduleUploads(runtime);
    }

    function retryBatchRestore(runtime) {
        if (runtime.destroyed || runtime.expired || runtime.unavailable) {
            return;
        }
        runtime.restoreState = 'retry';
        runtime.mount.removeAttribute('data-eforms-upload-restoring');
        runtime.mount.setAttribute('data-eforms-upload-restore-failed', '1');
        runtime.picker.disabled = true;
        runtime.chooseButton.disabled = false;
        runtime.chooseButton.textContent = 'Retry restore';
        runtime.fieldStatus.textContent = 'Uploaded photos could not be restored. Retry restore.';
        fieldAnnouncement(runtime, runtime.fieldStatus.textContent);
        updateFormUploadState(runtime.form);
    }

    function restoredItems(payload) {
        var source = payload ? payload[uploadResponseName('items')] : null;
        if (!Array.isArray(source)) {
            return null;
        }
        var seen = Object.create(null);
        var seenOrdinals = Object.create(null);
        var items = [];
        for (var i = 0; i < source.length; i += 1) {
            var serverItem = source[i];
            var id = serverItem && serverItem[uploadResponseName('uploadId')];
            var ordinal = serverItem && serverItem[uploadResponseName('ordinal')];
            var displayName = serverItem && serverItem[uploadResponseName('displayName')];
            var bytes = serverItem && serverItem[uploadResponseName('bytes')];
            if (!validManagedId(id, uploadRuntimeValue('uploadIdMaxChars'), false) || seen[id] || !Number.isInteger(ordinal) || ordinal < 0 || seenOrdinals[ordinal] || !validServerDisplayName(displayName) || !Number.isInteger(bytes) || bytes < 0) {
                return null;
            }
            seen[id] = true;
            seenOrdinals[ordinal] = true;
            items.push({
                id: id,
                ordinal: ordinal,
                file: null,
                name: displayName,
                bytes: bytes,
                objectUrl: '',
                previewUnavailable: false,
                state: 'uploaded',
                progress: 100,
                error: '',
                xhr: null,
                slotActive: false,
                starting: false
            });
        }
        return items;
    }

    function restoreBatch(runtime) {
        if (runtime.restoreState === 'restoring' || runtime.restoreState === 'ready' || runtime.destroyed || runtime.expired || runtime.unavailable) {
            return;
        }
        runtime.restoreState = 'restoring';
        runtime.mount.removeAttribute('data-eforms-upload-restore-failed');
        runtime.mount.setAttribute('data-eforms-upload-restoring', '1');
        runtime.picker.disabled = true;
        runtime.chooseButton.disabled = true;
        runtime.chooseButton.textContent = 'Choose photos';
        runtime.fieldStatus.textContent = 'Restoring uploaded photos\u2026';
        updateFormUploadState(runtime.form);
        fetch(managedUrl('/' + encodeURIComponent(runtime.batchId)), {
            method: 'GET',
            headers: uploadHeaders(runtime),
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (payload) {
                return { status: response.status, payload: payload };
            });
        }).then(function (result) {
            if (runtime.destroyed) {
                return;
            }
            var payload = result.payload;
            var state = payload && payload[uploadResponseName('state')];
            var acceptUntil = payload && payload[uploadResponseName('acceptUntil')];
            var deleteAfter = payload && payload[uploadResponseName('deleteAfter')];
            var recovering = runtime.rerenderRestore && state === 'finalizing';
            var items = result.status === 200 && (state === 'open' || recovering) ? restoredItems(payload) : null;
            if (result.status === 410 || (result.status >= 400 && result.status < 500 && result.status !== 408 && result.status !== 429) || (result.status === 200 && state === 'finalizing' && !recovering)) {
                unavailableRuntime(runtime);
                return;
            }
            var restoreUntil = recovering ? deleteAfter : acceptUntil;
            if (result.status !== 200 || (state !== 'open' && !recovering) || !Number.isInteger(restoreUntil) || restoreUntil <= 0 || items === null) {
                retryBatchRestore(runtime);
                return;
            }
            if (restoreUntil <= nowSeconds()) {
                expireRuntime(runtime);
                return;
            }

            runtime.items = items;
            runtime.nextOrdinal = 0;
            runtime.frozen = recovering;
            if (recovering) {
                setRecoveryUntil(runtime, deleteAfter);
            } else {
                setAcceptUntil(runtime, acceptUntil);
            }
            forEachNode(runtime.items, function (item) {
                runtime.nextOrdinal = Math.max(runtime.nextOrdinal, item.ordinal + 1);
                renderItem(runtime, item);
                fetch(managedUrl('/' + encodeURIComponent(runtime.batchId) + '/items/' + encodeURIComponent(item.id) + '/preview'), {
                    method: 'GET',
                    headers: uploadHeaders(runtime),
                    credentials: 'same-origin'
                }).then(function (response) {
                    if (!response.ok) {
                        throw new Error('preview');
                    }
                    return response.blob();
                }).then(function (blob) {
                    if (item.state === 'removed' || runtime.destroyed) {
                        return;
                    }
                    item.objectUrl = URL.createObjectURL(blob);
                    item.image.src = item.objectUrl;
                }).catch(function () {
                    // The status response remains authoritative for upload state;
                    // preview bytes are presentation-only and may fail transiently.
                    if (item.state === 'removed' || runtime.destroyed) {
                        return;
                    }
                    item.previewUnavailable = true;
                    renderItem(runtime, item);
                    fieldAnnouncement(runtime, item.name + ': Uploaded; preview unavailable');
                });
            });
            runtime.restoreState = 'ready';
            runtime.mount.removeAttribute('data-eforms-upload-restoring');
            runtime.picker.disabled = recovering;
            runtime.chooseButton.disabled = recovering;
            runtime.fieldStatus.textContent = recovering ? 'Uploaded photos restored for corrected submission.' : '';
            fieldAnnouncement(runtime, recovering ? runtime.fieldStatus.textContent : 'Uploaded photos restored');
            updateFormUploadState(runtime.form);
        }).catch(function () {
            retryBatchRestore(runtime);
        });
    }

    function buildUploadMount(runtime) {
        var controls = document.createElement('div');
        controls.className = 'eforms-upload-controls';
        runtime.chooseButton = document.createElement('button');
        runtime.chooseButton.type = 'button';
        runtime.chooseButton.className = 'eforms-upload-choose';
        runtime.chooseButton.textContent = 'Choose photos';
        runtime.chooseButton.disabled = true;
        runtime.chooseButton.addEventListener('click', function () {
            if (runtime.restoreState === 'retry') {
                restoreBatch(runtime);
                return;
            }
            if (!runtime.picker.disabled) {
                runtime.picker.click();
            }
        });
        controls.appendChild(runtime.chooseButton);
        var dropHint = document.createElement('span');
        dropHint.className = 'eforms-upload-drop-hint';
        dropHint.textContent = 'or drag and drop';
        controls.appendChild(dropHint);
        runtime.clearButton = document.createElement('button');
        runtime.clearButton.type = 'button';
        runtime.clearButton.className = 'eforms-upload-clear';
        runtime.clearButton.textContent = 'Clear all';
        runtime.clearButton.hidden = true;
        runtime.clearButton.addEventListener('click', function () {
            forEachNode(runtime.items.slice(), function (item) {
                removeItem(runtime, item);
            });
        });
        controls.appendChild(runtime.clearButton);
        runtime.mount.appendChild(controls);

        runtime.countStatus = document.createElement('p');
        runtime.countStatus.className = 'eforms-upload-count';
        runtime.mount.appendChild(runtime.countStatus);

        runtime.fieldStatus = document.createElement('p');
        runtime.fieldStatus.className = 'eforms-upload-field-status';
        runtime.fieldStatus.setAttribute('role', 'status');
        runtime.mount.appendChild(runtime.fieldStatus);
        runtime.live = document.createElement('div');
        runtime.live.className = 'screen-reader-text eforms-upload-live';
        runtime.live.setAttribute('aria-live', 'polite');
        runtime.live.setAttribute('aria-atomic', 'true');
        runtime.mount.appendChild(runtime.live);
        runtime.grid = document.createElement('div');
        runtime.grid.className = 'eforms-upload-grid';
        runtime.mount.appendChild(runtime.grid);

        runtime.picker.addEventListener('change', function () {
            addFiles(runtime, runtime.picker.files || []);
        });
        runtime.mount.addEventListener('dragover', function (event) {
            if (!runtime.picker.disabled) {
                event.preventDefault();
                runtime.mount.setAttribute('data-eforms-upload-dragover', '1');
            }
        });
        runtime.mount.addEventListener('dragleave', function () {
            runtime.mount.removeAttribute('data-eforms-upload-dragover');
        });
        runtime.mount.addEventListener('drop', function (event) {
            runtime.mount.removeAttribute('data-eforms-upload-dragover');
            if (runtime.picker.disabled) {
                return;
            }
            event.preventDefault();
            addFiles(runtime, event.dataTransfer && event.dataTransfer.files ? event.dataTransfer.files : []);
        });
    }

    function activateRuntime(runtime) {
        if (runtime.destroyed || runtime.activated || !areFieldsComplete(getTokenFields(runtime.form))) {
            return;
        }
        runtime.activated = true;
        runtime.picker.removeAttribute('required');
        runtime.picker.classList.add('eforms-upload-picker-enhanced');
        if (readRerenderCredentials(runtime)) {
            restoreBatch(runtime);
            return;
        }
        runtime.picker.disabled = false;
        runtime.chooseButton.disabled = false;
    }

    function destroyRuntime(runtime) {
        if (runtime.destroyed) {
            return;
        }
        runtime.destroyed = true;
        runtime.restoreState = 'terminal';
        runtime.secret = '';
        window.clearTimeout(runtime.expiryTimer);
        runtime.picker.disabled = true;
        runtime.mount.removeAttribute('data-eforms-upload-restoring');
        runtime.mount.removeAttribute('data-eforms-upload-restore-failed');
        forEachNode(runtime.items, function (item) {
            if (item.xhr) {
                item.xhr.abort();
            }
            if (item.objectUrl) {
                URL.revokeObjectURL(item.objectUrl);
                item.objectUrl = '';
            }
        });
        forEachNode(runtime.hiddenInputs, function (input) {
            if (input && input.parentNode) {
                input.parentNode.removeChild(input);
            }
        });
        runtime.hiddenInputs = [];
    }

    function freezeForSubmit(runtime) {
        runtime.frozen = true;
        runtime.picker.value = '';
        runtime.picker.disabled = true;
        runtime.chooseButton.disabled = true;
        runtime.mount.setAttribute('data-eforms-upload-frozen', '1');
        forEachNode(runtime.items, function (item) {
            renderItem(runtime, item);
        });
    }

    function initializeStagedUploads(form) {
        if (!uploadProtocol() || !uploadEndpoint()) {
            return;
        }
        var mounts = form.querySelectorAll('[' + uploadValue('dataAttributes', 'mount') + '="1"]');
        if (!mounts.length) {
            return;
        }
        var formState = { form: form, runtimes: [], submitLabels: [] };
        form.__eformsUploadState = formState;
        forEachNode(mounts, function (mount) {
            var fieldKey = mount.getAttribute(uploadValue('dataAttributes', 'field')) || '';
            var pickerId = mount.getAttribute(uploadValue('dataAttributes', 'pickerId')) || '';
            var picker = null;
            forEachNode(form.querySelectorAll('input[' + uploadValue('dataAttributes', 'picker') + '="1"]'), function (candidate) {
                if (candidate.id === pickerId) {
                    picker = candidate;
                }
            });
            if (!fieldKey || !pickerId || !picker) {
                return;
            }
            var acceptedExtensions = extensionAttribute(mount, uploadValue('dataAttributes', 'accept'));
            var runtime = {
                form: form,
                mount: mount,
                picker: picker,
                fieldKey: fieldKey,
                maxFiles: integerAttribute(mount, uploadValue('dataAttributes', 'maxFiles')),
                maxFileBytes: integerAttribute(mount, uploadValue('dataAttributes', 'maxFileBytes')),
                maxTotalBytes: integerAttribute(mount, uploadValue('dataAttributes', 'maxTotalBytes')),
                acceptedExtensions: acceptedExtensions,
                items: [],
                nextOrdinal: 0,
                active: 0,
                starting: 0,
                batchId: '',
                secret: '',
                hiddenInputs: [],
                createPending: false,
                createCallbacks: [],
                activated: false,
                frozen: false,
                rerenderRestore: false,
                expired: false,
                unavailable: false,
                destroyed: false,
                restoreState: 'none',
                recoveryUntil: 0,
                expiryTimer: 0,
                lastAnnouncement: ''
            };
            mount.__eformsUploadRuntime = runtime;
            buildUploadMount(runtime);
            formState.runtimes.push(runtime);
            activateRuntime(runtime);
            form.addEventListener('eforms:token-ready', function () {
                activateRuntime(runtime);
            });
        });

        form.addEventListener('submit', function (event) {
            var blocked = null;
            forEachNode(formState.runtimes, function (runtime) {
                if (!blocked) {
                    blocked = unresolvedItem(runtime);
                }
                if (!blocked && (restoreBlocked(runtime) || runtime.expired || runtime.unavailable)) {
                    blocked = runtime;
                }
            });
            if (blocked) {
                event.preventDefault();
                updateFormUploadState(form);
                var focusTarget = blocked.card || blocked.mount || formState.runtimes[0].mount;
                if (focusTarget && typeof focusTarget.focus === 'function') {
                    focusTarget.focus();
                }
                return;
            }
            var ready = true;
            forEachNode(formState.runtimes, function (runtime) {
                if (runtime.batchId && !writeCredentials(runtime)) {
                    ready = false;
                }
            });
            if (!ready) {
                event.preventDefault();
                return;
            }
            forEachNode(formState.runtimes, freezeForSubmit);
        });

        form.addEventListener('eforms:destroy', function () {
            forEachNode(formState.runtimes, destroyRuntime);
        });
        updateFormUploadState(form);
    }

    function destroyUploadFormsIn(node) {
        if (!node || node.nodeType !== 1) {
            return;
        }
        var forms = [];
        if (node.matches && node.matches('form.eforms-form')) {
            forms.push(node);
        }
        if (node.querySelectorAll) {
            forEachNode(node.querySelectorAll('form.eforms-form'), function (form) {
                forms.push(form);
            });
        }
        forEachNode(forms, function (form) {
            var connected = typeof form.isConnected === 'boolean' ? form.isConnected : document.documentElement.contains(form);
            if (form.__eformsUploadState && !connected) {
                form.dispatchEvent(new Event('eforms:destroy'));
            }
        });
    }

    function observeUploadTeardown() {
        if (typeof MutationObserver !== 'function' || !document.documentElement) {
            return;
        }
        var observer = new MutationObserver(function (mutations) {
            forEachNode(mutations, function (mutation) {
                forEachNode(mutation.removedNodes, destroyUploadFormsIn);
            });
        });
        observer.observe(document.documentElement, { childList: true, subtree: true });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var forms = document.querySelectorAll('form.eforms-form');
        forEachNode(forms, function (form) {
            setJsOk(form);
            focusErrors(form);
            initializeStagedUploads(form);
            addSubmitLock(form);
            if (getFormMode(form) === 'js') {
                handleJsMintedForm(form);
            }
        });
        observeUploadTeardown();
    });
})();
