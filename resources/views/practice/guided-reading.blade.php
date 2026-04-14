@extends('layouts.app')

@section('title', 'Guided Reading Practice')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-book me-2"></i>Guided Reading</h4>
        <a href="{{ route('practice.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back</a>
    </div>

    {{-- Controls --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form id="practiceForm" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Learner</label>
                    <select id="learnerSelect" class="form-select form-select-sm">
                        <option value="">Select learner...</option>
                        @foreach($learners ?? [] as $learner)
                            <option value="{{ $learner->id }}">{{ $learner->full_name }} (Grade {{ $learner->grade_level }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Reading Material</label>
                    <select id="materialSelect" class="form-select form-select-sm">
                        <option value="">Select material...</option>
                        @foreach($materials ?? [] as $material)
                            <option value="{{ $material->id }}" data-content="{{ $material->content }}">
                                {{ $material->title }} ({{ ucfirst($material->language) }})
                            </option>
                        @endforeach
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
                    <button type="button" id="startBtn" class="btn btn-sm btn-warning w-100">
                        <i class="bi bi-play-fill me-1"></i> Start
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Reading Area --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body py-5" id="readingArea">
            <div class="text-center text-muted">
                <i class="bi bi-book display-1 opacity-25"></i>
                <p class="mt-3">Select a learner and material to begin guided reading practice.</p>
            </div>
        </div>
    </div>

@push('scripts')
<script>
    document.getElementById('fontSizeSelect')?.addEventListener('change', function() {
        const area = document.getElementById('readingArea');
        if (area) area.style.fontSize = this.value;
    });

    document.getElementById('materialSelect')?.addEventListener('change', function() {
        const selected = this.options[this.selectedIndex];
        const content = selected?.dataset?.content;
        const area = document.getElementById('readingArea');
        if (content && area) {
            area.innerHTML = '<div class="reading-passage p-4 bg-light rounded" style="line-height: 2;">' + content + '</div>';
            area.style.fontSize = document.getElementById('fontSizeSelect').value;
        }
    });
</script>
@endpush
@endsection
