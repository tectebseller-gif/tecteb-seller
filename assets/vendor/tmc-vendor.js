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

  /**
   * Leaving a settings tab with something typed and not saved.
   *
   * The five tabs are ordinary links, so switching tab is a navigation and the
   * browser throws away whatever was typed — silently, which is what the
   * owner's brief says must stop. This asks first, and it asks only when
   * something really changed: the dirty flag is set by an actual `input` or
   * `change` event, never by rendering.
   *
   * Progressive enhancement, deliberately. Without JavaScript the links still
   * work and nothing is blocked — a tab switch then loses unsaved typing
   * exactly as any plain HTML form does, and the form carries a written hint
   * saying so, because a warning that only exists in a script must not be the
   * only place the rule is stated.
   */
  function guardUnsaved() {
    var forms = document.querySelectorAll('form[data-tmc-dirty-guard]');
    if (!forms.length) {
      return;
    }
    var dirty = false;
    // The sentence comes from the markup, so it is translated once, in PHP,
    // where every other string on the page is.
    var message = forms[0].getAttribute('data-tmc-dirty-guard') || '';
    var i;
    function markDirty() {
      dirty = true;
    }
    for (i = 0; i < forms.length; i++) {
      forms[i].addEventListener('input', markDirty);
      forms[i].addEventListener('change', markDirty);
      // A submit is how the work gets saved, so it is never a loss.
      forms[i].addEventListener('submit', function () {
        dirty = false;
      });
    }
    var tabs = document.querySelectorAll('.tv-tabs a');
    for (i = 0; i < tabs.length; i++) {
      tabs[i].addEventListener('click', function (event) {
        if (!dirty) {
          return;
        }
        if (message === '') {
          return;                      // nothing to say, so nothing is blocked
        }
        if (!window.confirm(message)) {
          event.preventDefault();
        }
      });
    }
  }

  function init() {
    bind(document.getElementById('tv-nav'));
    bind(document.getElementById('tmc-nav'));
    guardUnsaved();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
