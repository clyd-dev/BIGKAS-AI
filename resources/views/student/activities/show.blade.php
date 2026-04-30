@extends('layouts.student')

@section('title', 'Activity')

@section('content')

    {{-- Back button --}}
    <a href="{{ route('student.activities') }}" class="text-decoration-none d-inline-flex align-items-center gap-1 mb-3"
       style="color: var(--kid-primary); font-weight: 600; font-size: 0.9rem;">
        <i class="bi bi-chevron-left"></i> Back to Activities
    </a>

    {{-- Activity Header --}}
    <div class="kid-card kid-card-colored kid-card-purple mb-3">
        <h5 class="fw-bold mb-1">{{ $log->intervention->name ?? 'Activity' }}</h5>
        <p class="mb-0" style="opacity: 0.9; font-size: 0.85rem;">
            @if($log->status === 'completed')
                <i class="bi bi-check-circle-fill"></i> Completed
            @elseif($log->status === 'in_progress')
                <i class="bi bi-clock-fill"></i> In Progress
            @else
                <i class="bi bi-star-fill"></i> New Activity
            @endif
        </p>
    </div>

    {{-- Activity Details --}}
    <div class="kid-card mb-3">
        <h6 class="fw-bold mb-2">What to do:</h6>
        <p class="mb-0" style="font-size: 0.95rem; line-height: 1.8;">
            {{ $log->intervention->description ?? 'Follow the instructions from your teacher.' }}
        </p>
    </div>

    {{-- Intervention Type & Category --}}
    @if($log->intervention)
        <div class="kid-card mb-3">
            <div class="row g-3">
                <div class="col-6">
                    <div class="text-muted" style="font-size: 0.75rem; font-weight: 600;">Type</div>
                    <div class="fw-bold" style="font-size: 0.9rem;">
                        {{ ucfirst(str_replace('_', ' ', $log->intervention->type ?? 'activity')) }}
                    </div>
                </div>
                <div class="col-6">
                    <div class="text-muted" style="font-size: 0.75rem; font-weight: 600;">Category</div>
                    <div class="fw-bold" style="font-size: 0.9rem;">
                        {{ ucfirst($log->intervention->category ?? 'General') }}
                    </div>
                </div>
                @if($log->intervention->duration_minutes)
                    <div class="col-6">
                        <div class="text-muted" style="font-size: 0.75rem; font-weight: 600;">Estimated Time</div>
                        <div class="fw-bold" style="font-size: 0.9rem;">
                            {{ $log->intervention->duration_minutes }} minutes
                        </div>
                    </div>
                @endif
                <div class="col-6">
                    <div class="text-muted" style="font-size: 0.75rem; font-weight: 600;">XP Reward</div>
                    <div class="fw-bold" style="font-size: 0.9rem; color: var(--kid-warning);">
                        +15 XP ⭐
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Action Buttons --}}
    @if($log->status === 'pending')
        <div class="text-center mt-4">
            <button class="btn btn-kid btn-kid-primary btn-lg px-5" id="btnStart" onclick="startActivity()">
                Start Activity 🚀
            </button>
        </div>
    @elseif($log->status === 'in_progress')
        <div class="kid-card text-center py-3 mb-3" style="background: #FFF9E6; border: 2px solid var(--kid-warning);">
            <p class="fw-bold mb-2" style="color: var(--kid-warning);">
                <i class="bi bi-clock-fill"></i> Activity in progress
            </p>
            <p class="text-muted mb-0" style="font-size: 0.85rem;">
                When you're done, rate how well it went and tap "Complete"
            </p>
        </div>

        {{-- Rating --}}
        <div class="kid-card mb-3">
            <h6 class="fw-bold mb-2">How did it go?</h6>
            <div class="d-flex gap-2 justify-content-center mb-3" id="ratingStars">
                @for($i = 1; $i <= 5; $i++)
                    <button class="btn p-1" style="font-size: 2rem; background: none; border: none; cursor: pointer;"
                            onclick="setRating({{ $i }})" data-rating="{{ $i }}">
                        ⭐
                    </button>
                @endfor
            </div>
            <div class="text-center text-muted mb-2" style="font-size: 0.8rem;" id="ratingLabel">Tap a star to rate</div>

            <textarea class="form-control mb-3" id="activityNotes" rows="2"
                      placeholder="Any notes? (optional)"
                      style="border-radius: 12px; border: 2px solid #dee2e6; font-size: 0.9rem;"></textarea>

            <div class="text-center">
                <button class="btn btn-kid btn-kid-success btn-lg px-5" id="btnComplete" onclick="completeActivity()">
                    Complete ✅
                </button>
            </div>
        </div>
    @else
        {{-- Completed --}}
        <div class="kid-card text-center py-4">
            <div style="font-size: 3rem;" class="mb-2">🎉</div>
            <h5 class="fw-bold text-success">Activity Completed!</h5>
            @if($log->effectiveness_rating)
                <div class="mb-2">
                    @for($i = 0; $i < $log->effectiveness_rating; $i++)⭐@endfor
                </div>
            @endif
            @if($log->notes)
                <p class="text-muted" style="font-size: 0.85rem;">{{ $log->notes }}</p>
            @endif
        </div>
    @endif

@endsection

@push('scripts')
<script>
    let selectedRating = 0;

    function setRating(rating) {
        selectedRating = rating;
        const stars = document.querySelectorAll('#ratingStars button');
        const labels = ['', 'Difficult', 'A bit hard', 'Okay', 'Good', 'Easy!'];
        stars.forEach((star, i) => {
            star.style.opacity = i < rating ? '1' : '0.3';
        });
        document.getElementById('ratingLabel').textContent = labels[rating] || '';
    }

    async function startActivity() {
        const btn = document.getElementById('btnStart');
        btn.disabled = true;
        btn.textContent = 'Starting...';

        try {
            await StudentPortal.post('{{ route("student.activities.start", $log) }}');
            location.reload();
        } catch (e) {
            console.error('Start error:', e);
            btn.disabled = false;
            btn.textContent = 'Start Activity 🚀';
        }
    }

    async function completeActivity() {
        const btn = document.getElementById('btnComplete');
        btn.disabled = true;
        btn.textContent = 'Saving...';

        try {
            const data = await StudentPortal.post('{{ route("student.activities.complete", $log) }}', {
                effectiveness_rating: selectedRating || null,
                notes: document.getElementById('activityNotes')?.value || null,
            });

            if (data.xp_earned) {
                StudentPortal.celebrate('Activity Complete!', `+${data.xp_earned} XP earned!`, '🎉');
            }

            setTimeout(() => {
                window.location.href = '{{ route("student.activities") }}';
            }, 2500);
        } catch (e) {
            console.error('Complete error:', e);
            btn.disabled = false;
            btn.textContent = 'Complete ✅';
        }
    }
</script>
@endpush
