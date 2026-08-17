@extends('layouts.app')

@section('title', 'Compose Message')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Compose Message</h4>
        <a href="{{ route('messages.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to Messages
        </a>
    </div>

    <div class="row">
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form method="POST" action="{{ route('messages.store') }}">
                        @csrf

                        {{-- Recipient --}}
                        <div class="mb-3">
                            <label class="form-label">To <span class="text-danger">*</span></label>
                            <select name="receiver_id" id="receiverSelect" class="form-select" required>
                                <option value="">Select recipient...</option>
                                @foreach($recipients as $role => $group)
                                    <optgroup label="{{ ucfirst($role) }}s">
                                        @foreach($group as $person)
                                            <option value="{{ $person->id }}"
                                                    data-learners="{{ $person->learners->pluck('id')->implode(',') }}"
                                                    {{ old('receiver_id') == $person->id ? 'selected' : '' }}>
                                                {{ $person->name }}
                                                @if($person->email)
                                                    ({{ $person->email }})
                                                @endif
                                            </option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            @error('receiver_id')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- Regarding learner (optional) --}}
                        <div class="mb-3">
                            <label class="form-label">Regarding Learner <span class="text-muted">(optional)</span></label>
                            <select name="learner_id" id="learnerSelect" class="form-select">
                                <option value="">General message</option>
                                @foreach($learners as $learner)
                                    <option value="{{ $learner->id }}" {{ old('learner_id') == $learner->id ? 'selected' : '' }}>
                                        {{ $learner->full_name }} (Grade {{ $learner->grade_level }})
                                    </option>
                                @endforeach
                            </select>
                            @error('learner_id')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- Subject --}}
                        <div class="mb-3">
                            <label class="form-label">Subject <span class="text-danger">*</span></label>
                            <input type="text" name="subject" class="form-control" required maxlength="255"
                                   value="{{ old('subject') }}" placeholder="e.g., Update on reading progress">
                            @error('subject')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- Body --}}
                        <div class="mb-3">
                            <label class="form-label">Message <span class="text-danger">*</span></label>
                            <textarea name="body" class="form-control" rows="6" required maxlength="5000"
                                      placeholder="Type your message here...">{{ old('body') }}</textarea>
                            @error('body')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                            <div class="form-text">Maximum 5,000 characters.</div>
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <a href="{{ route('messages.index') }}" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-send me-1"></i>Send Message
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-info-circle me-1"></i>Tips</h6></div>
                <div class="card-body small text-muted">
                    <ul class="mb-0 ps-3">
                        <li class="mb-2">Admins can message any teacher or parent school-wide</li>
                        <li class="mb-2">Teachers can message their learners' parents and the admin</li>
                        <li class="mb-2">Select the specific learner if the message concerns them</li>
                        <li>Recipients will be notified and can reply directly</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const receiverSelect = document.getElementById('receiverSelect');
                const learnerSelect = document.getElementById('learnerSelect');
                if (!receiverSelect || !learnerSelect) return;

                const learnerOptions = Array.from(learnerSelect.options);

                function filterLearners() {
                    const selected = receiverSelect.options[receiverSelect.selectedIndex];
                    const allowedIds = selected?.dataset?.learners
                        ? selected.dataset.learners.split(',').filter(Boolean)
                        : null; // null = no recipient chosen yet, show all

                    learnerOptions.forEach(option => {
                        if (option.value === '') {
                            option.hidden = false; // always show "General message"
                            return;
                        }
                        option.hidden = allowedIds !== null && !allowedIds.includes(option.value);
                    });

                    // Reset selection if currently selected learner is no longer valid
                    if (learnerSelect.value && allowedIds !== null && !allowedIds.includes(learnerSelect.value)) {
                        learnerSelect.value = '';
                    }
                }

                receiverSelect.addEventListener('change', filterLearners);
                filterLearners(); // run on load (handles old() repopulation after validation error)
            });
        </script>
    @endpush
@endsection