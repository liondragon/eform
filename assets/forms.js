/*!
 * Electronic Forms basic JS
 * - sets js_ok=1 on DOM ready
 * - (later) handles error-summary focus and submit lock
 */
(function () {
  function onReady(fn){ if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', fn); } else { fn(); } }
  onReady(function () {
    document.querySelectorAll('input[name="js_ok"]').forEach(function (el) {
      try { el.value = '1'; } catch(_){}
    });
    var summary = document.querySelector('.eforms-error-summary');
    if (summary) {
      try { summary.focus(); } catch(e){}
      var firstInvalid = document.querySelector('[aria-invalid="true"]');
      if (firstInvalid && typeof firstInvalid.focus === 'function') {
        firstInvalid.focus();
      }
    }
    document.querySelectorAll('form').forEach(function (f) {
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
  });
})();
