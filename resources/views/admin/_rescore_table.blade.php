{{-- Recordings saved but not scored. $rows: Assessment collection; $showAttempts: bool --}}
<div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead class="table-light">
            <tr>
                <th>Learner</th><th>Passage</th><th>Recorded</th>
                <th>{{ $showAttempts ? 'Tries' : 'Waiting for' }}</th><th></th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr>
                    <td>
                        {{ $row->learner?->getFullName() ?? 'Learner removed' }}
                        <div class="small text-muted">{{ $row->learner?->schoolClass?->getDisplayName() }}</div>
                    </td>
                    <td class="small">{{ $row->material?->title ?? '—' }}</td>
                    <td class="small text-nowrap">{{ $row->created_at->format('M j, g:i A') }}</td>
                    <td class="small text-nowrap">
                        @if($showAttempts)
                            {{ $row->rescore_attempts }}
                        @else
                            {{ $row->result ? 'the model' : 'the speech engine' }}
                        @endif
                    </td>
                    <td class="text-end">
                        <a href="{{ route($row->result ? 'assessments.results' : 'assessments.show', $row) }}" class="btn btn-sm btn-outline-secondary py-0">Open</a>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
