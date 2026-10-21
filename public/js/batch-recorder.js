/*
 * The batch panel's recorder: one instance, bound to the panel that is open.
 *
 * Only one child reads at a time, so there is no per-card recorder anywhere -
 * the teacher opens a child's panel, records, listens, and sends.
 *
 * This is deliberately *not* public/js/audio-recorder.js. That file is the
 * single-child flow: global functions, hard-coded element ids (#audioPlayback,
 * #comprehensionPanel, window.BIGKAS_LOCAL_FLOW), and it ends by navigating to
 * a results page. Here, nothing navigates and nothing reloads: the card at the
 * top of the panel is patched in place by public/js/batch-board.js, the same
 * poll the board uses, so a recording the teacher is listening to is never cut
 * off.
 *
 * What it does, and nothing more:
 *   - record the open panel with MediaRecorder and post the take to uploadAudio
 *   - show that take straight away, so the teacher can listen before sending
 *   - save the comprehension answers through the answers endpoint
 *   - send, carrying any answers on the page with it
 *
 * The ordering obligation (spec 4.3) is the server's, not this script's: send()
 * records answers before it stamps anything, and refuses a comprehension
 * reading that has none. This script therefore posts the answers *with* the
 * Send rather than after it - but even if it posted nothing, an unanswered
 * reading could not reach the lane.
 */
(function () {
    'use strict';

    var config = window.BATCH_PANEL || {};
    var panel = document.querySelector('[data-recorder]');

    if (!config.sendUrl || !panel) {
        return;
    }

    var btnStart = panel.querySelector('[data-record-start]');
    var btnStop = panel.querySelector('[data-record-stop]');
    var btnSend = panel.querySelector('[data-send]');
    var timerEl = panel.querySelector('[data-record-timer]');
    var preview = panel.querySelector('[data-record-preview]');
    var message = panel.querySelector('[data-recorder-message]');

    var answersPanel = document.querySelector('[data-answers]');
    var btnSaveAnswers = answersPanel ? answersPanel.querySelector('[data-save-answers]') : null;
    var answersMessage = answersPanel ? answersPanel.querySelector('[data-answers-message]') : null;

    var MAX_SECONDS = 180;

    var recorder = null;
    var stream = null;
    var chunks = [];
    var mimeType = 'audio/webm';
    var startedAt = null;
    var ticker = null;
    var busy = false;
    /** True once a recording exists on the server for this child. */
    var hasRecording = !!document.querySelector('[data-entry] audio');

    /**
     * Whether the server's state offers Send. Seeded from the rendered state
     * and refreshed from every reply, so the gate below can only narrow it.
     */
    var sendOffered = config.sendOffered === true;

    /** The stand-down watcher, so it can be cleared when the page goes away. */
    var standDownTimer = null;

    // ── Small helpers ─────────────────────────────────────────────────

    function say(target, text, tone) {
        if (!target) {
            return;
        }

        target.textContent = text || '';
        target.classList.remove('text-danger', 'text-success', 'text-muted');
        target.classList.add(tone || 'text-muted');
    }

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            body: body,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        }).then(function (response) {
            return response.json()
                .catch(function () { return {}; })
                .then(function (payload) {
                    if (!response.ok) {
                        throw new Error(payload.message || firstError(payload)
                            || ('The server did not accept that (HTTP ' + response.status + ').'));
                    }

                    return payload;
                });
        });
    }

    /** The first message out of a Laravel 422 body. */
    function firstError(payload) {
        var errors = payload && payload.errors;
        if (!errors) {
            return null;
        }

        var keys = Object.keys(errors);

        return keys.length ? [].concat(errors[keys[0]])[0] : null;
    }

    function form(extra) {
        var body = new FormData();
        body.append('_token', config.csrf || '');

        (extra || []).forEach(function (pair) { body.append(pair[0], pair[1]); });

        return body;
    }

    /** question id -> picked letter, for every question answered on this page. */
    function pickedAnswers() {
        if (!answersPanel) {
            return null;
        }

        var questions = answersPanel.querySelectorAll('[data-question]');
        if (questions.length === 0) {
            return null;
        }

        var picked = [];

        questions.forEach(function (question) {
            var choice = question.querySelector('input[type="radio"]:checked');
            if (choice) {
                picked.push(['answers[' + question.dataset.question + ']', choice.value]);
            }
        });

        return { picked: picked, total: questions.length };
    }

    /**
     * Send is offered only when the server offers it, narrowed further by what
     * is true in the page.
     *
     * `sendOffered` comes from BatchEntryState's own `action`, and every reply
     * from the panel's endpoints carries a fresh state, so this script can only
     * ever take the offer away - never grant one. That matters: on a `queued` or
     * `failed` card the server offers no Send, and stamp() would no-op, so a
     * button enabled here would report "Sent. It is in line for analysis" for an
     * action that did nothing. A control that claims success for a no-op is the
     * same dishonesty as a card that claims a state it is not in.
     */
    function refreshSendGate() {
        if (!btnSend) {
            return;
        }

        var answers = pickedAnswers();
        var answered = !answers || answers.picked.length === answers.total;

        btnSend.disabled = busy || !sendOffered || !hasRecording || !answered;
    }

    /** Take the server's word for whether Send is on offer. */
    function adoptState(state) {
        if (state && typeof state.action !== 'undefined') {
            sendOffered = state.action === 'Send for analysis';
        }
    }

    // ── Recording ─────────────────────────────────────────────────────

    function supportedMimeType() {
        var types = [
            'audio/webm;codecs=opus', 'audio/webm',
            'audio/ogg;codecs=opus', 'audio/ogg',
            'audio/mp4', 'audio/wav',
        ];

        for (var i = 0; i < types.length; i++) {
            if (window.MediaRecorder && MediaRecorder.isTypeSupported(types[i])) {
                return types[i];
            }
        }

        return 'audio/webm';
    }

    function extensionFor(type) {
        var map = [
            ['audio/webm', 'webm'], ['audio/ogg', 'ogg'],
            ['audio/mp4', 'm4a'], ['audio/wav', 'wav'], ['audio/mpeg', 'mp3'],
        ];

        for (var i = 0; i < map.length; i++) {
            if (type.indexOf(map[i][0]) !== -1) {
                return map[i][1];
            }
        }

        return 'webm';
    }

    function tick() {
        var elapsed = Math.floor((Date.now() - startedAt) / 1000);
        var minutes = String(Math.floor(elapsed / 60)).padStart(2, '0');
        var seconds = String(elapsed % 60).padStart(2, '0');

        if (timerEl) {
            timerEl.textContent = minutes + ':' + seconds;
        }

        // A reading passage is short; a recorder left running by accident is not
        // a recording anyone wants to listen to.
        if (elapsed >= MAX_SECONDS) {
            stop();
        }
    }

    function releaseStream() {
        if (stream) {
            stream.getTracks().forEach(function (track) { track.stop(); });
            stream = null;
        }
    }

    function start() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
            say(message, 'This browser cannot record audio. Use the join code so the child can read on their own device.', 'text-danger');

            return;
        }

        navigator.mediaDevices.getUserMedia({
            audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true },
        }).then(function (granted) {
            stream = granted;
            mimeType = supportedMimeType();
            chunks = [];
            recorder = new MediaRecorder(stream, { mimeType: mimeType, audioBitsPerSecond: 128000 });

            recorder.ondataavailable = function (event) {
                if (event.data && event.data.size > 0) {
                    chunks.push(event.data);
                }
            };

            recorder.onstop = function () {
                releaseStream();
                upload(new Blob(chunks, { type: mimeType }));
            };

            recorder.start(1000);
            startedAt = Date.now();
            ticker = window.setInterval(tick, 500);

            btnStart.classList.add('d-none');
            btnStop.classList.remove('d-none');
            say(message, 'Recording. Tap Stop when the reading is finished.', 'text-muted');
        }).catch(function (error) {
            console.error('Microphone error:', error);
            say(message, 'The microphone could not be used: ' + (error.message || error.name) + '.', 'text-danger');
        });
    }

    function stop() {
        if (ticker) {
            window.clearInterval(ticker);
            ticker = null;
        }

        if (recorder && recorder.state === 'recording') {
            recorder.stop();
        } else {
            releaseStream();
        }

        btnStop.classList.add('d-none');
        btnStart.classList.remove('d-none');
    }

    /**
     * Keep the take, and nothing else.
     *
     * The entry comes to rest at `recorded` - the teacher listens, and only
     * then decides to spend lane time on it. The local player is built here
     * rather than left in the page, so a panel with no recording carries no
     * empty audio element.
     */
    function upload(blob) {
        if (!blob || blob.size === 0) {
            say(message, 'Nothing was recorded. Try again.', 'text-danger');

            return;
        }

        showLocalTake(blob);
        busy = true;
        refreshSendGate();
        say(message, 'Saving the recording...', 'text-muted');

        var body = form();
        body.append('audio', blob, 'recording.' + extensionFor(mimeType));

        post(config.uploadUrl, body).then(function (payload) {
            hasRecording = true;
            adoptState(payload.state);
            say(message, 'Recording saved. Listen to it, then send it for analysis when you are ready.', 'text-success');

            if (payload.state) {
                btnStart.innerHTML = '<i class="bi bi-mic-fill me-1"></i> Record again';
            }

            standDown();
        }).catch(function (error) {
            say(message, error.message, 'text-danger');
        }).finally(function () {
            busy = false;
            refreshSendGate();
        });
    }

    /** The take that was just made, playable at once. */
    function showLocalTake(blob) {
        if (!preview) {
            return;
        }

        preview.innerHTML = '';

        var player = document.createElement('audio');
        player.controls = true;
        player.className = 'w-100';
        player.src = URL.createObjectURL(blob);
        player.setAttribute('aria-label', 'The recording just made');

        preview.appendChild(player);
    }

    /**
     * Drop the local take once the card is showing the saved one.
     *
     * The board's poll patches this panel's card in place and, when the card
     * gains a recording it was rendered without, swaps in a server-rendered
     * card carrying a player for it. From that moment the panel would be
     * offering two players for the same recording, so the local one stands
     * down - but never mid-listen: the whole reason this file exists is that a
     * recording the teacher is listening to must not be cut off. If she is
     * playing it, the stand-down waits for her to stop.
     *
     * It is bounded, because the card it waits for may never arrive: the board
     * poll does not run at all on a completed or cancelled batch, and it can
     * fail. Thirty seconds of looking is plenty for a three-second poll; after
     * that the local player simply stays, which is harmless. It is also cleared
     * on pagehide, with the microphone.
     */
    function standDown() {
        var attempts = 0;

        if (standDownTimer) {
            window.clearInterval(standDownTimer);
        }

        standDownTimer = window.setInterval(function () {
            if (!document.querySelector('[data-entry] audio')) {
                if (++attempts >= 30) {
                    stopStandDown();
                }

                return;
            }

            stopStandDown();

            var local = preview ? preview.querySelector('audio') : null;

            if (!local) {
                return;
            }

            if (local.paused || local.ended) {
                preview.innerHTML = '';

                return;
            }

            local.addEventListener('pause', function () { preview.innerHTML = ''; }, { once: true });
        }, 1000);
    }

    function stopStandDown() {
        if (standDownTimer) {
            window.clearInterval(standDownTimer);
            standDownTimer = null;
        }
    }

    // ── Answers and Send ──────────────────────────────────────────────

    function saveAnswers() {
        var answers = pickedAnswers();
        if (!answers) {
            return;
        }

        busy = true;
        refreshSendGate();
        say(answersMessage, 'Saving the answers...', 'text-muted');

        post(config.answersUrl, form(answers.picked)).then(function (payload) {
            adoptState(payload.state);
            say(answersMessage, 'Answers saved — ' + payload.comprehension_score + '% correct.', 'text-success');
        }).catch(function (error) {
            say(answersMessage, error.message, 'text-danger');
        }).finally(function () {
            busy = false;
            refreshSendGate();
        });
    }

    /**
     * Hand this one recording to the scoring lane.
     *
     * Any answers on the page go with it, so the server records them before it
     * stamps anything - the ordering the spec requires. Nothing navigates: the
     * card above is patched by the board poll within a few seconds.
     */
    function send() {
        var answers = pickedAnswers();

        busy = true;
        refreshSendGate();
        say(message, 'Sending for analysis...', 'text-muted');

        post(config.sendUrl, form(answers ? answers.picked : [])).then(function (payload) {
            adoptState(payload.state);
            say(message, 'Sent. It is in line for analysis — you can go back to the board.', 'text-success');
        }).catch(function (error) {
            busy = false;
            refreshSendGate();
            say(message, error.message, 'text-danger');
        });
    }

    // ── Wiring ────────────────────────────────────────────────────────

    if (btnStart) {
        btnStart.addEventListener('click', start);
    }

    if (btnStop) {
        btnStop.addEventListener('click', stop);
    }

    if (btnSend) {
        btnSend.addEventListener('click', send);
    }

    if (btnSaveAnswers) {
        btnSaveAnswers.addEventListener('click', saveAnswers);
    }

    if (answersPanel) {
        answersPanel.addEventListener('change', function (event) {
            if (event.target.type === 'radio') {
                refreshSendGate();
            }
        });
    }

    refreshSendGate();
    window.addEventListener('pagehide', function () {
        releaseStream();
        stopStandDown();
    });
})();
