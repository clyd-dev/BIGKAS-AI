@extends('layouts.app')

@section('title', 'Guided Reading Practice')

@section('content')
    <x-page-header title="Guided Reading" icon="bi-book"
                   :back="route('practice.index')" back-label="Practice Center" />

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Learner</label>
                    <select id="learnerSelect" class="form-select form-select-sm">
                        <option value="">Select learner...</option>
                        @foreach($learners as $learner)
                            <option value="{{ $learner->id }}" data-grade="{{ $learner->grade_level }}">
                                {{ $learner->full_name }} (Grade {{ $learner->grade_level }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Reading Material</label>
                    <select id="materialSelect" class="form-select form-select-sm" disabled>
                        <option value="">Select learner first...</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Font Size</label>
                    <select id="fontSizeSelect" class="form-select form-select-sm">
                        <option value="1rem">Normal</option>
                        <option value="1.3rem" selected>Large</option>
                        <option value="1.6rem">Extra Large</option>
                        <option value="2rem">Huge</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="button" id="startBtn" class="btn btn-sm btn-warning w-100" disabled>
                        <i class="bi bi-play-fill me-1"></i> Start
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body py-5" id="readingArea">
            <div class="text-center text-muted">
                <i class="bi bi-book display-1 opacity-25"></i>
                <p class="mt-3">Select a learner and material to begin guided reading practice.</p>
            </div>
        </div>
    </div>

    <div id="recordingCard" class="card border-0 shadow-sm mt-3 d-none">
        <div class="card-body text-center">
            <h5><i class="bi bi-mic me-2"></i>Record Your Reading</h5>
            <p class="text-muted">Read the passage aloud while recording. Click stop when finished.</p>
            <div class="d-flex justify-content-center gap-3 mt-3">
                <button type="button" id="recordBtn" class="btn btn-danger">
                    <i class="bi bi-mic-fill me-1"></i> Start Recording
                </button>
                <button type="button" id="stopRecordBtn" class="btn btn-secondary" disabled>
                    <i class="bi bi-stop-fill me-1"></i> Stop & Analyze
                </button>
            </div>
            <div id="recordingStatus" class="mt-3 text-muted"></div>
        </div>
    </div>

    <div id="resultsCard" class="card border-0 shadow-sm mt-3 d-none">
        <div class="card-body">
            <h5><i class="bi bi-graph-up me-2"></i>Reading Analysis Results</h5>
            <div class="row g-3 mt-3">
                <div class="col-md-3">
                    <div class="p-3 bg-light rounded text-center">
                        <div class="text-muted small">Accuracy</div>
                        <div class="h4 mb-0" id="resultAccuracy">0%</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 bg-light rounded text-center">
                        <div class="text-muted small">Speed</div>
                        <div class="h4 mb-0" id="resultWpm">0 WPM</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 bg-light rounded text-center">
                        <div class="text-muted small">Fluency</div>
                        <div class="h4 mb-0" id="resultFluency">0/10</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 bg-light rounded text-center">
                        <div class="text-muted small">Level</div>
                        <div class="h4 mb-0" id="resultLevel">-</div>
                    </div>
                </div>
            </div>
            <div class="mt-3" id="resultWeakness"></div>
            <div class="mt-3" id="resultErrors"></div>
            <div class="mt-3">
                <a href="{{ route('practice.index') }}" class="btn btn-primary">Back to Practice Center</a>
            </div>
        </div>
    </div>
    @endsection

    @push('styles')
    <style>
        .reading-passage {
            line-height: 1.8;
            transition: font-size 0.2s ease;
        }
        .recording-pulse {
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
    </style>
    @endpush

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const learnerSelect = document.getElementById('learnerSelect');
            const materialSelect = document.getElementById('materialSelect');
            const fontSizeSelect = document.getElementById('fontSizeSelect');
            const startBtn = document.getElementById('startBtn');
            const readingArea = document.getElementById('readingArea');
            const recordingCard = document.getElementById('recordingCard');
            const resultsCard = document.getElementById('resultsCard');
            const recordBtn = document.getElementById('recordBtn');
            const stopRecordBtn = document.getElementById('stopRecordBtn');
            const recordingStatus = document.getElementById('recordingStatus');

            let mediaRecorder = null;
            let audioChunks = [];
            let currentMaterial = null;
            let loadedMaterials = [];
            let startTime = null;

            learnerSelect.addEventListener('change', function() {
                const grade = this.selectedOptions[0]?.dataset?.grade;
                if (!grade) {
                    materialSelect.innerHTML = '<option value="">Select learner first...</option>';
                    materialSelect.disabled = true;
                    startBtn.disabled = true;
                    return;
                }

                materialSelect.innerHTML = '<option value="">Loading...</option>';
                materialSelect.disabled = true;

                fetch("{{ route('practice.materials') }}?grade_level=" + grade)
                    .then(r => r.json())
                    .then(response => {
                        const items = response.data || [];
                        if (!response.success || items.length === 0) {
                            throw new Error('empty');
                        }
                        loadedMaterials = items;
                        materialSelect.innerHTML = '<option value="">Select material...</option>';
                        items.forEach(item => {
                            materialSelect.innerHTML += `<option value="${item.id}">${item.title} (${item.language_name}, ${item.word_count} words)</option>`;
                        });
                        materialSelect.disabled = false;
                    })
                    .catch(() => {
                        materialSelect.innerHTML = '<option value="">Error loading materials</option>';
                    });
            });

            materialSelect.addEventListener('change', function() {
                startBtn.disabled = !this.value;
            });

            startBtn.addEventListener('click', function() {
                const materialId = materialSelect.value;
                if (!materialId) return;

                startBtn.disabled = true;
                readingArea.innerHTML = '<div class="text-center"><div class="spinner-border text-warning" role="status"></div><p class="text-muted mt-2">Loading passage...</p></div>';

                currentMaterial = loadedMaterials.find(m => String(m.id) === String(materialId));
                if (!currentMaterial) {
                    readingArea.innerHTML = '<div class="alert alert-danger">Failed to load material. Please try again.</div>';
                    startBtn.disabled = false;
                    return;
                }
                showPassage();
            });

            function showPassage() {
                const fontSize = fontSizeSelect.value;
                readingArea.innerHTML = `
                    <div class="reading-passage" style="font-size: ${fontSize};">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="badge bg-warning bg-opacity-10 text-warning">${currentMaterial.title || 'Reading Passage'}</span>
                            <span class="text-muted small">Read the passage aloud, then record yourself</span>
                        </div>
                        <div class="p-4 bg-light rounded">
                            <p class="mb-0" style="white-space: pre-line;">${currentMaterial.content || currentMaterial.text || currentMaterial.passage || 'No content available.'}</p>
                        </div>
                    </div>
                `;
                recordingCard.classList.remove('d-none');
                resultsCard.classList.add('d-none');
            }

            recordBtn.addEventListener('click', async function() {
                try {
                    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                    mediaRecorder = new MediaRecorder(stream);
                    audioChunks = [];

                    mediaRecorder.ondataavailable = function(e) {
                        audioChunks.push(e.data);
                    };

                    mediaRecorder.onstop = function() {
                        const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                        stream.getTracks().forEach(track => stop());
                        sendForAnalysis(audioBlob);
                    };

                    mediaRecorder.startTime = Date.now();
                    startTime = Date.now();
                    mediaRecorder.start();

                    recordBtn.disabled = true;
                    recordBtn.classList.add('recording-pulse');
                    stopRecordBtn.disabled = false;
                    recordingStatus.innerHTML = '<span class="text-danger"><i class="bi bi-circle-fill me-1"></i> Recording...</span>';
                } catch (err) {
                    recordingStatus.innerHTML = '<span class="text-danger">Microphone access denied. Please allow microphone access.</span>';
                }
            });

            stopRecordBtn.addEventListener('click', function() {
                if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                    mediaRecorder.stop();
                }
                recordBtn.disabled = false;
                recordBtn.classList.remove('recording-pulse');
                stopRecordBtn.disabled = true;
                recordingStatus.innerHTML = '<span class="text-muted">Processing...</span>';
            });

            function sendForAnalysis(audioBlob) {
                const elapsed = Math.round((Date.now() - startTime) / 1000);
                const formData = new FormData();
                formData.append('audio', audioBlob, 'reading.webm');

                const url = "{{ route('practice.analyze', ['learner' => '__LEARNER__', 'material' => '__MATERIAL__']) }}"
                    .replace('__LEARNER__', learnerSelect.value)
                    .replace('__MATERIAL__', materialSelect.value);

                fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    },
                    body: formData
                })
                .then(r => r.json())
                .then(response => {
                    if (response.success) {
                        showResults(response.data, elapsed);
                    } else {
                        recordingStatus.innerHTML = '<span class="text-danger">Analysis failed: ' + (response.message || 'Unknown error') + '</span>';
                    }
                })
                .catch(() => {
                    recordingStatus.innerHTML = '<span class="text-danger">Analysis failed. Please try again.</span>';
                });
            }

            function showResults(data, elapsed) {
                recordingCard.classList.add('d-none');
                resultsCard.classList.remove('d-none');

                document.getElementById('resultAccuracy').textContent = (data.accuracy_rate || 0) + '%';
                document.getElementById('resultWpm').textContent = (data.words_per_minute || 0) + ' WPM';
                document.getElementById('resultFluency').textContent = (data.fluency_score || 0) + '/10';
                document.getElementById('resultLevel').textContent = data.reading_level || '-';

                const weaknessNames = {0: 'Independent', 1: 'Phonemic Awareness', 2: 'Decoding Accuracy', 3: 'Oral Reading Fluency', 4: 'Reading Comprehension'};
                const weaknessEl = document.getElementById('resultWeakness');
                if (data.primary_weakness !== null && data.primary_weakness !== undefined) {
                    const wName = weaknessNames[data.primary_weakness] || 'Unknown';
                    weaknessEl.innerHTML = `
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i><strong>Primary Focus:</strong> ${wName} (confidence: ${((data.confidence_score || 0) * 100).toFixed(0)}%)
                        </div>
                    `;
                } else {
                    weaknessEl.innerHTML = '';
                }

                const errorsEl = document.getElementById('resultErrors');
                if (data.error_count > 0) {
                    errorsEl.innerHTML = `
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i><strong>Errors:</strong> ${data.error_count} total (${data.substitutions} substitutions, ${data.omissions} omissions, ${data.insertions} insertions)
                        </div>
                    `;
                } else {
                    errorsEl.innerHTML = '';
                }

                fetch("{{ route('practice.complete') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    },
                    body: JSON.stringify({
                        learner_id: learnerSelect.value,
                        session_type: 'guided_reading',
                        score: data.accuracy_rate || 0,
                        time_spent: elapsed,
                        material_id: materialSelect.value,
                        details: data
                    })
                }).catch(() => {});
            }
        });
    </script>
    @endpush
