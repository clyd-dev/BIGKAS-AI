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
 * swapped. Never the page.
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

    if (!config.pollUrl) {
        return;
    }

    function cardFor(id) {
        return document.querySelector('[data-entry="' + id + '"]');
    }

    function signature(state) {
        return [state.chip, state.label, state.action || '', state.result_url || ''].join('|');
    }

    /** True when this state needs server-rendered markup we cannot build here. */
    function needsServerMarkup(card, state) {
        if (state.chip === 'done' && !card.querySelector('[data-done]')) {
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

    /** Fetch the board once and swap only the listed cards' elements. */
    function replaceCards(ids) {
        if (!config.boardUrl || ids.length === 0) {
            return;
        }

        fetch(config.boardUrl, { headers: { Accept: 'text/html' } })
            .then(function (response) { return response.ok ? response.text() : null; })
            .then(function (html) {
                if (!html) {
                    return;
                }

                var parsed = new DOMParser().parseFromString(html, 'text/html');

                ids.forEach(function (id) {
                    var current = cardFor(id);
                    var replacement = parsed.querySelector('[data-entry="' + id + '"]');

                    // Swap the grid column, so the card keeps its place in the row.
                    if (current && replacement) {
                        var currentCol = current.parentElement;
                        var replacementCol = replacement.parentElement;

                        if (currentCol && replacementCol) {
                            currentCol.replaceWith(replacementCol);
                        } else {
                            current.replaceWith(replacement);
                        }
                    }
                });
            })
            .catch(function (e) { console.error('Board card refresh error:', e); });
    }

    function apply(data) {
        var needMarkup = [];

        Object.keys(data.entries || {}).forEach(function (id) {
            var card = cardFor(id);
            if (!card) {
                return;
            }

            var state = data.entries[id];

            // Unchanged: touch nothing on this card at all.
            if (card.dataset.sig === signature(state)) {
                return;
            }

            if (needsServerMarkup(card, state)) {
                needMarkup.push(id);

                return;
            }

            patchChip(card, state);

            var label = card.querySelector('[data-label]');
            if (label && label.textContent.trim() !== state.label) {
                label.textContent = state.label;
            }

            patchActions(card, state);

            card.dataset.sig = signature(state);
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
