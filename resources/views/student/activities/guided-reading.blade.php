@extends('layouts.student')

@section('title', 'Guided Reading')

@section('content')

    <h4 class="fw-bold mb-3">📚 Guided Reading</h4>

    @if($materials->count() === 0)
        <div class="kid-card text-center py-5">
            <div style="font-size: 4rem;" class="mb-3">📭</div>
            <h5 class="fw-bold">No Stories Yet</h5>
            <p class="text-muted mb-3">There are no reading materials at your level yet. Ask your teacher!</p>
            <a href="{{ route('student.dashboard') }}" class="btn btn-kid btn-kid-primary">Back to Home</a>
        </div>
    @else
        <p class="text-muted mb-3" style="font-size: 0.85rem;">
            Pick a story to practice reading. Tap each word as you read it!
        </p>

        {{-- Material Selection --}}
        <div id="materialList">
            @foreach($materials as $material)
                <div class="activity-card" style="cursor: pointer;" onclick="openMaterial({{ $material->id }})">
                    <div class="activity-icon" style="background: #FFF0E6; color: var(--kid-orange);">
                        📖
                    </div>
                    <div class="activity-info">
                        <h6>{{ $material->title }}</h6>
                        <p>
                            Grade {{ $material->grade_level }}
                            @if($material->difficulty)
                                &middot; {{ ucfirst($material->difficulty) }}
                            @endif
                            &middot; {{ str_word_count($material->content ?? '') }} words
                        </p>
                    </div>
                    <i class="bi bi-chevron-right text-muted"></i>
                </div>
            @endforeach
        </div>

        {{-- Reading Area (hidden by default) --}}
        <div id="readingArea" class="d-none">
            {{-- Back button --}}
            <button class="btn btn-sm mb-3 d-inline-flex align-items-center gap-1"
                    style="color: var(--kid-primary); font-weight: 600;" onclick="closeMaterial()">
                <i class="bi bi-chevron-left"></i> Choose another story
            </button>

            <div class="kid-card mb-2">
                <h5 class="fw-bold mb-1" id="readingTitle"></h5>
                <div class="d-flex gap-3 mb-0" style="font-size: 0.75rem; color: var(--kid-text-light);">
                    <span id="readingGrade"></span>
                    <span id="readingWordCount"></span>
                </div>
            </div>

            {{-- Progress --}}
            <div class="d-flex align-items-center gap-2 mb-2">
                <div class="flex-grow-1" style="background: #E8E6FF; border-radius: 10px; height: 6px; overflow: hidden;">
                    <div id="readingProgress" style="width: 0%; height: 100%; background: linear-gradient(90deg, var(--kid-success), var(--kid-info)); border-radius: 10px; transition: width 0.3s;"></div>
                </div>
                <span style="font-size: 0.75rem; font-weight: 700; color: var(--kid-text-light);" id="readingProgressText">0%</span>
            </div>

            {{-- Passage --}}
            <div class="reading-passage mb-3" id="guidedPassage"></div>

            <div class="text-center text-muted mb-3" style="font-size: 0.8rem;">
                <i class="bi bi-hand-index"></i> Tap each word as you read it aloud
            </div>

            {{-- Done Button --}}
            <div class="text-center">
                <button class="btn btn-kid btn-kid-success btn-lg px-5" id="btnDoneReading" onclick="finishGuidedReading()" disabled>
                    I Finished Reading! 🎉
                </button>
            </div>
        </div>
    @endif

@endsection

@push('scripts')
<script>
    const materials = @json($materials);
    let currentMaterial = null;
    let guidedWordIndex = 0;
    let guidedTotalWords = 0;

    function openMaterial(id) {
        currentMaterial = materials.find(m => m.id === id);
        if (!currentMaterial) return;

        document.getElementById('materialList').classList.add('d-none');
        document.getElementById('readingArea').classList.remove('d-none');

        document.getElementById('readingTitle').textContent = currentMaterial.title;
        document.getElementById('readingGrade').textContent = `Grade ${currentMaterial.grade_level}`;

        // Build word spans
        const words = (currentMaterial.content || '').split(/\s+/).filter(w => w.length > 0);
        guidedTotalWords = words.length;
        guidedWordIndex = 0;
        document.getElementById('readingWordCount').textContent = `${guidedTotalWords} words`;

        const passage = document.getElementById('guidedPassage');
        passage.innerHTML = words.map((w, i) =>
            `<span class="word${i === 0 ? ' current' : ''}" data-index="${i}" onclick="tapGuidedWord(${i})">${w}</span> `
        ).join('');

        updateGuidedProgress();
        document.getElementById('btnDoneReading').disabled = true;
    }

    function closeMaterial() {
        document.getElementById('materialList').classList.remove('d-none');
        document.getElementById('readingArea').classList.add('d-none');
        currentMaterial = null;
    }

    function tapGuidedWord(index) {
        const words = document.querySelectorAll('#guidedPassage .word');

        // Mark all words up to and including tapped as read
        words.forEach((w, i) => {
            w.classList.remove('current');
            if (i <= index) {
                w.classList.add('read');
            }
        });

        // Set next word as current
        if (index + 1 < words.length) {
            words[index + 1].classList.add('current');
            words[index + 1].scrollIntoView({ behavior: 'smooth', block: 'center' });
            guidedWordIndex = index + 1;
        } else {
            guidedWordIndex = words.length;
            document.getElementById('btnDoneReading').disabled = false;
        }

        updateGuidedProgress();
    }

    function updateGuidedProgress() {
        const pct = guidedTotalWords > 0 ? Math.round((guidedWordIndex / guidedTotalWords) * 100) : 0;
        document.getElementById('readingProgress').style.width = pct + '%';
        document.getElementById('readingProgressText').textContent = pct + '%';
    }

    function finishGuidedReading() {
        StudentPortal.celebrate('Great Reading!', 'You read the whole story!', '📚');
        setTimeout(() => {
            closeMaterial();
        }, 2500);
    }
</script>
@endpush
