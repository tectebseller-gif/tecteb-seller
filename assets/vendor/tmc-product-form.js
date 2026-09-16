/**
 * Three things the product form cannot do without JavaScript, and nothing else.
 *
 *  1. Keep what somebody typed, so a closed tab does not cost an afternoon.
 *  2. Say so before they close it.
 *  3. Say a picture is too big BEFORE it is uploaded, not after.
 *
 * Everything the form DOES — every field, every step, every button — is a plain
 * POST that works with this file absent. That is the rule for this whole area
 * and this script does not bend it: a vendor with JavaScript off loses the
 * draft and the warning, and can still create, edit and submit a product.
 *
 * No framework, no bundler, no CDN. The configuration arrives on the form's own
 * data attributes rather than in an inline <script>, so the page carries no
 * executable markup at all.
 */
(function () {
    'use strict';

    // The picture check belongs to the form whether or not autosave is
    // configured, so it is wired from the input itself. Reading the config off
    // the control means a page that renders no file chooser — every step but
    // the first — simply has nothing to wire.
    preflightPicture();

    var form = document.querySelector('form.tv-form[data-autosave-url]');
    if (!form) {
        return;
    }

    var url = form.getAttribute('data-autosave-url');
    var action = form.getAttribute('data-autosave-action');
    var nonce = form.getAttribute('data-autosave-nonce');
    var status = document.getElementById('tmc-autosave-status');
    var dirty = false;
    var pending = null;

    /**
     * The revision the page was rendered from. When the server answers with a
     * different one, somebody else has saved this product since — and the
     * vendor is told NOW, rather than after another ten minutes of typing into
     * a form whose save is already going to be refused.
     */
    var revisionField = form.querySelector('input[name="revision"]');
    var openedWith = revisionField ? revisionField.value : '';

    function say(text, tone) {
        if (!status) {
            return;
        }
        status.textContent = text;
        status.className = 'tv-autosave tv-autosave--' + (tone || 'idle');
    }

    function collect() {
        var data = new FormData();
        data.append('action', action);
        data.append('nonce', nonce);
        ['product_id', 'revision', 'step', 'title', 'category_key', 'price', 'sale_price',
            'sku', 'stock', 'short_description', 'description', 'purchase_limit'].forEach(function (name) {
            var field = form.querySelector('[name="' + name + '"]');
            if (field && typeof field.value === 'string') {
                data.append(name, field.value);
            }
        });
        return data;
    }

    function save() {
        if (!dirty) {
            return;
        }
        // Cleared BEFORE the request, not after: a change made while the
        // request is in flight must mark the form dirty again, or that change
        // is the one that gets lost.
        dirty = false;
        say(form.getAttribute('data-text-saving') || '…', 'busy');

        fetch(url, { method: 'POST', body: collect(), credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (body) {
                if (!body || !body.success) {
                    dirty = true;
                    say(form.getAttribute('data-text-failed') || '', 'warn');
                    return;
                }
                say((form.getAttribute('data-text-saved') || '') + ' ' + (body.data.saved_at || ''), 'ok');

                var current = body.data.current_revision || '';
                if (current && openedWith && current !== openedWith) {
                    say(form.getAttribute('data-text-conflict') || '', 'warn');
                }
            })
            .catch(function () {
                dirty = true;
                say(form.getAttribute('data-text-failed') || '', 'warn');
            });
    }

    form.addEventListener('input', function () {
        dirty = true;
        window.clearTimeout(pending);
        // Idle-triggered rather than on a fixed interval: saving mid-sentence
        // costs a request and gains nothing, and a five-second pause is what
        // typing actually looks like.
        pending = window.setTimeout(save, 5000);
    });

    // The last chance to keep the work. `beforeunload` cannot await a fetch, so
    // the draft is written first and the browser's own warning covers the case
    // where it did not finish.
    window.addEventListener('beforeunload', function (event) {
        if (!dirty) {
            return undefined;
        }
        save();
        event.preventDefault();
        // Browsers ignore custom text here and show their own sentence; the
        // assignment is still what makes the prompt appear at all.
        event.returnValue = '';
        return '';
    });

    // Submitting IS saving. Without this the vendor gets a «you have unsaved
    // changes» prompt on the one action that saves them.
    form.addEventListener('submit', function () {
        dirty = false;
        window.clearTimeout(pending);
    });

    /**
     * Name a picture that is already going to be refused, at the moment it is
     * chosen.
     *
     * This does NOT replace the server's check and cannot: `size` and `type`
     * here are the browser's word, and `type` in particular comes from the
     * file extension, which is exactly what the server refuses to believe. The
     * server still reads the bytes and still refuses. What this buys is the
     * minute the vendor would otherwise spend uploading 8 MB over a phone
     * connection to be told the limit is 3 — and the save that goes through
     * with the picture missing.
     *
     * It never blocks the submit. A vendor who wants to save the text and deal
     * with the picture afterwards is doing something reasonable, and the
     * server's own refusal keeps the text save either way.
     */
    function preflightPicture() {
        var input = document.querySelector('input[type="file"][data-max-bytes]');
        var slot = document.getElementById('f-product-image-problem');
        if (!input || !slot) {
            return;
        }

        var maxBytes = parseInt(input.getAttribute('data-max-bytes'), 10);
        var allowed = (input.getAttribute('data-allowed-mime') || '').split(',');

        input.addEventListener('change', function () {
            slot.textContent = '';
            var file = input.files && input.files[0];
            if (!file || !(maxBytes > 0)) {
                return;
            }
            if (file.size > maxBytes) {
                slot.textContent = (input.getAttribute('data-text-too-large') || '')
                    .replace('{size}', digits((file.size / 1048576).toFixed(1)));
                return;
            }
            // An empty type is «the browser does not know», not «wrong»: some
            // browsers report nothing for WebP. Guessing wrong here would stop
            // a vendor uploading a file the server would have accepted.
            if (file.type && allowed.indexOf(file.type) === -1) {
                slot.textContent = input.getAttribute('data-text-bad-type') || '';
            }
        });
    }

    /** ۱٫۵ not 1.5 — the rest of this page counts in Persian digits. */
    function digits(value) {
        return String(value)
            .replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.charAt(Number(d)); })
            .replace('.', '٫');
    }
}());
