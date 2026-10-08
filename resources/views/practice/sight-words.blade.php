@extends('layouts.app')

@section('title', 'Sight Words Practice')

@section('content')
    <x-page-header title="Sight Words" icon="bi-eye"
                   :back="route('practice.index')" back-label="Practice Center" />

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
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
                    <label class="form-label small">Language</label>
                    <select id="languageSelect" class="form-select form-select-sm" disabled>
                        <option value="">Select learner first...</option>
                        <option value="en">English</option>
                        <option value="fil">Filipino</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="button" id="startBtn" class="btn btn-sm btn-success w-100" disabled>
                        <i class="bi bi-play-fill me-1"></i> Start
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5" id="practiceArea">
            <i class="bi bi-eye display-1 text-muted opacity-25"></i>
            <p class="text-muted mt-3">Select a learner and language to begin sight word practice.</p>
        </div>
    </div>

    <div id="progressCard" class="card border-0 shadow-sm mt-3 d-none">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="small text-muted">Progress</span>
                <span class="small text-muted" id="progressText">0 / 0</span>
            </div>
            <div class="progress" style="height: 8px;">
                <div class="progress-bar bg-success" id="progressBar" style="width: 0%"></div>
            </div>
        </div>
    </div>

    <div id="resultsCard" class="card border-0 shadow-sm mt-3 d-none">
        <div class="card-body text-center">
            <h5><i class="bi bi-trophy me-2"></i>Practice Complete!</h5>
            <div class="row g-3 mt-3">
                <div class="col-md-4">
                    <div class="p-3 bg-light rounded">
                        <div class="text-muted small">Score</div>
                        <div class="h4 mb-0" id="resultScore">0%</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 bg-light rounded">
                        <div class="text-muted small">Known</div>
                        <div class="h4 mb-0" id="resultKnown">0/0</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 bg-light rounded">
                        <div class="text-muted small">Time</div>
                        <div class="h4 mb-0" id="resultTime">0:00</div>
                    </div>
                </div>
            </div>
            <div class="mt-3">
                <a href="{{ route('practice.index') }}" class="btn btn-primary">Back to Practice Center</a>
            </div>
        </div>
    </div>
    @endsection

    @push('styles')
    <style>
        .flashcard {
            perspective: 1000px;
            cursor: pointer;
        }
        .flashcard-inner {
            transition: transform 0.6s;
            transform-style: preserve-3d;
        }
        .flashcard.flipped .flashcard-inner {
            transform: rotateY(180deg);
        }
        .flashcard-front, .flashcard-back {
            backface-visibility: hidden;
        }
        .flashcard-back {
            transform: rotateY(180deg);
        }
    </style>
    @endpush

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const learnerSelect = document.getElementById('learnerSelect');
            const languageSelect = document.getElementById('languageSelect');
            const startBtn = document.getElementById('startBtn');
            const practiceArea = document.getElementById('practiceArea');
            const progressCard = document.getElementById('progressCard');
            const resultsCard = document.getElementById('resultsCard');
            const progressBar = document.getElementById('progressBar');
            const progressText = document.getElementById('progressText');

            let currentItems = [];
            let currentIndex = 0;
            let knownCount = 0;
            let startTime = null;
            let sessionResults = [];

            learnerSelect.addEventListener('change', function() {
                const grade = this.selectedOptions[0]?.dataset?.grade;
                if (!grade) {
                    languageSelect.innerHTML = '<option value="">Select learner first...</option><option value="en">English</option><option value="fil">Filipino</option>';
                    languageSelect.disabled = true;
                    startBtn.disabled = true;
                    return;
                }
                languageSelect.disabled = false;
                startBtn.disabled = true;
            });

            languageSelect.addEventListener('change', function() {
                startBtn.disabled = !this.value;
            });

            startBtn.addEventListener('click', function() {
                const grade = learnerSelect.selectedOptions[0]?.dataset?.grade;
                const lang = languageSelect.value;
                if (!grade || !lang) return;

                startBtn.disabled = true;
                practiceArea.innerHTML = '<div class="text-center"><div class="spinner-border text-success" role="status"></div><p class="text-muted mt-2">Loading words...</p></div>';

                fetch("{{ route('practice.items') }}?type=sight_word&grade_level=" + grade + "&language=" + lang)
                    .then(r => r.json())
                    .then(response => {
                        const items = response.data || [];
                        if (!response.success || items.length === 0) {
                            throw new Error('empty');
                        }
                        currentItems = items;
                        currentIndex = 0;
                        knownCount = 0;
                        startTime = Date.now();
                        sessionResults = [];
                        progressCard.classList.remove('d-none');
                        resultsCard.classList.add('d-none');
                        showFlashcard();
                    })
                    .catch(() => {
                        practiceArea.innerHTML = '<div class="alert alert-danger">Failed to load words. Please try again.</div>';
                        startBtn.disabled = false;
                    });
            });

            function showFlashcard() {
                if (currentIndex >= currentItems.length) {
                    showResults();
                    return;
                }

                const item = currentItems[currentIndex];
                progressText.textContent = (currentIndex + 1) + ' / ' + currentItems.length;
                progressBar.style.width = ((currentIndex + 1) / currentItems.length * 100) + '%';

                practiceArea.innerHTML = `
                    <div class="flashcard mx-auto" id="flashcard" style="max-width: 400px;">
                        <div class="flashcard-inner position-relative" style="min-height: 250px;">
                            <div class="flashcard-front card border-0 shadow-sm position-absolute w-100 h-100 d-flex align-items-center justify-content-center">
                                <div class="text-center">
                                    <span class="badge bg-success bg-opacity-10 text-success mb-3">Word ${currentIndex + 1}</span>
                                    <h2 class="display-4 fw-bold">${item.content}</h2>
                                    <p class="text-muted mt-3 mb-0">Click to flip</p>
                                </div>
                            </div>
                            <div class="flashcard-back card border-0 shadow-sm position-absolute w-100 h-100 d-flex align-items-center justify-content-center bg-success bg-opacity-10">
                                <div class="text-center">
                                    <h4>${item.definition || item.example || 'No definition available'}</h4>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-center gap-3 mt-4">
                        <button class="btn btn-outline-danger px-4" id="unknownBtn">
                            <i class="bi bi-x-lg me-1"></i> Still Learning
                        </button>
                        <button class="btn btn-outline-success px-4" id="knownBtn">
                            <i class="bi bi-check-lg me-1"></i> I Know This
                        </button>
                    </div>
                `;

                document.getElementById('flashcard').addEventListener('click', function() {
                    this.classList.toggle('flipped');
                });

                document.getElementById('knownBtn').addEventListener('click', function() {
                    knownCount++;
                    sessionResults.push({ item_id: item.id, known: true });
                    nextCard();
                });

                document.getElementById('unknownBtn').addEventListener('click', function() {
                    sessionResults.push({ item_id: item.id, known: false });
                    nextCard();
                });
            }

            function nextCard() {
                currentIndex++;
                showFlashcard();
            }

            function showResults() {
                const total = currentItems.length;
                const score = total > 0 ? Math.round((knownCount / total) * 100) : 0;
                const elapsed = Math.round((Date.now() - startTime) / 1000);

                document.getElementById('resultScore').textContent = score + '%';
                document.getElementById('resultKnown').textContent = knownCount + '/' + total;
                document.getElementById('resultTime').textContent = Math.floor(elapsed / 60) + ':' + String(elapsed % 60).padStart(2, '0');

                progressCard.classList.add('d-none');
                resultsCard.classList.remove('d-none');

                fetch("{{ route('practice.complete') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    },
                    body: JSON.stringify({
                        learner_id: learnerSelect.value,
                        session_type: 'sight_words',
                        score: score,
                        time_spent: elapsed,
                        results: sessionResults
                    })
                }).catch(() => {});
            }
        });
    </script>
    @endpush
