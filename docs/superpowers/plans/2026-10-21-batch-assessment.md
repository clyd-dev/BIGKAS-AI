# Batch Assessment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A teacher sets up one batch covering up to 15 children, captures each child's recording either on the child's own device or teacher-led one at a time, and tracks the whole group from a single polling board.

**Architecture:** A batch is an `assessment_batches` row; each roster entry *is* an ordinary `Assessment` carrying `batch_id`. Scoring adds no new mechanism — `Assessment::scopeAwaitingRescore()` is widened additively so a **sent** batch entry is owed a score at once, and the existing `assessments:rescore` scheduler plus `RescoreQueue` plus `AssessmentScorer` do the work on their existing single serial lane. The board is a grid of clickable cards, one per child; the card panel is where the teacher conducts the reading, plays back the audio and answers comprehension questions. All seven card states are derived from data the database already holds — no parallel state machine is stored.

**Branching:** `master` is never committed to. Every task gets its own branch off the integration branch `feat/batch-assessment` and merges back with `--no-ff`, so each phase is one revertible merge commit. See spec §9.3.

**Tech Stack:** Laravel 11 (PHP 8.3), Blade + Bootstrap 5, PHPUnit 11 with `RefreshDatabase`, MySQL, vanilla JS (no build step for board/recorder), local faster-whisper + Random Forest behind a Flask service.

**Spec:** `docs/2026-10-09-batch-assessment-plan.html` (rev. 2, 21 Oct 2026)

## Global Constraints

- **Roster cap is 15** learners per batch, from `config('assessment.batch_max_learners')`.
- **Feature ships behind a flag**, default **off**: `config('assessment.batch_enabled')` ← `BATCH_ASSESSMENT_ENABLED`. Off means the entry link is hidden and every batch route returns 404. **The flag lands in Task 5, before any batch screen exists** (spec §9.1).
- **Every task on its own branch**, off `feat/batch-assessment`, merged back with `--no-ff`. Never commit to `master`. Each task's first step is `git switch -c feat/batch-tNN-<slug>` from the integration branch; each task's last step is the merge.
- **Scheduler settings move together:** `--limit=20` with `withoutOverlapping(120)`. Changing one without the other re-creates the double-scoring bug.
- **Nothing reaches the lane unsent.** `submitted_for_analysis_at` is the gate; an unsent recording is never claimed.
- **Single-child behaviour must not change.** The entire existing suite must stay green **with zero edits to any existing test file**. If an existing test must change to pass, the change is wrong.
- **`RescoreQueueTest` must stay green, unedited** — it pins outage-recovery behaviour already in production.
- **Never add a rule that authors a weakness label.** Only the Random Forest may name one. No fallback, no "clearest problem" pick.
- **Read weaknesses only through `Assessment::weaknessOutcome()`**, never `->primary_weakness`. `ClassificationPagesTest` fails otherwise.
- **No `app/Jobs/`, no `queue:work`, no Laravel queue usage.** The lane is the scheduler plus the database.
- **No new `Assessment` status constant.** "Owed a score" is expressed by `scopeAwaitingRescore()`.
- **`--limit` × worst-case per-recording time must stay comfortably below the `withoutOverlapping` window.** The two move together or not at all.
- **Never stage anything under `ml-service/`** — the working tree holds the user's deliberate file moves there.
- **Page headers:** a page in the nav gets no back link; a drill-down gets one labelled with its destination. Enforced by `PageHeaderTest`.
- Commits end with: `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`

## Review Focus

Five conditions the spec implies that no task's happy path exercises, most likely to bite first:

1. **A transient `failed` status during an engine outage read as permanent failure.** `RescoreQueue::rescore()` calls `markFailed()` even when the engine — not the recording — is at fault, deliberately leaving the attempt uncounted. A board that renders raw `status` would tell a teacher that ten children failed when nothing did. *(Task 8)*
2. **A comprehension batch entry whose answers never arrive.** `RescoreQueue::run()` skips an unscored assessment that `needsComprehensionTest()` without answers — forever. The Send gate is what prevents the silent stall: such an entry can never be sent, so it rests visibly at `recorded` saying what it needs, rather than sitting in a lane that will never take it. *(Tasks 8 and 9)*
3. **A learner leaving the teacher's reach mid-batch.** Class reassignment during a 40-minute batch must not expose that child's result to a teacher who no longer has access, and must not 500 the board. *(Task 8)*
4. **The same learner added to a batch while another assessment of theirs is still unscored.** Two in-flight assessments for one child, both owed a score, both reaching `createResult()` — which replaces. *(Task 6)*
5. **Roster boundaries: 0 learners, exactly 15, exactly 16, and the same learner twice.** *(Task 6)*
6. **A batch left unwatched.** The browser drives nothing, but the draft had the batch's own status updating only on poll — so a batch finishing overnight would still read `analyzing`. Pinned by a test that makes no HTTP request at all. *(Task 8)*
7. **A removal that quietly rewrites a child's record.** Deleting an `Assessment` cascades to its result, verdict and answers, and silently recomputes the learner's reading level. No board action may do this. *(Tasks 11 and 12)*

---

### Task 1: Schema and the batch model

**Files:**
- Create: `database/migrations/2026_10_21_010000_create_assessment_batches_table.php`
- Create: `database/migrations/2026_10_21_010001_add_batch_columns_to_assessments_table.php`
- Create: `app/Models/AssessmentBatch.php`
- Create: `database/factories/AssessmentBatchFactory.php`
- Modify: `app/Models/Assessment.php` — add `batch_id` to `$fillable`, add `batch()` relation
- Test: `tests/Feature/AssessmentBatchTest.php`

**The migration timestamps are not free to choose.** `2026_10_21_000000` is already taken by `add_rescore_queue_and_service_health`. Use `010000` and `010001` as given.

**Column conventions:** follow the *newest* migration, not the oldest. `service_health_events.state` is `string(24)` with a `->comment()` listing permitted values; the original `assessments` table uses `enum()`. **Use `string` + comment** — adding a batch status later would otherwise need an `ALTER` on a MySQL enum, and the values are already enforced by model constants and validation.

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `AssessmentBatch` with `$fillable`: `teacher_id`, `school_class_id`, `title`, `language`, `assessment_type`, `default_material_id`, `status`, `completed_at`, `cancelled_at`
  - Constants `AssessmentBatch::STATUS_COLLECTING = 'collecting'`, `STATUS_ANALYZING = 'analyzing'`, `STATUS_COMPLETED = 'completed'`, `STATUS_CANCELLED = 'cancelled'` — **there is no `engine_unavailable`** (spec §4.4)
  - Relations: `teacher(): BelongsTo`, `schoolClass(): BelongsTo`, `defaultMaterial(): BelongsTo` (`ReadingMaterial`, FK `default_material_id`), `entries(): HasMany` (`Assessment`, FK `batch_id`)
  - `Assessment::batch(): BelongsTo`

`assessment_batches` columns, with on-delete behaviour that is load-bearing, not incidental:

| Column | Type | On delete | Why |
|---|---|---|---|
| `teacher_id` | `foreignId` → `users` | `restrictOnDelete` | Matches `assessments.assessor_id` — a teacher with history is never silently deleted |
| `school_class_id` | `foreignId` nullable → `school_classes` | `nullOnDelete` | A hand-picked batch has no class; a deleted class leaves the batch as history |
| `title` | `string` nullable | — | Teacher's own label |
| `language` | `string(8)` | — | Comment: `en \| fil \| hil` |
| `assessment_type` | `string(16)` | — | Comment: `oral_reading \| comprehension \| combined` |
| `default_material_id` | `foreignId` → `reading_materials` | `restrictOnDelete` | Matches `assessments.material_id` — a cited material cannot vanish |
| `status` | `string(16)` | — | Comment: `collecting \| analyzing \| completed \| cancelled` |
| `completed_at`, `cancelled_at` | `timestamp` nullable | — | Lifecycle |

Indexes on `assessment_batches`: `teacher_id`, `status`.

Columns added to `assessments`, all nullable and additive:

| Column | Type | Placement | Why |
|---|---|---|---|
| `batch_id` | `foreignId` nullable → `assessment_batches`, **`nullOnDelete`** | `->after('assessor_id')` | `NULL` means single-child. `nullOnDelete` is load-bearing: **deleting a batch must never delete a child's assessment or result.** |
| `submitted_for_analysis_at` | `timestamp` nullable | `->after('last_rescore_at')` | The Send gate. Separates `recorded` from `queued` and is what Task 2's scope branch checks. |
| `analysis_started_at` | `timestamp` nullable | after the above | Set, with `analysis_duration_ms` still null, **is** the `transcribing` state |
| `analysis_duration_ms` | `unsignedInteger` nullable | after the above | Written on every run incl. single-child; feeds the estimator |

Indexes added to `assessments`: `batch_id`, and composite **`(batch_id, submitted_for_analysis_at)`** — Task 2's scope branch filters on both, and without it that branch scans `assessments` every scheduler tick, which at `everyMinute` is every minute. Do **not** index the two timing columns: the estimator reads the last 20 rows by `id`, and the `transcribing` check is always already narrowed by `batch_id`.

**There is no `recording_mode` column.** Every card offers both a join code and *Conduct here*; nothing is chosen in advance.

- [ ] **Step 0: Branch**

```bash
git switch feat/batch-assessment && git switch -c feat/batch-t01-schema
```

- [ ] **Step 1: Write the failing test**

In `tests/Feature/AssessmentBatchTest.php`:

```php
public function test_an_assessment_with_no_batch_is_a_single_child_assessment(): void
{
    $assessment = Assessment::factory()->create();

    $this->assertNull($assessment->batch_id);
    $this->assertNull($assessment->batch);
}

public function test_a_batch_holds_its_entries_and_its_defaults(): void
{
    $batch = AssessmentBatch::factory()->create([
        'language' => 'fil',
        'assessment_type' => Assessment::TYPE_COMBINED,
        'status' => AssessmentBatch::STATUS_COLLECTING,
    ]);
    $entry = Assessment::factory()->create(['batch_id' => $batch->id]);

    $this->assertTrue($batch->entries->contains($entry));
    $this->assertSame($batch->id, $entry->batch->id);
}

public function test_deleting_a_batch_keeps_its_assessments_and_their_results(): void
{
    $batch = AssessmentBatch::factory()->create();
    $entry = Assessment::factory()->create(['batch_id' => $batch->id]);

    $batch->delete();

    $this->assertDatabaseHas('assessments', ['id' => $entry->id, 'batch_id' => null]);
}

public function test_a_material_cited_by_a_batch_cannot_be_deleted(): void
{
    $batch = AssessmentBatch::factory()->create();

    $this->expectException(\Illuminate\Database\QueryException::class);
    ReadingMaterial::findOrFail($batch->default_material_id)->delete();
}

public function test_a_new_batch_starts_collecting_and_has_no_submitted_entries(): void
{
    $entry = Assessment::factory()->create(['batch_id' => AssessmentBatch::factory()]);

    $this->assertNull($entry->submitted_for_analysis_at);
    $this->assertNull($entry->analysis_started_at);
    $this->assertNull($entry->analysis_duration_ms);
}
```

Create `database/factories/AssessmentBatchFactory.php` alongside, following the shape of the existing factories in that directory.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=AssessmentBatchTest`
Expected: FAIL — `Class "App\Models\AssessmentBatch" not found`

- [ ] **Step 3: Write the two migrations**

Per the tables in Interfaces. Both carry a working `down()`; the second drops its four columns and both indexes. All changes are additive — one new table, four nullable columns, two indexes. Nothing is renamed, retyped or dropped. Add a docblock to each explaining what it is for, matching the style of `2026_10_21_000000_add_rescore_queue_and_service_health.php`.

- [ ] **Step 4: Implement `AssessmentBatch` and the `Assessment` additions**

Follow `app/Models/AssessmentSession.php` for model style (`$fillable`, `casts(): array`, relations, scopes). Cast `completed_at` and `cancelled_at` to `datetime`.

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=AssessmentBatchTest`
Expected: PASS

- [ ] **Step 6: Verify the rollback is clean and nothing else broke**

Run: `php artisan migrate:fresh && php artisan migrate:rollback --step=2 && php artisan migrate && php artisan test`
Expected: rollback and re-migrate both succeed; full suite PASS with zero edits to existing test files.

- [ ] **Step 7: Confirm the indexes actually exist**

Run: `php artisan db:table assessments`
Expected: the output lists an index on `batch_id` and a composite index covering `batch_id, submitted_for_analysis_at`. A migration that silently skipped an index is a slow scan every minute in production, so this is checked rather than assumed.

- [ ] **Step 8: Commit and merge the phase**

```bash
git add database/migrations database/factories/AssessmentBatchFactory.php app/Models/AssessmentBatch.php app/Models/Assessment.php tests/Feature/AssessmentBatchTest.php
git commit -m "feat(batch): add assessment batches and the roster columns"
git switch feat/batch-assessment && git merge --no-ff feat/batch-t01-schema
```

---

### Task 2: Claim batch entries for scoring at once

**Files:**
- Modify: `app/Models/Assessment.php:602` — `scopeAwaitingRescore()`
- Test: `tests/Feature/BatchRescoreClaimTest.php`

**Interfaces:**
- Consumes: Task 1's `batch_id` column.
- Produces:
  - no new production signature. `scopeAwaitingRescore()` gains **one** additive `orWhere` branch, narrow by design:

    ```
    batch_id IS NOT NULL  AND  submitted_for_analysis_at IS NOT NULL  AND  no result
    ```

    The `failed` and 15-minutes-stale-in-`processing` conditions are left exactly as they are, so a non-batch recording behaves identically. The `submitted_for_analysis_at` gate means **a half-finished batch cannot leak into the lane**: a teacher who rosters fifteen children and records three has three recordings stored and nothing queued until they send.
  - **`tests/Concerns/MakesBatchAssessments.php`** — the shared test helpers every later task uses. Define them here, once:
    - `batchEntryWithAudio(array $attributes = []): Assessment` — a batch entry with a stored fake audio file, **not yet sent**; creates a batch unless `batch_id` is given
    - `sentBatchEntry(array $attributes = []): Assessment` — the same, with `submitted_for_analysis_at` stamped
    - `assessmentWithAudio(array $attributes = []): Assessment` — the same without a batch
    - `classifiedAnalysis(array $overrides = []): array` — a minimal valid payload for `createResult()` with `classification_status = 'classified'`
    - `fakeEngine(bool $stt = true, bool $classifier = true): void` — `Http::fake()` for the Flask service, copied from `RescoreQueueTest`'s existing faking rather than reinvented
    - `teacher(): User` / `otherTeacher(): User` — a teacher with one class and reachable learners

Tasks 3, 6, 7, 8, 9 and 10 all `use MakesBatchAssessments;` rather than rolling their own fixtures.

This is the riskiest edit in the plan: the scope is shared with outage recovery that already works in production. It is widened **only** for rows with a `batch_id`.

- [ ] **Step 0: Branch**

```bash
git switch feat/batch-assessment && git switch -c feat/batch-t02-claim
```

- [ ] **Step 1: Write the failing test**

```php
public function test_a_sent_batch_entry_is_owed_a_score_at_once(): void
{
    $entry = $this->sentBatchEntry(['status' => Assessment::STATUS_PROCESSING]);

    $this->assertTrue(Assessment::awaitingRescore()->whereKey($entry->id)->exists());
}

public function test_an_unsent_batch_entry_is_never_claimed(): void
{
    $entry = $this->batchEntryWithAudio();   // audio saved, not sent

    $this->assertFalse(Assessment::awaitingRescore()->whereKey($entry->id)->exists());
}

public function test_a_single_child_recording_still_waits_for_the_stale_rule(): void
{
    $solo = $this->assessmentWithAudio([
        'batch_id' => null,
        'status' => Assessment::STATUS_PROCESSING,
        'updated_at' => now()->subMinutes(5),
    ]);

    $this->assertFalse(Assessment::awaitingRescore()->whereKey($solo->id)->exists());
}

public function test_a_sent_batch_entry_that_already_has_a_result_is_not_owed_a_score(): void
{
    $entry = $this->sentBatchEntry();
    $entry->createResult($this->classifiedAnalysis());

    $this->assertFalse(Assessment::awaitingRescore()->whereKey($entry->id)->exists());
}

public function test_a_batch_entry_with_no_audio_is_not_owed_a_score(): void
{
    $entry = Assessment::factory()->create([
        'batch_id' => AssessmentBatch::factory(),
        'audio_file' => null,
        'submitted_for_analysis_at' => now(),   // even if somehow marked sent
    ]);

    $this->assertFalse(Assessment::awaitingRescore()->whereKey($entry->id)->exists());
}

public function test_a_sent_entry_whose_result_lacks_a_classification_is_still_owed_one(): void
{
    $entry = $this->sentBatchEntry();
    $entry->createResult($this->unclassifiedAnalysis());

    $this->assertTrue(Assessment::awaitingRescore()->whereKey($entry->id)->exists());
}
```

The last one pins the model-or-nothing rule against the existing scope: an unclassified result is still owed a classification. Add `unclassifiedAnalysis()` to the helper trait beside `classifiedAnalysis()`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=BatchRescoreClaimTest`
Expected: FAIL on the first test — the batch entry is not yet claimed.

- [ ] **Step 3: Widen the scope and add the test-helper trait**

Add the batch branch inside the existing `$unscored` closure, leaving the `failed` and stale-`processing` conditions exactly as they are. The `whereNotNull('audio_file')` guard at the top of the scope already covers the no-audio case. Create `tests/Concerns/MakesBatchAssessments.php` with the helpers listed in Interfaces.

- [ ] **Step 3b: Confirm the new branch uses the composite index**

Run: `php artisan tinker --execute="echo Assessment::awaitingRescore()->toSql();"` then `EXPLAIN` that query against a seeded table.
Expected: the batch branch resolves via the `(batch_id, submitted_for_analysis_at)` index from Task 1, not a full scan. This query runs **every minute** in production, so a scan here is a standing cost.

- [ ] **Step 4: Run the new test and the outage-recovery test together**

Run: `php artisan test --filter="BatchRescoreClaimTest|RescoreQueueTest"`
Expected: PASS, with **zero edits to `RescoreQueueTest`**. If it needed editing, revert and re-scope the change.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Assessment.php tests/Concerns/MakesBatchAssessments.php tests/Feature/BatchRescoreClaimTest.php
git commit -m "feat(batch): owe a score to a batch entry once the teacher sends it"
git switch feat/batch-assessment && git merge --no-ff feat/batch-t02-claim
```

---

### Task 3: Measure how long scoring takes, and estimate the wait

**Files:**
- Modify: `app/Services/AssessmentScorer.php` — stamp timing around the run
- Create: `app/Services/AnalysisQueueEstimator.php`
- Test: `tests/Feature/AnalysisQueueEstimatorTest.php`

**Interfaces:**
- Consumes: Task 1's `analysis_started_at` / `analysis_duration_ms`; Task 2's widened scope.
- Produces:
  - `AssessmentScorer::score()` — unchanged signature, now stamps `analysis_started_at` before transcription and `analysis_duration_ms` after `createResult()`, on **every** run including single-child.
  - `AnalysisQueueEstimator::typicalSeconds(): float` — median of the last 20 non-null `analysis_duration_ms`, or `150.0` when there are none.
  - `AnalysisQueueEstimator::forBatch(AssessmentBatch $batch): array` — returns `['remaining' => int, 'ahead' => int, 'seconds' => int]`: entries of this batch still owed a score, entries of *other* teachers' work ahead of this batch's oldest due entry, and the estimated seconds until this batch finishes.

Median, not mean, so one slow outlier does not distort every figure on screen. `seconds` must include one scheduler tick of latency.

- [ ] **Step 1: Write the failing test**

```php
public function test_the_typical_duration_is_the_median_of_recent_runs(): void
{
    foreach ([60_000, 120_000, 300_000] as $ms) {
        Assessment::factory()->create(['analysis_duration_ms' => $ms]);
    }

    $this->assertSame(120.0, app(AnalysisQueueEstimator::class)->typicalSeconds());
}

public function test_the_typical_duration_falls_back_to_a_seed_with_no_data(): void
{
    $this->assertSame(150.0, app(AnalysisQueueEstimator::class)->typicalSeconds());
}

public function test_a_batch_estimate_counts_only_entries_still_owed_a_score(): void
{
    $batch = AssessmentBatch::factory()->create();
    $scored = $this->batchEntryWithAudio(['batch_id' => $batch->id]);
    $scored->createResult($this->classifiedAnalysis());
    $this->batchEntryWithAudio(['batch_id' => $batch->id]);
    $this->batchEntryWithAudio(['batch_id' => $batch->id]);

    $estimate = app(AnalysisQueueEstimator::class)->forBatch($batch->fresh());

    $this->assertSame(2, $estimate['remaining']);
    $this->assertGreaterThan(0, $estimate['seconds']);
}

public function test_other_teachers_work_counts_as_ahead(): void
{
    $older = $this->batchEntryWithAudio(['batch_id' => AssessmentBatch::factory()]);
    $batch = AssessmentBatch::factory()->create();
    $this->batchEntryWithAudio(['batch_id' => $batch->id]);

    $this->assertSame(1, app(AnalysisQueueEstimator::class)->forBatch($batch->fresh())['ahead']);
}

public function test_scoring_records_how_long_it_took(): void
{
    // fake a successful STT + classify, then score one assessment
    $assessment = $this->assessmentWithAudio();

    app(AssessmentScorer::class)->score($assessment);

    $this->assertNotNull($assessment->fresh()->analysis_started_at);
    $this->assertGreaterThan(0, $assessment->fresh()->analysis_duration_ms);
}
```

Fake the Flask service with `Http::fake()` as `RescoreQueueTest` does; copy that file's faking helpers rather than inventing new ones.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=AnalysisQueueEstimatorTest`
Expected: FAIL — `AnalysisQueueEstimator` not found.

- [ ] **Step 3: Stamp the timings in `AssessmentScorer::score()`**

Additive only — do not reorder or alter any existing step. `analysis_started_at` goes beside the existing `updateStatus(STATUS_PROCESSING)`; the duration is written after `createResult()` returns. Use `forceFill()->save()` for both, matching how `RescoreQueue::rescore()` writes `last_rescore_at`.

- [ ] **Step 4: Implement `AnalysisQueueEstimator`**

`ahead` is `RescoreQueue::due()` rows whose `id` is lower than this batch's oldest due entry, excluding this batch's own entries. Inject `RescoreQueue` rather than re-deriving the due query.

- [ ] **Step 5: Run the test to verify it passes, then the full suite**

Run: `php artisan test --filter=AnalysisQueueEstimatorTest && php artisan test`
Expected: both PASS, zero edits to existing test files.

- [ ] **Step 6: Commit**

```bash
git add app/Services/AssessmentScorer.php app/Services/AnalysisQueueEstimator.php tests/Feature/AnalysisQueueEstimatorTest.php
git commit -m "feat(batch): measure scoring time and estimate the queue wait"
git switch feat/batch-assessment && git merge --no-ff feat/batch-t03-estimator
```

---

### Task 4: Tune the lane for a batch arriving at once

**Files:**
- Modify: `routes/console.php`
- Test: `tests/Feature/RescoreScheduleTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: the scheduled command becomes `assessments:rescore --limit=20`, `everyMinute()`, `withoutOverlapping(120)`.

| Setting | Was | Now | Why |
|---|---|---|---|
| `--limit` | 5 | **20** | A full 15-child batch clears in **one run**, with room for a few single assessments alongside |
| worst-case run | 15 min | **60 min** | 20 × 3 min |
| × 1.5 margin | 22.5 min | **90 min** | The figure the lock must clear |
| `withoutOverlapping` | 30 min | **120 min** | Comfortably above 90 |
| tick | 5 min | **1 min** | See below |

`everyMinute` is deliberate and not a typo: `withoutOverlapping` already prevents a second concurrent run, so a one-minute tick means "start again as soon as the lane is free" instead of idling to the next multiple of five. The command exits immediately when nothing is due, so an idle tick costs nothing.

**Two costs to carry forward into Task 11's deploy notes, not to discover in production:**

1. **A 60-minute run holds the single lane.** Another teacher's one-off assessment created mid-batch waits behind it. `RescoreQueue` orders `oldest('id')` first, so this is fair FIFO rather than starvation — but fair still means slow with one lane.
2. **A 120-minute lock is a 120-minute liability if the process is killed.** `withoutOverlapping` releases on normal exit; a `SIGKILL` (OOM, `systemctl stop` mid-run, reboot) leaves the cache lock. Until it expires **nothing is rescored at all**, including outage recovery for ordinary single-child assessments. The unstick command is `php artisan schedule:clear-cache`, and the symptom is recordings sitting at `queued` with a demonstrably healthy engine and no `Rescored assessment` lines in the log.

- [ ] **Step 1: Write the failing test**

Two tests. The first drives the change; the second is a standing guard, and it is written to fail at today's values too so that both fail before Step 3.

```php
private function rescoreEvent(): \Illuminate\Console\Scheduling\Event
{
    $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'assessments:rescore'));

    $this->assertNotNull($event, 'assessments:rescore is not scheduled.');

    return $event;
}

public function test_the_lane_is_tuned_for_a_batch_arriving_at_once(): void
{
    $event = $this->rescoreEvent();

    $this->assertStringContainsString('--limit=20', $event->command);
    $this->assertSame('* * * * *', $event->expression);     // every minute
    $this->assertSame(120, $event->expiresAt);              // minutes

    // A full roster must clear in one run.
    $this->assertGreaterThanOrEqual(config('assessment.batch_max_learners'), 20);
}

public function test_a_rescore_run_cannot_outlast_its_overlap_lock(): void
{
    $event = $this->rescoreEvent();
    preg_match('/--limit=(\d+)/', $event->command, $m);
    $limit = (int) ($m[1] ?? 5);

    // Worst case 3 min per recording, plus a 1.5x safety margin, must fit
    // inside the lock - otherwise the lock expires mid-run and the next tick
    // starts a second run that scores the same recordings again.
    $this->assertLessThanOrEqual(
        $event->expiresAt,
        $limit * 3 * 1.5,
        'Raising --limit without raising withoutOverlapping lets two runs score the same recording.'
    );
}
```

At today's values the guard computes `5 × 3 × 1.5 = 22.5` against a `30`-minute lock and passes, so the *first* test is what must fail. After Step 3 the guard computes `20 × 3 × 1.5 = 90` against `120` and passes with real margin.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=RescoreScheduleTest`
Expected: `test_the_lane_is_tuned_for_a_batch_arriving_at_once` FAILS — `--limit=20` not found in the command. If `assertNotNull` fails instead, the scheduler is not registering the command in the test environment; fix the test's access to the schedule before going further.

- [ ] **Step 3: Apply the new schedule in `routes/console.php`**

Keep the existing explanatory comment and the `health:check` line untouched.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=RescoreScheduleTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add routes/console.php tests/Feature/RescoreScheduleTest.php
git commit -m "feat(batch): tune the rescore lane for 15 recordings at once"
git switch feat/batch-assessment && git merge --no-ff feat/batch-t04-schedule
```

---

### Task 5: Config, feature flag and routes

**Files:**
- Create: `config/assessment.php`
- Modify: `.env.example` — add `BATCH_ASSESSMENT_ENABLED=false` beside the other assessment keys
- Modify: `routes/web.php:183` region — add the batch routes inside the existing teacher-only group
- Create: `app/Http/Middleware/EnsureBatchAssessmentEnabled.php`
- Test: `tests/Feature/BatchFeatureFlagTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `config('assessment.batch_enabled')` (bool, default **false**), `config('assessment.batch_max_learners')` (int, default **15**)
  - Middleware alias `batch.enabled`, registered in `bootstrap/app.php`, aborting 404 when the flag is off
  - Named routes, all inside the teacher group and all behind `batch.enabled`:
    - `GET  /assessments/batch/create` → `assessments.batch.create`
    - `POST /assessments/batch` → `assessments.batch.store`
    - `GET  /assessments/batch/{batch}` → `assessments.batch.board`
    - `GET  /assessments/batch/{batch}/poll` → `assessments.batch.poll`
    - `GET  /assessments/batch/{batch}/entry/{assessment}` → `assessments.batch.entry` (the card panel)
    - `POST /assessments/batch/{batch}/entry/{assessment}/upload-audio` → `assessments.batch.upload-audio`
    - `POST /assessments/batch/{batch}/entry/{assessment}/answers` → `assessments.batch.answers`
    - `POST /assessments/batch/{batch}/entry/{assessment}/send` → `assessments.batch.send`
    - `POST /assessments/batch/{batch}/send-all` → `assessments.batch.send-all`
    - `POST /assessments/batch/{batch}/cancel` → `assessments.batch.cancel`

**Route ordering matters:** `/assessments/batch/create` must be declared **before** `/assessments/{assessment}`, for the same reason `/assessments/new` already is — see the existing comment at `routes/web.php:180`.

- [ ] **Step 1: Write the failing test**

```php
public function test_the_batch_routes_are_hidden_when_the_flag_is_off(): void
{
    config(['assessment.batch_enabled' => false]);

    $this->actingAs($this->teacher())->get(route('assessments.batch.create'))->assertNotFound();
}

public function test_the_batch_setup_page_opens_when_the_flag_is_on(): void
{
    config(['assessment.batch_enabled' => true]);

    $this->actingAs($this->teacher())->get(route('assessments.batch.create'))->assertOk();
}

public function test_the_flag_defaults_to_off(): void
{
    $this->assertFalse(config('assessment.batch_enabled'));
}

public function test_the_roster_cap_is_fifteen(): void
{
    $this->assertSame(15, config('assessment.batch_max_learners'));
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=BatchFeatureFlagTest`
Expected: FAIL — route `assessments.batch.create` not defined.

- [ ] **Step 3: Add the config, the middleware, the routes, and a placeholder controller**

`AssessmentBatchController::create()` returns a view stub for now; Task 7 fills it. Use the `AuthorizesLearnerAccess` trait as the other assessment controllers do.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=BatchFeatureFlagTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add config/assessment.php .env.example routes/web.php bootstrap/app.php app/Http/Middleware/EnsureBatchAssessmentEnabled.php app/Http/Controllers/AssessmentBatchController.php resources/views/assessments/batch tests/Feature/BatchFeatureFlagTest.php
git commit -m "feat(batch): add the batch routes behind a default-off flag"
git switch feat/batch-assessment && git merge --no-ff feat/batch-t05-flag
```

---

### Task 6: Roster validation

**Files:**
- Create: `app/Services/BatchRosterBuilder.php`
- Test: `tests/Feature/BatchRosterBuilderTest.php`

**Interfaces:**
- Consumes: Task 1's models, Task 5's config.
- Produces:
  - `BatchRosterBuilder::build(User $teacher, array $input): AssessmentBatch` — validates the whole roster, creates the batch and one `Assessment` per entry inside a single transaction, and returns the batch.
  - Throws `Illuminate\Validation\ValidationException` with per-field messages; creates nothing when any entry is invalid.
  - `$input` shape: `['title' => ?string, 'language' => string, 'assessment_type' => string, 'default_material_id' => int, 'school_class_id' => ?int, 'entries' => [['learner_id' => int, 'material_id' => ?int, 'allow_fallback' => bool], ...]]`
  - **No `recording_mode`.** Every card offers both routes, so nothing about recording is decided at setup.

Every rule already enforced by `AssessmentController::store()` is enforced here **per entry**, reusing the same thresholds and the same messages: material language must match the batch language; a material whose grade differs from the learner's requires that entry's own `allow_fallback`; the difference may never exceed one grade; comprehension and combined types require a material that has comprehension questions.

- [ ] **Step 1: Write the failing test**

```php
public function test_a_roster_within_the_cap_creates_one_assessment_per_learner(): void
public function test_a_roster_of_sixteen_is_rejected(): void            // cap boundary
public function test_a_roster_of_exactly_fifteen_is_accepted(): void    // cap boundary
public function test_an_empty_roster_is_rejected(): void
public function test_the_same_learner_cannot_appear_twice(): void
public function test_a_learner_the_teacher_cannot_access_is_rejected(): void
public function test_a_cross_grade_material_needs_that_entry_s_own_fallback_tick(): void
public function test_a_material_two_grades_away_is_rejected_even_with_fallback(): void
public function test_a_material_in_another_language_is_rejected(): void
public function test_a_comprehension_batch_needs_materials_with_questions(): void
public function test_a_per_child_material_override_is_stored_on_that_entry(): void
public function test_nothing_is_created_when_one_entry_is_invalid(): void
public function test_a_learner_with_another_unscored_assessment_can_still_join(): void
```

The last one pins Review Focus #4: it must succeed, and both assessments must exist independently — `createResult()` replaces per assessment, not per learner, so two in-flight assessments for one child are safe but must be proven so.

Write the assertions out in full against the spec's exact thresholds; the names above are the contract.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=BatchRosterBuilderTest`
Expected: FAIL — `BatchRosterBuilder` not found.

- [ ] **Step 3: Implement `BatchRosterBuilder::build()`**

Wrap creation in `DB::transaction()`. Entries are created with `status = Assessment::STATUS_PENDING` and no `audio_file`, so nothing is owed a score until audio arrives. Batch `status` starts at `STATUS_COLLECTING`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=BatchRosterBuilderTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/BatchRosterBuilder.php tests/Feature/BatchRosterBuilderTest.php
git commit -m "feat(batch): validate a roster per child before creating anything"
git switch feat/batch-assessment && git merge --no-ff feat/batch-t06-roster
```

---

### Task 7: Batch setup screen

**Files:**
- Modify: `app/Http/Controllers/AssessmentBatchController.php` — `create()`, `store()`, `cancel()`
- Create: `resources/views/assessments/batch/create.blade.php`
- Modify: `resources/views/assessments/create.blade.php` — one added link
- Test: `tests/Feature/BatchSetupTest.php`

**Interfaces:**
- Consumes: `BatchRosterBuilder::build()` (Task 6), the routes from Task 5.
- Produces: `store()` redirects to `assessments.batch.board` on success, or back with errors and input on `ValidationException`.

The added link on the existing single-learner screen is the **only** change to that file, and it renders only when `config('assessment.batch_enabled')` is true. Learners come from `$user->accessibleLearnersQuery()` sorted through `Directory::sortLearners()`, as `AssessmentController::create()` does. Materials load progressively via the existing `materials.options` route — do not build a second materials endpoint.

- [ ] **Step 1: Write the failing test**

```php
public function test_the_setup_page_lists_only_learners_the_teacher_can_reach(): void
public function test_a_valid_roster_redirects_to_the_board(): void
public function test_an_invalid_roster_returns_to_the_form_with_the_input(): void
public function test_another_teachers_learner_cannot_be_rostered(): void
public function test_an_admin_cannot_create_a_batch(): void
public function test_the_group_link_is_hidden_on_the_single_learner_page_when_the_flag_is_off(): void
public function test_cancelling_a_batch_marks_it_cancelled(): void
```

`test_an_admin_cannot_create_a_batch` follows the `LearnerAdminReadOnlyTest` precedent — admins browse, teachers assess.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=BatchSetupTest`
Expected: FAIL

- [ ] **Step 3: Implement the controller methods and the view**

Build the page on the staff shell with `<x-page-header>`. It is reached from the single-learner screen, so it is a drill-down and **gets a back link labelled "Assessments"**.

- [ ] **Step 4: Run the test and `PageHeaderTest` to verify they pass**

Run: `php artisan test --filter="BatchSetupTest|PageHeaderTest"`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/AssessmentBatchController.php resources/views/assessments/batch/create.blade.php resources/views/assessments/create.blade.php tests/Feature/BatchSetupTest.php
git commit -m "feat(batch): add the batch setup screen"
git switch feat/batch-assessment && git merge --no-ff feat/batch-t07-setup
```

---

### Task 8: The card board

**Files:**
- Modify: `app/Http/Controllers/AssessmentBatchController.php` — `board()`, `poll()`
- Create: `app/Services/BatchEntryState.php`
- Create: `app/Observers/AssessmentObserver.php`
- Modify: `app/Providers/AppServiceProvider.php` — register the observer
- Create: `resources/views/assessments/batch/board.blade.php`
- Create: `resources/views/assessments/batch/_card.blade.php`
- Create: `public/css/batch-board.css`
- Create: `public/js/batch-board.js`
- Test: `tests/Feature/BatchBoardTest.php`

**Interfaces:**
- Consumes: `AnalysisQueueEstimator::forBatch()` (Task 3), the routes from Task 5.
- Produces:
  - `BatchEntryState::for(Assessment $entry): array` → `['chip' => string, 'label' => string, 'tone' => 'good'|'ok'|'help'|'none', 'playable' => bool, 'action' => ?string, 'result_url' => ?string]`
  - `AssessmentBatch::refreshStatus(): void` — recomputes the batch's own status from its entries and saves it. **This is the only thing that writes `assessment_batches.status`** apart from creation (`collecting`) and cancellation. Rules: any entry past `waiting` ⇒ `analyzing`; every entry `done` or `failed` ⇒ `completed` with `completed_at`; a `cancelled` batch never changes.
  - `AssessmentObserver::saved()` — when a saved `Assessment` has a `batch_id`, calls `refreshStatus()` on its batch.
  - `poll()` returns `['entries' => [id => state], 'batch_status' => string, 'estimate' => ['remaining' => int, 'ahead' => int, 'seconds' => int], 'ready_to_send' => int]` — **read-only**, it writes nothing.

**`refreshStatus()` must not be driven by the browser.** The draft called it from `poll()`, which meant a batch finishing overnight would still read `analyzing` until someone reopened the board. The observer makes the batch complete itself with nobody watching; `poll()` only reads. A stored status that updates only while someone is looking at it is a rendering artefact, not a stored status.

**`result_url`** is set as soon as a result exists — at `needs verdict` and at `done` — and renders as a **Check Result** button pointing at the existing `assessments.results` screen. One place a reading is ever read; the verdict is recorded there as it always has been.

**A grid of cards, one per child, every card clickable** — not a table. The card carries the child's name, the state chip, and the one action that state calls for; clicking it opens that child's panel (Task 9). Build on the staff shell with `<x-page-header>`; the board is reached from the setup screen, so it gets a back link labelled **"Assessments"**. Card tone classes reuse the existing `good` / `ok` / `help` / `none` palette from `parent.css` rather than inventing new colours.

**The seven states are the user's own vocabulary.** Derive them in exactly this order and return the first match:

| Chip | Condition | Card shows |
|---|---|---|
| `waiting` | no `audio_file` | *Conduct here*, join code |
| `recorded` | audio saved, `submitted_for_analysis_at` null, no result | **audio player**, *Send for analysis*, *Re-record* |
| `transcribing` | `analysis_started_at` set, `analysis_duration_ms` null, status `processing` | live progress; audio playable |
| `queued` | `awaitingRescore()` claims it | place in line + ETA; audio playable |
| `needs verdict` | result exists, classification not `unavailable`, no verdict | **Check Result** → existing results screen |
| `done` | verdict exists | reading level + accuracy, **Check Result** |
| `failed` | `rescore_attempts >= RescoreQueue::MAX_ATTEMPTS`, no result | what went wrong, *Analyze* by hand |

`playable` is simply `hasAudio()` — **audio is available in the card from `recorded` onward**, not only at `recorded`, so a surprising result stays checkable.

This task carries four of the five Review Focus items, so its tests are the load-bearing ones in the plan.

**Two honesty rules, both enforced by the ordering above:**

- **`BatchEntryState` must never render raw `status`.** `RescoreQueue::rescore()` calls `markFailed()` during an engine outage *by design*, leaving the attempt uncounted. An entry whose `status` is `failed` but which `awaitingRescore()` still claims is **`queued`, waiting for the speech engine** — never `failed`. Only `rescore_attempts >= MAX_ATTEMPTS` is `failed`.
- **An unclassified result is not `done`.** A result with `classification_status = 'unavailable'` has measurements but no weakness label; `awaitingRescore()` still claims it, so it reads `queued` — waiting for the model. It must never present as finished.

**One bulk action, and only one.** *Send all ready* releases every `recorded` card at once, for the common case of fifteen children reading simultaneously on their own devices. This is **not** a contradiction of the no-bulk-accept rule: sending asks the machine to measure something and a bad send costs lane time, whereas accepting a verdict writes a judgement about a child into their permanent record. The first is safe fifteen at a time; the second never is. **No bulk verdict control anywhere on this page.**

- [ ] **Step 1: Write the failing test**

```php
// Review Focus #1 — the one most likely to bite
public function test_an_entry_waiting_for_a_downed_engine_reads_as_queued_not_failed(): void
{
    $entry = $this->sentBatchEntry();
    $entry->markFailed('The speech engine is down.');   // what RescoreQueue does on an outage

    $state = BatchEntryState::for($entry->fresh());

    $this->assertSame('queued', $state['chip']);
    $this->assertStringNotContainsStringIgnoringCase('failed', $state['label']);
}

public function test_an_entry_that_used_up_its_attempts_reads_as_failed(): void
{
    $entry = $this->sentBatchEntry(['rescore_attempts' => RescoreQueue::MAX_ATTEMPTS]);

    $this->assertSame('failed', BatchEntryState::for($entry->fresh())['chip']);
}

// Review Focus #2 — the Send gate is what prevents the silent stall
public function test_a_comprehension_entry_without_answers_cannot_be_sent(): void
{
    $entry = $this->batchEntryWithAudio(['assessment_type' => Assessment::TYPE_COMBINED]);

    $state = BatchEntryState::for($entry->fresh());

    $this->assertSame('recorded', $state['chip']);
    $this->assertStringContainsStringIgnoringCase('question', $state['label']);
    $this->assertNull($state['action']);   // Send is not offered until answers exist
}

// Review Focus #3
public function test_the_board_omits_a_learner_the_teacher_no_longer_reaches(): void
public function test_the_board_does_not_error_when_every_learner_moved_away(): void

// the seven states
public function test_an_entry_with_no_audio_reads_as_waiting(): void
public function test_a_recorded_entry_reads_as_recorded_and_is_playable(): void
public function test_an_entry_being_scored_right_now_reads_as_transcribing(): void
public function test_a_sent_entry_waiting_its_turn_reads_as_queued(): void
public function test_a_scored_entry_awaiting_a_verdict_reads_as_needs_verdict(): void
public function test_an_entry_with_a_verdict_reads_as_done(): void
public function test_an_unclassified_result_reads_as_queued_not_done(): void

// audio availability
public function test_audio_is_playable_in_every_state_once_a_recording_exists(): void

// Check Result
public function test_check_result_appears_as_soon_as_a_reading_is_analysed(): void
public function test_check_result_is_absent_before_a_result_exists(): void

// board + batch status
public function test_the_board_reads_weaknesses_through_weakness_outcome(): void
public function test_poll_reports_the_estimate_and_every_entry(): void
public function test_poll_counts_how_many_cards_are_ready_to_send(): void
public function test_another_teacher_cannot_open_or_poll_this_board(): void
public function test_a_batch_turns_to_analyzing_once_any_entry_has_audio(): void
public function test_a_cancelled_batch_is_not_revived_by_polling(): void

// the batch must finish with nobody watching
public function test_a_batch_completes_without_anyone_opening_the_board(): void
{
    $batch = AssessmentBatch::factory()->create();
    $entry = $this->sentBatchEntry(['batch_id' => $batch->id]);

    // score it the way the scheduler does - no HTTP request, no poll
    $entry->createResult($this->classifiedAnalysis());
    $entry->verdict()->create($this->acceptedVerdict());

    $this->assertSame(AssessmentBatch::STATUS_COMPLETED, $batch->fresh()->status);
    $this->assertNotNull($batch->fresh()->completed_at);
}

public function test_poll_writes_nothing(): void
{
    // the batch status must already be correct before any poll happens
}
```

`test_a_batch_completes_without_anyone_opening_the_board` is the load-bearing one for "the process must not stop": it deliberately makes **no HTTP request at all**. Add `acceptedVerdict()` to the helper trait.

`test_an_unclassified_result_reads_as_queued_not_done` pins the model-or-nothing rule; `test_an_entry_being_scored_right_now_reads_as_transcribing` pins that the state comes from Task 1's timing columns rather than from raw `status`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=BatchBoardTest`
Expected: FAIL — `BatchEntryState` not found.

- [ ] **Step 3: Implement `BatchEntryState::for()`, `refreshStatus()` and the observer**

Derivation order matters — check in this sequence and return the first match. `failed` is checked **before** `queued` so an exhausted entry does not read as still waiting, and `transcribing` before `queued` because a scoring entry also satisfies `awaitingRescore()`:

```
no audio_file                                           → waiting
no submitted_for_analysis_at, no result                 → recorded
  (label names the blocker when answers are missing;
   action is null until they exist)
rescore_attempts >= MAX_ATTEMPTS, no result             → failed
analysis_started_at set, analysis_duration_ms null      → transcribing
awaitingRescore() claims it                             → queued
  (covers both the outage case and an unclassified result)
result exists, verdict exists                           → done
result exists, no verdict                               → needs verdict
```

Guard the observer against recursion: `refreshStatus()` saves the *batch*, not the assessment, so it will not re-enter — but do not save the assessment from inside it.

- [ ] **Step 4: Implement `board()`, `poll()`, the card grid and the polling JS**

Scope entries through the teacher's `accessibleLearnersQuery()` so Review Focus #3 holds. Poll on the same interval pattern as `assessments.session.poll`. Cards go in `_card.blade.php` so Task 9's panel and the board share one rendering of a card's chip and actions.

The board carries a standing reassurance, because a teacher who doubts it will sit and wait: **"Analysis continues even if you close this page — you can come back any time."**

**No bulk verdict control** anywhere on this page. *Send all ready* is the only bulk action; each child's verdict goes through the existing per-child flow.

- [ ] **Step 5: Run the test to verify it passes, then the full suite**

Run: `php artisan test --filter=BatchBoardTest && php artisan test`
Expected: both PASS, zero edits to existing test files.

- [ ] **Step 6: Commit**

```bash
git add app/Services/BatchEntryState.php app/Observers/AssessmentObserver.php app/Providers/AppServiceProvider.php app/Models/AssessmentBatch.php app/Http/Controllers/AssessmentBatchController.php resources/views/assessments/batch public/css/batch-board.css public/js/batch-board.js tests/Feature/BatchBoardTest.php
git commit -m "feat(batch): add the card board and honest per-child state"
git switch feat/batch-assessment && git merge --no-ff feat/batch-t08-board
```

---

### Task 9: The card panel — conduct, listen, send

**Files:**
- Modify: `app/Http/Controllers/AssessmentBatchController.php` — `entry()`, `uploadAudio()`, `answers()`, `send()`, `sendAll()`
- Create: `resources/views/assessments/batch/entry.blade.php`
- Create: `public/js/batch-recorder.js`
- Test: `tests/Feature/BatchEntryPanelTest.php`

**Interfaces:**
- Consumes: Task 8's `BatchEntryState::for()` and `_card.blade.php`, Task 5's entry/upload/answers/send routes.
- Produces:
  - `entry()` renders the child's panel: the passage to read from, the recorder, the join code, the audio player when one exists, and the comprehension questions when the type needs them.
  - `uploadAudio()` stores the recording and returns `['success' => true, 'state' => <BatchEntryState::for()>]`. The entry becomes **`recorded`**, *not* queued — nothing is sent yet.
  - `send()` stamps `submitted_for_analysis_at` and returns the new state. **Refuses with 422 when the assessment needs comprehension answers and has none.**
  - `sendAll()` stamps every `recorded` entry of the batch that is eligible, and reports how many were sent and how many were skipped for missing answers.

**The panel is where the teacher works.** This is the screen for a child with no device, or who needs to read from the teacher's laptop: open the card, the passage is there, record, listen, send. The same panel shows the join code for a child who does have a device, so the teacher chooses in the moment rather than at setup.

**One recorder instance, bound to the open panel.** Only one child reads at a time, so a per-card recorder is unnecessary. `public/js/audio-recorder.js` cannot be reused: it is global-function, hard-coded to single-page element ids (`audioPlayback`, the comprehension gate, `window.BIGKAS_LOCAL_FLOW`), and it ends by navigating to a results page. `batch-recorder.js` records the open panel and posts it, nothing more.

**Ordering obligation, from the spec §4.3.** Comprehension answers must reach `ComprehensionService::record()` **before** anything is sent, exactly as `AssessmentController::analyze()` does — otherwise a dead engine can discard a child's answers. The Send gate enforces this structurally: `send()` refuses without answers, so an unanswered entry can never be in the lane.

Reuse the extension allow-list in `AssessmentController::keepRecording()`: the audio folder is served publicly, so a file named `recording.php` must never be written as-is.

- [ ] **Step 1: Write the failing test**

```php
// the recorded resting state
public function test_an_uploaded_recording_rests_at_recorded_and_is_not_queued(): void
{
    $entry = // a waiting entry in this teacher's batch
    $this->actingAs($this->teacher())->post(route('assessments.batch.upload-audio', [...]), [...]);

    $entry->refresh();
    $this->assertNotNull($entry->audio_file);
    $this->assertNull($entry->submitted_for_analysis_at);
    $this->assertFalse(Assessment::awaitingRescore()->whereKey($entry->id)->exists());
}

public function test_sending_puts_the_entry_in_the_lane(): void
public function test_re_recording_replaces_the_audio_and_keeps_it_unsent(): void
public function test_the_panel_offers_both_a_join_code_and_conduct_here(): void
public function test_the_panel_shows_the_passage_the_child_must_read(): void
public function test_the_audio_player_appears_once_a_recording_exists(): void

// the Send gate — Review Focus #2, enforced structurally
public function test_a_comprehension_entry_cannot_be_sent_without_answers(): void
public function test_recording_answers_then_sending_works(): void
public function test_answers_are_stored_before_anything_is_sent(): void
public function test_answers_survive_when_no_speech_engine_answers(): void

// send all
public function test_send_all_sends_every_eligible_recorded_entry(): void
public function test_send_all_skips_entries_missing_answers_and_reports_them(): void
public function test_send_all_does_not_touch_entries_already_sent(): void

// safety
public function test_a_dangerous_file_extension_is_not_written_as_given(): void
public function test_a_teacher_cannot_open_or_upload_to_another_teachers_batch(): void
public function test_an_admin_cannot_record_in_a_batch(): void
```

`test_answers_survive_when_no_speech_engine_answers` is the regression guard for the ordering obligation: record answers, send, fake a dead engine, let the lane run, and assert the answers are still there.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=BatchEntryPanelTest`
Expected: FAIL

- [ ] **Step 3: Implement the five endpoints, the panel view and the recorder**

- [ ] **Step 4: Run the test to verify it passes, then the full suite**

Run: `php artisan test --filter=BatchEntryPanelTest && php artisan test`
Expected: both PASS, zero edits to existing test files.

- [ ] **Step 5: Commit and merge the phase**

```bash
git add app/Http/Controllers/AssessmentBatchController.php public/js/batch-recorder.js resources/views/assessments/batch/entry.blade.php tests/Feature/BatchEntryPanelTest.php
git commit -m "feat(batch): conduct, listen and send from a child's card"
git switch feat/batch-assessment && git merge --no-ff feat/batch-t09-panel
```

---

### Task 10: A join code for every child

**Files:**
- Modify: `app/Services/BatchRosterBuilder.php` — issue a session per entry
- Modify: `resources/views/assessments/batch/entry.blade.php` — show the code
- Test: `tests/Feature/BatchSelfRecordTest.php`

**Interfaces:**
- Consumes: the existing `AssessmentSession` model, `AssessmentSession::generateCode()`, and the existing student join flow; `BatchRosterBuilder::build()` from Task 6.
- Produces: one `AssessmentSession` per entry — **every entry, unconditionally** — created with the batch, `status = 'waiting'` and that entry's `assessment_id`. Since there is no `recording_mode`, every child can use either route and the code always exists.

No new table and no new student-facing flow: a self-recording child is exactly today's live session, created in bulk.

**A child's own recording also rests at `recorded`.** The teacher still listens and sends, which is why *Send all ready* (Task 8) exists — fifteen children reading simultaneously produce fifteen `recorded` cards for the teacher to review and release.

- [ ] **Step 1: Write the failing test**

```php
public function test_every_entry_in_a_batch_gets_a_join_code(): void
public function test_every_code_in_a_batch_is_distinct(): void
public function test_a_child_joining_with_their_code_reaches_their_own_entry(): void
public function test_a_child_cannot_join_another_childs_entry_with_their_own_code(): void
public function test_a_childs_own_recording_rests_at_recorded_not_queued(): void
public function test_a_cancelled_batch_s_codes_no_longer_admit_a_child(): void
```

`test_a_childs_own_recording_rests_at_recorded_not_queued` is the one that matters: a self-recorded reading must not bypass the teacher's review and go straight into the lane.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=BatchSelfRecordTest`
Expected: FAIL

- [ ] **Step 3: Issue the sessions and surface the code**

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=BatchSelfRecordTest`
Expected: PASS

- [ ] **Step 5: Commit and merge the phase**

```bash
git add app/Services/BatchRosterBuilder.php resources/views/assessments/batch/entry.blade.php tests/Feature/BatchSelfRecordTest.php
git commit -m "feat(batch): issue a join code for every child in a batch"
git switch feat/batch-assessment && git merge --no-ff feat/batch-t10-codes
```

---

### Task 11: Cancelling and removing

**Files:**
- Modify: `app/Http/Controllers/AssessmentBatchController.php` — `removeEntry()`, `unsendEntry()`, `cancel()`
- Modify: `routes/web.php` — two routes: `DELETE .../entry/{assessment}` → `assessments.batch.entry.remove`, `POST .../entry/{assessment}/unsend` → `assessments.batch.unsend`
- Modify: `resources/views/assessments/batch/_card.blade.php`, `board.blade.php` — the actions and the confirmation dialog
- Test: `tests/Feature/BatchCancelTest.php`

**Interfaces:**
- Consumes: `BatchEntryState::for()` (Task 8).
- Produces:
  - `unsendEntry()` — clears `submitted_for_analysis_at`. The card returns to `recorded` with its audio intact; the lane stops taking it.
  - `removeEntry()` — behaviour depends on whether a reading was measured (table below).
  - `cancel()` — behind a confirmation; sets `cancelled` + `cancelled_at`, un-sends every queued entry, removes every un-recorded entry, **keeps every scored reading**.

**The governing rule: nothing available on this board may destroy a measured reading.** A teacher moving through fifteen cards must not be able to delete a child's record with one misplaced tap.

| State | Action | What `removeEntry()` does |
|---|---|---|
| `waiting` | *Remove* | Deletes the entry and its `AssessmentSession`. Nothing was recorded. |
| `recorded` | *Re-record* / *Remove* | Discards audio → `waiting`, or deletes the entry. Nothing was measured. |
| `queued` | *Cancel analysis* | `unsendEntry()` → back to `recorded`, audio kept. |
| `transcribing` | **none** | A running Whisper process cannot be aborted honestly. The card offers nothing and says *"being analysed now — about N min left"*, rather than a button that would not do what it claims. |
| `needs verdict` · `done` | *Remove from batch* | **Sets `batch_id` to null and nothing else.** Assessment, result, verdict and comprehension answers all survive. |
| `failed` | *Remove from batch* | Also `batch_id` to null, keeping the recording so Analyze still works from the child's history. |

**Why not delete:** deleting an `Assessment` cascade-deletes `assessment_results`, `assessment_verdicts`, `comprehension_answers` and `assessment_sessions`, and `Learner::readingLevelFromAssessments()` derives the child's reading level from their assessments — so a delete silently changes what parents see, what Practice Center recommends, which interventions are chosen and what the admin chart counts. That belongs on the learner's own history screen (Task 12), not on a card.

- [ ] **Step 1: Write the failing test**

```php
// the boundary that matters most
public function test_removing_a_scored_card_keeps_the_assessment_and_its_result(): void
{
    $entry = $this->sentBatchEntry();
    $entry->createResult($this->classifiedAnalysis());

    $this->actingAs($this->teacher())->delete(route('assessments.batch.entry.remove', [...]));

    $this->assertDatabaseHas('assessments', ['id' => $entry->id, 'batch_id' => null]);
    $this->assertDatabaseHas('assessment_results', ['assessment_id' => $entry->id]);
}

public function test_removing_a_scored_card_does_not_change_the_childs_reading_level(): void
public function test_removing_a_scored_card_keeps_its_verdict_and_answers(): void
public function test_no_board_action_can_delete_a_measured_reading(): void

// pre-result removal is real deletion, and that is fine
public function test_removing_a_waiting_card_deletes_the_entry_and_its_session(): void
public function test_removing_a_recorded_card_deletes_the_entry_and_its_audio_file(): void

// un-send
public function test_cancelling_analysis_returns_a_queued_card_to_recorded(): void
public function test_a_cancelled_analysis_is_no_longer_claimed_by_the_lane(): void
public function test_cancelling_analysis_keeps_the_audio(): void
public function test_a_transcribing_card_offers_no_cancel_action(): void

// batch cancel
public function test_cancelling_a_batch_keeps_every_scored_reading(): void
public function test_cancelling_a_batch_unsends_every_queued_entry(): void
public function test_cancelling_a_batch_removes_entries_that_were_never_recorded(): void
public function test_a_cancelled_batchs_join_codes_no_longer_admit_a_child(): void
public function test_cancelling_a_batch_reports_what_will_be_kept_and_stopped(): void
public function test_a_cancelled_batch_cannot_accept_new_recordings(): void

// authorization
public function test_another_teacher_cannot_remove_an_entry_or_cancel_the_batch(): void
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=BatchCancelTest`
Expected: FAIL

- [ ] **Step 3: Implement the three actions and the confirmation dialog**

The confirmation names the consequences rather than asking a generic question: *"Cancel this batch? 4 children already assessed keep their results. 6 waiting and 5 queued will be stopped."* Counts come from `BatchEntryState`.

- [ ] **Step 4: Run the test to verify it passes, then the full suite**

Run: `php artisan test --filter=BatchCancelTest && php artisan test`
Expected: both PASS, zero edits to existing test files.

- [ ] **Step 5: Commit and merge the phase**

```bash
git add app/Http/Controllers/AssessmentBatchController.php routes/web.php resources/views/assessments/batch tests/Feature/BatchCancelTest.php
git commit -m "feat(batch): cancel and remove without ever losing a reading"
git switch feat/batch-assessment && git merge --no-ff feat/batch-t11-cancel
```

---

### Task 12: Deleting a reading, on the learner's own history

**Files:**
- Modify: `app/Http/Controllers/AssessmentController.php` — add `destroy()`
- Modify: `routes/web.php` — `DELETE /assessments/{assessment}` → `assessments.destroy`
- Modify: `resources/views/assessments/learner-history.blade.php` — the delete control and its confirmation
- Test: `tests/Feature/AssessmentDeleteTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks. This is deliberately **outside** the batch feature and **outside** the batch feature flag — it is a gap in the existing app that the batch work surfaced.
- Produces: `AssessmentController::destroy(Assessment $assessment)` — deletes the assessment after an explicit confirmation, then recomputes the learner's reading level.

**This is the only place a reading can be destroyed, and it must earn it:**
- The confirmation shows what is being deleted — the date, material, reading level and accuracy — not a generic "are you sure".
- It names the consequences: the result, verdict and comprehension answers go with it, and the child's reading level will be recomputed.
- An assessment with a **verdict** is a reviewed record with an audit trail; it requires the typed name of the learner to confirm, not just a click.
- `ActivityLog::log()` records the deletion, with who and when, before the row goes.
- The learner's reading level is recomputed afterwards, the same way `learners:recompute-levels` does it, so the level never silently reflects a deleted reading.

**Admins stay read-only**, per the `LearnerAdminReadOnlyTest` precedent — deleting is the assessing teacher's action.

- [ ] **Step 1: Write the failing test**

```php
public function test_a_teacher_can_delete_an_assessment_from_the_learners_history(): void
public function test_deleting_an_assessment_removes_its_result_verdict_and_answers(): void
public function test_deleting_an_assessment_recomputes_the_learners_reading_level(): void
public function test_deleting_a_reviewed_assessment_requires_the_learners_typed_name(): void
public function test_a_deletion_is_written_to_the_activity_log(): void
public function test_an_admin_cannot_delete_an_assessment(): void
public function test_a_teacher_cannot_delete_another_teachers_learners_assessment(): void
public function test_the_confirmation_names_the_reading_being_deleted(): void
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=AssessmentDeleteTest`
Expected: FAIL — route `assessments.destroy` not defined.

- [ ] **Step 3: Implement `destroy()`, the route and the confirmation**

Route placement: `DELETE /assessments/{assessment}` cannot shadow `/assessments/batch/...` because the methods differ, but declare it beside the existing `GET /assessments/{assessment}` for clarity.

- [ ] **Step 4: Run the test to verify it passes, then the full suite**

Run: `php artisan test --filter=AssessmentDeleteTest && php artisan test`
Expected: both PASS, zero edits to existing test files.

- [ ] **Step 5: Commit and merge the phase**

```bash
git add app/Http/Controllers/AssessmentController.php routes/web.php resources/views/assessments/learner-history.blade.php tests/Feature/AssessmentDeleteTest.php
git commit -m "feat(assessment): delete a reading from the learner's history, deliberately"
git switch feat/batch-assessment && git merge --no-ff feat/batch-t12-delete
```

---

### Task 13: Deploy notes and a real 15-child run

**Files:**
- Modify: `docs/deployment-vps.md` — the batch flag, the new schedule values, and the cron prerequisite
- Test: manual verification on the VPS; no new automated test

**Interfaces:**
- Consumes: everything above.
- Produces: no code.

- [ ] **Step 1: Run the full suite and confirm no existing test was edited**

```bash
php artisan test
git diff --stat pre-batch-assessment -- tests/
```
Expected: suite PASS; the diff shows **only added files** under `tests/`, no modifications to pre-existing ones.

- [ ] **Step 2: Document the deploy**

Cover: the flag and its default; `BATCH_ASSESSMENT_ENABLED` needing `config:cache` to take effect; the new `--limit=20` / 1-minute tick / 120-minute lock and the invariant tying limit and lock together; the `schedule:run` cron prerequisite; and all three cache rebuilds, per the project's standing deploy rule.

**Two operational notes that must not be omitted**, both from spec §8:
- A 15-child batch holds the single lane for up to an hour; another teacher's one-off assessment created mid-batch waits behind it. Fair FIFO, but slow.
- **`php artisan schedule:clear-cache`** is the unstick command when a run is killed rather than allowed to finish. Until the 120-minute lock expires, *nothing* is rescored — including outage recovery for ordinary single-child assessments. Document the symptom: recordings sitting at `queued` with a demonstrably healthy engine and no `Rescored assessment` lines in the log.

- [ ] **Step 3: Measure a real batch of 15 against local Whisper**

Record the observed median per-recording duration and the wall-clock time from the last *send* to the last result. Confirm against the spec's stated 25–45 minutes, confirm the whole roster cleared in one run, and confirm no recording was scored twice.

- [ ] **Step 3b: Prove the work survives the browser closing**

Send a batch, then **close the browser entirely** and leave it closed. Come back after the run should have finished and check, without opening the board first, that every entry has a result and the batch status is `completed`. This is the requirement that motivated the observer in Task 8, and it is only genuinely proven by not watching.

- [ ] **Step 4: Re-tune if the measurement disagrees**

If the measured worst case per recording exceeds 3 minutes, `--limit=20` breaches the §8 invariant and both it and `withoutOverlapping` must move together. `RescoreScheduleTest` from Task 4 should fail if they do not.

- [ ] **Step 5: Commit, merge, and stop**

```bash
git add docs/deployment-vps.md
git commit -m "docs(batch): deploy notes for the batch flag and the tuned lane"
git switch feat/batch-assessment && git merge --no-ff feat/batch-t13-deploy
```

**Do not merge `feat/batch-assessment` into `master`.** That is a separate, explicit decision for the user to take after reviewing the measured run (spec §9.3).

---

## Notes carried from the spec

- A 15-child batch is **25–45 minutes of transcription after the last child stops reading**. Nothing in this plan makes that shorter; the board makes it visible, interruptible and safe to walk away from.
- Out of scope: parallel Whisper, GPU inference, websockets, batch-level PDF or Form 3 export, cross-batch analytics, bulk verdict acceptance, and the latent `queue:work` timeout in `bigkas_first_day_setup.sh:285`.
- Rollback: tag `pre-batch-assessment` (pushed), branch `feat/batch-assessment`, flag default off, all migrations additive and reversible. Full procedure in the spec, §9.
