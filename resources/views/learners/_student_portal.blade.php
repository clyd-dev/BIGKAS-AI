{{-- Student Portal container (teachers): how the children are using the portal, and the badges they can earn.
     Shows only what the list above does not (no section, LRN or reading level repeated). --}}
@php
    $tabUrl = fn ($tab) => request()->fullUrlWithQuery(['portal_tab' => $tab, 'portal_page' => null, 'badge_page' => null]) . '#student-portal';
@endphp

<div class="card border-0 shadow-sm mt-4" id="student-portal">
    <div class="card-header bg-white">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h6 class="mb-0"><i class="bi bi-controller me-1 text-primary"></i> Student Portal</h6>
            <ul class="nav nav-pills nav-sm">
                <li class="nav-item"><a class="nav-link py-1 {{ $portalTab === 'activity' ? 'active' : '' }}" href="{{ $tabUrl('activity') }}">Activity</a></li>
                <li class="nav-item"><a class="nav-link py-1 {{ $portalTab === 'badges' ? 'active' : '' }}" href="{{ $tabUrl('badges') }}">Badges</a></li>
            </ul>
        </div>
    </div>

    @if($portalTab === 'activity')
        <div class="px-3 pt-2 small text-muted">Learners with the most XP first, with their PINs. Based on the filters above.</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Learner</th>
                            <th>PIN</th>
                            <th class="text-center">XP</th>
                            <th class="text-center">Streak</th>
                            <th class="text-center">Badges</th>
                            <th>Last active</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($portalLearners as $l)
                            <tr>
                                <td class="fw-semibold">{{ $l->getFullName() }}</td>
                                <td>
                                    @if($l->pin)
                                        <code class="fs-6 user-select-all">{{ $l->pin }}</code>
                                    @elseif($l->hasLegacyPin())
                                        <span class="small text-muted" title="Saved before PINs could be viewed. It works, and becomes viewable after the learner's next login, or issue a new one.">hidden</span>
                                    @else
                                        <a href="{{ route('learners.show', $l) }}" class="small text-danger">Issue PIN</a>
                                    @endif
                                </td>
                                <td class="text-center fw-bold text-primary">{{ number_format($l->total_xp) }}</td>
                                <td class="text-center">
                                    @if($l->current_streak > 0)
                                        <span title="Current {{ $l->current_streak }} · Longest {{ $l->longest_streak }}">🔥 {{ $l->current_streak }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-center"><span class="badge bg-warning text-dark">🏅 {{ $l->badges_count }}</span></td>
                                <td class="small text-muted">{{ $l->last_activity_date?->diffForHumans() ?? 'Never' }}</td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('learners.reset-xp', $l) }}"
                                          onsubmit="return confirm('Reset XP and streak for {{ addslashes($l->getFullName()) }}? This cannot be undone.')">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Reset XP &amp; streak">
                                            <i class="bi bi-arrow-counterclockwise"></i> Reset
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">No learners to show.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="small text-muted">
                @if($portalLearners->total() > 0)
                    Showing {{ $portalLearners->firstItem() }}–{{ $portalLearners->lastItem() }} of {{ $portalLearners->total() }}
                @endif
            </div>
            {{ $portalLearners->onEachSide(1)->fragment('student-portal')->links('partials.pagination') }}
        </div>
    @else
        <div class="px-3 pt-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="small text-muted">Badges are awarded automatically when a learner meets the rule. They apply to the whole school.</div>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#badgeCreate">
                <i class="bi bi-plus-circle me-1"></i> Add Badge
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Badge</th>
                            <th>Awarded when</th>
                            <th class="text-center">XP</th>
                            <th class="text-center">Earned by</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($badges as $b)
                            <tr class="{{ $b->is_active ? '' : 'text-muted' }}">
                                <td>
                                    <span class="fs-5 me-1">{{ $b->icon }}</span><span class="fw-semibold">{{ $b->name }}</span>
                                    <div class="small text-muted">{{ $b->description }}</div>
                                </td>
                                <td class="small">{{ $b->ruleLabel() }}</td>
                                <td class="text-center"><span class="badge bg-warning text-dark">+{{ $b->xp_reward }}</span></td>
                                <td class="text-center">{{ $b->learners_count }}</td>
                                <td>{!! $b->is_active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' !!}</td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-primary" title="Edit"
                                            data-bs-toggle="modal" data-bs-target="#badgeEdit"
                                            data-action="{{ route('badges.update', $b) }}"
                                            data-name="{{ $b->name }}" data-icon="{{ $b->icon }}"
                                            data-description="{{ $b->description }}" data-xp="{{ $b->xp_reward }}">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" action="{{ route('badges.toggle', $b) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm {{ $b->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}"
                                                title="{{ $b->is_active ? 'Deactivate' : 'Activate' }}">
                                            <i class="bi bi-{{ $b->is_active ? 'eye-slash' : 'eye' }}"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">No badges yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="small text-muted">
                @if($badges->total() > 0)
                    Showing {{ $badges->firstItem() }}–{{ $badges->lastItem() }} of {{ $badges->total() }}
                @endif
            </div>
            {{ $badges->onEachSide(1)->fragment('student-portal')->links('partials.pagination') }}
        </div>

        {{-- Add badge --}}
        <div class="modal fade" id="badgeCreate" tabindex="-1">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('badges.store') }}" class="modal-content">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-award me-1"></i> Add Badge</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        @if($errors->any())
                            <div class="alert alert-danger small py-2">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
                        @endif
                        <div class="row g-2 mb-3">
                            <div class="col-8">
                                <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" value="{{ old('name') }}" maxlength="255" required>
                            </div>
                            <div class="col-4">
                                <label class="form-label fw-semibold">Icon (emoji)</label>
                                <input type="text" name="icon" class="form-control" value="{{ old('icon', '🏅') }}" maxlength="10" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
                            <textarea name="description" rows="2" class="form-control" maxlength="500" required>{{ old('description') }}</textarea>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold">Category</label>
                                <select name="category" class="form-select">
                                    @foreach(\App\Models\Badge::CATEGORIES as $c)
                                        <option value="{{ $c }}" {{ old('category') === $c ? 'selected' : '' }}>{{ ucfirst($c) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">XP reward</label>
                                <input type="number" name="xp_reward" class="form-control" min="0" max="10000" value="{{ old('xp_reward', 20) }}" required>
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-8">
                                <label class="form-label fw-semibold">Awarded when the learner&hellip;</label>
                                <select name="criteria_type" id="badgeRule" class="form-select">
                                    @foreach(\App\Models\Badge::RULES as $key => [$label, $takesNumber, $unit])
                                        <option value="{{ $key }}" data-number="{{ $takesNumber ? 1 : 0 }}" data-unit="{{ $unit }}"
                                                {{ old('criteria_type') === $key ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-4" id="badgeValueWrap">
                                <label class="form-label fw-semibold">How many <span class="small text-muted fw-normal" id="badgeUnit"></span></label>
                                <input type="number" name="criteria_value" id="badgeValue" class="form-control" min="1" value="{{ old('criteria_value', 5) }}">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Badge</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Edit badge (one modal, filled when opened) --}}
        <div class="modal fade" id="badgeEdit" tabindex="-1">
            <div class="modal-dialog">
                <form method="POST" action="" class="modal-content" id="badgeEditForm">
                    @csrf @method('PUT')
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-pencil me-1"></i> Edit Badge</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-2 mb-3">
                            <div class="col-8">
                                <label class="form-label fw-semibold">Name</label>
                                <input type="text" name="name" id="beName" class="form-control" maxlength="255" required>
                            </div>
                            <div class="col-4">
                                <label class="form-label fw-semibold">Icon</label>
                                <input type="text" name="icon" id="beIcon" class="form-control" maxlength="10" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea name="description" id="beDescription" rows="2" class="form-control" maxlength="500" required></textarea>
                        </div>
                        <div>
                            <label class="form-label fw-semibold">XP reward</label>
                            <input type="number" name="xp_reward" id="beXp" class="form-control" min="0" max="10000" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>

        @push('scripts')
        <script>
            (function () {
                var rule = document.getElementById('badgeRule');
                function syncRule() {
                    var opt = rule.options[rule.selectedIndex];
                    var needs = opt.getAttribute('data-number') === '1';
                    document.getElementById('badgeValueWrap').style.display = needs ? '' : 'none';
                    document.getElementById('badgeUnit').textContent = needs ? '(' + opt.getAttribute('data-unit') + ')' : '';
                    document.getElementById('badgeValue').disabled = !needs;
                }
                rule.addEventListener('change', syncRule);
                syncRule();

                document.getElementById('badgeEdit').addEventListener('show.bs.modal', function (e) {
                    var b = e.relatedTarget;
                    document.getElementById('badgeEditForm').action = b.getAttribute('data-action');
                    document.getElementById('beName').value = b.getAttribute('data-name');
                    document.getElementById('beIcon').value = b.getAttribute('data-icon');
                    document.getElementById('beDescription').value = b.getAttribute('data-description');
                    document.getElementById('beXp').value = b.getAttribute('data-xp');
                });
                @if($errors->any() && old('criteria_type'))
                    new bootstrap.Modal(document.getElementById('badgeCreate')).show();
                @endif
            })();
        </script>
        @endpush
    @endif
</div>
