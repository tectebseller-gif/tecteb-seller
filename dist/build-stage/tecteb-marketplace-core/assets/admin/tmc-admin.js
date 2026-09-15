/*
 * Tecteb Marketplace Core — admin behaviour (vanilla, module pattern, no
 * globals, no CDN). Loaded only on the plugin's own screens.
 * Responsibilities: move focus to the error summary after a failed save,
 * make summary links focus their field, re-announce live notices.
 */
(function () {
  'use strict';

  var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function focusErrorSummary() {
    var summary = document.getElementById('tmc-error-summary');
    if (!summary) { return; }
    summary.focus();
    var links = summary.querySelectorAll('a[href^="#"]');
    Array.prototype.forEach.call(links, function (link) {
      link.addEventListener('click', function (event) {
        var id = link.getAttribute('href').slice(1);
        var field = document.getElementById(id);
        if (!field) { return; }
        event.preventDefault();
        field.focus();
        if (field.scrollIntoView) {
          field.scrollIntoView({ block: 'center', behavior: reducedMotion ? 'auto' : 'smooth' });
        }
      });
    });
  }

  function reannounceLiveRegion() {
    var live = document.querySelector('.tmc-live');
    if (!live || !live.textContent.trim()) { return; }
    var content = live.innerHTML;
    live.innerHTML = '';
    window.setTimeout(function () { live.innerHTML = content; }, 120);
  }

  function init() {
    focusErrorSummary();
    reannounceLiveRegion();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
