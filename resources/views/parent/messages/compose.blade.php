@extends('layouts.app')

@section('title', 'Compose Message')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Compose Message</h4>
        <a href="{{ route('parent.messages.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to Messages
        </a>
    </div>

    <div class="row">
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form method="POST" action="{{ route('parent.messages.store') }}">
                        @csrf

                        {{-- Recipient --}}
                        <div class="mb-3">
                            <label class="form-label">To <span class="text-danger">*</span></label>
                            <select name="receiver_id" class="form-select" required>
                                <option value="">Select teacher...</option>
                                @foreach($teachers as $teacher)
                                    <option value="{{ $teacher->id }}" {{ old('receiver_id') == $teacher->id ? 'selected' : '' }}>
                                        {{ $teacher->name }}
                                        @if($teacher->email)
                                            ({{ $teacher->email }})
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                            @error('receiver_id')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- Regarding child (optional) --}}
                        <div class="mb-3">
                            <label class="form-label">Regarding Child <span class="text-muted">(optional)</span></label>
                            <select name="learner_id" class="form-select">
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
                                   value="{{ old('subject') }}" placeholder="e.g., Question about reading assessment">
                            @error('subject')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- Message body --}}
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
                            <a href="{{ route('parent.messages.index') }}" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-send me-1"></i>Send Message
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- Tips --}}
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-info-circle me-1"></i>Tips</h6></div>
                <div class="card-body small text-muted">
                    <ul class="mb-0 ps-3">
                        <li class="mb-2">Select a teacher to send your message to</li>
                        <li class="mb-2">If your message is about a specific child, select them in the "Regarding Child" dropdown</li>
                        <li class="mb-2">Be specific about your concern or question</li>
                        <li class="mb-2">Teachers will reply directly to this conversation</li>
                        <li>You can view all your conversations in the Messages section</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
@endsection
