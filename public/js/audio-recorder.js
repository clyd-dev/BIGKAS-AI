/**
 * BIGKAS-AI Audio Recorder
 * 
 * Handles browser-based audio recording using the Web Audio API
 * and MediaRecorder API for the reading assessment flow.
 */

// ============================================================
// STATE
// ============================================================
let mediaRecorder = null;
let audioChunks = [];
let audioBlob = null;
let audioUrl = null;
let recordingStartTime = null;
let timerInterval = null;
let audioStream = null;
let analyserNode = null;
let animationFrameId = null;

// ============================================================
// RECORDING CONTROLS
// ============================================================

async function startRecording() {
    try {
        // Request microphone access
        audioStream = await navigator.mediaDevices.getUserMedia({
            audio: {
                channelCount: 1,
                sampleRate: 16000,
                echoCancellation: true,
                noiseSuppression: true
            }
        });

        // Set up MediaRecorder
        const mimeType = getSupportedMimeType();
        mediaRecorder = new MediaRecorder(audioStream, {
            mimeType: mimeType,
            audioBitsPerSecond: 128000
        });

        audioChunks = [];

        mediaRecorder.ondataavailable = (event) => {
            if (event.data.size > 0) {
                audioChunks.push(event.data);
            }
        };

        mediaRecorder.onstop = () => {
            audioBlob = new Blob(audioChunks, { type: mimeType });
            audioUrl = URL.createObjectURL(audioBlob);

            // Show playback
            const audioPlayback = document.getElementById('audioPlayback');
            if (audioPlayback) {
                audioPlayback.src = audioUrl;
                audioPlayback.classList.remove('d-none');
            }

            // Update UI
            showStatus('idle');
            const btnStart = document.getElementById('btnStartRecording');
            const btnStop = document.getElementById('btnStopRecording');
            const btnRetry = document.getElementById('btnRetry');
            const btnAnalyze = document.getElementById('btnAnalyze');

            if (btnStart) btnStart.classList.add('d-none');
            if (btnStop) btnStop.classList.add('d-none');
            if (btnRetry) btnRetry.classList.remove('d-none');
            if (btnAnalyze) btnAnalyze.classList.remove('d-none');

            // Reading is done — the comprehension questions come next.
            revealComprehensionPanel();
            updateAnalyzeGate();
        };

        // Start recording
        mediaRecorder.start(1000); // Collect data every second
        recordingStartTime = Date.now();

        // Update UI
        showStatus('recording');
        document.getElementById('btnStartRecording').classList.add('d-none');
        document.getElementById('btnStopRecording').classList.remove('d-none');

        // Uploading a file is an alternative to recording, not an addition —
        // once we're recording here, it no longer applies.
        hideUploadSection();

        // Start timer
        startTimer();

        // Start audio visualizer
        setupVisualizer(audioStream);

    } catch (error) {
        console.error('Microphone access error:', error);
        alert('Could not access microphone. Please ensure microphone permissions are granted.\n\nError: ' + error.message);
    }
}

function stopRecording() {
    if (mediaRecorder && mediaRecorder.state === 'recording') {
        mediaRecorder.stop();

        // Stop all tracks
        if (audioStream) {
            audioStream.getTracks().forEach(track => track.stop());
        }

        // Stop timer
        stopTimer();

        // Stop visualizer
        if (animationFrameId) {
            cancelAnimationFrame(animationFrameId);
        }
    }
}

function playRecording() {
    const audioPlayback = document.getElementById('audioPlayback');
    if (audioPlayback.src) {
        audioPlayback.play();
    }
}

function retryRecording() {
    // Reset state
    audioBlob = null;
    audioUrl = null;
    audioChunks = [];

    // Reset UI
    const audioPlayback = document.getElementById('audioPlayback');
    if (audioPlayback) {
        audioPlayback.src = '';
        audioPlayback.classList.add('d-none');
    }

    const btnStart = document.getElementById('btnStartRecording');
    const btnStop = document.getElementById('btnStopRecording');
    const btnRetry = document.getElementById('btnRetry');
    const btnAnalyze = document.getElementById('btnAnalyze');

    if (btnStart) btnStart.classList.remove('d-none');
    if (btnStop) btnStop.classList.add('d-none');
    if (btnRetry) btnRetry.classList.add('d-none');
    if (btnAnalyze) btnAnalyze.classList.add('d-none');

    showStatus('idle');
    resetTimer();
}

// ============================================================
// COMPREHENSION TEST
// ============================================================

function hideUploadSection() {
    const uploadSection = document.getElementById('uploadAudioSection');
    if (uploadSection) uploadSection.classList.add('d-none');
}

function revealComprehensionPanel() {
    const panel = document.getElementById('comprehensionPanel');
    if (panel) panel.classList.remove('d-none');
}

/**
 * Returns {answers, total, answered} for the comprehension panel, or null when
 * this assessment has no questions to ask (or the learner already answered them).
 */
function collectComprehensionAnswers() {
    const panel = document.getElementById('comprehensionPanel');
    if (!panel || panel.dataset.prefilled === '1') return null;

    const questions = panel.querySelectorAll('.comprehension-question');
    if (questions.length === 0) return null;

    const answers = {};
    let answered = 0;

    questions.forEach(q => {
        const picked = q.querySelector('input[type="radio"]:checked');
        if (picked) {
            answers[q.dataset.questionId] = picked.value;
            answered++;
        }
    });

    return { answers: answers, total: questions.length, answered: answered };
}

/** Analyze stays disabled until every comprehension question has an answer. */
function updateAnalyzeGate() {
    const btnAnalyze = document.getElementById('btnAnalyze');
    if (!btnAnalyze) return;

    const state = collectComprehensionAnswers();
    if (!state) return;

    const complete = state.answered === state.total;
    btnAnalyze.disabled = !complete;

    const msg = document.getElementById('comprehensionGateMsg');
    if (msg) {
        msg.textContent = complete
            ? 'All questions answered — you can analyze now.'
            : `Answered ${state.answered} of ${state.total}. Answer all to enable Analyze.`;
        msg.classList.toggle('text-success', complete);
        msg.classList.toggle('text-muted', !complete);
    }
}

// ============================================================
// AI ANALYSIS
// ============================================================

async function analyzeRecording() {
    const hasExistingAudio = document.getElementById('audioPlayback') && document.getElementById('audioPlayback').src && document.getElementById('audioPlayback').src !== window.location.href;
    
    if (!audioBlob && !hasExistingAudio) {
        alert('No recording found. Please record first.');
        return;
    }

    const comprehension = collectComprehensionAnswers();
    if (comprehension && comprehension.answered < comprehension.total) {
        alert(`Please answer all ${comprehension.total} comprehension questions before analyzing.`);
        return;
    }

    const assessmentId = document.getElementById('assessmentId').value;
    const csrfToken = window.BIGKAS_CSRF || document.querySelector('meta[name="csrf-token"]')?.content;

    // Show processing status
    showStatus('processing');
    const btnAnalyze = document.getElementById('btnAnalyze');
    const originalText = btnAnalyze.innerHTML;
    btnAnalyze.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing AI...';
    btnAnalyze.disabled = true;

    try {
        const formData = new FormData();
        if (audioBlob) {
            const extension = getExtensionFromMime(audioBlob.type);
            formData.append('audio', audioBlob, `recording.${extension}`);
        }
        formData.append('_token', csrfToken);

        if (comprehension) {
            Object.entries(comprehension.answers).forEach(([questionId, letter]) => {
                formData.append(`answers[${questionId}]`, letter);
            });
        }

        // We will send BOTH the audio and trigger analysis in a single step for this prototype
        const response = await fetch(`/assessments/${assessmentId}/analyze`, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        const result = await response.json();

        if (!result.success) {
            throw new Error(result.message || 'Analysis failed');
        }

        // Show complete status
        showStatus('complete');
        btnAnalyze.innerHTML = '<i class="bi bi-check-circle"></i> Complete! Redirecting...';
        
        // Redirect to the actual Results page!
        setTimeout(() => {
            window.location.href = result.redirect_url;
        }, 500);

    } catch (error) {
        console.error('Analysis error:', error);
        showStatus('idle');
        btnAnalyze.innerHTML = originalText;
        btnAnalyze.disabled = false;
        alert('Analysis failed: ' + error.message + '\n\nPlease try again.');
    }
}

// ============================================================
// EVENT LISTENERS BINDING
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    const btnStart = document.getElementById('btnStartRecording');
    const btnStop = document.getElementById('btnStopRecording');
    const btnRetry = document.getElementById('btnRetry');
    const btnAnalyze = document.getElementById('btnAnalyze');

    if (btnStart) btnStart.addEventListener('click', startRecording);
    if (btnStop) btnStop.addEventListener('click', stopRecording);
    if (btnRetry) btnRetry.addEventListener('click', retryRecording);
    if (btnAnalyze) btnAnalyze.addEventListener('click', analyzeRecording);

    // Comprehension answers gate the Analyze button; highlight the picked option.
    const panel = document.getElementById('comprehensionPanel');
    if (panel) {
        panel.addEventListener('change', function (e) {
            if (e.target.type !== 'radio') return;

            const question = e.target.closest('.comprehension-question');
            if (question) {
                question.querySelectorAll('.comprehension-option').forEach(opt => {
                    opt.classList.remove('border-primary', 'bg-primary-subtle');
                });
                e.target.closest('.comprehension-option').classList.add('border-primary', 'bg-primary-subtle');
            }

            updateAnalyzeGate();
        });

        // Audio already on the server (uploaded, or recorded by the learner):
        // the questions apply right away rather than waiting on a stop event.
        updateAnalyzeGate();
    }
});

// ============================================================
// UI HELPERS
// ============================================================

function showStatus(status) {
    const statuses = ['Idle', 'Recording', 'Processing', 'Complete'];
    statuses.forEach(s => {
        const el = document.getElementById('status' + s);
        if (el) {
            el.classList.toggle('d-none', s.toLowerCase() !== status);
        }
    });
}

function updateProgress(percent, text) {
    const bar = document.getElementById('progressBar');
    const label = document.getElementById('progressText');
    if (bar) bar.style.width = percent + '%';
    if (label) label.textContent = text;
}

// ============================================================
// TIMER
// ============================================================

function startTimer() {
    const timerEl = document.getElementById('recordingTimer');
    timerInterval = setInterval(() => {
        const elapsed = Math.floor((Date.now() - recordingStartTime) / 1000);
        const minutes = Math.floor(elapsed / 60).toString().padStart(2, '0');
        const seconds = (elapsed % 60).toString().padStart(2, '0');
        if (timerEl) timerEl.textContent = `${minutes}:${seconds}`;

        // Auto-stop after 3 minutes
        if (elapsed >= 180) {
            stopRecording();
        }
    }, 1000);
}

function stopTimer() {
    if (timerInterval) {
        clearInterval(timerInterval);
        timerInterval = null;
    }
}

function resetTimer() {
    stopTimer();
    const timerEl = document.getElementById('recordingTimer');
    if (timerEl) timerEl.textContent = '00:00';
}

// ============================================================
// AUDIO VISUALIZER
// ============================================================

function setupVisualizer(stream) {
    const canvas = document.getElementById('audioVisualizer');
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    const audioContext = new (window.AudioContext || window.webkitAudioContext)();
    const source = audioContext.createMediaStreamSource(stream);
    analyserNode = audioContext.createAnalyser();
    analyserNode.fftSize = 256;

    source.connect(analyserNode);

    const bufferLength = analyserNode.frequencyBinCount;
    const dataArray = new Uint8Array(bufferLength);

    function draw() {
        animationFrameId = requestAnimationFrame(draw);

        analyserNode.getByteFrequencyData(dataArray);

        ctx.fillStyle = 'rgba(255, 255, 255, 0.9)';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        const barWidth = (canvas.width / bufferLength) * 2;
        let x = 0;

        for (let i = 0; i < bufferLength; i++) {
            const barHeight = (dataArray[i] / 255) * canvas.height;

            const r = Math.floor(220 - dataArray[i] * 0.5);
            const g = Math.floor(53 + dataArray[i] * 0.3);
            const b = Math.floor(69 + dataArray[i] * 0.2);
            ctx.fillStyle = `rgb(${r}, ${g}, ${b})`;

            ctx.fillRect(x, canvas.height - barHeight, barWidth - 1, barHeight);
            x += barWidth;
        }
    }

    draw();
}

// ============================================================
// FONT SIZE CONTROLS
// ============================================================

document.addEventListener('DOMContentLoaded', function() {
    const passageText = document.getElementById('passageText');

    const btnIncrease = document.getElementById('btnFontIncrease');
    const btnDecrease = document.getElementById('btnFontDecrease');

    let currentFontSize = 1.3; // rem

    if (btnIncrease) {
        btnIncrease.addEventListener('click', () => {
            currentFontSize = Math.min(currentFontSize + 0.2, 2.5);
            if (passageText) passageText.style.fontSize = currentFontSize + 'rem';
        });
    }

    if (btnDecrease) {
        btnDecrease.addEventListener('click', () => {
            currentFontSize = Math.max(currentFontSize - 0.2, 0.9);
            if (passageText) passageText.style.fontSize = currentFontSize + 'rem';
        });
    }
});

// ============================================================
// UTILITY FUNCTIONS
// ============================================================

function getSupportedMimeType() {
    const types = [
        'audio/webm;codecs=opus',
        'audio/webm',
        'audio/ogg;codecs=opus',
        'audio/ogg',
        'audio/mp4',
        'audio/wav'
    ];

    for (const type of types) {
        if (MediaRecorder.isTypeSupported(type)) {
            return type;
        }
    }

    return 'audio/webm'; // fallback
}

function getExtensionFromMime(mimeType) {
    const map = {
        'audio/webm': 'webm',
        'audio/ogg': 'ogg',
        'audio/mp4': 'mp4',
        'audio/wav': 'wav',
        'audio/mpeg': 'mp3'
    };

    for (const [key, ext] of Object.entries(map)) {
        if (mimeType.includes(key)) return ext;
    }

    return 'webm';
}
