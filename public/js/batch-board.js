/*
 * The batch board's poll, on the same pattern as the live session monitor:
 * fetch JSON every three seconds and update what changed.
 *
 * It never reloads the page. A recording stays playable in the card from
 * `recorded` onward, and a reload would stop the audio the teacher is listening
 * to, lose her scroll position and collapse the player she was halfway through -
 * every time some *other* child's chip moved. So each changed card is patched in
 * place and nothing else in the document is touched.
 *
 * What a patch changes, and only when it actually differs: the chip's word, its
 * tone class and its spinner, the label line, and the action row (Check Result
 * and the one action the state calls for). A card whose state is unchanged is
 * skipped whole - not re-rendered - by comparing data-sig.
 *
 * Two transitions need markup this script cannot honestly build: becoming
 * `done` (which carries the reading level, accuracy and the weakness label, all
 * read server-side through weaknessOutcome()) and gaining a recording it was
 * rendered without (no audio URL existed at render time). For those, and only
 * those, the board HTML is fetched once and *that one card's element* is
 * swapped. Never the page. Even then the live <audio> node is carried into the
 * replacement rather than re-created, so a recording playing on this board is
 * not cut off by a verdict recorded somewhere else; and one card is only ever
 * being replaced by one fetch at a time, with a late response dropped if the
 * card's state has moved on since the fetch was issued.
 *
 * The poll only reads. The batch's own status is kept true by the server's
 * observers, so closing this page does not stop anything - which is exactly what
 * the standing line on the board promises.
 */
(function () {
    'use strict';

    var config = window.BATCH_BOARD || {};
    var POLL_MS = 3000;
    var TONES = ['pp-good', 'pp-ok', 'pp-help', 'pp-none'];
    var timer = null;

    /** entry id -> the signature a replacement fetch is currently in flight for. */
    var replacing = {};

    /** entry id -> the signature the most recent poll reported for that card. */
    var latestSig = {};

    if (!config.pollUrl) {
        return;
    }

    function cardFor(id) {
        return document.querySelector('[data-entry="' + id + '"]');
    }

    function signature(state) {
        // Mirrors $signature in _card.blade.php, `running` included: crossing
        // the lane's staleness bound changes no chip and no label, only whether
        // the card offers a door off the board, so without it the poll would
        // skip the card and the door would never appear.
        return [
            state.chip, state.label, state.action || '', state.result_url || '',
            state.running ? 'running' : '',
            // The place in the line and the wait. A card moving from "3rd in
            // line" to "2nd in line" keeps its chip, its tone and its action,
            // so without these the signature would match and the card would
            // never repaint - the number would just sit there being wrong.
            state.position === null || state.position === undefined ? '' : state.position,
            state.eta_minutes === null || state.eta_minutes === undefined ? '' : state.eta_minutes
        ].join('|');
    }

    /**
     * Which way a card in this state lets a child leave the board. Mirrors the
     * match() in _card.blade.php; the row itself is only ever rendered by the
     * server, because it holds forms with CSRF tokens.
     */
    function manageFor(state) {
        var chip = state.chip;

        if (chip === 'waiting' || chip === 'recorded') {
            return 'remove';
        }
        if (chip === 'queued') {
            return 'unsend';
        }
        if (chip === 'needs verdict' || chip === 'done' || chip === 'failed') {
            return 'detach';
        }
        // A transcribing card offers nothing only while something is really
        // running; past the lane's staleness bound the run is gone and the card
        // gains a Cancel analysis, so this row has to move with it.
        if (chip === 'transcribing') {
            return state.running ? '' : 'unsend';
        }

        return '';
    }

    /** True when this state needs server-rendered markup we cannot build here. */
    function needsServerMarkup(card, state) {
        if (state.chip === 'done' && !card.querySelector('[data-done]')) {
            return true;
        }

        // A card that has moved between manage rows - a queued reading the lane
        // has now started, say - must be re-rendered rather than patched: an
        // un-send form left behind would offer to stop a running Whisper.
        var manage = card.querySelector('[data-manage]');
        if ((manage ? manage.getAttribute('data-manage') : '') !== manageFor(state)) {
            return true;
        }

        return state.playable && !card.querySelector('audio');
    }

    function patchChip(card, state) {
        var chip = card.querySelector('[data-chip]');
        if (!chip) {
            return;
        }

        var text = chip.querySelector('[data-chip-text]');
        if (text && text.textContent.trim() !== state.chip) {
            text.textContent = state.chip;
        }

        TONES.forEach(function (tone) {
            chip.classList.toggle(tone, tone === 'pp-' + state.tone);
        });

        var spinner = chip.querySelector('[data-chip-spinner]');
        if (spinner) {
            spinner.classList.toggle('d-none', state.chip !== 'transcribing');
        }

        // The indeterminate bar follows the chip, so a reading that has stopped
        // being transcribed stops looking as though something is working on it.
        var progress = card.querySelector('[data-progress]');
        if (progress) {
            progress.classList.toggle('d-none', state.chip !== 'transcribing');
        }
    }

    function patchActions(card, state) {
        var actions = card.querySelector('[data-actions]');
        if (!actions) {
            return;
        }

        var html = '';

        if (state.result_url) {
            html += '<a href="' + encodeURI(state.result_url) + '" class="btn btn-sm btn-primary">'
                + '<i class="bi bi-clipboard-data me-1"></i> Check Result</a>';
        }

        if (state.action) {
            html += '<a href="' + encodeURI(card.dataset.entryUrl || '#') + '" class="btn btn-sm btn-outline-primary">'
                + escapeText(state.action) + '</a>';
        }

        if (actions.innerHTML.trim() !== html) {
            actions.innerHTML = html;
        }
    }

    function escapeText(value) {
        var holder = document.createElement('span');
        holder.textContent = value;

        return holder.innerHTML;
    }

    /**
     * Move the card's live <audio> element into its replacement, so the teacher
     * keeps her place in the recording.
     *
     * The node itself is carried across, not its src: an element re-created from
     * markup starts at zero and paused, which is the very thing F3 was about.
     * The move happens in the same synchronous task as the swap and both parents
     * are already in the document, so the element is never out of a document
     * when the browser next reaches a stable state - the point at which a
     * removed media element would be paused.
     *
     * If the replacement has no audio slot (a state rendered without a player),
     * the live node is grafted in anyway rather than dropped.
     */
    function carryAudio(current, replacement) {
        var live = current.querySelector('audio');
        if (!live) {
            return;
        }

        var slot = replacement.querySelector('audio');
        if (slot) {
            slot.replaceWith(live);

            return;
        }

        var body = replacement.querySelector('.card-body') || replacement;
        var actions = body.querySelector('[data-actions]');

        if (actions) {
            body.insertBefore(live, actions);
        } else {
            body.appendChild(live);
        }
    }

    /**
     * Fetch the board once and swap only the listed cards' elements.
     *
     * @param requests [{id, sig}] - sig is the state the card was asked to
     *        become, so a response can be dropped if that has since moved on.
     */
    function replaceCards(requests) {
        // One card is only ever replaced by one fetch at a time: without this,
        // two overlapping polls could swap the same element twice and the slower
        // response would win with older markup.
        var wanted = requests.filter(function (request) {
            return !replacing[request.id];
        });

        if (!config.boardUrl || wanted.length === 0) {
            return;
        }

        wanted.forEach(function (request) { replacing[request.id] = request.sig; });

        fetch(config.boardUrl, { headers: { Accept: 'text/html' } })
            .then(function (response) { return response.ok ? response.text() : null; })
            .then(function (html) {
                if (!html) {
                    return;
                }

                var parsed = new DOMParser().parseFromString(html, 'text/html');

                wanted.forEach(function (request) {
                    // The card moved on while this was in flight: let the next
                    // poll deal with the state that is actually true now.
                    if (latestSig[request.id] !== request.sig) {
                        return;
                    }

                    var current = cardFor(request.id);
                    var replacement = parsed.querySelector('[data-entry="' + request.id + '"]');

                    if (!current || !replacement) {
                        return;
                    }

                    var currentCol = current.parentElement;
                    var replacementCol = replacement.parentElement;

                    if (currentCol && replacementCol) {
                        // In the document first, so carrying the audio across
                        // never takes it out of one. Then the old column goes.
                        currentCol.after(replacementCol);
                        carryAudio(current, replacement);
                        currentCol.remove();
                    } else {
                        carryAudio(current, replacement);
                        current.replaceWith(replacement);
                    }
                });
            })
            .catch(function (e) { console.error('Board card refresh error:', e); })
            .finally(function () {
                wanted.forEach(function (request) { delete replacing[request.id]; });
            });
    }

    function apply(data) {
        var needMarkup = [];

        Object.keys(data.entries || {}).forEach(function (id) {
            var card = cardFor(id);
            if (!card) {
                return;
            }

            var state = data.entries[id];
            var sig = signature(state);

            // Recorded for every entry on every poll, so a replacement fetch
            // that lands late can tell its target state is no longer current.
            latestSig[id] = sig;

            // Unchanged: touch nothing on this card at all.
            if (card.dataset.sig === sig) {
                return;
            }

            if (needsServerMarkup(card, state)) {
                needMarkup.push({ id: id, sig: sig });

                return;
            }

            patchChip(card, state);

            var label = card.querySelector('[data-label]');
            if (label && label.textContent.trim() !== state.label) {
                label.textContent = state.label;
            }

            patchActions(card, state);

            card.dataset.sig = sig;
        });

        var count = document.querySelector('[data-ready-count]');
        if (count && typeof data.ready_to_send === 'number' && count.textContent.trim() !== String(data.ready_to_send)) {
            count.textContent = data.ready_to_send;
            var button = count.closest('button');
            if (button) {
                button.disabled = data.ready_to_send === 0;
            }
        }

        var estimate = document.querySelector('[data-estimate]');
        if (estimate && data.estimate) {
            var text;

            if (data.estimate.remaining > 0) {
                var minutes = Math.max(1, Math.round(data.estimate.seconds / 60));
                text = data.estimate.remaining
                    + (data.estimate.remaining === 1 ? ' reading' : ' readings')
                    + ' still to score — about ' + minutes + ' min left.';
            } else {
                text = 'Nothing is waiting to be scored right now.';
            }

            if (estimate.textContent.trim() !== text) {
                estimate.textContent = text;
            }
        }

        // Nothing is driving the lane. Raised and cleared from the same poll
        // that draws the cards, so it goes away by itself the moment something
        // is scored again - a warning that stayed up after the lane recovered
        // would train a teacher to ignore it.
        var stall = document.querySelector('[data-lane-stall]');
        if (stall) {
            var minutes = data.lane_stalled_minutes;
            var stalled = typeof minutes === 'number' && minutes > 0;

            stall.classList.toggle('d-none', !stalled);

            var stallText = stall.querySelector('[data-lane-stall-text]');
            if (stalled && stallText) {
                stallText.textContent = 'Nothing has been scored for ' + minutes
                    + ' minutes, although readings are waiting.';
            }
        }

        replaceCards(needMarkup);

        if (data.batch_status === 'completed' || data.batch_status === 'cancelled') {
            stop();
        }
    }

    function stop() {
        if (timer) {
            window.clearInterval(timer);
            timer = null;
        }
    }

    function poll() {
        fetch(config.pollUrl, { headers: { Accept: 'application/json' } })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) { if (data) { apply(data); } })
            .catch(function (e) { console.error('Board poll error:', e); });
    }

    if (config.status !== 'completed' && config.status !== 'cancelled') {
        timer = window.setInterval(poll, POLL_MS);
    }

    // Nothing is lost by closing the page, so there is nothing to warn about.
    window.addEventListener('pagehide', stop);
})();
