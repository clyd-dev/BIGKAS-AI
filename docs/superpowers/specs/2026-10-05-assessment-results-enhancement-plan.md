# Assessment Results Enhancement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enhance the `assessments.results` view to display the raw Whisper transcription, surface specific linguistic error patterns, and improve the readability of the word-by-word comparison.

**Architecture:** This is a purely frontend (Blade template) enhancement. It relies on existing data already computed and stored in the database by `ReadingAnalyzerService` (`$assessment->getTranscribedText()`, `$result->ml_analysis_json['error_patterns']`, and `$result->word_comparison_data`). We will modify the Blade view to extract and render this existing data in a more actionable format.

**Tech Stack:** Laravel 12.x, Blade Templates, Bootstrap 5, Bootstrap Icons.

## Global Constraints

- Use Bootstrap 5 utility classes (`p-3`, `bg-light`, `rounded`, `text-muted`, `fst-italic`, `badge`, `bg-info-subtle`, `text-info`, `text-danger`, `text-warning`, `px-1`, `mx-1`, `fw-medium`).
- Use Bootstrap Icons (`bi bi-*`).
- Maintain the existing `$result` and `$assessment` variable usage in the view.
- Word-by-word comparison must use `line-height: 2`.

---

### Task 1: Add Raw Audio Transcription Card

**Files:**
- Modify: `resources/views/assessments/results.blade.php`

**Interfaces:**
- Consumes: `$assessment->getTranscribedText()`

- [ ] **Step 1: Locate the injection point**
Find the space immediately after the Summary Cards (`<div class="row g-3 mb-4">...</div>`) and before the `Error Breakdown` / `Weakness Classification` / `Details` row (`<div class="row g-3">`).

- [ ] **Step 2: Add the Raw Transcription Card HTML**
Insert the following Blade/HTML code:

```html
        {{-- Raw Transcription --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><h6 class="mb-0">Raw Audio Transcription</h6></div>
            <div class="card-body">
                <p class="p-3 bg-light rounded fst-italic text-muted mb-0">
                    "{{ $assessment->getTranscribedText() ?: 'No transcription available.' }}"
                </p>
            </div>
        </div>
```

- [ ] **Step 3: Commit**

```bash
git add resources/views/assessments/results.blade.php
git commit -m "feat: add raw transcription card to assessment results"
```

---

### Task 2: Enhance Error Breakdown with Linguistic Patterns

**Files:**
- Modify: `resources/views/assessments/results.blade.php`

**Interfaces:**
- Consumes: `$result->ml_analysis_json['error_patterns']`

- [ ] **Step 1: Locate the Error Breakdown table**
Find the `table` inside the "Error Breakdown" card:
`<table class="table table-sm table-borderless mb-0">...</table>`

- [ ] **Step 2: Add Linguistic Patterns logic and UI**
Immediately after the closing `</table>` tag, add a new section that checks for `error_patterns` and iterates through them:

```html
                            @php
                                $errorPatterns = $result->ml_analysis_json['error_patterns'] ?? [];
                            @endphp
                            @if(!empty($errorPatterns))
                                <div class="mt-4 pt-3 border-top">
                                    <h6 class="small text-muted mb-2">Specific Linguistic Patterns</h6>
                                    <div class="d-flex flex-wrap gap-2">
                                        @foreach($errorPatterns as $pattern => $count)
                                            <span class="badge bg-light text-dark border">
                                                {{ ucwords(str_replace('_', ' ', $pattern)) }}: <span class="fw-bold text-danger">{{ $count }}</span>
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
```

- [ ] **Step 3: Commit**

```bash
git add resources/views/assessments/results.blade.php
git commit -m "feat: display specific linguistic error patterns in breakdown"
```

---

### Task 3: Refine Word-by-Word Comparison UI

**Files:**
- Modify: `resources/views/assessments/results.blade.php`

**Interfaces:**
- Consumes: `$result->word_comparison_data`

- [ ] **Step 1: Locate the Word Comparison container**
Find the div with `class="d-flex flex-wrap gap-1"` inside the "Word-by-Word Comparison" card.

- [ ] **Step 2: Replace the container and loop contents**
Replace the entire `<div class="d-flex flex-wrap gap-1">...</div>` block with the following modernized, paragraph-style layout using `line-height: 2`:

```html
                <div class="p-4 bg-light rounded" style="font-size: 1.1rem; line-height: 2;">
                    @foreach($result->word_comparison_data ?? [] as $word)
                        @if(($word['status'] ?? '') === 'correct')
                            <span class="px-1">{{ $word['reference'] }}</span>
                        @elseif(($word['status'] ?? '') === 'substitution')
                            <span class="text-danger fw-medium px-1" title="Said: {{ $word['spoken'] ?? '?' }}">
                                <s>{{ $word['reference'] }}</s> {{ $word['spoken'] ?? '?' }}
                            </span>
                        @elseif(($word['status'] ?? '') === 'omission')
                            <span class="text-warning px-1" title="Omitted">
                                <s>{{ $word['reference'] }}</s>
                            </span>
                        @elseif(($word['status'] ?? '') === 'insertion')
                            <span class="badge bg-info-subtle text-info mx-1" title="Inserted">
                                +{{ $word['spoken'] ?? '?' }}
                            </span>
                        @endif
                    @endforeach
                </div>
```

- [ ] **Step 3: Commit**

```bash
git add resources/views/assessments/results.blade.php
git commit -m "feat: refine word-by-word comparison for better readability"
```