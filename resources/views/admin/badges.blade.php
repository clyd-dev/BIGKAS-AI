@extends('layouts.app')

@section('title', 'Manage Badges')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-award me-2"></i>Manage Badges</h4>
        <div class="d-flex gap-2">
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createBadgeModal">
                <i class="bi bi-plus-circle me-1"></i> Add Badge
            </button>
            <a href="{{ route('admin.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Admin Panel
            </a>
        </div>
    </div>

    {{-- Badge Grid --}}
    @php $byCategory = $badges->groupBy('category'); @endphp

    @foreach($byCategory as $category => $categoryBadges)
        <h6 class="text-uppercase text-muted fw-bold mb-2" style="font-size: 0.75rem; letter-spacing: 0.5px;">
            {{ ucfirst($category) }}
        </h6>
        <div class="row g-3 mb-4">
            @foreach($categoryBadges as $badge)
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100 {{ !$badge->is_active ? 'opacity-50' : '' }}">
                        <div class="card-body text-center py-3">
                            <div style="font-size: 2.5rem; line-height: 1;">{{ $badge->icon }}</div>
                            <h6 class="fw-bold mt-2 mb-1">{{ $badge->name }}</h6>
                            <p class="text-muted mb-2" style="font-size: 0.75rem;">{{ $badge->description }}</p>
                            <div class="d-flex justify-content-center gap-2 mb-2">
                                <span class="badge bg-warning text-dark">+{{ $badge->xp_reward }} XP</span>
                                @if($badge->is_active)
                                    <span class="badge bg-success">Active</span>
                                @else
                                    <span class="badge bg-secondary">Inactive</span>
                                @endif
                            </div>
                            <div class="text-muted" style="font-size: 0.7rem;">
                                Earned by: <strong>{{ $badge->learners_count ?? $badge->learners()->count() }}</strong> learners
                            </div>
                        </div>
                        <div class="card-footer bg-white border-top d-flex gap-1 justify-content-center py-2">
                            <button class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="modal"
                                    data-bs-target="#editBadge{{ $badge->id }}">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" action="{{ route('admin.badges.toggle', $badge) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm {{ $badge->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}"
                                        title="{{ $badge->is_active ? 'Deactivate' : 'Activate' }}">
                                    <i class="bi bi-{{ $badge->is_active ? 'eye-slash' : 'eye' }}"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                {{-- Edit Modal --}}
                <div class="modal fade" id="editBadge{{ $badge->id }}" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST" action="{{ route('admin.badges.update', $badge) }}">
                                @csrf @method('PUT')
                                <div class="modal-header">
                                    <h5 class="modal-title">Edit Badge: {{ $badge->icon }} {{ $badge->name }}</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="row g-2">
                                        <div class="col-8">
                                            <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="name" value="{{ $badge->name }}" required>
                                        </div>
                                        <div class="col-4">
                                            <label class="form-label fw-semibold">Icon (emoji)</label>
                                            <input type="text" class="form-control" name="icon" value="{{ $badge->icon }}" maxlength="10">
                                        </div>
                                    </div>
                                    <div class="mb-3 mt-2">
                                        <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
                                        <textarea class="form-control" name="description" rows="2" required>{{ $badge->description }}</textarea>
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <label class="form-label fw-semibold">XP Reward</label>
                                            <input type="number" class="form-control" name="xp_reward"
                                                   value="{{ $badge->xp_reward }}" min="0" required>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label fw-semibold">Sort Order</label>
                                            <input type="number" class="form-control" name="sort_order" value="{{ $badge->sort_order }}" min="1">
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Save Changes</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endforeach

    {{-- Create Badge Modal --}}
    <div class="modal fade" id="createBadgeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="{{ route('admin.badges.store') }}">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-award me-1"></i> Add New Badge</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-2 mb-3">
                            <div class="col-8">
                                <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="name" value="{{ old('name') }}" required>
                            </div>
                            <div class="col-4">
                                <label class="form-label fw-semibold">Icon (emoji)</label>
                                <input type="text" class="form-control" name="icon" value="{{ old('icon', '🏅') }}" maxlength="10" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Slug <span class="text-danger">*</span>
                                <small class="text-muted fw-normal">(unique, lowercase, hyphens)</small>
                            </label>
                            <input type="text" class="form-control" name="slug" value="{{ old('slug') }}"
                                   pattern="[a-z0-9\-]+" required placeholder="e.g. my-badge">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="description" rows="2" required>{{ old('description') }}</textarea>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                                <select name="category" class="form-select" required>
                                    @foreach(['assessment','streak','practice','milestone'] as $cat)
                                        <option value="{{ $cat }}" {{ old('category') === $cat ? 'selected' : '' }}>
                                            {{ ucfirst($cat) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">XP Reward</label>
                                <input type="number" class="form-control" name="xp_reward" value="{{ old('xp_reward', 25) }}" min="0" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Criteria JSON
                                <small class="text-muted fw-normal">(optional, e.g. {"type":"assessment_count","value":5})</small>
                            </label>
                            <textarea class="form-control font-monospace" name="criteria" rows="2"
                                      style="font-size: 0.8rem;">{{ old('criteria', '{}') }}</textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-1"></i> Create Badge
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
