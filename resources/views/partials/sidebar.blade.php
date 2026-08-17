{{-- Sidebar Navigation --}}
<div class="bg-white border-end shadow-sm" id="sidebar-wrapper" style="min-width: 250px; max-width: 250px; min-height: calc(100vh - 56px);">
    <div class="list-group list-group-flush pt-2">
        @php $role = Auth::user()->role ?? 'teacher'; @endphp

        {{-- Dashboard --}}
        <a href="{{ route('dashboard') }}"
           class="list-group-item list-group-item-action border-0 {{ request()->routeIs('dashboard') ? 'active' : '' }}">
            <i class="bi bi-speedometer2 me-2"></i> Dashboard
        </a>

        @if(in_array($role, ['admin', 'teacher']))
            {{-- Learners --}}
            <a href="{{ route('learners.index') }}"
               class="list-group-item list-group-item-action border-0 {{ request()->routeIs('learners.*') ? 'active' : '' }}">
                <i class="bi bi-people me-2"></i> Learners
            </a>

            {{-- Assessments --}}
            <a href="{{ route('assessments.index') }}"
               class="list-group-item list-group-item-action border-0 {{ request()->routeIs('assessments.*') ? 'active' : '' }}">
                <i class="bi bi-clipboard-check me-2"></i> Assessments
            </a>

            {{-- Reading Materials --}}
            <a href="{{ route('materials.index') }}"
               class="list-group-item list-group-item-action border-0 {{ request()->routeIs('materials.*') ? 'active' : '' }}">
                <i class="bi bi-journal-text me-2"></i> Materials
            </a>

            {{-- Interventions --}}
            <a href="{{ route('interventions.index') }}"
               class="list-group-item list-group-item-action border-0 {{ request()->routeIs('interventions.*') ? 'active' : '' }}">
                <i class="bi bi-lightbulb me-2"></i> Interventions
            </a>
        @endif

        @if(in_array($role, ['admin', 'teacher', 'student']))
            {{-- Practice Center --}}
            <a href="{{ route('practice.index') }}"
               class="list-group-item list-group-item-action border-0 {{ request()->routeIs('practice.*') ? 'active' : '' }}">
                <i class="bi bi-controller me-2"></i> Practice Center
            </a>
        @endif

        @if(in_array($role, ['admin', 'teacher', 'parent']))
            {{-- Reports --}}
            <a href="{{ route('reports.index') }}"
               class="list-group-item list-group-item-action border-0 {{ request()->routeIs('reports.*') ? 'active' : '' }}">
                <i class="bi bi-bar-chart me-2"></i> Reports
            </a>
        @endif

        @if(in_array($role, ['admin', 'teacher']))
            {{-- Messages --}}
            <a href="{{ route('messages.index') }}"
               class="list-group-item list-group-item-action border-0 {{ request()->routeIs('messages.*') ? 'active' : '' }}">
                <i class="bi bi-envelope me-2"></i> Messages
                @php $teacherUnreadMsgCount = \App\Models\Message::where('receiver_id', auth()->id())->whereNull('read_at')->count(); @endphp
                @if($teacherUnreadMsgCount > 0)
                    <span class="badge bg-danger rounded-pill float-end">{{ $teacherUnreadMsgCount }}</span>
                @endif
            </a>
        @endif

        @if($role === 'student')
            <hr class="my-1">
            <small class="text-muted px-3">MY LEARNING</small>

            <a href="{{ route('student.progress') }}"
               class="list-group-item list-group-item-action border-0 {{ request()->routeIs('student.progress') ? 'active' : '' }}">
                <i class="bi bi-graph-up me-2"></i> My Progress
            </a>

            <a href="{{ route('student.assessments') }}"
               class="list-group-item list-group-item-action border-0 {{ request()->routeIs('student.assessments*') ? 'active' : '' }}">
                <i class="bi bi-clipboard-data me-2"></i> My Assessments
            </a>

            <a href="{{ route('student.interventions') }}"
               class="list-group-item list-group-item-action border-0 {{ request()->routeIs('student.interventions') ? 'active' : '' }}">
                <i class="bi bi-lightbulb me-2"></i> My Interventions
            </a>
        @endif

        @if($role === 'parent')
            <hr class="my-1">
            <small class="text-muted px-3">MY CHILDREN</small>

            <a href="{{ route('parent.dashboard') }}"
               class="list-group-item list-group-item-action border-0 {{ request()->routeIs('parent.dashboard') ? 'active' : '' }}">
                <i class="bi bi-house-heart me-2"></i> Home
            </a>

            @php $parentLearners = auth()->user()->learners()->orderBy('first_name')->get(); @endphp
            @foreach($parentLearners as $child)
                <a href="{{ route('parent.children.profile', $child) }}"
                   class="list-group-item list-group-item-action border-0 ps-4 {{ request()->is('parent/children/'.$child->id.'*') ? 'active' : '' }}">
                    <i class="bi bi-person me-2"></i> {{ $child->first_name }}
                </a>
            @endforeach

            <hr class="my-1">
            <small class="text-muted px-3">COMMUNICATION</small>

            <a href="{{ route('parent.messages.index') }}"
               class="list-group-item list-group-item-action border-0 {{ request()->routeIs('parent.messages.*') ? 'active' : '' }}">
                <i class="bi bi-envelope me-2"></i> Messages
                @php $unreadMsgCount = \App\Models\Message::where('receiver_id', auth()->id())->whereNull('read_at')->count(); @endphp
                @if($unreadMsgCount > 0)
                    <span class="badge bg-danger rounded-pill float-end">{{ $unreadMsgCount }}</span>
                @endif
            </a>
        @endif

        @if($role === 'admin')
            <hr class="my-1">
            <small class="text-muted px-3">ADMINISTRATION</small>

            <a href="{{ route('admin.index') }}"
               class="list-group-item list-group-item-action border-0 {{ request()->routeIs('admin.index') ? 'active' : '' }}">
                <i class="bi bi-gear me-2"></i> Admin Panel
            </a>
            <a href="{{ route('admin.phil-iri') }}"
               class="list-group-item list-group-item-action border-0 {{ request()->routeIs('admin.phil-iri') ? 'active' : '' }}">
                <i class="bi bi-journal-bookmark-fill me-2"></i> Phil-IRI Profile
            </a>
            <a href="{{ route('admin.classes') }}"
               class="list-group-item list-group-item-action border-0 {{ request()->routeIs('admin.classes') ? 'active' : '' }}">
                <i class="bi bi-diagram-3 me-2"></i> Classrooms
            </a>
            <a href="{{ route('admin.users') }}"
               class="list-group-item list-group-item-action border-0 {{ request()->routeIs('admin.users') ? 'active' : '' }}">
                <i class="bi bi-person-badge me-2"></i> Users
            </a>
            <a href="{{ route('admin.schools') }}"
               class="list-group-item list-group-item-action border-0 {{ request()->routeIs('admin.schools') ? 'active' : '' }}">
                <i class="bi bi-building me-2"></i> Schools
            </a>
            <a href="{{ route('admin.logs') }}"
               class="list-group-item list-group-item-action border-0 {{ request()->routeIs('admin.logs') ? 'active' : '' }}">
                <i class="bi bi-clock-history me-2"></i> Activity Logs
            </a>
            <a href="{{ route('admin.settings') }}"
               class="list-group-item list-group-item-action border-0 {{ request()->routeIs('admin.settings') ? 'active' : '' }}">
                <i class="bi bi-sliders me-2"></i> Settings
            </a>
        @endif
    </div>
</div>
