(function () {
    'use strict';

    var DEFAULT_MINT_ENDPOINT = '/eforms/mint';
    var MINT_ERROR_MESSAGE = 'This form is temporarily unavailable. Please reload the page.';
    var ENHANCED_CORRECTABLE_MESSAGE = 'Please fix the highlighted fields.';
    var ENHANCED_RETRY_MESSAGE = 'Your request couldn\'t be sent. Please try again.';
    var ENHANCED_BLOCKED_MESSAGE = 'This request can\'t be finished from this page. Please reload.';
    var preparationQueue = [];
    var activePreparation = null;
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
        if (Object.prototype.hasOwnProperty.call(DEFAULT_PROTOCOL.dataAttributes, key)) {
            return DEFAULT_PROTOCOL.dataAttributes[key];
        }

        return '';
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
        var names = ['batchSecretHeader', 'formParam', 'fieldParam', 'fileParam', 'ordinalParam', 'displayNameParam', 'bytesParam', 'mimeParam', 'receiptParam', 'workerGrantHeader', 'localTransport', 'workerTransport'];
        var batchFields = ['root', 'batch_id', 'batch_secret'];
        var dataAttributes = ['mount', 'picker', 'pickerId', 'field', 'accept', 'maxFiles', 'maxFileBytes', 'maxTotalBytes'];
        var responseFields = ['batchId', 'state', 'acceptUntil', 'deleteAfter', 'items', 'intents', 'limits', 'maxFileBytes', 'maxFiles', 'maxTotalBytes', 'uploadId', 'ordinal', 'displayName', 'bytes', 'authorized', 'committed', 'transport', 'transportKind', 'transportUrl', 'transportGrant', 'transportMime'];
        var runtimeValues = ['batchIdChars', 'batchSecretBytes', 'uploadIdBytes', 'uploadIdMaxChars', 'transferConcurrency', 'workerPipelineConcurrency', 'localPipelineConcurrency', 'displayNameMaxChars'];
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
        }) && configured.mimeByExtension && Object.keys(configured.mimeByExtension).length > 0 && Object.keys(configured.mimeByExtension).every(function (extension) {
            return /^[a-z0-9]+$/.test(extension) && /^image\/[a-z0-9.+-]+$/.test(configured.mimeByExtension[extension]);
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

    function clientPreparationSettings() {
        var settings = window.eformsSettings && window.eformsSettings.clientPreparation;
        var recipe = settings && settings.recipe;
        var preparationValues = ['version', 'slots', 'jpegTriggerBytes', 'jpegTriggerEdge', 'inputMaxBytes', 'inputMaxPixels', 'inputMaxEdge', 'outputMaxEdge', 'jpegQuality', 'minimumSavingsPercent', 'timeoutMs', 'headerScanBytes', 'exifMaxEntries'];
        if (!settings || typeof settings !== 'object'
            || typeof settings.workerUrl !== 'string' || settings.workerUrl === '') {
            return null;
        }
        if (!recipe || !preparationValues.every(function (key) {
            return Number.isInteger(recipe[key]) && recipe[key] > 0;
        }) || recipe.slots !== 1 || recipe.jpegQuality >= 100 || recipe.minimumSavingsPercent >= 100) {
            return null;
        }
        return settings;
    }

    function chosenUsage(runtime, excluded) {
        return runtime.items.reduce(function (usage, item) {
            if (item !== excluded && item.artifactChosen) {
                usage.count += 1;
                usage.bytes += item.bytes;
            }
            return usage;
        }, { count: 0, bytes: 0 });
    }

    function artifactWithinLimits(runtime, count, total, bytes) {
        return Number.isInteger(bytes) && bytes > 0
            && count < runtime.maxFiles
            && bytes <= runtime.maxFileBytes
            && total <= runtime.maxTotalBytes - bytes;
    }

    function artifactFits(runtime, item, bytes) {
        var usage = chosenUsage(runtime, item);
        return artifactWithinLimits(runtime, usage.count, usage.bytes, bytes);
    }

    function enforceChosenArtifactLimits(runtime) {
        var accepted = 0;
        var total = 0;
        forEachNode(runtime.items, function (item) {
            if (!item.artifactChosen) {
                return;
            }
            if (!artifactWithinLimits(runtime, accepted, total, item.bytes)) {
                item.artifactChosen = false;
                setItemState(runtime, item, 'failed', 'Photo exceeds the current upload limits.');
                return;
            }
            accepted += 1;
            total += item.bytes;
        });
    }

    function bindPreviewError(runtime, item, image, request) {
        image.addEventListener('error', function () {
            markPreviewUnavailable(runtime, item, request);
        });
    }

    function refreshArtifactPreview(runtime, item, artifact) {
        if (!item.image || !artifact) {
            return;
        }
        if (item.objectUrl) {
            URL.revokeObjectURL(item.objectUrl);
        }
        item.objectUrl = URL.createObjectURL(artifact);
        item.previewUnavailable = false;
        item.previewRequest += 1;
        var previous = item.image;
        var image = document.createElement('img');
        image.className = 'eforms-upload-preview';
        image.alt = '';
        bindPreviewError(runtime, item, image, item.previewRequest);
        previous.parentNode.replaceChild(image, previous);
        item.image = image;
        image.src = item.objectUrl;
    }

    function chooseArtifact(runtime, item, artifact, prepared) {
        var bytes = artifact && artifact.size;
        if (!artifactFits(runtime, item, bytes)) {
            var canFitAfterCapacityRelease = Number.isInteger(bytes) && bytes > 0
                && runtime.maxFiles > 0
                && bytes <= runtime.maxFileBytes
                && bytes <= runtime.maxTotalBytes;
            item.file = canFitAfterCapacityRelease ? artifact : null;
            item.bytes = Number.isInteger(bytes) ? bytes : item.bytes;
            if (!canFitAfterCapacityRelease) {
                item.sourceFile = null;
            }
            item.artifactChosen = false;
            setItemState(runtime, item, 'failed', 'Photo exceeds the allowed size.');
            return false;
        }
        item.file = artifact;
        item.bytes = artifact.size;
        item.artifactChosen = true;
        if (prepared) {
            refreshArtifactPreview(runtime, item, artifact);
        }
        item.sourceFile = null;
        setItemState(runtime, item, 'queued');
        scheduleUploads(runtime);
        return true;
    }

    function chooseSourceArtifact(runtime, item) {
        var source = item.sourceFile || item.file;
        return source ? chooseArtifact(runtime, item, source, false) : false;
    }

    function finishPreparation(job) {
        if (activePreparation !== job) {
            return false;
        }
        window.clearTimeout(job.timer);
        if (job.worker) {
            job.worker.terminate();
        }
        activePreparation = null;
        return true;
    }

    function fallbackPreparation(job) {
        var runtime = job.runtime;
        var item = job.item;
        if (!finishPreparation(job)) {
            return;
        }
        if (runtimeMayPrepare(runtime) && item.state !== 'removed' && item.state !== 'removing') {
            chooseSourceArtifact(runtime, item);
        }
        schedulePreparation();
    }

    function runPreparation(job) {
        var runtime = job.runtime;
        var item = job.item;
        var settings = clientPreparationSettings();
        if (!settings || typeof window.Worker !== 'function') {
            fallbackPreparation(job);
            return;
        }
        try {
            job.worker = new window.Worker(settings.workerUrl);
        } catch (error) {
            fallbackPreparation(job);
            return;
        }
        job.timer = window.setTimeout(function () {
            fallbackPreparation(job);
        }, settings.recipe.timeoutMs);
        job.worker.onerror = function () {
            fallbackPreparation(job);
        };
        job.worker.onmessage = function (event) {
            if (activePreparation !== job || !event.data || event.data.requestId !== job.requestId) {
                return;
            }
            if (event.data.type === 'ready') {
                var source = item.sourceFile;
                var preparationSource = source && source.type === 'image/jpeg'
                    ? source
                    : source.slice(0, source.size, 'image/jpeg');
                job.worker.postMessage({
                    type: 'prepare',
                    requestId: job.requestId,
                    file: preparationSource,
                    recipe: settings.recipe,
                    maxOutputBytes: runtime.maxFileBytes
                });
                return;
            }
            if (event.data.type === 'preparing') {
                setItemState(runtime, item, 'preparing');
                return;
            }
            if (event.data.type === 'prepared') {
                var blob = event.data.blob;
                if (!finishPreparation(job)) {
                    return;
                }
                if (!runtimeMayPrepare(runtime) || item.state === 'removed' || item.state === 'removing') {
                    schedulePreparation();
                    return;
                }
                if (!(blob instanceof Blob) || blob.type !== 'image/jpeg' || blob.size < 1
                    || blob.size > Math.floor(item.sourceFile.size * (100 - settings.recipe.minimumSavingsPercent) / 100)) {
                    chooseSourceArtifact(runtime, item);
                    schedulePreparation();
                    return;
                }
                chooseArtifact(runtime, item, blob, true);
                schedulePreparation();
                return;
            }
            if (event.data.type === 'use_source') {
                fallbackPreparation(job);
                return;
            }
            if (event.data.type === 'reject_source') {
                if (!finishPreparation(job)) {
                    return;
                }
                if (!runtimeMayPrepare(runtime) || item.state === 'removed' || item.state === 'removing') {
                    schedulePreparation();
                    return;
                }
                item.file = null;
                item.sourceFile = null;
                item.artifactChosen = false;
                setItemState(runtime, item, 'failed', 'Photo exceeds the allowed image dimensions.');
                schedulePreparation();
            }
        };
        job.worker.postMessage({ type: 'probe', requestId: job.requestId, recipe: settings.recipe });
    }

    function runtimeMayPrepare(runtime) {
        return !runtime.clearing && !runtime.destroyed && !runtime.expired && !runtime.unavailable && !runtime.frozen;
    }

    function schedulePreparation() {
        if (activePreparation) {
            return;
        }
        while (preparationQueue.length) {
            var job = preparationQueue.shift();
            if (runtimeMayPrepare(job.runtime) && job.item.state !== 'removed' && job.item.state !== 'removing') {
                activePreparation = job;
                runPreparation(job);
                return;
            }
        }
    }

    function queuePreparation(runtime, item) {
        if (!runtimeMayPrepare(runtime)) {
            return;
        }
        item.preparationAttempt += 1;
        preparationQueue.push({
            runtime: runtime,
            item: item,
            requestId: item.id + ':' + item.preparationAttempt,
            worker: null,
            timer: 0
        });
        schedulePreparation();
    }

    function abortPreparation(item) {
        preparationQueue = preparationQueue.filter(function (job) {
            return job.item !== item;
        });
        if (activePreparation && activePreparation.item === item) {
            finishPreparation(activePreparation);
            schedulePreparation();
        }
    }

    function abortRuntimePreparation(runtime) {
        preparationQueue = preparationQueue.filter(function (job) {
            return job.runtime !== runtime;
        });
        if (activePreparation && activePreparation.runtime === runtime) {
            finishPreparation(activePreparation);
            schedulePreparation();
        }
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

    function phoneFormatAttribute() {
        return dataAttributeName('phone_format');
    }

    function phoneControls(form) {
        var attr = phoneFormatAttribute();
        return attr === '' ? [] : form.querySelectorAll('[' + attr + '="tel_us"]');
    }

    function zipControls(form) {
        var attr = dataAttributeName('zip_format');
        return attr === '' ? [] : form.querySelectorAll('[' + attr + '="zip_us"]');
    }

    function integerControls(form) {
        var attr = dataAttributeName('integer_format');
        return attr === '' ? [] : form.querySelectorAll('[' + attr + '="1"]');
    }

    function urlControls(form) {
        var attr = dataAttributeName('url_normalize');
        return attr === '' ? [] : form.querySelectorAll('[' + attr + '="1"]');
    }

    function nativeScalarValidityControls(form) {
        var attr = dataAttributeName('field_control');
        if (attr === '') {
            return [];
        }
        var controls = [];
        forEachNode(form.querySelectorAll('input[' + attr + '="1"]'), function (control) {
            var type = typeof control.type === 'string' ? control.type.toLowerCase() : '';
            if (type === 'email' || type === 'url') {
                controls.push(control);
            }
        });
        return controls;
    }

    function isNativeScalarValidityControl(control) {
        if (!control || !control.getAttribute) {
            return false;
        }
        var attr = dataAttributeName('field_control');
        var type = typeof control.type === 'string' ? control.type.toLowerCase() : '';
        return attr !== '' && control.getAttribute(attr) === '1' && (type === 'email' || type === 'url');
    }

    function unitControls(form) {
        var attr = dataAttributeName('input_unit');
        return attr === '' ? [] : form.querySelectorAll('[' + attr + ']');
    }

    function phoneDigits(value) {
        return typeof value === 'string' ? value.replace(/\D/g, '') : '';
    }

    function phoneGrammarAllowed(value) {
        if (typeof value !== 'string') {
            return false;
        }
        if (/[^0-9\s().+-]/.test(value)) {
            return false;
        }
        var plusPos = value.indexOf('+');
        return plusPos === -1 || (plusPos === 0 && value.indexOf('+', 1) === -1);
    }

    function displayPhoneDigits(digits) {
        if (digits.length === 11 && digits.charAt(0) === '1') {
            digits = digits.slice(1);
        }
        if (digits.length === 0) {
            return '';
        }
        if (digits.length > 10) {
            return digits;
        }
        if (digits.length < 4) {
            return '(' + digits;
        }
        if (digits.length < 7) {
            return '(' + digits.slice(0, 3) + ') ' + digits.slice(3);
        }
        return '(' + digits.slice(0, 3) + ') ' + digits.slice(3, 6) + '-' + digits.slice(6);
    }

    function normalizedPhoneDigits(value) {
        if (!phoneGrammarAllowed(value)) {
            return null;
        }
        var original = typeof value === 'string' ? value.trim() : '';
        var digits = phoneDigits(value);
        if (digits.length === 0) {
            return original === '' ? '' : null;
        }
        if (digits.length === 11 && digits.charAt(0) === '1') {
            digits = digits.slice(1);
        }
        return digits.length === 10 ? digits : null;
    }

    function setPhoneValidity(control, clearServerError) {
        var normalized = normalizedPhoneDigits(control.value);
        var message = control.value === '' || normalized !== null ? '' : 'Enter a valid 10-digit phone number.';
        var clientInvalid = control.getAttribute('data-eforms-phone-client-invalid') === '1';
        if (typeof control.setCustomValidity === 'function') {
            control.setCustomValidity(message);
        }
        if (message !== '') {
            control.setAttribute('data-eforms-phone-client-invalid', '1');
            control.setAttribute('aria-invalid', 'true');
        } else {
            control.removeAttribute('data-eforms-phone-client-invalid');
            if (clearServerError === true || (clientInvalid && !control.getAttribute('aria-describedby'))) {
                control.removeAttribute('aria-invalid');
            }
            if (clearServerError === true) {
                control.removeAttribute('aria-describedby');
            }
        }
    }

    function formatPhoneControl(control, clearServerError) {
        var digits = phoneDigits(control.value);
        if (phoneGrammarAllowed(control.value) && (digits.length > 0 || control.value.trim() === '')) {
            control.value = displayPhoneDigits(digits);
        }
        setPhoneValidity(control, clearServerError === true);
    }

    function normalizePhoneControl(control, clearServerError) {
        var normalized = normalizedPhoneDigits(control.value);
        if (normalized !== null) {
            control.value = normalized;
        }
        setPhoneValidity(control, clearServerError === true);
    }

    function invalidPhoneInsertion(text) {
        return typeof text === 'string' && /[^0-9\s().+-]/.test(text);
    }

    function normalizedZipValue(value) {
        if (typeof value !== 'string') {
            return null;
        }
        value = value.trim();
        if (value === '') {
            return '';
        }
        if (/^\d{5}$/.test(value)) {
            return value;
        }
        var match = value.match(/^(\d{5})-\d{4}$/);
        return match ? match[1] : null;
    }

    function formatZipControl(control) {
        var normalized = normalizedZipValue(control.value);
        if (normalized !== null) {
            control.value = normalized;
        }
    }

    function integerDigits(value) {
        if (typeof value !== 'string') {
            return null;
        }
        value = value.trim();
        if (value === '') {
            return '';
        }
        if (!/^[0-9,]+$/.test(value)) {
            return null;
        }
        return value.replace(/,/g, '');
    }

    function normalizedIntegerValue(value) {
        var digits = integerDigits(value);
        return digits === null || digits === '' || /^\d+$/.test(digits) ? digits : null;
    }

    function displayIntegerDigits(digits) {
        return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function digitCountBefore(value, offset) {
        var count = 0;
        var text = typeof value === 'string' ? value.slice(0, Math.max(0, offset)) : '';
        for (var i = 0; i < text.length; i += 1) {
            if (/\d/.test(text.charAt(i))) {
                count += 1;
            }
        }
        return count;
    }

    function offsetAfterDigitCount(value, digitCount) {
        if (digitCount <= 0) {
            return 0;
        }
        var seen = 0;
        for (var i = 0; i < value.length; i += 1) {
            if (/\d/.test(value.charAt(i))) {
                seen += 1;
            }
            if (seen >= digitCount) {
                return i + 1;
            }
        }
        return value.length;
    }

    function refreshUnitAdornment(control) {
        var wrapper = control.parentNode;
        if (!wrapper || !wrapper.classList || !wrapper.classList.contains('eforms-input-unit-wrap')) {
            return;
        }
        var measure = wrapper.querySelector('.eforms-input-unit-measure');
        var label = wrapper.querySelector('.eforms-input-unit');
        if (!measure || !label) {
            return;
        }
        measure.textContent = control.value || '';
        label.hidden = control.value === '';
        if (label.hidden) {
            return;
        }
        var styles = window.getComputedStyle ? window.getComputedStyle(control) : null;
        var paddingLeft = styles ? parseFloat(styles.paddingLeft) || 0 : 0;
        label.style.left = paddingLeft + measure.offsetWidth + 4 + 'px';
    }

    function formatIntegerControl(control, preserveSelection) {
        var before = control.value;
        var start = typeof control.selectionStart === 'number' ? control.selectionStart : null;
        var end = typeof control.selectionEnd === 'number' ? control.selectionEnd : null;
        var normalized = normalizedIntegerValue(control.value);
        if (normalized !== null) {
            control.value = displayIntegerDigits(normalized);
            if (preserveSelection === true && document.activeElement === control && start !== null && end !== null && typeof control.setSelectionRange === 'function') {
                control.setSelectionRange(
                    offsetAfterDigitCount(control.value, digitCountBefore(before, start)),
                    offsetAfterDigitCount(control.value, digitCountBefore(before, end))
                );
            }
        }
        refreshUnitAdornment(control);
    }

    function normalizeIntegerControl(control) {
        var normalized = normalizedIntegerValue(control.value);
        if (normalized !== null) {
            control.value = normalized;
        }
        refreshUnitAdornment(control);
    }

    function normalizeUrlValue(value) {
        if (typeof value !== 'string') {
            return '';
        }
        value = value.trim();
        if (value === '' || /\s/.test(value)) {
            return value;
        }
        if (value.indexOf('://') === -1 && /^[^@/]+\.[^@/]+(?:\/.*)?$/.test(value)) {
            return 'https://' + value;
        }
        return value;
    }

    function normalizeUrlControl(control) {
        control.value = normalizeUrlValue(control.value);
    }

    function nativeScalarValid(control) {
        if (!control) {
            return true;
        }
        if (control.validity && typeof control.validity.valid === 'boolean') {
            return control.validity.valid;
        }
        if (typeof control.checkValidity === 'function') {
            return control.checkValidity();
        }
        return true;
    }

    function syncNativeScalarValidity(control, clearServerError) {
        if (!control) {
            return;
        }
        var value = typeof control.value === 'string' ? control.value.trim() : '';
        var clientInvalid = control.getAttribute('data-eforms-native-client-invalid') === '1';
        var valid = nativeScalarValid(control);
        if (!valid && (value !== '' || clientInvalid)) {
            control.setAttribute('data-eforms-native-client-invalid', '1');
            control.setAttribute('aria-invalid', 'true');
            return;
        }
        if (!valid) {
            return;
        }
        control.removeAttribute('data-eforms-native-client-invalid');
        if (clearServerError === true || clientInvalid) {
            control.removeAttribute('aria-invalid');
            control.removeAttribute('aria-describedby');
        }
    }

    function markNativeScalarInvalid(control) {
        if (!isNativeScalarValidityControl(control)) {
            return;
        }
        control.setAttribute('data-eforms-native-client-invalid', '1');
        control.setAttribute('aria-invalid', 'true');
    }

    function syncFriendlyInputDisplay(form) {
        forEachNode(phoneControls(form), formatPhoneControl);
        forEachNode(zipControls(form), formatZipControl);
        forEachNode(integerControls(form), formatIntegerControl);
        forEachNode(urlControls(form), normalizeUrlControl);
        forEachNode(nativeScalarValidityControls(form), syncNativeScalarValidity);
    }

    function scheduleFriendlyInputSync(form) {
        [0, 250, 1000, 2000].forEach(function (delay) {
            window.setTimeout(function () {
                syncFriendlyInputDisplay(form);
            }, delay);
        });
    }

    function normalizeFriendlyInputSubmitValues(form) {
        forEachNode(phoneControls(form), function (control) {
            normalizePhoneControl(control, true);
        });
        forEachNode(zipControls(form), formatZipControl);
        forEachNode(integerControls(form), normalizeIntegerControl);
        forEachNode(urlControls(form), normalizeUrlControl);
    }

    function submitControlTarget(node) {
        while (node && node.nodeType === 1) {
            var tag = node.tagName ? node.tagName.toLowerCase() : '';
            var type = typeof node.type === 'string' ? node.type.toLowerCase() : '';
            if ((tag === 'button' && (type === '' || type === 'submit')) || (tag === 'input' && (type === 'submit' || type === 'image'))) {
                return true;
            }
            node = node.parentNode;
        }
        return false;
    }

    function normalizeUrlsBeforeNativeSubmit(form) {
        forEachNode(urlControls(form), normalizeUrlControl);
    }

    function addUnitAdornments(form) {
        forEachNode(unitControls(form), function (control) {
            if (control.getAttribute('data-eforms-input-unit-bound') === '1') {
                return;
            }
            var unit = control.getAttribute(dataAttributeName('input_unit'));
            if (typeof unit !== 'string' || unit === '') {
                return;
            }
            control.setAttribute('data-eforms-input-unit-bound', '1');
            var wrapper = document.createElement('span');
            wrapper.className = 'eforms-input-unit-wrap';
            var measure = document.createElement('span');
            measure.className = 'eforms-input-unit-measure';
            measure.setAttribute('aria-hidden', 'true');
            var label = document.createElement('span');
            label.className = 'eforms-input-unit';
            label.setAttribute('aria-hidden', 'true');
            label.textContent = unit;
            control.parentNode.insertBefore(wrapper, control);
            wrapper.appendChild(control);
            wrapper.appendChild(measure);
            wrapper.appendChild(label);
            refreshUnitAdornment(control);
        });
    }

    function bindFriendlyInputs(form) {
        forEachNode(phoneControls(form), function (control) {
            if (control.getAttribute('data-eforms-phone-format-bound') === '1') {
                return;
            }
            control.setAttribute('data-eforms-phone-format-bound', '1');
            formatPhoneControl(control);
            control.addEventListener('beforeinput', function (event) {
                if (invalidPhoneInsertion(event.data)) {
                    event.preventDefault();
                }
            });
            control.addEventListener('paste', function (event) {
                var text = event.clipboardData && typeof event.clipboardData.getData === 'function'
                    ? event.clipboardData.getData('text')
                    : '';
                if (invalidPhoneInsertion(text)) {
                    event.preventDefault();
                }
            });
            control.addEventListener('input', function () {
                formatPhoneControl(control, true);
            });
            control.addEventListener('change', function () {
                formatPhoneControl(control, true);
            });
            control.addEventListener('blur', function () {
                formatPhoneControl(control, true);
            });
        });
        forEachNode(zipControls(form), function (control) {
            if (control.getAttribute('data-eforms-zip-format-bound') === '1') {
                return;
            }
            control.setAttribute('data-eforms-zip-format-bound', '1');
            formatZipControl(control);
            control.addEventListener('beforeinput', function (event) {
                if (typeof event.data === 'string' && /\D/.test(event.data)) {
                    event.preventDefault();
                }
            });
            control.addEventListener('paste', function (event) {
                var text = event.clipboardData && typeof event.clipboardData.getData === 'function'
                    ? event.clipboardData.getData('text')
                    : '';
                if (typeof text === 'string' && /[^0-9\s-]/.test(text)) {
                    event.preventDefault();
                }
            });
            control.addEventListener('input', function () {
                formatZipControl(control);
            });
            control.addEventListener('change', function () {
                formatZipControl(control);
            });
            control.addEventListener('blur', function () {
                formatZipControl(control);
            });
        });
        forEachNode(integerControls(form), function (control) {
            if (control.getAttribute('data-eforms-integer-format-bound') === '1') {
                return;
            }
            control.setAttribute('data-eforms-integer-format-bound', '1');
            formatIntegerControl(control);
            control.addEventListener('beforeinput', function (event) {
                if (typeof event.data === 'string' && /[^0-9,]/.test(event.data)) {
                    event.preventDefault();
                }
            });
            control.addEventListener('paste', function (event) {
                var text = event.clipboardData && typeof event.clipboardData.getData === 'function'
                    ? event.clipboardData.getData('text')
                    : '';
                if (typeof text === 'string' && /[^0-9,]/.test(text)) {
                    event.preventDefault();
                }
            });
            control.addEventListener('input', function () {
                formatIntegerControl(control, true);
            });
            control.addEventListener('blur', function () {
                formatIntegerControl(control);
            });
            control.addEventListener('change', function () {
                formatIntegerControl(control);
            });
        });
        forEachNode(urlControls(form), function (control) {
            if (control.getAttribute('data-eforms-url-normalize-bound') === '1') {
                return;
            }
            control.setAttribute('data-eforms-url-normalize-bound', '1');
            normalizeUrlControl(control);
            control.addEventListener('blur', function () {
                normalizeUrlControl(control);
            });
            control.addEventListener('change', function () {
                normalizeUrlControl(control);
            });
        });
        forEachNode(nativeScalarValidityControls(form), function (control) {
            if (control.getAttribute('data-eforms-native-validity-bound') === '1') {
                return;
            }
            control.setAttribute('data-eforms-native-validity-bound', '1');
            syncNativeScalarValidity(control);
            control.addEventListener('input', function () {
                syncNativeScalarValidity(control, true);
            });
            control.addEventListener('change', function () {
                syncNativeScalarValidity(control, true);
            });
            control.addEventListener('blur', function () {
                syncNativeScalarValidity(control, true);
            });
        });
        syncFriendlyInputDisplay(form);
        scheduleFriendlyInputSync(form);
        addUnitAdornments(form);
        if (form.getAttribute('data-eforms-friendly-inputs-submit-bound') === '1') {
            return;
        }
        form.setAttribute('data-eforms-friendly-inputs-submit-bound', '1');
        form.addEventListener('click', function (event) {
            if (submitControlTarget(event.target)) {
                normalizeUrlsBeforeNativeSubmit(form);
            }
        }, true);
        form.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                normalizeUrlsBeforeNativeSubmit(form);
            }
        }, true);
        form.addEventListener('invalid', function (event) {
            syncFriendlyInputDisplay(form);
            markNativeScalarInvalid(event.target);
        }, true);
        form.addEventListener('submit', function () {
            normalizeFriendlyInputSubmitValues(form);
            window.setTimeout(function () {
                syncFriendlyInputDisplay(form);
            }, 0);
        });
    }

    function focusErrors(form, focusFirstInvalid) {
        // Focus summary once, then optionally first invalid control to guide keyboard users.
        var summary = form.querySelector('.eforms-error-summary');
        if (summary) {
            summary.focus();
        }

        if (focusFirstInvalid === false) {
            return;
        }
        var firstInvalid = form.querySelector('[aria-invalid="true"]');
        if (firstInvalid && typeof firstInvalid.focus === 'function') {
            firstInvalid.focus();
        }
    }

    function buttonSpinnerLayout(button) {
        if (!window.getComputedStyle) {
            return 'inline';
        }
        var display = window.getComputedStyle(button).display;
        if (display === 'block' || display === 'flex' || display === 'grid' || display === 'inline-block' || display === 'inline-flex' || display === 'inline-grid') {
            return display;
        }
        return 'inline';
    }

    function ensureSubmitLabel(button) {
        if (button.querySelector('.eforms-submit-label')) {
            return;
        }
        var label = document.createElement('span');
        label.className = 'eforms-submit-label';
        while (button.firstChild) {
            label.appendChild(button.firstChild);
        }
        button.appendChild(label);
    }

    function updateSubmitSpinnerOffset(button) {
        var label = button.querySelector('.eforms-submit-label');
        if (!label || typeof label.getBoundingClientRect !== 'function') {
            return;
        }
        button.style.setProperty('--eforms-submit-label-half', (label.getBoundingClientRect().width / 2) + 'px');
    }

    function restoreSubmitLabel(button) {
        var label = button.querySelector('.eforms-submit-label');
        if (!label || button.querySelector('.eforms-spinner')) {
            return;
        }
        while (label.firstChild) {
            button.insertBefore(label.firstChild, label);
        }
        if (label.parentNode) {
            label.parentNode.removeChild(label);
        }
        button.style.removeProperty('--eforms-submit-label-half');
    }

    function addButtonSpinner(button, markerAttribute) {
        if (button.tagName.toLowerCase() !== 'button' || button.querySelector('.eforms-spinner')) {
            return;
        }
        button.setAttribute('data-eforms-spinner-button', buttonSpinnerLayout(button));
        ensureSubmitLabel(button);
        updateSubmitSpinnerOffset(button);
        var spinner = document.createElement('span');
        spinner.className = 'eforms-spinner';
        spinner.setAttribute('aria-hidden', 'true');
        if (markerAttribute) {
            spinner.setAttribute(markerAttribute, '1');
        }
        button.appendChild(spinner);
    }

    function removeButtonSpinners(button, selector) {
        forEachNode(button.querySelectorAll(selector), function (spinner) {
            if (spinner.parentNode) {
                spinner.parentNode.removeChild(spinner);
            }
        });
        if (!button.querySelector('.eforms-spinner')) {
            button.removeAttribute('data-eforms-spinner-button');
            restoreSubmitLabel(button);
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
                addButtonSpinner(button, null);
            });
        });
    }

    function focusErrorSummary(form) {
        focusErrors(form, false);
    }

    function enhancedSettings() {
        var configured = protocol().enhancedResponse;
        var response = configured && configured.response;
        var names = ['ok', 'location', 'errors', 'global', 'fields', 'error', 'code', 'message', 'canRetry', 'uploadRecovery', 'state', 'open', 'finalizingRecovery', 'challenge', 'provider', 'siteKey'];
        if (!configured || typeof configured.header !== 'string' || configured.header === ''
            || typeof configured.value !== 'string' || configured.value === ''
            || !response || !names.every(function (key) {
                return typeof response[key] === 'string' && response[key] !== '';
            })) {
            return null;
        }
        return configured;
    }

    function enhancedCapabilityAvailable(form) {
        var state = form.__eformsUploadState;
        return Boolean(window.fetch && window.FormData && window.URL && enhancedSettings()
            && state && Array.isArray(state.runtimes) && state.runtimes.length > 0);
    }

    function setEnhancedPending(form, pending, enableSubmit) {
        var buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        forEachNode(buttons, function (button) {
            if (pending) {
                if (!button.disabled) {
                    button.disabled = true;
                    button.setAttribute('data-eforms-enhanced-disabled', '1');
                }
                addButtonSpinner(button, 'data-eforms-enhanced-spinner');
                return;
            }
            if (button.getAttribute('data-eforms-enhanced-disabled') === '1' && enableSubmit !== false) {
                button.disabled = false;
                button.removeAttribute('data-eforms-enhanced-disabled');
            }
            removeButtonSpinners(button, '[data-eforms-enhanced-spinner="1"]');
        });
    }

    function fieldNodes(form, attribute, value) {
        var nodes = [];
        forEachNode(form.querySelectorAll('[' + attribute + ']'), function (node) {
            if (node.getAttribute(attribute) === value) {
                nodes.push(node);
            }
        });
        return nodes;
    }

    function fieldEntryMessage(entry, response) {
        return entry[response.message];
    }

    function errorIdForControl(control) {
        return control && control.id ? 'error-' + control.id : '';
    }

    function clearEnhancedErrors(form) {
        var attrs = protocol().dataAttributes || {};
        if (!attrs.field_control) {
            return;
        }
        forEachNode(form.querySelectorAll('[' + attrs.field_control + '="1"]'), function (control) {
            control.removeAttribute('aria-invalid');
            control.removeAttribute('aria-describedby');
        });
        var summary = form.querySelector('.eforms-error-summary');
        if (summary && summary.parentNode) {
            summary.parentNode.removeChild(summary);
        }
    }

    function nativeFormValid(form) {
        if (form.noValidate) {
            return true;
        }
        return typeof form.checkValidity !== 'function' || form.checkValidity();
    }

    function addClientValidation(form) {
        if (form.getAttribute('data-eforms-client-validation') === '1') {
            return;
        }
        form.setAttribute('data-eforms-client-validation', '1');
        if (!form.hasAttribute('novalidate')) {
            form.noValidate = false;
        }
        form.addEventListener('invalid', function () {
            syncFriendlyInputDisplay(form);
        }, true);
    }

    function validErrorEntries(entries, response) {
        return Array.isArray(entries) && entries.every(function (entry) {
            return entry && typeof entry === 'object'
                && typeof entry[response.code] === 'string'
                && typeof entry[response.message] === 'string';
        });
    }

    function applyCorrectableErrors(form, payload, settings) {
        var response = settings.response;
        var errors = payload[response.errors];
        if (!errors || typeof errors !== 'object'
            || !validErrorEntries(errors[response.global], response)
            || !errors[response.fields] || typeof errors[response.fields] !== 'object'
            || Array.isArray(errors[response.fields])) {
            return false;
        }
        var attrs = protocol().dataAttributes || {};
        if (typeof attrs.field_key !== 'string' || typeof attrs.field_control !== 'string' || typeof attrs.field_error_mount !== 'string') {
            return false;
        }
        clearEnhancedErrors(form);
        var summary = ensureErrorSummary(form);
        var messages = [ENHANCED_CORRECTABLE_MESSAGE].concat(errors[response.global].map(function (entry) { return entry[response.message]; }));
        Object.keys(errors[response.fields]).forEach(function (key) {
            var entries = errors[response.fields][key];
            if (!validErrorEntries(entries, response)) {
                messages = null;
                return;
            }
            var controls = fieldNodes(form, attrs.field_key, key).filter(function (node) {
                return node.getAttribute(attrs.field_control) === '1';
            });
            if (!controls.length) {
                messages = null;
                return;
            }
            var text = entries.map(function (entry) { return fieldEntryMessage(entry, response); }).join(' ');
            var errorId = errorIdForControl(controls[0]);
            if (!errorId) {
                messages = null;
                return;
            }
            forEachNode(controls, function (control) {
                control.setAttribute('aria-invalid', 'true');
                control.setAttribute('aria-describedby', errorId);
            });
            messages.push({ text: text, control: controls[0], errorId: errorId, fieldKey: key });
        });
        if (!messages) {
            clearEnhancedErrors(form);
            return false;
        }
        var list = summary;
        forEachNode(messages, function (entry) {
            var item = document.createElement('li');
            if (typeof entry === 'string') {
                item.textContent = entry;
            } else {
                item.id = entry.errorId;
                item.setAttribute(attrs.field_key, entry.fieldKey);
                item.setAttribute(attrs.field_error_mount, '1');
                var link = document.createElement('a');
                link.textContent = entry.text;
                if (entry.control.id) {
                    link.href = '#' + entry.control.id;
                }
                item.appendChild(link);
            }
            list.appendChild(item);
        });
        return true;
    }

    function thawRuntime(runtime) {
        if (runtime.destroyed || runtime.expired || runtime.unavailable) {
            return;
        }
        runtime.frozen = false;
        runtime.mount.removeAttribute('data-eforms-upload-frozen');
        runtime.picker.disabled = false;
        runtime.chooseButton.disabled = false;
        runtime.clearButton.disabled = false;
        forEachNode(runtime.items, function (item) {
            renderItem(runtime, item);
        });
        updateFormUploadState(runtime.form);
    }

    function normalizedEnhancedRecoveryState(state, settings) {
        if (state === settings.response.open) {
            return 'open';
        }
        if (state === settings.response.finalizingRecovery) {
            return 'finalizing';
        }
        return '';
    }

    function normalizedUploadStatusState(state) {
        if (state === 'open') {
            return 'open';
        }
        if (state === 'finalizing') {
            return 'finalizing';
        }
        return '';
    }

    function applyKnownRecoveryStates(statuses) {
        forEachNode(statuses, function (status) {
            if (status.state === 'open' || status.state === 'not_created') {
                thawRuntime(status.runtime);
                return;
            }
            freezeForSubmit(status.runtime);
        });
    }

    function freezeEnhancedRuntimes(form) {
        var formState = form.__eformsUploadState;
        var runtimes = formState ? formState.runtimes : [];
        forEachNode(runtimes, freezeForSubmit);
    }

    function applyRecoveryState(form, state, settings) {
        var formState = form.__eformsUploadState;
        var runtimes = formState ? formState.runtimes : [];
        var normalized = normalizedEnhancedRecoveryState(state, settings);
        if (!runtimes.length || !normalized) {
            return false;
        }
        applyKnownRecoveryStates(runtimes.map(function (runtime) {
            return { runtime: runtime, state: normalized };
        }));
        return true;
    }

    function clearEnhancedPendingForNavigation(form, clearNavigating) {
        form.removeAttribute('data-eforms-enhanced-pending');
        if (clearNavigating === true) {
            form.removeAttribute('data-eforms-enhanced-navigating');
        }
        setEnhancedPending(form, false, true);
    }

    function safeNavigate(form, location) {
        if (typeof location !== 'string' || form.getAttribute('data-eforms-enhanced-navigating') === '1') {
            return false;
        }
        try {
            var target = new URL(location, window.location.href);
            if (target.origin !== window.location.origin) {
                return false;
            }
            var result = target.searchParams.get('eforms_result');
            var formId = target.searchParams.get('eforms_form');
            if ((result !== 'success' && result !== 'email_failure') || typeof formId !== 'string' || formId === '') {
                return false;
            }
            form.setAttribute('data-eforms-enhanced-navigating', '1');
            window.location.assign(target.href);
            return true;
        } catch (error) {
            return false;
        }
    }

    function activateChallenge(form, challenge, settings) {
        var response = settings.response;
        if (challenge === null) {
            clearChallenge(form);
            return true;
        }
        if (!challenge || challenge[response.provider] !== 'turnstile' || typeof challenge[response.siteKey] !== 'string' || challenge[response.siteKey] === '') {
            return false;
        }
        var attrs = protocol().dataAttributes || {};
        if (typeof attrs.challenge_mount !== 'string') {
            return false;
        }
        var mount = null;
        forEachNode(form.querySelectorAll('[' + attrs.challenge_mount + ']'), function (candidate) {
            if (!mount && (candidate.getAttribute(attrs.challenge_mount) === 'turnstile' || candidate.getAttribute(attrs.challenge_mount) === '1')) {
                mount = candidate;
            }
        });
        if (!mount) {
            return false;
        }
        mount.hidden = false;
        if (mount.getAttribute('data-eforms-challenge-rendered') === '1') {
            clearChallengeResponse(form);
            if (window.turnstile && typeof window.turnstile.reset === 'function') {
                window.turnstile.reset(mount.getAttribute('data-eforms-challenge-widget-id') || undefined);
                return true;
            }
            mount.removeAttribute('data-eforms-challenge-rendered');
            mount.removeAttribute('data-eforms-challenge-widget-id');
            mount.textContent = '';
        }
        if (!window.turnstile || typeof window.turnstile.render !== 'function') {
            return false;
        }
        var widget = document.createElement('div');
        widget.className = 'cf-turnstile';
        widget.setAttribute('data-sitekey', challenge[response.siteKey]);
        mount.textContent = '';
        mount.appendChild(widget);
        mount.setAttribute('data-eforms-challenge-rendered', '1');
        var widgetId = window.turnstile.render(widget, { sitekey: challenge[response.siteKey] });
        if (typeof widgetId === 'string' && widgetId !== '') {
            mount.setAttribute('data-eforms-challenge-widget-id', widgetId);
        }
        return true;
    }

    function validTurnstileChallenge(challenge, settings) {
        var response = settings.response;
        return Boolean(challenge
            && challenge[response.provider] === 'turnstile'
            && typeof challenge[response.siteKey] === 'string'
            && challenge[response.siteKey] !== '');
    }

    function needsServerRenderedChallenge(challenge, settings) {
        return validTurnstileChallenge(challenge, settings)
            && (!window.turnstile || typeof window.turnstile.render !== 'function');
    }

    function submitServerRenderedChallenge(form) {
        form.setAttribute('data-eforms-enhanced-navigating', '1');
        try {
            if (window.HTMLFormElement
                && window.HTMLFormElement.prototype
                && typeof window.HTMLFormElement.prototype.submit === 'function') {
                window.HTMLFormElement.prototype.submit.call(form);
                return true;
            }
        } catch (error) {
            return false;
        }
        return false;
    }

    function clearChallenge(form) {
        clearChallengeResponse(form, true);
        var attrs = protocol().dataAttributes || {};
        if (typeof attrs.challenge_mount !== 'string') {
            return;
        }
        forEachNode(form.querySelectorAll('[' + attrs.challenge_mount + ']'), function (mount) {
            mount.hidden = true;
            mount.removeAttribute('data-eforms-challenge-rendered');
            mount.removeAttribute('data-eforms-challenge-widget-id');
            mount.textContent = '';
        });
    }

    function clearChallengeResponse(form, removeControl) {
        forEachNode(form.querySelectorAll('input[name="cf-turnstile-response"], textarea[name="cf-turnstile-response"]'), function (control) {
            control.value = '';
            if (removeControl === true && control.parentNode) {
                control.parentNode.removeChild(control);
            }
        });
    }

    function enhancedRecoveryOutcome(sendEnabled, blocked) {
        return { sendEnabled: sendEnabled === true, blocked: blocked === true };
    }

    function statusCheckResult(runtime, settings) {
        if (!runtime.batchId || !runtime.secret) {
            return Promise.resolve({ runtime: runtime, state: 'not_created' });
        }
        return fetch(managedUrl('/' + encodeURIComponent(runtime.batchId)), {
            method: 'GET',
            headers: uploadHeaders(runtime),
            credentials: 'same-origin'
        }).then(function (response) {
            if (response.status === 410) {
                unavailableRuntime(runtime);
                return { runtime: runtime, state: 'blocked' };
            }
            return response.json().catch(function () { return null; }).then(function (payload) {
                var state = payload && normalizedUploadStatusState(payload[uploadResponseName('state')]);
                if (response.status === 200 && state) {
                    return { runtime: runtime, state: state };
                }
                if (response.status >= 400 && response.status < 500 && response.status !== 408 && response.status !== 429) {
                    unavailableRuntime(runtime);
                    return { runtime: runtime, state: 'blocked' };
                }
                return { runtime: runtime, state: 'check_failed' };
            });
        }).catch(function () {
            return { runtime: runtime, state: 'check_failed' };
        });
    }

    function reconcileEnhancedSubmission(form, settings) {
        var formState = form.__eformsUploadState;
        var runtimes = formState ? formState.runtimes : [];
        if (!runtimes.length) {
            return Promise.resolve(enhancedRecoveryOutcome(false, true));
        }
        return Promise.all(runtimes.map(function (runtime) {
            return statusCheckResult(runtime, settings);
        })).then(function (statuses) {
            if (statuses.some(function (status) { return status.state === 'check_failed'; })) {
                freezeEnhancedRuntimes(form);
                return enhancedRecoveryOutcome(true, false);
            }
            if (statuses.some(function (status) { return status.state === 'blocked'; })) {
                freezeEnhancedRuntimes(form);
                return enhancedRecoveryOutcome(false, true);
            }
            applyKnownRecoveryStates(statuses);
            return enhancedRecoveryOutcome(true, false);
        });
    }

    function appendSummaryText(list, text) {
        if (typeof text !== 'string' || text === '') {
            return null;
        }
        var item = document.createElement('li');
        item.textContent = text;
        list.appendChild(item);
        return item;
    }

    function showEnhancedMessage(form, text) {
        clearEnhancedErrors(form);
        appendSummaryText(ensureErrorSummary(form), text);
    }



    function thawEnhancedRuntimes(form) {
        var formState = form.__eformsUploadState;
        var runtimes = formState ? formState.runtimes : [];
        forEachNode(runtimes, thawRuntime);
    }

    function restoreEnhancedSubmission(form, enableSubmit, enableUploads) {
        form.removeAttribute('data-eforms-enhanced-pending');
        if (enableUploads === true) {
            thawEnhancedRuntimes(form);
        }
        setEnhancedPending(form, false, enableSubmit === true);
        focusErrorSummary(form);
    }

    function restoreAmbiguousSubmission(form, settings) {
        return reconcileEnhancedSubmission(form, settings).then(function (outcome) {
            showEnhancedMessage(form, outcome.blocked ? ENHANCED_BLOCKED_MESSAGE : ENHANCED_RETRY_MESSAGE);
            restoreEnhancedSubmission(form, outcome.sendEnabled);
        });
    }

    function handleEnhancedResponse(form, result, settings) {
        var response = settings.response;
        var payload = result.payload;
        if (result.status >= 300 && result.status < 400 && typeof result.location === 'string' && safeNavigate(form, result.location)) {
            return Promise.resolve();
        }
        if (!payload || typeof payload !== 'object') {
            return restoreAmbiguousSubmission(form, settings);
        }
        if (payload[response.ok] === true && typeof payload[response.location] === 'string') {
            if (!safeNavigate(form, payload[response.location])) {
                return restoreAmbiguousSubmission(form, settings);
            }
            return Promise.resolve();
        }
        if (result.status === 422 && payload[response.ok] === false) {
            var recovery = payload[response.uploadRecovery];
            var state = recovery && typeof recovery === 'object' ? recovery[response.state] : null;
            if (!applyCorrectableErrors(form, payload, settings)) {
                return restoreAmbiguousSubmission(form, settings);
            }
            if (needsServerRenderedChallenge(payload[response.challenge], settings)) {
                if (submitServerRenderedChallenge(form)) {
                    return Promise.resolve();
                }
                return restoreAmbiguousSubmission(form, settings);
            }
            if (!activateChallenge(form, payload[response.challenge], settings)) {
                return restoreAmbiguousSubmission(form, settings);
            }
            if (state !== null && applyRecoveryState(form, state, settings)) {
                restoreEnhancedSubmission(form, true);
                return Promise.resolve();
            }
            return reconcileEnhancedSubmission(form, settings).then(function (outcome) {
                restoreEnhancedSubmission(form, outcome.sendEnabled);
            });
        }
        var failure = payload[response.error];
        var structured = payload[response.ok] === false && failure && typeof failure === 'object'
            && typeof failure[response.code] === 'string' && typeof failure[response.message] === 'string'
            && typeof payload[response.canRetry] === 'boolean'
            && (payload[response.location] === null || typeof payload[response.location] === 'string');
        if (structured) {
            if (typeof payload[response.location] === 'string' && safeNavigate(form, payload[response.location])) {
                return Promise.resolve();
            }
            showEnhancedMessage(form, failure[response.message]);
            restoreEnhancedSubmission(form, payload[response.canRetry] === true, payload[response.canRetry] === true);
            return Promise.resolve();
        }
        return restoreAmbiguousSubmission(form, settings);
    }

    function addEnhancedSubmitHandler(form) {
        if (!enhancedCapabilityAvailable(form) || form.getAttribute('data-eforms-enhanced-handler') === '1') {
            return;
        }
        form.setAttribute('data-eforms-enhanced-handler', '1');
        form.addEventListener('submit', function (event) {
            if (event.defaultPrevented || !event.cancelable) {
                return;
            }
            if (form.getAttribute('data-eforms-enhanced-pending') === '1' || form.getAttribute('data-eforms-enhanced-navigating') === '1') {
                event.preventDefault();
                return;
            }
            if (!enhancedCapabilityAvailable(form)) {
                return;
            }
            event.preventDefault();
            if (!nativeFormValid(form)) {
                return;
            }
            form.setAttribute('data-eforms-enhanced-pending', '1');
            setEnhancedPending(form, true);
            var settings = enhancedSettings();
            var headers = {};
            headers[settings.header] = settings.value;
            fetch(form.action || window.location.href, {
                method: (form.method || 'post').toUpperCase(),
                headers: headers,
                body: new FormData(form),
                credentials: 'same-origin',
                redirect: 'manual'
            }).then(function (response) {
                return response.json().catch(function () { return null; }).then(function (payload) {
                    return {
                        status: response.status,
                        payload: payload,
                        location: response.headers && typeof response.headers.get === 'function' ? response.headers.get('Location') : null,
                        type: response.type
                    };
                });
            }).then(function (result) {
                return handleEnhancedResponse(form, result, settings);
            }).catch(function () {
                return restoreAmbiguousSubmission(form, settings);
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
            var parsed = parseMintPayload(JSON.parse(raw));
            if (!parsed) {
                removeCachedToken(formId);
                return null;
            }

            var now = nowSeconds();
            if (parsed.expires <= now) {
                removeCachedToken(formId);
                return null;
            }

            if (ttlMax > 0 && parsed.expires - parseInt(parsed.timestamp, 10) > ttlMax) {
                removeCachedToken(formId);
                return null;
            }

            return parsed;
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

    function uploadMime(item) {
        var extension = fileExtension(item && item.name);
        var managed = uploadProtocol();
        return managed && typeof managed.mimeByExtension[extension] === 'string'
            ? managed.mimeByExtension[extension]
            : '';
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
        if (item.state === 'preparing') {
            return 'Preparing photo...';
        }
        if (item.state === 'uploading') {
            return 'Uploading';
        }
        if (item.state === 'securing' || item.state === 'registering') {
            return 'Processing';
        }
        if (item.state === 'uploaded') {
            return item.previewUnavailable ? 'Uploaded (preview unavailable)' : 'Uploaded';
        }
        if (item.state === 'failed') {
            return item.error || 'Upload failed';
        }
        if (item.state === 'removing') {
            return 'Removing';
        }
        return item.state === 'queued' || item.state === 'authorizing' ? 'Queued' : '';
    }

    function visibleItemState(item) {
        if (item.state === 'authorizing') {
            return 'queued';
        }
        if (item.state === 'securing' || item.state === 'registering') {
            return 'processing';
        }
        return item.state;
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
            bindPreviewError(runtime, item, image, item.previewRequest || 0);
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

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'eforms-upload-remove';
            var removeIcon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            removeIcon.classList.add('eforms-upload-remove-icon');
            removeIcon.setAttribute('viewBox', '0 0 24 24');
            removeIcon.setAttribute('aria-hidden', 'true');
            removeIcon.setAttribute('focusable', 'false');
            var removeGlyph = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            removeGlyph.setAttribute('d', 'M7 7l10 10M17 7 7 17');
            removeIcon.appendChild(removeGlyph);
            remove.appendChild(removeIcon);
            remove.setAttribute('aria-label', 'Remove ' + item.name);
            remove.addEventListener('click', function () {
                removeItem(runtime, item);
            });
            media.appendChild(remove);
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
            card.appendChild(actions);
            runtime.grid.appendChild(card);

            item.card = card;
            item.image = image;
            item.nameNode = name;
            item.progressNode = progress;
            item.statusNode = status;
            item.actionsNode = actions;
            item.retryButton = retry;
            item.removeButton = remove;
        }

        item.card.setAttribute('data-eforms-upload-id', item.id);
        item.card.setAttribute('data-eforms-upload-state', visibleItemState(item));
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
        item.statusNode.classList.toggle('screen-reader-text', item.state === 'uploaded' && !item.previewUnavailable);
        item.progressNode.textContent = item.progress + '%';
        item.progressNode.setAttribute('aria-valuenow', String(item.progress));
        item.progressNode.style.background = 'conic-gradient(var(--eforms-upload-accent) ' + (item.progress * 3.6) + 'deg, var(--eforms-upload-track) 0)';
        item.progressNode.hidden = item.state !== 'uploading';
        item.retryButton.hidden = item.state !== 'failed' || runtime.frozen || runtime.expired || runtime.unavailable || (!item.file && !item.sourceFile);
        item.actionsNode.hidden = item.retryButton.hidden;
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
        renderItem(runtime, item);
        if (changed && visibleItemState(item) !== 'queued') {
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
                entry.node.textContent = waiting ? 'Finishing photos...' : entry.value;
            } else {
                entry.node.value = waiting ? 'Finishing photos...' : entry.value;
            }
        });
    }

    function requiredUploadMissing(runtime) {
        return runtime.required === true && runtime.items.length === 0;
    }

    function unresolvedItem(runtime) {
        for (var i = 0; i < runtime.items.length; i += 1) {
            var state = runtime.items[i].state;
            if (state !== 'uploaded') {
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
            var selected = runtime.items.length;
            if (unresolvedItem(runtime) || restoreBlocked(runtime) || runtime.expired || runtime.unavailable) {
                waiting = true;
            }
            runtime.countStatus.textContent = selected > 0 ? selected + ' of ' + runtime.maxFiles + ' photos selected' : '';
            if (requiredUploadMissing(runtime) && runtime.requiredPrompted && runtime.fieldStatus.textContent === '') {
                runtime.fieldStatus.textContent = 'Add at least one photo.';
            } else if (!requiredUploadMissing(runtime) && runtime.fieldStatus.textContent === 'Add at least one photo.') {
                runtime.fieldStatus.textContent = '';
            }
            runtime.clearButton.hidden = restoreBlocked(runtime) || runtime.frozen || runtime.unavailable || runtime.items.length === 0 || runtime.items.every(function (item) {
                return item.state === 'removing';
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
        abortRuntimePreparation(runtime);
        runtime.restoreState = 'terminal';
        runtime.mount.removeAttribute('data-eforms-upload-restoring');
        runtime.mount.removeAttribute('data-eforms-upload-restore-failed');
        runtime.picker.disabled = true;
        runtime.chooseButton.disabled = true;
        runtime.chooseButton.textContent = 'Browse photos';
        runtime.mount.setAttribute('data-eforms-upload-expired', '1');
        runtime.fieldStatus.textContent = 'Form expired\u2014reload and select your photos again.';
        forEachNode(runtime.items, function (item) {
            if (item.state !== 'uploaded' && item.state !== 'removed') {
                setItemState(runtime, item, 'failed', 'Form expired. Reload and select this photo again.');
            }
            if (item.xhr) {
                item.xhr.abort();
            }
            abortTransferFetch(runtime, item);
            abortControlFetch(item);
            renderItem(runtime, item);
        });
        fieldAnnouncement(runtime, 'Form expired\u2014reload and select your photos again.');
        updateFormUploadState(runtime.form);
    }

    function unavailableRuntime(runtime) {
        if (runtime.unavailable || runtime.destroyed) {
            return;
        }
        runtime.unavailable = true;
        abortRuntimePreparation(runtime);
        runtime.restoreState = 'terminal';
        runtime.mount.removeAttribute('data-eforms-upload-restoring');
        runtime.mount.removeAttribute('data-eforms-upload-restore-failed');
        runtime.picker.disabled = true;
        runtime.chooseButton.disabled = true;
        runtime.chooseButton.textContent = 'Browse photos';
        runtime.mount.setAttribute('data-eforms-upload-unavailable', '1');
        runtime.fieldStatus.textContent = 'Photos are unavailable\u2014reload and select them again.';
        forEachNode(runtime.items, function (item) {
            if (item.state !== 'uploaded' && item.state !== 'removed') {
                setItemState(runtime, item, 'failed', 'Upload unavailable. Reload the form.');
            }
            if (item.xhr) {
                item.xhr.abort();
            }
            abortTransferFetch(runtime, item);
            abortControlFetch(item);
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

    function updateLimitLabel(runtime) {
        if (runtime.limitsStatus) {
            runtime.limitsStatus.textContent = 'Limits: ' + runtime.maxFiles + ' photos \u00b7 ' + uploadSizeLabel(runtime.maxFileBytes) + ' each \u00b7 ' + uploadSizeLabel(runtime.maxTotalBytes) + ' total';
        }
    }

    function applyServerLimits(runtime, payload) {
        var limits = payload && payload[uploadResponseName('limits')];
        var maxFileBytes = limits && limits[uploadResponseName('maxFileBytes')];
        var maxFiles = limits && limits[uploadResponseName('maxFiles')];
        var maxTotalBytes = limits && limits[uploadResponseName('maxTotalBytes')];
        if (!Number.isSafeInteger(maxFileBytes) || maxFileBytes <= 0
            || !Number.isSafeInteger(maxFiles) || maxFiles <= 0
            || !Number.isSafeInteger(maxTotalBytes) || maxTotalBytes <= 0
            || maxFiles > Math.floor(Number.MAX_SAFE_INTEGER / maxFileBytes)
            || maxTotalBytes > maxFileBytes * maxFiles) {
            return false;
        }
        runtime.maxFileBytes = maxFileBytes;
        runtime.maxFiles = maxFiles;
        runtime.maxTotalBytes = maxTotalBytes;
        updateLimitLabel(runtime);
        updateFormUploadState(runtime.form);
        return true;
    }

    function beginRuntimeFetch(runtime) {
        abortRuntimeFetch(runtime);
        var request = {
            controller: typeof AbortController === 'function' ? new AbortController() : null
        };
        runtime.runtimeRequest = request;
        return request;
    }

    function clearRuntimeFetch(runtime, request) {
        if (runtime.runtimeRequest === request) {
            runtime.runtimeRequest = null;
        }
    }

    function abortRuntimeFetch(runtime) {
        var request = runtime.runtimeRequest;
        runtime.runtimeRequest = null;
        if (request && request.controller) {
            request.controller.abort();
        }
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
        var request = beginRuntimeFetch(runtime);
        var options = {
            method: 'POST',
            headers: headers,
            body: body.join('&'),
            credentials: 'same-origin'
        };
        if (request.controller) {
            options.signal = request.controller.signal;
        }
        fetch(managedUrl(''), options).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (payload) {
                return { status: response.status, payload: payload };
            });
        }).then(function (result) {
            if (runtime.runtimeRequest !== request) {
                return;
            }
            clearRuntimeFetch(runtime, request);
            var batchIdName = uploadResponseName('batchId');
            var acceptUntilName = uploadResponseName('acceptUntil');
            if (result.status === 410) {
                expireRuntime(runtime);
                finishBatchCreate(runtime, false);
                return;
            }
            var ok = result.status === 200
                && result.payload
                && validManagedId(result.payload[batchIdName], uploadRuntimeValue('batchIdChars'), true)
                && applyServerLimits(runtime, result.payload);
            if (ok) {
                runtime.batchId = result.payload[batchIdName];
                setAcceptUntil(runtime, result.payload[acceptUntilName]);
                enforceChosenArtifactLimits(runtime);
            }
            finishBatchCreate(runtime, ok);
        }).catch(function () {
            if (runtime.runtimeRequest !== request) {
                return;
            }
            clearRuntimeFetch(runtime, request);
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

    function itemHoldsTransferPermit(item) {
        return item.state === 'authorizing' || item.state === 'uploading';
    }

    function itemHoldsPipelinePermit(item) {
        return itemHoldsTransferPermit(item) || item.state === 'securing' || item.state === 'registering';
    }

    function uploadPermitCensus(runtime) {
        var census = { transfer: 0, worker: 0, local: 0 };
        forEachNode(runtime.items, function (item) {
            if (itemHoldsTransferPermit(item)) {
                census.transfer += 1;
            }
            if (!itemHoldsPipelinePermit(item)) {
                return;
            }
            if (item.transportKind === uploadName('workerTransport')) {
                census.worker += 1;
            } else if (item.transportKind === uploadName('localTransport')) {
                census.local += 1;
            } else {
                census.worker += 1;
                census.local += 1;
            }
        });
        return census;
    }

    function uploadPermitsAvailable(runtime) {
        var census = uploadPermitCensus(runtime);
        return census.transfer < uploadRuntimeValue('transferConcurrency')
            && census.worker < uploadRuntimeValue('workerPipelineConcurrency')
            && census.local < uploadRuntimeValue('localPipelineConcurrency');
    }

    function markPreviewUnavailable(runtime, item, request) {
        if (request !== item.previewRequest || item.state === 'removed' || runtime.destroyed) {
            return;
        }
        if (item.objectUrl) {
            URL.revokeObjectURL(item.objectUrl);
            item.objectUrl = '';
        }
        item.image.removeAttribute('src');
        item.previewUnavailable = true;
        renderItem(runtime, item);
        var current = item.state === 'uploaded' ? 'Uploaded' : stateLabel(item);
        fieldAnnouncement(runtime, item.name + ': ' + (current || 'Preview unavailable') + '; preview unavailable');
    }

    function beginControlFetch(item) {
        var previous = item.controlRequest;
        if (previous && previous.controller) {
            previous.controller.abort();
        }
        var request = {
            controller: typeof AbortController === 'function' ? new AbortController() : null
        };
        item.controlRequest = request;
        return request;
    }

    function clearControlFetch(item, request) {
        if (item.controlRequest === request) {
            item.controlRequest = null;
        }
    }

    function abortControlFetch(item) {
        var request = item.controlRequest;
        item.controlRequest = null;
        if (request && request.controller) {
            request.controller.abort();
        }
    }

    function reconcileItem(runtime, item, removal, retireIfAbsent) {
        if (runtime.destroyed) {
            return Promise.resolve();
        }
        if (!runtime.batchId) {
            setItemState(runtime, item, 'failed', removal ? 'Could not remove photo.' : 'Upload failed. Retry.');
            return Promise.resolve();
        }
        var request = beginControlFetch(item);
        var options = {
            method: 'GET',
            headers: uploadHeaders(runtime),
            credentials: 'same-origin'
        };
        if (request.controller) {
            options.signal = request.controller.signal;
        }
        return fetch(managedUrl('/' + encodeURIComponent(runtime.batchId)), options).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (payload) {
                return { status: response.status, payload: payload };
            });
        }).then(function (result) {
            if (runtime.destroyed || item.controlRequest !== request || item.state === 'removed' || (item.state === 'removing' && !removal)) {
                return;
            }
            if (result.status === 410) {
                unavailableRuntime(runtime);
                setItemState(runtime, item, 'failed', 'Upload unavailable. Reload the form.');
                return;
            }
            var itemsName = uploadResponseName('items');
            var intentsName = uploadResponseName('intents');
            if (result.status !== 200 || !result.payload || !Array.isArray(result.payload[itemsName]) || !Array.isArray(result.payload[intentsName]) || !applyServerLimits(runtime, result.payload)) {
                setItemState(runtime, item, 'failed', removal ? 'Could not remove photo.' : 'Upload failed. Retry.');
                return;
            }
            setAcceptUntil(runtime, result.payload[uploadResponseName('acceptUntil')]);
            if (result.payload[uploadResponseName('state')] === 'finalizing') {
                freezeForSubmit(runtime);
                setItemState(runtime, item, 'failed', 'Photos are being submitted.');
                return;
            }
            var found = result.payload[itemsName].find(function (serverItem) {
                return serverItem && serverItem[uploadResponseName('uploadId')] === item.id;
            });
            var pending = result.payload[intentsName].find(function (serverIntent) {
                return serverIntent && serverIntent[uploadResponseName('uploadId')] === item.id;
            });
            if (removal) {
                if (found || pending) {
                    setItemState(runtime, item, 'failed', 'Could not remove photo.');
                } else {
                    finishLocalRemoval(runtime, item);
                }
                return;
            }
            var displayName = found && found[uploadResponseName('displayName')];
            if (found && validServerDisplayName(displayName)) {
                item.name = displayName;
                item.progress = 100;
                setItemState(runtime, item, 'uploaded');
                return;
            }
            if (retireIfAbsent && !pending) {
                var replacementId = randomId(uploadRuntimeValue('uploadIdBytes'));
                if (replacementId) {
                    item.id = replacementId;
                    setItemState(runtime, item, 'failed', 'Upload expired. Retry.');
                    return;
                }
                item.file = null;
                setItemState(runtime, item, 'failed', 'Remove and select this photo again.');
                return;
            }
            setItemState(runtime, item, 'failed', 'Upload failed. Retry.');
        }).catch(function () {
            if (runtime.destroyed || item.controlRequest !== request) {
                return;
            }
            setItemState(runtime, item, 'failed', removal ? 'Could not remove photo.' : 'Upload failed. Retry.');
        }).then(function () {
            clearControlFetch(item, request);
        });
    }

    function reconcileStartingUpload(runtime, item, retireIfAbsent) {
        var operation = reconcileItem(runtime, item, false, retireIfAbsent);
        var release = function () {
            scheduleUploads(runtime);
        };
        return operation.then(release, release);
    }

    function reconcileActiveUpload(runtime, item) {
        var operation = reconcileItem(runtime, item);
        var release = function () {
            scheduleUploads(runtime);
        };
        return operation.then(release, release);
    }

    function beginTransferFetch(item) {
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        item.transferController = controller;
        return controller;
    }

    function clearTransferFetch(item, controller) {
        if (item.transferController === controller) {
            item.transferController = null;
        }
    }

    function abortTransferFetch(runtime, item) {
        var controller = item.transferController;
        item.transferController = null;
        if (controller) {
            controller.abort();
        }
        scheduleUploads(runtime);
    }

    function acceptCommittedItem(runtime, item, payload) {
        var displayName = payload && payload[uploadResponseName('displayName')];
        if (!validServerDisplayName(displayName)) {
            setItemState(runtime, item, 'failed', 'Upload rejected. Retry or remove.');
            return false;
        }
        item.name = displayName;
        item.progress = 100;
        setItemState(runtime, item, 'uploaded');
        return true;
    }

    function beginMultipartUpload(runtime, item) {
        if (runtime.frozen || runtime.expired || runtime.unavailable || runtime.destroyed || item.state !== 'authorizing') {
            scheduleUploads(runtime);
            return;
        }
        var xhr = new XMLHttpRequest();
        item.xhr = xhr;
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
                setItemState(runtime, item, 'securing');
                scheduleUploads(runtime);
            } else {
                renderItem(runtime, item);
            }
        };
        xhr.upload.onload = function () {
            if (item.state === 'uploading') {
                item.progress = 100;
                setItemState(runtime, item, 'securing');
                scheduleUploads(runtime);
            }
        };
        xhr.onload = function () {
            if (item.state === 'removed' || item.state === 'removing') {
                scheduleUploads(runtime);
                return;
            }
            if (xhr.status === 200) {
                var payload = null;
                try {
                    payload = JSON.parse(xhr.responseText);
                } catch (error) {
                    payload = null;
                }
                acceptCommittedItem(runtime, item, payload);
                scheduleUploads(runtime);
                return;
            }
            if (xhr.status === 410) {
                unavailableRuntime(runtime);
                setItemState(runtime, item, 'failed', 'Upload unavailable. Reload the form.');
                scheduleUploads(runtime);
                return;
            }
            if (xhr.status === 0 || xhr.status === 409 || xhr.status >= 500) {
                reconcileActiveUpload(runtime, item);
                return;
            }
            setItemState(runtime, item, 'failed', 'Upload rejected. Retry or remove.');
            scheduleUploads(runtime);
        };
        xhr.onerror = function () {
            if (item.state !== 'removed' && item.state !== 'removing') {
                reconcileActiveUpload(runtime, item);
            } else {
                scheduleUploads(runtime);
            }
        };
        xhr.onabort = function () {
            scheduleUploads(runtime);
        };
        var data = new FormData();
        var declaredMime = uploadMime(item);
        var multipartFile = item.file;
        if (declaredMime && item.file.type !== declaredMime && typeof item.file.slice === 'function') {
            multipartFile = item.file.slice(0, item.file.size, declaredMime);
        }
        data.append(uploadName('fileParam'), multipartFile, item.name);
        data.append(uploadName('ordinalParam'), String(item.ordinal));
        xhr.send(data);
    }

    function registerWorkerStoredReceipt(runtime, item, receipt) {
        var body = encodeURIComponent(uploadName('receiptParam')) + '=' + encodeURIComponent(receipt);
        var headers = uploadHeaders(runtime);
        headers['Content-Type'] = 'application/x-www-form-urlencoded';
        var controller = beginTransferFetch(item);
        var options = {
            method: 'POST',
            headers: headers,
            body: body,
            credentials: 'same-origin'
        };
        if (controller) {
            options.signal = controller.signal;
        }
        fetch(managedUrl('/' + encodeURIComponent(runtime.batchId) + '/items/' + encodeURIComponent(item.id)), options).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (payload) {
                return { status: response.status, payload: payload };
            });
        }).then(function (result) {
            clearTransferFetch(item, controller);
            if (item.state === 'removed' || item.state === 'removing' || runtime.destroyed) {
                scheduleUploads(runtime);
                return;
            }
            if (result.status === 200) {
                acceptCommittedItem(runtime, item, result.payload);
                scheduleUploads(runtime);
                return;
            }
            if (result.status === 410) {
                unavailableRuntime(runtime);
                setItemState(runtime, item, 'failed', 'Upload unavailable. Reload the form.');
                scheduleUploads(runtime);
                return;
            }
            if (result.status === 0 || result.status === 409 || result.status >= 500) {
                reconcileActiveUpload(runtime, item);
                return;
            }
            setItemState(runtime, item, 'failed', 'Upload rejected. Retry or remove.');
            scheduleUploads(runtime);
        }).catch(function () {
            clearTransferFetch(item, controller);
            if (item.state !== 'removed' && item.state !== 'removing') {
                reconcileActiveUpload(runtime, item);
            } else {
                scheduleUploads(runtime);
            }
        });
    }

    function beginWorkerUpload(runtime, item, transport) {
        if (runtime.frozen || runtime.expired || runtime.unavailable || runtime.destroyed || item.state !== 'authorizing') {
            scheduleUploads(runtime);
            return;
        }
        var xhr = new XMLHttpRequest();
        item.xhr = xhr;
        item.progress = 0;
        setItemState(runtime, item, 'uploading');
        xhr.open('PUT', transport[uploadResponseName('transportUrl')], true);
        xhr.withCredentials = false;
        xhr.setRequestHeader(uploadName('workerGrantHeader'), transport[uploadResponseName('transportGrant')]);
        xhr.setRequestHeader('Content-Type', transport[uploadResponseName('transportMime')]);
        xhr.upload.onprogress = function (event) {
            if (item.state !== 'uploading' || !event.lengthComputable) {
                return;
            }
            item.progress = Math.max(0, Math.min(100, Math.floor((event.loaded / event.total) * 100)));
            if (item.progress >= 100) {
                setItemState(runtime, item, 'securing');
                scheduleUploads(runtime);
            } else {
                renderItem(runtime, item);
            }
        };
        xhr.upload.onload = function () {
            if (item.state === 'uploading') {
                item.progress = 100;
                setItemState(runtime, item, 'securing');
                scheduleUploads(runtime);
            }
        };
        xhr.onload = function () {
            if (item.state === 'removed' || item.state === 'removing' || runtime.destroyed) {
                scheduleUploads(runtime);
                return;
            }
            if (xhr.status === 200) {
                var payload = null;
                try {
                    payload = JSON.parse(xhr.responseText);
                } catch (error) {
                    payload = null;
                }
                var receipt = payload && payload[uploadName('receiptParam')];
                if (typeof receipt === 'string' && receipt !== '') {
                    setItemState(runtime, item, 'registering');
                    registerWorkerStoredReceipt(runtime, item, receipt);
                    return;
                }
            }
            if (xhr.status === 0 || xhr.status === 409 || xhr.status >= 500) {
                reconcileActiveUpload(runtime, item);
                return;
            }
            setItemState(runtime, item, 'failed', 'Upload rejected. Retry or remove.');
            scheduleUploads(runtime);
        };
        xhr.onerror = function () {
            if (item.state !== 'removed' && item.state !== 'removing') {
                reconcileActiveUpload(runtime, item);
            } else {
                scheduleUploads(runtime);
            }
        };
        xhr.onabort = function () {
            scheduleUploads(runtime);
        };
        xhr.send(item.file);
    }

    function validWorkerTransport(transport, item) {
        var declaredMime = uploadMime(item);
        if (!transport || transport[uploadResponseName('transportKind')] !== uploadName('workerTransport')
            || typeof transport[uploadResponseName('transportUrl')] !== 'string'
            || typeof transport[uploadResponseName('transportGrant')] !== 'string'
            || typeof transport[uploadResponseName('transportMime')] !== 'string'
            || transport[uploadResponseName('transportGrant')] === ''
            || declaredMime === ''
            || transport[uploadResponseName('transportMime')] !== declaredMime) {
            return false;
        }
        try {
            var transportUrl = new URL(transport[uploadResponseName('transportUrl')]);
            return transportUrl.protocol === 'https:' && transportUrl.origin !== window.location.origin;
        } catch (error) {
            return false;
        }
    }

    function authorizeUpload(runtime, item) {
        var body = [];
        body.push(encodeURIComponent(uploadName('ordinalParam')) + '=' + encodeURIComponent(String(item.ordinal)));
        body.push(encodeURIComponent(uploadName('displayNameParam')) + '=' + encodeURIComponent(item.name));
        body.push(encodeURIComponent(uploadName('bytesParam')) + '=' + encodeURIComponent(String(item.bytes)));
        body.push(encodeURIComponent(uploadName('mimeParam')) + '=' + encodeURIComponent(uploadMime(item)));
        var headers = uploadHeaders(runtime);
        headers['Content-Type'] = 'application/x-www-form-urlencoded';
        var controller = beginTransferFetch(item);
        var options = {
            method: 'POST',
            headers: headers,
            body: body.join('&'),
            credentials: 'same-origin'
        };
        if (controller) {
            options.signal = controller.signal;
        }
        fetch(managedUrl('/' + encodeURIComponent(runtime.batchId) + '/items/' + encodeURIComponent(item.id)), options).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (payload) {
                return { status: response.status, payload: payload };
            });
        }).then(function (result) {
            clearTransferFetch(item, controller);
            if (item.state === 'removed' || item.state === 'removing' || runtime.destroyed) {
                scheduleUploads(runtime);
                return;
            }
            if (result.status === 410) {
                unavailableRuntime(runtime);
                setItemState(runtime, item, 'failed', 'Upload unavailable. Reload the form.');
                scheduleUploads(runtime);
                return;
            }
            if (result.status === 200 && result.payload && result.payload[uploadResponseName('authorized')] === true) {
                if (result.payload[uploadResponseName('committed')] === true) {
                    acceptCommittedItem(runtime, item, result.payload);
                    scheduleUploads(runtime);
                } else {
                    var transport = result.payload[uploadResponseName('transport')];
                    var transportKind = transport && transport[uploadResponseName('transportKind')];
                    if (transportKind === uploadName('localTransport')) {
                        item.transportKind = transportKind;
                        beginMultipartUpload(runtime, item);
                    } else if (validWorkerTransport(transport, item)) {
                        item.transportKind = transportKind;
                        beginWorkerUpload(runtime, item, transport);
                    } else {
                        setItemState(runtime, item, 'failed', 'Upload failed. Retry.');
                        scheduleUploads(runtime);
                    }
                }
                return;
            }
            if (result.status === 409 || result.status >= 500) {
                setItemState(runtime, item, 'securing');
                scheduleUploads(runtime);
                reconcileStartingUpload(runtime, item, result.status === 409);
            } else {
                setItemState(runtime, item, 'failed', 'Upload rejected. Retry or remove.');
                scheduleUploads(runtime);
            }
        }).catch(function () {
            clearTransferFetch(item, controller);
            if (item.state !== 'removed' && item.state !== 'removing') {
                setItemState(runtime, item, 'securing');
                scheduleUploads(runtime);
                reconcileStartingUpload(runtime, item);
            } else {
                scheduleUploads(runtime);
            }
        });
    }

    function startUpload(runtime, item) {
        if (runtime.frozen || runtime.expired || runtime.unavailable || runtime.destroyed || !item.artifactChosen || !item.file || item.state !== 'queued') {
            return;
        }
        item.transportKind = '';
        setItemState(runtime, item, 'authorizing');
        ensureBatch(runtime, function (ok) {
            if (!ok || runtime.expired || runtime.unavailable || item.state !== 'authorizing') {
                if (item.state === 'authorizing') {
                    setItemState(runtime, item, 'failed', 'Upload could not start. Retry.');
                }
                scheduleUploads(runtime);
                return;
            }
            if (item.bytes > runtime.maxFileBytes) {
                setItemState(runtime, item, 'failed', 'Upload rejected. Retry or remove.');
                scheduleUploads(runtime);
                return;
            }
            authorizeUpload(runtime, item);
        });
    }

    function scheduleUploads(runtime) {
        if (runtime.clearing || runtime.frozen || runtime.expired || runtime.unavailable || runtime.destroyed) {
            return;
        }
        for (var i = 0; i < runtime.items.length && uploadPermitsAvailable(runtime); i += 1) {
            if (runtime.items[i].state === 'queued' && runtime.items[i].artifactChosen) {
                startUpload(runtime, runtime.items[i]);
            }
        }
    }

    function retryItem(runtime, item) {
        if (runtime.frozen || runtime.expired || runtime.unavailable || runtime.destroyed || item.state !== 'failed' || (!item.file && !item.sourceFile)) {
            return;
        }
        item.progress = 0;
        if (!item.artifactChosen && item.file) {
            chooseArtifact(runtime, item, item.file, Boolean(item.sourceFile));
            return;
        }
        if (!item.artifactChosen && item.sourceFile) {
            setItemState(runtime, item, 'queued');
            queuePreparation(runtime, item);
            return;
        }
        setItemState(runtime, item, 'queued');
        scheduleUploads(runtime);
    }

    function retireItem(runtime, item) {
        var index = runtime.items.indexOf(item);
        abortPreparation(item);
        item.state = 'removed';
        if (item.objectUrl) {
            URL.revokeObjectURL(item.objectUrl);
            item.objectUrl = '';
        }
        if (item.card && item.card.parentNode) {
            item.card.parentNode.removeChild(item.card);
        }
        item.previewRequest += 1;
        item.file = null;
        item.sourceFile = null;
        item.xhr = null;
        item.transferController = null;
        item.controlRequest = null;
        item.card = null;
        item.image = null;
        item.nameNode = null;
        item.progressNode = null;
        item.statusNode = null;
        item.actionsNode = null;
        item.retryButton = null;
        item.removeButton = null;
        if (index !== -1) {
            runtime.items.splice(index, 1);
        }
    }

    function finishLocalRemoval(runtime, item) {
        var name = item.name;
        retireItem(runtime, item);
        fieldAnnouncement(runtime, name + ': removed');
        updateFormUploadState(runtime.form);
    }

    function releaseRemovalSlot(runtime, item) {
        if (!item.removalInFlight) {
            return;
        }
        item.removalInFlight = false;
        runtime.removalActive = false;
        scheduleRemovals(runtime);
    }

    function beginRemoval(runtime, item) {
        item.removalInFlight = true;
        runtime.removalActive = true;
        var request = beginControlFetch(item);
        var options = {
            method: 'DELETE',
            headers: uploadHeaders(runtime),
            credentials: 'same-origin'
        };
        if (request.controller) {
            options.signal = request.controller.signal;
        }
        var operation = fetch(managedUrl('/' + encodeURIComponent(runtime.batchId) + '/items/' + encodeURIComponent(item.id)), options).then(function (response) {
            if (runtime.destroyed || item.controlRequest !== request) {
                return;
            }
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
                return reconcileItem(runtime, item, true);
            }
            setItemState(runtime, item, 'failed', 'Could not remove photo.');
        }).catch(function () {
            if (runtime.destroyed || item.controlRequest !== request) {
                return;
            }
            return reconcileItem(runtime, item, true);
        });
        operation.then(function () {
            clearControlFetch(item, request);
            releaseRemovalSlot(runtime, item);
        }, function () {
            clearControlFetch(item, request);
            releaseRemovalSlot(runtime, item);
        });
    }

    function scheduleRemovals(runtime) {
        if (runtime.clearing || runtime.removalActive || runtime.frozen || runtime.unavailable || runtime.destroyed) {
            return;
        }
        for (var i = 0; i < runtime.items.length; i += 1) {
            if (runtime.items[i].state === 'removing' && !runtime.items[i].removalInFlight) {
                beginRemoval(runtime, runtime.items[i]);
                return;
            }
        }
    }

    function removeItem(runtime, item) {
        if (runtime.frozen || runtime.unavailable || runtime.destroyed || item.state === 'removed' || item.state === 'removing') {
            return;
        }
        var hasServerState = item.artifactChosen || item.controlRequest;
        var abortBody = item.xhr && (item.state === 'uploading' || item.state === 'securing');
        setItemState(runtime, item, 'removing');
        if (abortBody) {
            item.xhr.abort();
        }
        abortPreparation(item);
        abortTransferFetch(runtime, item);
        if (!runtime.batchId || !hasServerState) {
            finishLocalRemoval(runtime, item);
            return;
        }
        scheduleRemovals(runtime);
    }

    function addFiles(runtime, files) {
        if (restoreBlocked(runtime) || runtime.frozen || runtime.expired || runtime.unavailable || runtime.destroyed) {
            return;
        }
        var preparation = clientPreparationSettings();
        for (var i = 0; i < files.length; i += 1) {
            var file = files[i];
            var extension = fileExtension(file.name);
            var canScreenJpeg = preparation
                && (extension === 'jpg' || extension === 'jpeg')
                && file.size <= preparation.recipe.inputMaxBytes;
            if (runtime.items.length >= runtime.maxFiles
                || runtime.acceptedExtensions.indexOf(extension) === -1
                || (!canScreenJpeg && !artifactFits(runtime, null, file.size))) {
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
                file: canScreenJpeg ? null : file,
                sourceFile: canScreenJpeg ? file : null,
                name: safeFileName(file.name),
                bytes: file.size,
                artifactChosen: !canScreenJpeg,
                objectUrl: URL.createObjectURL(file),
                previewUnavailable: false,
                state: 'queued',
                progress: 0,
                error: '',
                xhr: null,
                transferController: null,
                controlRequest: null,
                transportKind: '',
                removalInFlight: false,
                preparationAttempt: 0,
                previewRequest: 0
            };
            runtime.nextOrdinal += 1;
            runtime.items.push(item);
            renderItem(runtime, item);
            if (canScreenJpeg) {
                queuePreparation(runtime, item);
            }
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
        var pending = payload ? payload[uploadResponseName('intents')] : null;
        if (!Array.isArray(source) || !Array.isArray(pending)) {
            return null;
        }
        var seen = Object.create(null);
        var seenOrdinals = Object.create(null);
        var items = [];
        function append(serverItem, state) {
            var id = serverItem && serverItem[uploadResponseName('uploadId')];
            var ordinal = serverItem && serverItem[uploadResponseName('ordinal')];
            var displayName = serverItem && serverItem[uploadResponseName('displayName')];
            var bytes = serverItem && serverItem[uploadResponseName('bytes')];
            if (!validManagedId(id, uploadRuntimeValue('uploadIdMaxChars'), false) || seen[id] || !Number.isInteger(ordinal) || ordinal < 0 || seenOrdinals[ordinal] || !validServerDisplayName(displayName) || !Number.isInteger(bytes) || bytes < 0) {
                return false;
            }
            seen[id] = true;
            seenOrdinals[ordinal] = true;
            items.push({
                id: id,
                ordinal: ordinal,
                file: null,
                sourceFile: null,
                name: displayName,
                bytes: bytes,
                artifactChosen: true,
                objectUrl: '',
                previewUnavailable: true,
                state: state,
                progress: state === 'uploaded' ? 100 : 0,
                error: state === 'uploaded' ? '' : 'Remove and select this photo again.',
                xhr: null,
                transferController: null,
                controlRequest: null,
                transportKind: '',
                removalInFlight: false,
                preparationAttempt: 0,
                previewRequest: 0
            });
            return true;
        }
        for (var i = 0; i < source.length; i += 1) {
            if (!append(source[i], 'uploaded')) {
                return null;
            }
        }
        for (var pendingIndex = 0; pendingIndex < pending.length; pendingIndex += 1) {
            if (!append(pending[pendingIndex], 'failed')) {
                return null;
            }
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
        runtime.chooseButton.textContent = 'Browse photos';
        runtime.fieldStatus.textContent = 'Restoring uploaded photos\u2026';
        updateFormUploadState(runtime.form);
        var request = beginRuntimeFetch(runtime);
        var options = {
            method: 'GET',
            headers: uploadHeaders(runtime),
            credentials: 'same-origin'
        };
        if (request.controller) {
            options.signal = request.controller.signal;
        }
        fetch(managedUrl('/' + encodeURIComponent(runtime.batchId)), options).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (payload) {
                return { status: response.status, payload: payload };
            });
        }).then(function (result) {
            if (runtime.runtimeRequest !== request) {
                return;
            }
            clearRuntimeFetch(runtime, request);
            var payload = result.payload;
            var state = payload && payload[uploadResponseName('state')];
            var acceptUntil = payload && payload[uploadResponseName('acceptUntil')];
            var deleteAfter = payload && payload[uploadResponseName('deleteAfter')];
            var recovering = runtime.rerenderRestore && state === 'finalizing';
            var limitsValid = result.status === 200 && (state === 'open' || recovering) && applyServerLimits(runtime, payload);
            var items = limitsValid ? restoredItems(payload) : null;
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
            if (recovering) {
                setRecoveryUntil(runtime, deleteAfter);
            } else {
                setAcceptUntil(runtime, acceptUntil);
            }
            forEachNode(runtime.items, function (item) {
                runtime.nextOrdinal = Math.max(runtime.nextOrdinal, item.ordinal + 1);
                if (!recovering) {
                    renderItem(runtime, item);
                }
            });
            runtime.restoreState = 'ready';
            runtime.mount.removeAttribute('data-eforms-upload-restoring');
            if (recovering) {
                freezeForSubmit(runtime);
            } else {
                runtime.picker.disabled = false;
                runtime.chooseButton.disabled = false;
            }
            runtime.fieldStatus.textContent = recovering ? 'Uploaded photos restored for corrected submission.' : '';
            fieldAnnouncement(runtime, recovering ? runtime.fieldStatus.textContent : 'Uploaded photos restored');
            if (!recovering) {
                updateFormUploadState(runtime.form);
            }
        }).catch(function () {
            if (runtime.runtimeRequest !== request) {
                return;
            }
            clearRuntimeFetch(runtime, request);
            retryBatchRestore(runtime);
        });
    }

    function uploadSizeLabel(bytes) {
        var megabytes = bytes / (1024 * 1024);
        var rounded = Math.round(megabytes * 10) / 10;
        return rounded + ' MB';
    }

    function uploadFormatLabel(extension) {
        if (extension === 'jpg' || extension === 'jpeg') {
            return 'JPG';
        }
        if (extension === 'webp') {
            return 'WebP';
        }
        return extension.toUpperCase();
    }

    function uploadFormats(runtime) {
        var formats = [];
        runtime.acceptedExtensions.forEach(function (extension) {
            var label = uploadFormatLabel(extension);
            if (formats.indexOf(label) === -1) {
                formats.push(label);
            }
        });
        return formats.join(', ');
    }

    function buildUploadIcon() {
        var namespace = 'http://www.w3.org/2000/svg';
        var icon = document.createElement('span');
        var svg = document.createElementNS(namespace, 'svg');
        var cloud = document.createElementNS(namespace, 'path');
        var arrow = document.createElementNS(namespace, 'path');
        icon.className = 'eforms-upload-icon';
        icon.setAttribute('aria-hidden', 'true');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('focusable', 'false');
        cloud.setAttribute('d', 'M7.5 18h9a4.5 4.5 0 0 0 .65-8.95A6 6 0 0 0 5.7 10.7 3.75 3.75 0 0 0 7.5 18Z');
        arrow.setAttribute('d', 'M12 16V9m0 0-3 3m3-3 3 3');
        svg.appendChild(cloud);
        svg.appendChild(arrow);
        icon.appendChild(svg);
        return icon;
    }

    function buildUploadMount(runtime) {
        var controls = document.createElement('div');
        controls.className = 'eforms-upload-controls';
        controls.appendChild(buildUploadIcon());
        var dropHint = document.createElement('span');
        dropHint.className = 'eforms-upload-drop-hint';
        dropHint.textContent = 'Drag and drop photos here';
        controls.appendChild(dropHint);
        runtime.chooseButton = document.createElement('button');
        runtime.chooseButton.type = 'button';
        runtime.chooseButton.className = 'eforms-upload-choose';
        runtime.chooseButton.textContent = 'Browse photos';
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
        runtime.mount.appendChild(controls);

        var guidance = document.createElement('div');
        guidance.className = 'eforms-upload-guidance';
        var formats = document.createElement('span');
        formats.className = 'eforms-upload-formats';
        formats.textContent = 'Supported formats: ' + uploadFormats(runtime);
        guidance.appendChild(formats);
        runtime.limitsStatus = document.createElement('span');
        runtime.limitsStatus.className = 'eforms-upload-limits';
        guidance.appendChild(runtime.limitsStatus);
        updateLimitLabel(runtime);
        runtime.mount.appendChild(guidance);

        var meta = document.createElement('div');
        meta.className = 'eforms-upload-meta';
        runtime.countStatus = document.createElement('p');
        runtime.countStatus.className = 'eforms-upload-count';
        meta.appendChild(runtime.countStatus);
        runtime.clearButton = document.createElement('button');
        runtime.clearButton.type = 'button';
        runtime.clearButton.className = 'eforms-upload-clear';
        runtime.clearButton.textContent = 'Clear all';
        runtime.clearButton.hidden = true;
        runtime.clearButton.addEventListener('click', function () {
            runtime.clearing = true;
            try {
                forEachNode(runtime.items.slice(), function (item) {
                    removeItem(runtime, item);
                });
            } finally {
                runtime.clearing = false;
            }
            scheduleRemovals(runtime);
        });
        meta.appendChild(runtime.clearButton);
        runtime.mount.appendChild(meta);

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
        abortRuntimePreparation(runtime);
        runtime.restoreState = 'terminal';
        runtime.secret = '';
        abortRuntimeFetch(runtime);
        runtime.createPending = false;
        runtime.createCallbacks = [];
        window.clearTimeout(runtime.expiryTimer);
        runtime.expiryTimer = null;
        runtime.picker.disabled = true;
        runtime.mount.removeAttribute('data-eforms-upload-restoring');
        runtime.mount.removeAttribute('data-eforms-upload-restore-failed');
        forEachNode(runtime.items.slice(), function (item) {
            if (item.xhr) {
                item.xhr.abort();
            }
            abortTransferFetch(runtime, item);
            abortControlFetch(item);
            retireItem(runtime, item);
        });
        runtime.items = [];
        forEachNode(runtime.hiddenInputs, function (input) {
            if (input && input.parentNode) {
                input.parentNode.removeChild(input);
            }
        });
        runtime.hiddenInputs = [];
    }

    function freezeForSubmit(runtime) {
        runtime.frozen = true;
        abortRuntimePreparation(runtime);
        runtime.picker.value = '';
        runtime.picker.disabled = true;
        runtime.chooseButton.disabled = true;
        runtime.clearButton.disabled = true;
        runtime.mount.setAttribute('data-eforms-upload-frozen', '1');
        forEachNode(runtime.items, function (item) {
            renderItem(runtime, item);
        });
        updateFormUploadState(runtime.form);
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
                required: picker.hasAttribute('required'),
                requiredPrompted: false,
                items: [],
                nextOrdinal: 0,
                clearing: false,
                removalActive: false,
                batchId: '',
                secret: '',
                hiddenInputs: [],
                createPending: false,
                createCallbacks: [],
                runtimeRequest: null,
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
                if (!blocked && requiredUploadMissing(runtime)) {
                    runtime.requiredPrompted = true;
                    runtime.fieldStatus.textContent = 'Add at least one photo.';
                    fieldAnnouncement(runtime, runtime.fieldStatus.textContent);
                    blocked = runtime;
                }
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
            bindFriendlyInputs(form);
            addClientValidation(form);
            initializeStagedUploads(form);
            if (getFormMode(form) === 'js') {
                handleJsMintedForm(form);
            }
            addEnhancedSubmitHandler(form);
            addSubmitLock(form);
        });
        observeUploadTeardown();
    });

    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) {
            return;
        }
        forEachNode(document.querySelectorAll('form.eforms-form[data-eforms-enhanced-pending="1"], form.eforms-form[data-eforms-enhanced-navigating="1"]'), function (form) {
            clearEnhancedPendingForNavigation(form, true);
        });
    });
})();
