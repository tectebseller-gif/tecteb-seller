/*
 * Tecteb Marketplace Core — vendor panel shell behaviour.
 *
 * One job: the menu is a `<details>` that ships COLLAPSED, because a ten-item
 * menu laid out two-up put five rows of buttons above every page on a phone.
 * On a wide viewport it should simply be the menu bar it used to be.
 *
 * That last part cannot be done in CSS, and this is not a guess — it was
 * measured in Chromium 141: neither `details:not([open]) > * { display:
 * block }` nor `display: contents` on the `<details>` makes a closed panel
 * visible, because the UA hides the content through its own shadow slot.
 *
 * So the rule this file obeys is the one the shell has always had: a script
 * may only ADD. With JavaScript off the menu is collapsed everywhere and one
 * click away; nothing is unreachable, and no page depends on this file.
 */
(function () {
  'use strict';

  var WIDE = '(min-width: 48rem)';

  function bind(details) {
    if (!details || !window.matchMedia) { return; }
    var wide = window.matchMedia(WIDE);
    // `userClosed` exists so that a person who deliberately collapses the menu
    // on a wide screen does not have it forced back open by a resize. The
    // toggle is theirs once they have used it.
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
      wide.addListener(apply);          // Safari < 14
    }
  }

  function init() {
    bind(document.getElementById('tv-nav'));
    bind(document.getElementById('tmc-nav'));
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
