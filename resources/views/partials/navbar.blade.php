{{-- Top Navigation Bar --}}
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm sticky-top">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="{{ route('dashboard') }}">
            <i class="bi bi-book"></i> BIGKAS-AI
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            {{-- Search (optional) --}}
            <form class="d-flex mx-auto" style="max-width: 400px;" role="search">
                <input class="form-control form-control-sm" type="search" placeholder="Search learners..." aria-label="Search">
            </form>

            {{-- Right side --}}
            <ul class="navbar-nav ms-auto align-items-center">
                {{-- Notification Bell --}}
                <li class="nav-item dropdown me-2">
                    <a class="nav-link position-relative" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-bell fs-5"></i>
                        @php $unreadNotifCount = auth()->user()->unreadNotifications->count(); @endphp
                        @if($unreadNotifCount > 0)
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.6rem;">
                                {{ $unreadNotifCount }}
                            </span>
                        @endif
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end p-2" style="width: 320px; max-height: 400px; overflow-y: auto;">
                        <li><h6 class="dropdown-header">Notifications</h6></li>
                        @forelse(auth()->user()->notifications()->latest()->limit(10)->get() as $notification)
                            <li>
                                <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                                    @csrf
                                    <button type="submit" class="dropdown-item small text-wrap {{ $notification->read_at ? 'text-muted' : 'fw-bold' }}">
                                        {{ $notification->data['message'] ?? 'Notification' }}
                                        <br><span class="text-muted" style="font-size: 0.7rem;">{{ $notification->created_at->diffForHumans() }}</span>
                                    </button>
                                </form>
                            </li>
                        @empty
                            <li><span class="dropdown-item-text text-muted small">No notifications</span></li>
                        @endforelse
                    </ul>
                </li>

                {{-- User Profile Dropdown --}}
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle me-1"></i>
                        {{ Auth::user()->name ?? 'User' }}
                        <span class="badge bg-light text-primary ms-1 small">{{ ucfirst(Auth::user()->role ?? '') }}</span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="{{ route('profile.show') }}"><i class="bi bi-person me-2"></i>Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="dropdown-item text-danger">
                                    <i class="bi bi-box-arrow-right me-2"></i>Logout
                                </button>
                            </form>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
