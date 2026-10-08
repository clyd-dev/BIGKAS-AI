@extends('layouts.app')

@section('title', 'Form 4 – ' . $form['name'])

@push('styles')
<style>
    @include('learners._form4_css')
    .f4-sheet { background: #fff; padding: 18px; border: 1px solid #dee2e6; border-radius: 8px; overflow-x: auto; }
    .f4-sheet .f4-page { page-break-after: auto; border-bottom: 2px dashed #ced4da; padding-bottom: 14px; }
    .f4-sheet .f4-page:last-child { border-bottom: 0; }
</style>
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-file-earmark-person me-2"></i>Phil-IRI Form 4 – Individual Summary Record</h4>
        <div class="d-flex gap-2">
            <a href="{{ route('learners.form4.print', $learner) }}" target="_blank" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-printer me-1"></i> Print
            </a>
            <a href="{{ route('learners.form4.pdf', $learner) }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-file-earmark-pdf me-1"></i> Download PDF
            </a>
            <a href="{{ route('learners.show', $learner) }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    @unless($form['any_data'])
        <div class="alert alert-info">
            {{ $form['name'] }} has no completed assessments yet, so the form is blank. It can still be printed and filled in by hand.
        </div>
    @endunless

    <div class="alert alert-light border small">
        Filled from completed oral-reading assessments: <strong>passage level, word-reading level and date taken</strong>.
        Set, Comprehension, the observation checklist and page 2 are completed by the teacher on the printed form.
    </div>

    <div class="f4-sheet">
        <div class="f4">
            @include('learners._form4_pages', ['form' => $form])
        </div>
    </div>
@endsection
