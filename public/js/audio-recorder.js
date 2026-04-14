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
            audioPlayback.src = audioUrl;
            audioPlayback.classList.remove('d-none');

            // Update UI
            showStatus('idle');
            document.getElementById('btnStartRecording').classList.add('d-none');
            document.getElementById('btnStopRecording').classList.add('d-none');
            document.getElementById('btnPlayback').classList.remove('d-none');
            document.getElementById('btnRetry').classList.remove('d-none');
            document.getElementById('btnAnalyze').classList.remove('d-none');
        };

        // Start recording
        mediaRecorder.start(1000); // Collect data every second
        recordingStartTime = Date.now();

        // Update UI
        showStatus('recording');
        document.getElementById('btnStartRecording').classList.add('d-none');
        document.getElementById('btnStopRecording').classList.remove('d-none');

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
    audioPlayback.src = '';
    audioPlayback.classList.add('d-none');

    document.getElementById('btnStartRecording').classList.remove('d-none');
    document.getElementById('btnStopRecording').classList.add('d-none');
    document.getElementById('btnPlayback').classList.add('d-none');
    document.getElementById('btnRetry').classList.add('d-none');
    document.getElementById('btnAnalyze').classList.add('d-none');

    showStatus('idle');
    resetTimer();
}

// ============================================================
// AI ANALYSIS
// ============================================================

async function analyzeRecording() {
    if (!audioBlob) {
        alert('No recording found. Please record first.');
        return;
    }

    const assessmentId = document.getElementById('assessmentId').value;
    const csrfToken = BigkasAI.getCsrfToken();

    // Show processing status
    showStatus('processing');
    document.getElementById('btnPlayback').classList.add('d-none');
    document.getElementById('btnRetry').classList.add('d-none');
    document.getElementById('btnAnalyze').classList.add('d-none');

    try {
        // Step 1: Upload audio (0-40%)
        updateProgress(5, 'Uploading audio recording...');

        const formData = new FormData();
        const extension = getExtensionFromMime(audioBlob.type);
        formData.append('audio', audioBlob, `recording.${extension}`);
        formData.append('_token', csrfToken);

        const uploadResponse = await fetch(`/assessments/${assessmentId}/upload-audio`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken
            },
            body: formData
        });

        const uploadResult = await uploadResponse.json();

        if (!uploadResult.success) {
            throw new Error(uploadResult.message || 'Upload failed');
        }

        updateProgress(40, 'Audio uploaded. Starting AI analysis...');

        // Step 2: Trigger analysis (40-100%)
        updateProgress(50, 'Transcribing speech to text...');

        const analyzeResponse = await fetch(`/assessments/${assessmentId}/analyze`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify({})
        });

        // Simulate progress during analysis
        let progress = 50;
        const progressInterval = setInterval(() => {
            progress = Math.min(progress + 5, 90);
            const messages = {
                55: 'Transcribing speech to text...',
                65: 'Comparing with reference text...',
                75: 'Running ML classification...',
                85: 'Generating recommendations...',
                90: 'Finalizing results...'
            };
            updateProgress(progress, messages[progress] || 'Processing...');
        }, 1500);

        const analyzeResult = await analyzeResponse.json();
        clearInterval(progressInterval);

        if (!analyzeResult.success) {
            throw new Error(analyzeResult.message || 'Analysis failed');
        }

        updateProgress(100, 'Analysis complete!');

        // Show complete status
        showStatus('complete');

        // Redirect to results after brief delay
        setTimeout(() => {
            if (analyzeResult.data && analyzeResult.data.redirect_url) {
                window.location.href = analyzeResult.data.redirect_url;
            } else {
                window.location.href = `/assessments/${assessmentId}/results`;
            }
        }, 1500);

    } catch (error) {
        console.error('Analysis error:', error);
        showStatus('idle');

        document.getElementById('btnPlayback').classList.remove('d-none');
        document.getElementById('btnRetry').classList.remove('d-none');
        document.getElementById('btnAnalyze').classList.remove('d-none');

        alert('Analysis failed: ' + error.message + '\n\nPlease try again.');
    }
}

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
