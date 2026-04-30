@extends('layouts.app')

@section('title', $message->subject)

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-chat-left-text me-2"></i>{{ $message->subject }}</h4>
        <a href="{{ route('parent.messages.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to Messages
        </a>
    </div>

    {{-- Thread info --}}
    @if($message->learner)
        <div class="alert alert-light border py-2 small mb-3">
            <i class="bi bi-mortarboard me-1"></i>Regarding: <strong>{{ $message->learner->full_name }}</strong>
        </div>
    @endif

    {{-- Original message --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <strong>{{ $message->sender->name }}</strong>
                    <span class="badge bg-light text-dark border ms-1">{{ ucfirst($message->sender->role) }}</span>
                </div>
                <small class="text-muted">{{ $message->created_at?->format('M d, Y h:i A') }}</small>
            </div>
            <div class="mt-2">{!! nl2br(e($message->body)) !!}</div>
        </div>
    </div>

    {{-- Replies --}}
    @foreach($message->replies as $reply)
        <div class="card border-0 shadow-sm mb-2 {{ $reply->sender_id === auth()->id() ? 'ms-4' : 'me-4' }}">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <strong>{{ $reply->sender->name }}</strong>
                        <span class="badge bg-light text-dark border ms-1">{{ ucfirst($reply->sender->role) }}</span>
                        @if($reply->sender_id === auth()->id())
                            <span class="badge bg-primary ms-1">You</span>
                        @endif
                    </div>
                    <small class="text-muted">{{ $reply->created_at?->format('M d, Y h:i A') }}</small>
                </div>
                <div>{!! nl2br(e($reply->body)) !!}</div>
            </div>
        </div>
    @endforeach

    {{-- Reply form --}}
    <div class="card border-0 shadow-sm mt-3">
        <div class="card-header bg-white">
            <h6 class="mb-0"><i class="bi bi-reply me-1"></i>Reply</h6>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('parent.messages.reply', $message) }}">
                @csrf
                <div class="mb-3">
                    <textarea name="body" class="form-control" rows="4" required placeholder="Type your reply..." maxlength="5000">{{ old('body') }}</textarea>
                    @error('body')
                        <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted">Replying to {{ $otherParticipant?->name ?? 'Unknown' }}</small>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-send me-1"></i>Send Reply
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection
