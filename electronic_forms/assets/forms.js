/*!
 * Electronic Forms basic JS
 * - sets js_ok=1 on DOM ready
 * - (later) handles error-summary focus and submit lock
 */
(function () {
  function onReady(fn){ if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', fn); } else { fn(); } }
  onReady(function () {
    document.querySelectorAll('input[name="js_ok"]').forEach(function (el) { try { el.value = '1'; } catch(_){} });
  });
})();
