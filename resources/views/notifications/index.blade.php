@extends(auth()->user()->isParent() ? 'layouts.parent' : 'layouts.app')

@section('title', 'Notifications')

@section('content')
    <x-page-header title="Notifications" icon="bi-bell">
        <x-slot:actions>
            @if(auth()->user()->unreadNotifications()->exists())
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-check2-all me-1"></i> Mark all as read
                    </button>
                </form>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="card border-0 shadow-sm">
        <div class="list-group list-group-flush">
            @forelse($notifications as $notification)
                <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                    @csrf
                    <button type="submit" class="list-group-item list-group-item-action text-start w-100 {{ $notification->read_at ? 'text-muted' : 'fw-bold' }}">
                        <div class="d-flex justify-content-between align-items-start">
                            <span>
                                @unless($notification->read_at)
                                    <span class="badge bg-primary me-1">New</span>
                                @endunless
                                {{ $notification->data['message'] ?? 'Notification' }}
                            </span>
                            <small class="text-muted fw-normal ms-3 text-nowrap">{{ $notification->created_at->diffForHumans() }}</small>
                        </div>
                    </button>
                </form>
            @empty
                <div class="list-group-item text-center text-muted py-4">
                    <i class="bi bi-bell-slash me-1"></i> No notifications yet.
                </div>
            @endforelse
        </div>
    </div>

    @if($notifications->hasPages())
        <div class="mt-3">{{ $notifications->links() }}</div>
    @endif
@endsection
