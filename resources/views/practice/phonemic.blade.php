@extends('layouts.app')

@section('title', 'Phonemic Awareness Practice')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-ear me-2"></i>Phonemic Awareness</h4>
        <a href="{{ route('practice.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back</a>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small">Learner</label>
                    <select id="learnerSelect" class="form-select form-select-sm">
                        <option value="">Select learner...</option>
                        @foreach($learners as $learner)
                            <option value="{{ $learner->id }}" data-grade="{{ $learner->grade_level }}" data-lang="{{ $learner->mother_tongue ?? 'en' }}">
                                {{ $learner->full_name }} (Grade {{ $learner->grade_level }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Category</label>
                    <select id="categorySelect" class="form-select form-select-sm" disabled>
                        <option value="">Select learner first...</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="button" id="startBtn" class="btn btn-sm btn-primary w-100" disabled>
                        <i class="bi bi-play-fill me-1"></i> Start
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5" id="practiceArea">
            <i class="bi bi-ear display-1 text-muted opacity-25"></i>
            <p class="text-muted mt-3">Select a learner and category to begin practice.</p>
        </div>
    </div>

    <div id="progressCard" class="card border-0 shadow-sm mt-3 d-none">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="small text-muted">Progress</span>
                <span class="small text-muted" id="progressText">0 / 0</span>
            </div>
            <div class="progress" style="height: 8px;">
                <div class="progress-bar bg-primary" id="progressBar" style="width: 0%"></div>
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
                        <div class="text-muted small">Correct</div>
                        <div class="h4 mb-0" id="resultCorrect">0/0</div>
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
        .practice-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .practice-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.1) !important;
        }
        .practice-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            font-size: 1.75rem;
        }
    </style>
    @endpush

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const learnerSelect = document.getElementById('learnerSelect');
            const categorySelect = document.getElementById('categorySelect');
            const startBtn = document.getElementById('startBtn');
            const practiceArea = document.getElementById('practiceArea');
            const progressCard = document.getElementById('progressCard');
            const resultsCard = document.getElementById('resultsCard');
            const progressBar = document.getElementById('progressBar');
            const progressText = document.getElementById('progressText');

            let currentItems = [];
            let currentIndex = 0;
            let correctCount = 0;
            let startTime = null;
            let sessionResults = [];

            learnerSelect.addEventListener('change', function() {
                const grade = this.selectedOptions[0]?.dataset?.grade;
                if (!grade) {
                    categorySelect.innerHTML = '<option value="">Select learner first...</option>';
                    categorySelect.disabled = true;
                    startBtn.disabled = true;
                    return;
                }

                categorySelect.innerHTML = '<option value="">Loading...</option>';
                categorySelect.disabled = true;

                fetch("{{ route('practice.items') }}?type=phonemic_sound&grade_level=" + grade)
                    .then(r => r.json())
                    .then(response => {
                        const items = response.data || [];
                        if (response.success && items.length > 0) {
                            categorySelect.innerHTML = '<option value="phonemic_sound">Phonemic Sounds (' + items.length + ' items)</option>';
                            categorySelect.disabled = false;
                            startBtn.disabled = false;
                        } else {
                            categorySelect.innerHTML = '<option value="">No items for this grade</option>';
                        }
                    })
                    .catch(() => {
                        categorySelect.innerHTML = '<option value="">Error loading categories</option>';
                    });
            });

            categorySelect.addEventListener('change', function() {
                startBtn.disabled = !this.value;
            });

            startBtn.addEventListener('click', function() {
                const grade = learnerSelect.selectedOptions[0]?.dataset?.grade;
                if (!grade) return;

                startBtn.disabled = true;
                practiceArea.innerHTML = '<div class="text-center"><div class="spinner-border text-primary" role="status"></div><p class="text-muted mt-2">Loading items...</p></div>';

                fetch("{{ route('practice.items') }}?type=phonemic_sound&grade_level=" + grade)
                    .then(r => r.json())
                    .then(response => {
                        const items = response.data || [];
                        if (!response.success || items.length === 0) {
                            throw new Error('empty');
                        }
                        currentItems = items;
                        currentIndex = 0;
                        correctCount = 0;
                        startTime = Date.now();
                        sessionResults = [];
                        progressCard.classList.remove('d-none');
                        resultsCard.classList.add('d-none');
                        showItem();
                    })
                    .catch(() => {
                        practiceArea.innerHTML = '<div class="alert alert-danger">Failed to load items. Please try again.</div>';
                        startBtn.disabled = false;
                    });
            });

            function showItem() {
                if (currentIndex >= currentItems.length) {
                    showResults();
                    return;
                }

                const item = currentItems[currentIndex];
                const meta = item.metadata || {};
                const options = meta.options || [];
                const correctAnswer = meta.example;
                progressText.textContent = (currentIndex + 1) + ' / ' + currentItems.length;
                progressBar.style.width = ((currentIndex + 1) / currentItems.length * 100) + '%';

                practiceArea.innerHTML = `
                    <div class="practice-question">
                        <div class="mb-3">
                            <span class="badge bg-primary bg-opacity-10 text-primary">Question ${currentIndex + 1}</span>
                        </div>
                        <h5 class="mb-4">Which word starts with the sound <strong>${item.content}</strong>?</h5>
                        <div class="row g-3 justify-content-center">
                            ${options.map((choice) => `
                                <div class="col-md-3 col-6">
                                    <button class="btn btn-outline-primary w-100 choice-btn" data-value="${choice}">
                                        ${choice}
                                    </button>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;

                practiceArea.querySelectorAll('.choice-btn').forEach(btn => {
                    btn.addEventListener('click', function() {
                        const selected = this.dataset.value;
                        const isCorrect = selected === correctAnswer;

                        if (isCorrect) {
                            correctCount++;
                            this.classList.remove('btn-outline-primary');
                            this.classList.add('btn-success');
                        } else {
                            this.classList.remove('btn-outline-primary');
                            this.classList.add('btn-danger');
                            practiceArea.querySelectorAll('.choice-btn').forEach(b => {
                                if (b.dataset.value === correctAnswer) b.classList.add('btn-success');
                            });
                        }

                        practiceArea.querySelectorAll('.choice-btn').forEach(b => b.disabled = true);

                        sessionResults.push({
                            item_id: item.id,
                            correct: isCorrect
                        });

                        setTimeout(() => {
                            currentIndex++;
                            showItem();
                        }, 1000);
                    });
                });
            }

            function showResults() {
                const total = currentItems.length;
                const score = total > 0 ? Math.round((correctCount / total) * 100) : 0;
                const elapsed = Math.round((Date.now() - startTime) / 1000);

                document.getElementById('resultScore').textContent = score + '%';
                document.getElementById('resultCorrect').textContent = correctCount + '/' + total;
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
                        session_type: 'phonemic_awareness',
                        score: score,
                        time_spent: elapsed,
                        results: sessionResults
                    })
                }).catch(() => {});
            }
        });
    </script>
    @endpush
