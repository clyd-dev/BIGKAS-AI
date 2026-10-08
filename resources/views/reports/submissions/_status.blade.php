@switch($status)
    @case('submitted') <span class="badge bg-primary">Submitted</span> @break
    @case('reviewed')  <span class="badge bg-success">Reviewed</span> @break
    @case('returned')  <span class="badge bg-warning text-dark">Returned</span> @break
    @default           <span class="badge bg-secondary">Not submitted</span>
@endswitch
