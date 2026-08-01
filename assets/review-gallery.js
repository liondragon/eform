(function () {
    'use strict';

    var previewQueue = [];
    var activePreview = null;

    function previewShell(image) {
        return image && typeof image.closest === 'function' ? image.closest('.eforms-review-preview') : null;
    }

    function previewFallback(image) {
        var shell = previewShell(image);
        return shell ? shell.querySelector('[data-eforms-review-fallback]') : null;
    }

    function previewLink(image) {
        return image && typeof image.closest === 'function' ? image.closest('a') : null;
    }

    function fallbackStatus(image) {
        var fallback = previewFallback(image);
        return fallback ? fallback.querySelector('[data-eforms-review-fallback-status]') : null;
    }

    function originalButton(image) {
        var fallback = previewFallback(image);
        return fallback ? fallback.querySelector('[data-eforms-review-original]') : null;
    }

    function setPreviewLinkEnabled(image, enabled, displayWidth, displayHeight) {
        var link = previewLink(image);
        if (!link) {
            return;
        }
        if (enabled) {
            var width = displayWidth > 0 ? displayWidth : image.naturalWidth;
            var height = displayHeight > 0 ? displayHeight : image.naturalHeight;
            link.setAttribute('href', image.__eformsReviewDisplaySrc);
            link.setAttribute('data-lbwps-srcsmall', image.__eformsReviewDisplaySrc);
            if (width > 0 && height > 0) {
                link.setAttribute('data-lbwps-width', String(width));
                link.setAttribute('data-lbwps-height', String(height));
            }
            link.removeAttribute('aria-disabled');
            if (image.__eformsReviewLinkTabindex === null) {
                link.removeAttribute('tabindex');
            } else {
                link.setAttribute('tabindex', image.__eformsReviewLinkTabindex);
            }
            return;
        }
        link.removeAttribute('href');
        link.removeAttribute('data-lbwps-srcsmall');
        link.setAttribute('aria-disabled', 'true');
        link.setAttribute('tabindex', '-1');
    }

    function setUnavailable(image) {
        var fallback = previewFallback(image);
        var retry = fallback ? fallback.querySelector('[data-eforms-review-retry]') : null;
        var original = originalButton(image);
        var status = fallbackStatus(image);
        image.hidden = true;
        image.removeAttribute('alt');
        setPreviewLinkEnabled(image, false);
        if (fallback) {
            fallback.hidden = false;
            fallback.removeAttribute('aria-hidden');
        }
        if (retry) {
            retry.disabled = false;
        }
        if (original) {
            original.disabled = false;
        }
        if (status) {
            status.textContent = 'Preview unavailable';
        }
    }

    function setAvailable(image, displayWidth, displayHeight) {
        var fallback = previewFallback(image);
        var retry = fallback ? fallback.querySelector('[data-eforms-review-retry]') : null;
        var original = originalButton(image);
        image.hidden = false;
        image.setAttribute('alt', image.__eformsReviewDisplayAlt || image.__eformsReviewAlt);
        setPreviewLinkEnabled(image, true, displayWidth, displayHeight);
        if (fallback) {
            fallback.hidden = true;
            fallback.setAttribute('aria-hidden', 'true');
        }
        if (retry) {
            retry.disabled = false;
        }
        if (original) {
            original.disabled = false;
        }
    }

    function finishPreview(image, available) {
        if (!image.__eformsReviewLoading) {
            return;
        }
        window.clearTimeout(image.__eformsReviewTimer);
        image.__eformsReviewTimer = null;
        image.__eformsReviewLoading = false;
        if (activePreview === image) {
            activePreview = null;
        }
        if (available) {
            image.__eformsReviewDisplaySrc = image.__eformsReviewSrc;
            image.__eformsReviewDisplayAlt = image.__eformsReviewAlt;
            setAvailable(image);
        } else {
            setUnavailable(image);
        }
        pumpPreviews();
    }

    function pumpPreviews() {
        if (activePreview || previewQueue.length === 0) {
            return;
        }
        var image = previewQueue.shift();
        image.__eformsReviewQueued = false;
        image.__eformsReviewLoading = true;
        activePreview = image;
        image.__eformsReviewDisplaySrc = image.__eformsReviewSrc;
        setPreviewLinkEnabled(image, false);
        image.hidden = false;
        image.setAttribute('alt', image.__eformsReviewAlt);
        image.removeAttribute('src');
        if (!image.__eformsReviewTimeoutMs) {
            finishPreview(image, false);
            return;
        }
        window.requestAnimationFrame(function () {
            if (activePreview === image && image.__eformsReviewLoading) {
                image.__eformsReviewTimer = window.setTimeout(function () {
                    if (activePreview === image && image.__eformsReviewLoading) {
                        image.removeAttribute('src');
                        finishPreview(image, false);
                    }
                }, image.__eformsReviewTimeoutMs);
                image.setAttribute('src', image.__eformsReviewSrc);
            }
        });
    }

    function requestPreview(image) {
        if (!image.__eformsReviewSrc || image.__eformsReviewQueued || image.__eformsReviewLoading) {
            return;
        }
        var fallback = previewFallback(image);
        var retry = fallback ? fallback.querySelector('[data-eforms-review-retry]') : null;
        var original = originalButton(image);
        if (retry) {
            retry.disabled = true;
        }
        if (original) {
            original.disabled = true;
        }
        image.__eformsReviewQueued = true;
        previewQueue.push(image);
        pumpPreviews();
    }

    function finishOriginal(image, available, attempt, displayWidth, displayHeight) {
        if (!image.__eformsReviewOriginalLoading || image.__eformsReviewOriginalAttempt !== attempt) {
            return;
        }
        window.clearTimeout(image.__eformsReviewOriginalTimer);
        image.__eformsReviewOriginalTimer = null;
        image.__eformsReviewOriginalLoading = false;
        image.__eformsReviewOriginalController = null;
        image.__eformsReviewOriginalAttempt = null;
        if (image.__eformsReviewOriginalLoader) {
            image.__eformsReviewOriginalLoader.onload = null;
            image.__eformsReviewOriginalLoader.onerror = null;
            image.__eformsReviewOriginalLoader = null;
        }
        if (available) {
            setAvailable(image, displayWidth, displayHeight);
        } else {
            if (image.__eformsReviewObjectUrl) {
                window.URL.revokeObjectURL(image.__eformsReviewObjectUrl);
                image.__eformsReviewObjectUrl = '';
            }
            setUnavailable(image);
        }
    }

    function requestOriginal(image) {
        var button = originalButton(image);
        var status = fallbackStatus(image);
        var source = button ? button.getAttribute('data-eforms-review-original-src') || '' : '';
        if (!source || image.__eformsReviewOriginalLoading || typeof window.fetch !== 'function' || !window.URL || typeof window.URL.createObjectURL !== 'function') {
            return;
        }
        var retry = previewFallback(image) ? previewFallback(image).querySelector('[data-eforms-review-retry]') : null;
        if (button) {
            button.disabled = true;
        }
        if (retry) {
            retry.disabled = true;
        }
        if (status) {
            status.textContent = 'Loading original...';
        }
        image.__eformsReviewOriginalLoading = true;
        var attempt = {};
        var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
        image.__eformsReviewOriginalAttempt = attempt;
        image.__eformsReviewOriginalController = controller;
        if (image.__eformsReviewTimeoutMs) {
            image.__eformsReviewOriginalTimer = window.setTimeout(function () {
                if (controller) {
                    controller.abort();
                }
                finishOriginal(image, false, attempt);
            }, image.__eformsReviewTimeoutMs);
        }
        window.fetch(source, {
            cache: 'no-store',
            credentials: 'same-origin',
            signal: controller ? controller.signal : undefined
        }).then(function (response) {
            if (!image.__eformsReviewOriginalLoading || image.__eformsReviewOriginalAttempt !== attempt || !response.ok) {
                throw new Error('original unavailable');
            }
            return response.blob();
        }).then(function (blob) {
            if (!image.__eformsReviewOriginalLoading || image.__eformsReviewOriginalAttempt !== attempt || !blob || typeof blob.type !== 'string' || blob.type.indexOf('image/') !== 0) {
                throw new Error('original is not an image');
            }
            window.clearTimeout(image.__eformsReviewOriginalTimer);
            image.__eformsReviewOriginalTimer = null;
            if (image.__eformsReviewObjectUrl) {
                window.URL.revokeObjectURL(image.__eformsReviewObjectUrl);
            }
            image.__eformsReviewObjectUrl = window.URL.createObjectURL(blob);
            image.__eformsReviewDisplaySrc = image.__eformsReviewObjectUrl;
            image.__eformsReviewDisplayAlt = image.__eformsReviewAlt.replace(/ preview$/i, '') + ' original';
            var loader = new window.Image();
            image.__eformsReviewOriginalLoader = loader;
            loader.onload = function () {
                if (!image.__eformsReviewOriginalLoading || image.__eformsReviewOriginalAttempt !== attempt || image.__eformsReviewOriginalLoader !== loader) {
                    return;
                }
                image.hidden = false;
                image.setAttribute('alt', image.__eformsReviewDisplayAlt);
                image.removeAttribute('src');
                image.setAttribute('src', image.__eformsReviewObjectUrl);
                finishOriginal(image, true, attempt, loader.naturalWidth, loader.naturalHeight);
            };
            loader.onerror = function () {
                finishOriginal(image, false, attempt);
            };
            loader.src = image.__eformsReviewObjectUrl;
        }).catch(function () {
            finishOriginal(image, false, attempt);
        });
    }

    function bindPreview(image) {
        var fallback = previewFallback(image);
        var retry = fallback ? fallback.querySelector('[data-eforms-review-retry]') : null;
        var original = originalButton(image);
        var gallery = image.closest('[data-eforms-review="gallery"]');
        var timeout = gallery ? parseInt(gallery.getAttribute('data-eforms-review-preview-timeout-ms'), 10) : 0;
        image.__eformsReviewAlt = image.getAttribute('alt') || 'Submitted image preview';
        image.__eformsReviewSrc = image.getAttribute('data-eforms-review-src') || '';
        var link = previewLink(image);
        image.__eformsReviewLinkTabindex = link ? link.getAttribute('tabindex') : null;
        image.__eformsReviewTimeoutMs = Number.isSafeInteger(timeout) && timeout > 0 ? timeout : 0;
        image.__eformsReviewTimer = null;
        image.__eformsReviewQueued = false;
        image.__eformsReviewLoading = false;
        image.__eformsReviewOriginalLoading = false;
        image.__eformsReviewOriginalTimer = null;
        image.__eformsReviewOriginalController = null;
        image.__eformsReviewOriginalAttempt = null;
        image.__eformsReviewOriginalLoader = null;
        image.__eformsReviewObjectUrl = '';
        image.__eformsReviewDisplaySrc = '';
        image.__eformsReviewDisplayAlt = image.__eformsReviewAlt;
        image.hidden = true;
        image.removeAttribute('src');
        setPreviewLinkEnabled(image, false);
        if (link) {
            link.addEventListener('click', function (event) {
                if (link.getAttribute('aria-disabled') === 'true') {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                }
            }, true);
        }
        image.addEventListener('error', function () {
            if (image.__eformsReviewOriginalLoading) {
                return;
            }
            finishPreview(image, false);
        });
        image.addEventListener('load', function () {
            if (image.__eformsReviewOriginalLoading) {
                return;
            }
            finishPreview(image, true);
        });
        if (retry) {
            retry.addEventListener('click', function () {
                requestPreview(image);
            });
        }
        if (original) {
            original.addEventListener('click', function () {
                requestOriginal(image);
            });
        }
        if (!image.__eformsReviewSrc) {
            setUnavailable(image);
            return;
        }
        requestPreview(image);
    }

    function bindPreviews() {
        var images = document.querySelectorAll('[data-eforms-review-preview]');
        for (var index = 0; index < images.length; index++) {
            bindPreview(images[index]);
        }
    }

    function releaseOriginalUrls() {
        var images = document.querySelectorAll('[data-eforms-review-preview]');
        for (var index = 0; index < images.length; index++) {
            var image = images[index];
            var controller = image.__eformsReviewOriginalController;
            var hadOriginalActivity = image.__eformsReviewOriginalLoading || image.__eformsReviewOriginalLoader || image.__eformsReviewObjectUrl;
            window.clearTimeout(image.__eformsReviewOriginalTimer);
            image.__eformsReviewOriginalTimer = null;
            image.__eformsReviewOriginalLoading = false;
            image.__eformsReviewOriginalAttempt = null;
            image.__eformsReviewOriginalController = null;
            if (image.__eformsReviewOriginalLoader) {
                image.__eformsReviewOriginalLoader.onload = null;
                image.__eformsReviewOriginalLoader.onerror = null;
                image.__eformsReviewOriginalLoader = null;
            }
            if (controller) {
                controller.abort();
            }
            if (image.__eformsReviewObjectUrl) {
                window.URL.revokeObjectURL(image.__eformsReviewObjectUrl);
                image.__eformsReviewObjectUrl = '';
            }
            if (hadOriginalActivity) {
                image.__eformsReviewDisplaySrc = '';
                image.__eformsReviewDisplayAlt = image.__eformsReviewAlt;
                setUnavailable(image);
            }
        }
    }

    function bindDialog(openSelector, dialogSelector, closeSelector) {
        var open = document.querySelector(openSelector);
        var dialog = document.querySelector(dialogSelector);
        if (!open || !dialog) {
            return;
        }
        var close = dialog.querySelector(closeSelector);
        open.addEventListener('click', function () {
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', 'open');
            }
        });
        if (close) {
            close.addEventListener('click', function () {
                if (typeof dialog.close === 'function') {
                    dialog.close();
                } else {
                    dialog.removeAttribute('open');
                }
            });
        }
    }

    function bindReviewGallery() {
        bindPreviews();
        bindDialog('[data-eforms-review-delete-open]', '[data-eforms-review-delete-dialog]', '[data-eforms-review-delete-close]');
        bindDialog('[data-eforms-review-availability-open]', '[data-eforms-review-availability-dialog]', '[data-eforms-review-availability-close]');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindReviewGallery);
    } else {
        bindReviewGallery();
    }
    window.addEventListener('pagehide', function (event) {
        if (!event.persisted) {
            releaseOriginalUrls();
        }
    });
}());
