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

  /*
   * The section menu is a `<details>` that ships COLLAPSED: this plugin now
   * registers more than twenty manager screens, and as a flat list that was a
   * wall of links above every page on a narrow viewport.
   *
   * Opening it on a wide screen needs a script, and that is measured rather
   * than assumed: in Chromium 141 neither `details:not([open]) > * { display:
   * block }` nor `display: contents` on the `<details>` makes a closed panel
   * visible, because the UA hides the content through its own shadow slot.
   *
   * With scripts off the menu is collapsed on desktop too — one click away,
   * never missing. Nothing on any page depends on this running.
   */
  function bindNav() {
    var details = document.getElementById('tmc-nav');
    if (!details || !window.matchMedia) { return; }
    // wp-admin puts a 160px menu (36px folded) beside the content, so the
    // viewport is the wrong thing to ask. The shell is a container; its own
    // width is what decides whether a menu bar fits.
    var wide = window.matchMedia('(min-width: 52rem)');
    var userClosed = false;

    details.addEventListener('toggle', function () {
      if (wide.matches) { userClosed = !details.open; }
    });

    function apply() {
      if (wide.matches) {
        if (!userClosed) { details.open = true; }
        details.classList.add('is-wide');
      } else {
        details.open = false;
        details.classList.remove('is-wide');
      }
    }

    apply();
    if (wide.addEventListener) {
      wide.addEventListener('change', apply);
    } else if (wide.addListener) {
      wide.addListener(apply);
    }
  }

  function init() {
    focusErrorSummary();
    reannounceLiveRegion();
    bindNav();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
