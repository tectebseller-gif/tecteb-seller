/**
 * Suggestions while somebody types a category name.
 *
 * The picker works with this file absent. The «جست‌وجوی دسته» button submits
 * the form, the form saves what has been typed, and the page comes back with
 * the list for that query — that is the feature, and this script only removes
 * the round trip. So every early `return` below lands on behaviour that is
 * already correct rather than on a broken control.
 *
 * Four things it has to get right, and each of them is a way the obvious
 * version gets it wrong:
 *
 *  1. **Stale answers.** Two requests in flight do not come back in the order
 *     they left, and the gap is wider than it looks: after a keystroke there
 *     is a quarter of a second of debounce during which the PREVIOUS query's
 *     answer can arrive with nothing newer to compare it against. Comparing
 *     against «the newest answer already rendered» lets that one through — it
 *     really is the newest — so the vendor watches results for a word they
 *     have finished editing appear under a box that says something else, and
 *     if the next request then fails, that wrong list is what stays.
 *
 *     So there are two counters, not one. A **generation** moves on every
 *     change to the input, before any request exists; a **sequence** numbers
 *     the requests. An answer, a failure, or a render held back for focus is
 *     applied only if its generation is still the current one. Cancelling the
 *     previous request would not have been enough: the answer that does the
 *     damage was already on its way back.
 *  2. **Focus.** Replacing the list while somebody is walking it with the
 *     arrow keys takes the row out from under them mid-choice. So the swap
 *     waits until focus leaves the list.
 *  3. **The selection.** It lives in its own block above the results and is
 *     never re-rendered by the server mid-typing. When a result is chosen the
 *     whole `<li>` MOVES there, radio and all, so the form still posts a
 *     `category` no matter what the list below is showing.
 *  4. **Nothing is chosen for anybody.** The script never sets `checked`
 *     except to restore it on a row it has just moved. A suggestion is a
 *     suggestion; the vendor picks.
 *
 * No framework, no bundler, no CDN. Every Persian string arrives on a data
 * attribute, because a string baked in here is a string no translation
 * catalogue can reach.
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 250;

    var wrap = document.querySelector('.tv-catpick');
    if (!wrap) {
        return;
    }
    var search = wrap.querySelector('.tv-catpick__search');
    var input = wrap.querySelector('#f-category_q');
    var results = wrap.querySelector('#tv-catpick-results');
    var chosen = wrap.querySelector('#tv-catpick-chosen');
    var status = wrap.querySelector('#tv-catpick-status');
    if (!search || !input || !results || !chosen) {
        return;
    }

    var url = search.getAttribute('data-suggest-url');
    var action = search.getAttribute('data-suggest-action');
    var nonce = search.getAttribute('data-suggest-nonce');
    // A reader who may not edit gets no endpoint, and the button stays the
    // only way to search — which is exactly right for somebody who cannot
    // save the form anyway.
    if (!url || !action || !nonce || !window.fetch || !window.FormData) {
        return;
    }
    var textSearching = search.getAttribute('data-text-searching') || '';
    var textFailed = search.getAttribute('data-text-failed') || '';

    var seq = 0;                 // the last request sent
    var rendered = 0;            // the newest answer already on screen
    var generation = 0;          // bumped by the INPUT, not by the request
    var timer = null;
    var deferred = null;         // an answer held back because focus was in the list
    var lastQuery = input.value;

    /**
     * The input changed. Everything in flight now belongs to the past.
     *
     * Called before the debounce timer is even set, which is the whole point:
     * the window this closes is the one between a keystroke and the request
     * it will eventually cause.
     */
    function newGeneration() {
        generation += 1;
        deferred = null;         // a held answer to an older question
        return generation;
    }

    // ------------------------------------------------------------ selection

    /**
     * Move the chosen row into the block above, radio and all.
     *
     * Moving rather than copying is what keeps ONE radio per category in the
     * document. Two radios with the same name and the same value would post
     * the same thing, but the vendor would be looking at their category
     * twice and unable to tell which one they had clicked.
     */
    function adoptSelection(row) {
        if (!row || row.parentNode === chosenList()) {
            return;
        }
        var radio = row.querySelector('input[type="radio"]');
        var hadFocus = radio && document.activeElement === radio;

        var list = chosenList();
        while (list.firstChild) {
            // The previous choice is dropped, not kept: it is one search away,
            // and a block that grows a row per change stops being «the
            // chosen category».
            list.removeChild(list.firstChild);
        }
        var hint = chosen.querySelector('.tv-hint');
        if (hint) {
            hint.parentNode.removeChild(hint);
        }
        list.appendChild(row);
        row.classList.add('is-selected');
        if (radio) {
            // Re-asserted after the move. The property survives reparenting in
            // every browser this was measured in, but a selection that depends
            // on that is a selection nobody can promise.
            radio.checked = true;
            if (hadFocus) {
                radio.focus();
            }
        }
    }

    function chosenList() {
        var list = chosen.querySelector('.tv-catpick__list');
        if (!list) {
            list = document.createElement('ul');
            list.className = 'tv-catpick__list';
            list.setAttribute('role', 'group');
            list.setAttribute('aria-label', chosenLabel());
            chosen.appendChild(list);
        }
        return list;
    }

    function chosenLabel() {
        var label = chosen.querySelector('.tv-catpick__chosen-label');
        return label ? (label.textContent || '') : '';
    }

    function selectedValue() {
        var radio = wrap.querySelector('input[name="category"]:checked');
        return radio ? radio.value : '';
    }

    // The change can only come from a click or an arrow key — the script never
    // fires it — so this is the «explicit action» the selection waits for.
    wrap.addEventListener('change', function (event) {
        var target = event.target;
        if (!target || target.name !== 'category') {
            return;
        }
        var row = target.closest ? target.closest('.tv-catpick__row') : null;
        if (row && results.contains(row)) {
            adoptSelection(row);
        }
    });

    // -------------------------------------------------------------- fetching

    function say(message) {
        if (status) {
            status.textContent = message;
        }
    }

    function apply(html, summary, answered, gen) {
        if (gen !== generation) {
            return;                         // answers a query nobody is asking
        }
        if (answered <= rendered) {
            return;                         // an older answer; drop it
        }
        // Someone is inside the list with the keyboard. Taking it away now
        // would move the choice under their fingers, so it waits.
        if (results.contains(document.activeElement)) {
            deferred = { html: html, summary: summary, seq: answered, gen: gen };
            return;
        }
        rendered = answered;
        results.innerHTML = html;
        // Which answer is on screen. Useful when something goes wrong, and
        // the only deterministic signal a test has that the list it is
        // reading is the one it asked for rather than the one before it.
        results.setAttribute('data-seq', String(answered));
        hideDuplicate();
        say(summary);
    }

    /** The chosen row is shown above; the server leaves it out, and so do we. */
    function hideDuplicate() {
        var value = selectedValue();
        if (value === '') {
            return;
        }
        var twin = results.querySelector('input[name="category"][value="' + cssEscape(value) + '"]');
        var row = twin && twin.closest ? twin.closest('.tv-catpick__row') : null;
        if (row) {
            row.parentNode.removeChild(row);
        }
    }

    function cssEscape(value) {
        return String(value).replace(/["\\]/g, '\\$&');
    }

    results.addEventListener('focusout', function () {
        // `focusout` fires before focus lands, so the check is deferred one
        // tick; otherwise every arrow key inside the list looks like leaving.
        window.setTimeout(function () {
            if (!deferred || results.contains(document.activeElement)) {
                return;
            }
            var held = deferred;
            deferred = null;
            // `apply` checks the generation again rather than trusting this
            // one: focus can leave the list minutes after the answer was
            // held, and by then the box may say something else entirely.
            apply(held.html, held.summary, held.seq, held.gen);
        }, 0);
    });

    function request(query, gen) {
        seq += 1;
        var mine = seq;
        var body = new FormData();
        body.append('action', action);
        body.append('nonce', nonce);
        body.append('q', query);
        body.append('seq', String(mine));
        body.append('selected', selectedValue());

        say(textSearching);
        window.fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                if (!payload || !payload.success || !payload.data) {
                    throw new Error('refused');
                }
                // The sequence the SERVER echoed, not the one this closure
                // remembers: it is the only evidence of which question was
                // answered, and the two differ the moment a retry exists.
                apply(payload.data.html, payload.data.summary || '', Number(payload.data.seq) || mine, gen);
            })
            .catch(function () {
                if (gen !== generation || mine < seq) {
                    return;                 // nobody is waiting on this any more
                }
                // The list on screen belongs to an older query and this one
                // failed, so there is nothing trustworthy to show. Say so,
                // and leave the button that still works.
                say(textFailed);
            });
    }

    input.addEventListener('input', function () {
        var value = input.value;
        if (value === lastQuery) {
            return;
        }
        lastQuery = value;
        var gen = newGeneration();
        if (timer) {
            window.clearTimeout(timer);
        }
        timer = window.setTimeout(function () { request(value, gen); }, DEBOUNCE_MS);
    });

    // Enter in a search box would submit the form — a full save and a page
    // load for something already on screen. Asking now is both faster and
    // what somebody pressing Enter means. With the script absent, Enter still
    // submits, which is the same answer by a longer road.
    input.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') {
            return;
        }
        event.preventDefault();
        if (timer) {
            window.clearTimeout(timer);
            timer = null;
        }
        lastQuery = input.value;
        request(input.value, newGeneration());
    });
})();
