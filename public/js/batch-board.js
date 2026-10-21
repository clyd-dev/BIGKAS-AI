/*
 * The batch board's poll, on the same pattern as the live session monitor:
 * fetch JSON every three seconds and update what changed.
 *
 * The poll only reads. The batch's own status is kept true by the server's
 * observer, so closing this page does not stop anything - which is exactly what
 * the standing line on the board promises.
 */
(function () {
    'use strict';

    var config = window.BATCH_BOARD || {};
    var POLL_MS = 3000;
    var timer = null;

    if (!config.pollUrl) {
        return;
    }

    function chipOf(card) {
        var chip = card.querySelector('[data-chip]');
        return chip ? chip.textContent.trim() : null;
    }

    function apply(data) {
        var changed = false;

        Object.keys(data.entries || {}).forEach(function (id) {
            var card = document.querySelector('[data-entry="' + id + '"]');
            if (!card) {
                return;
            }

            var state = data.entries[id];
            var chip = card.querySelector('[data-chip]');
            var label = card.querySelector('[data-label]');

            if (chip && chipOf(card) !== state.chip) {
                // The actions a card offers change with its state, and only the
                // server knows how to render them, so take the whole page again.
                changed = true;
            }

            if (label && label.textContent.trim() !== state.label) {
                label.textContent = state.label;
            }
        });

        var count = document.querySelector('[data-ready-count]');
        if (count && typeof data.ready_to_send === 'number') {
            count.textContent = data.ready_to_send;
            var button = count.closest('button');
            if (button) {
                button.disabled = data.ready_to_send === 0;
            }
        }

        var estimate = document.querySelector('[data-estimate]');
        if (estimate && data.estimate) {
            if (data.estimate.remaining > 0) {
                var minutes = Math.max(1, Math.round(data.estimate.seconds / 60));
                estimate.textContent = data.estimate.remaining
                    + (data.estimate.remaining === 1 ? ' reading' : ' readings')
                    + ' still to score — about ' + minutes + ' min left.';
            } else {
                estimate.textContent = 'Nothing is waiting to be scored right now.';
            }
        }

        if (changed) {
            stop();
            window.location.reload();
            return;
        }

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
