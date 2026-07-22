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

    function setPreviewLinkEnabled(image, enabled) {
        var link = previewLink(image);
        if (!link) {
            return;
        }
        if (enabled) {
            link.setAttribute('href', image.__eformsReviewSrc);
            link.setAttribute('data-lbwps-srcsmall', image.__eformsReviewSrc);
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
    }

    function setAvailable(image) {
        var fallback = previewFallback(image);
        var retry = fallback ? fallback.querySelector('[data-eforms-review-retry]') : null;
        image.hidden = false;
        image.setAttribute('alt', image.__eformsReviewAlt);
        setPreviewLinkEnabled(image, true);
        if (fallback) {
            fallback.hidden = true;
            fallback.setAttribute('aria-hidden', 'true');
        }
        if (retry) {
            retry.disabled = false;
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
        if (retry) {
            retry.disabled = true;
        }
        image.__eformsReviewQueued = true;
        previewQueue.push(image);
        pumpPreviews();
    }

    function bindPreview(image) {
        var fallback = previewFallback(image);
        var retry = fallback ? fallback.querySelector('[data-eforms-review-retry]') : null;
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
            finishPreview(image, false);
        });
        image.addEventListener('load', function () {
            finishPreview(image, true);
        });
        if (retry) {
            retry.addEventListener('click', function () {
                requestPreview(image);
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
}());
