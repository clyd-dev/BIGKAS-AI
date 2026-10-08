@extends('layouts.app')

@section('title', 'Messages')

@section('content')
    <x-page-header title="Messages" icon="bi-envelope">
        <x-slot:actions>
            <a href="{{ route('messages.create') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-pencil-square me-1"></i> Compose
            </a>
        </x-slot:actions>
    </x-page-header>

    @if($unreadCount > 0)
        <div class="alert alert-info py-2 small">
            <i class="bi bi-envelope-exclamation me-1"></i>You have <strong>{{ $unreadCount }}</strong> unread message{{ $unreadCount > 1 ? 's' : '' }}.
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="list-group list-group-flush">
                @forelse($threads as $thread)
                    @php
                        $isUnread = $thread->receiver_id === auth()->id() && !$thread->read_at;
                        $unreadInThread = $thread->getUnreadCountForUser(auth()->id());
                        $other = $thread->getOtherParticipant(auth()->id());
                    @endphp
                    <a href="{{ route('messages.show', $thread) }}"
                       class="list-group-item list-group-item-action {{ $isUnread ? 'bg-light' : '' }}">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    @if($isUnread)
                                        <span class="badge bg-primary rounded-pill" style="font-size: 0.6rem;">NEW</span>
                                    @endif
                                    <strong class="{{ $isUnread ? '' : 'fw-normal' }}">{{ $thread->subject }}</strong>
                                </div>
                                <div class="small text-muted">
                                    <i class="bi bi-person me-1"></i>
                                    @if($thread->sender_id === auth()->id())
                                        To: {{ $other?->name ?? 'Unknown' }}
                                    @else
                                        From: {{ $other?->name ?? 'Unknown' }}
                                    @endif
                                    @if($thread->learner)
                                        <span class="ms-2"><i class="bi bi-mortarboard me-1"></i>{{ $thread->learner->full_name }}</span>
                                    @endif
                                </div>
                                <p class="small text-muted mb-0 mt-1">{{ Str::limit($thread->body, 100) }}</p>
                            </div>
                            <div class="text-end ms-3" style="min-width: 80px;">
                                <small class="text-muted d-block">{{ $thread->created_at?->diffForHumans(null, true) }}</small>
                                @if($thread->replies_count > 0)
                                    <span class="badge bg-light text-dark border mt-1">
                                        <i class="bi bi-chat-dots me-1"></i>{{ $thread->replies_count }}
                                    </span>
                                @endif
                                @if($unreadInThread > 0)
                                    <span class="badge bg-danger rounded-pill mt-1">{{ $unreadInThread }}</span>
                                @endif
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="list-group-item text-center text-muted py-5">
                        <i class="bi bi-envelope-open display-4 d-block mb-2"></i>
                        No messages yet.
                        <br><a href="{{ route('messages.create') }}" class="btn btn-sm btn-primary mt-2">Send your first message</a>
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    @if($threads->hasPages())
        <div class="mt-3">
            {{ $threads->links() }}
        </div>
    @endif
@endsection