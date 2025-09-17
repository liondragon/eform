/*!
 * Electronic Forms basic JS
 * - sets js_ok=1 on DOM ready
 * - (later) handles error-summary focus and submit lock
 */
(function () {
  function onReady(fn){ if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', fn); } else { fn(); } }
  function expireCookie(name, path) {
    if (!name) { return; }
    var parts = [name + '='];
    parts.push('expires=Thu, 01 Jan 1970 00:00:00 GMT');
    parts.push('path=' + (path || '/'));
    parts.push('SameSite=Lax');
    if (window.location && window.location.protocol === 'https:') {
      parts.push('Secure');
    }
    document.cookie = parts.join('; ');
  }
  function clearSuccessQuery(formId) {
    if (!formId || typeof URL !== 'function' || !window.history || typeof window.history.replaceState !== 'function') {
      return;
    }
    try {
      var url = new URL(window.location.href);
      if (url.searchParams.get('eforms_success') === formId) {
        url.searchParams.delete('eforms_success');
        window.history.replaceState(null, document.title, url.toString());
      }
    } catch (e) {
      /* noop */
    }
  }
  function handleSuccessPlaceholders() {
    var nodes = document.querySelectorAll('.eforms-success-pending');
    if (!nodes.length) { return; }
    nodes.forEach(function (node) {
      var formId = node.getAttribute('data-form-id');
      var submissionId = node.getAttribute('data-submission-id');
      var verifyUrl = node.getAttribute('data-verify-url');
      var message = node.getAttribute('data-message') || 'Success';
      var cookieName = node.getAttribute('data-cookie-name');
      var cookiePath = node.getAttribute('data-cookie-path') || '/';
      if (!formId || !submissionId || !verifyUrl) {
        node.parentNode && node.parentNode.removeChild(node);
        return;
      }
      var url = verifyUrl + '?f=' + encodeURIComponent(formId) + '&s=' + encodeURIComponent(submissionId);
      fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (resp) { if (!resp.ok) { return { ok: false }; } return resp.json().catch(function () { return { ok: false }; }); })
        .then(function (body) {
          if (body && body.ok) {
            node.classList.remove('eforms-success-pending');
            node.classList.add('eforms-success');
            node.textContent = message;
            expireCookie(cookieName, cookiePath);
            clearSuccessQuery(formId);
          } else {
            node.parentNode && node.parentNode.removeChild(node);
          }
        })
        .catch(function () {
          node.parentNode && node.parentNode.removeChild(node);
        });
    });
  }
  onReady(function () {
    document.querySelectorAll('input[name="js_ok"]').forEach(function (el) {
      try { el.value = '1'; } catch(_){}
    });
    document.querySelectorAll('form').forEach(function (f) {
      var summary = f.querySelector('.eforms-error-summary');
      if (summary) {
        try { summary.focus(); } catch(e){}
        var firstInvalid = f.querySelector('[aria-invalid="true"]');
        if (firstInvalid && typeof firstInvalid.focus === 'function') {
          firstInvalid.focus();
        }
      }
      var submitting = false;
      f.addEventListener('submit', function (e) {
        if (submitting) { e.preventDefault(); return false; }
        submitting = true;
        var btn = f.querySelector('button[type="submit"]');
        if (btn) {
          btn.disabled = true;
          var spin = document.createElement('span');
          spin.className = 'eforms-spinner';
          btn.parentNode.insertBefore(spin, btn.nextSibling);
        }
      });
    });
    handleSuccessPlaceholders();
  });
})();
