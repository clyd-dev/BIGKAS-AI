# BIGKAS-AI Assessment Results Enhancement Design

## Overview
This design outlines the enhancement of the `assessments.results` view to provide teachers with better visibility into student reading performance. It surfaces the raw Whisper transcription and refines the visual presentation of error patterns and word-by-word comparisons.

## Target File
`resources/views/assessments/results.blade.php`

## Components

### 1. Raw Audio Transcription Card
A new card added above the Word-by-Word comparison section.
*   **Purpose:** Shows exactly what the speech-to-text engine (Whisper) transcribed before alignment algorithms processed it.
*   **Data Source:** `$assessment->getTranscribedText()`
*   **UI:** A muted, italicized text block (`p-3 bg-light rounded fst-italic text-muted`) that clearly separates the raw speech from the analytical views.

### 2. Enhanced Error Breakdown (Linguistic Patterns)
Augmenting the existing Error Breakdown card (which currently only shows raw counts like "Substitutions: 4").
*   **Purpose:** Translate raw error counts into specific, actionable linguistic struggles (e.g., Phonetic Confusion, Vowel Confusion).
*   **Data Source:** Extracted from `$result->ml_analysis_json['error_patterns']` (computed by `ReadingAnalyzerService`).
*   **UI:** A new section appended to the bottom of the existing Error Breakdown table, listing the top detected patterns with their occurrence counts as small badges.

### 3. Refined Word-by-Word Comparison
Overhauling the current comparison UI, which currently wraps *every* word (including correct ones) in a heavy colored badge.
*   **Purpose:** Improve readability so teachers can easily read the passage and immediately spot the errors.
*   **UI Changes:**
    *   **Correct words:** Rendered as normal paragraph text (`<span class="px-1">word</span>`) instead of badges.
    *   **Substitutions:** Highlighted text with strikethrough for the reference word, followed by the spoken word (e.g., `<span class="text-danger fw-medium px-1"><s>ref</s> spoken</span>`).
    *   **Omissions:** Muted orange strikethrough (e.g., `<span class="text-warning px-1"><s>ref</s></span>`).
    *   **Insertions:** Subtle blue badge with a plus sign (e.g., `<span class="badge bg-info-subtle text-info mx-1">+spoken</span>`).
    *   **Layout:** The container will use `line-height: 2` and flow like a standard paragraph, rather than a flex container of tightly packed badges.