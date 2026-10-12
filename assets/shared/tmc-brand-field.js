/**
 * Widening the brand field's suggestion list to what has been typed.
 *
 * **The field works with this file absent, and that is the design.** The
 * `<datalist>` is seeded by the server and the browser filters it as you type;
 * typing a name that is not in it is a REQUEST for that brand, which is the
 * ordinary save. So every early `return` below lands on behaviour that is
 * already correct — not on a broken control.
 *
 * What this adds is the names past the seeded cap. A shop with more brands
 * than one page of the datalist would otherwise hide the rest behind a list
 * that looks complete.
 *
 * Two counters, not one — the `alpha.27` rule, and the reason it is a rule:
 * after a keystroke there is a quarter-second of debounce during which the
 * PREVIOUS query's answer can arrive with nothing newer to compare it against.
 * Comparing against «the newest answer already applied» lets that one through,
 * so the vendor gets the option list for a word they have finished editing.
 * A **generation** moves on every input event, before any request exists; a
 * **sequence** numbers the requests; an answer is applied only while its
 * generation is still current. Cancelling the previous request would not have
 * been enough: the answer that does the damage is already on its way back.
 *
 * It never writes to the input. A suggestion is a suggestion; the vendor types.
 *
 * **The names arrive as data and the options are built as nodes.** Not as
 * markup assigned to `innerHTML`: a `<datalist>` option carries exactly one
 * datum — its value — so `createElement` plus one assignment is the whole
 * renderer, and it cannot be talked into being markup by a brand name. The
 * `alpha.26` rule about two renderers of one list is answered by there being
 * one LIST: the server picks the names, and HTML and DOM are two spellings of
 * the same strings rather than two opinions about them.
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 250;

    var input = document.querySelector('[data-tmc-brand-field="1"]');
    if (!input) {
        return;
    }
    var list = document.querySelector('[data-tmc-brand-list="1"]');
    var url = input.getAttribute('data-suggest-url') || '';
    var nonce = input.getAttribute('data-suggest-nonce') || '';
    var action = input.getAttribute('data-suggest-action') || '';
    if (!list || !url || !nonce || !action) {
        // A reader with no edit rights gets no endpoint, and the seeded list
        // is still there to be filtered.
        return;
    }

    var generation = 0;
    var sequence = 0;
    var timer = null;
    var lastAsked = null;

    function apply(generationAsked, names) {
        if (generationAsked !== generation) {
            return;                 // the box has moved on; this list is stale
        }
        while (list.firstChild) {
            list.removeChild(list.firstChild);
        }
        for (var i = 0; i < names.length; i += 1) {
            var option = document.createElement('option');
            // An assignment, not markup: whatever the name contains, it is a
            // value and stays one.
            option.value = String(names[i]);
            list.appendChild(option);
        }
    }

    function ask(query) {
        var generationAsked = generation;
        var seq = ++sequence;
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('nonce', nonce);
        body.set('q', query);
        body.set('seq', String(seq));
        window.fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (payload) {
            if (!payload || !payload.success || !payload.data) {
                return;             // the seeded list stays; nothing is lost
            }
            if (Number(payload.data.seq) !== seq) {
                return;
            }
            var names = payload.data.names;
            if (!Array.isArray(names)) {
                return;
            }
            apply(generationAsked, names);
        }).catch(function () {
            // Deliberately silent: the datalist the server seeded is still
            // correct, and a message about a failed suggestion would be noise
            // over a field that is working.
        });
    }

    input.addEventListener('input', function () {
        // BEFORE any request exists, so an answer already in flight is stale
        // the moment the box changes.
        generation += 1;
        var query = input.value;
        if (timer) {
            window.clearTimeout(timer);
        }
        timer = window.setTimeout(function () {
            timer = null;
            if (query === lastAsked) {
                return;
            }
            lastAsked = query;
            ask(query);
        }, DEBOUNCE_MS);
    });
}());
