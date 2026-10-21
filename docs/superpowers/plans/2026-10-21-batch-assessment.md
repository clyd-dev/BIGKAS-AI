# Batch Assessment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A teacher sets up one batch covering up to 15 children, captures each child's recording either on the child's own device or teacher-led one at a time, and tracks the whole group from a single polling board.

**Architecture:** A batch is an `assessment_batches` row; each roster entry *is* an ordinary `Assessment` carrying `batch_id`. Scoring adds no new mechanism — `Assessment::scopeAwaitingRescore()` is widened additively so batch entries are owed a score the moment their audio is saved, and the existing `assessments:rescore` scheduler plus `RescoreQueue` plus `AssessmentScorer` do the work on their existing single serial lane. The board derives every per-child chip from state the database already holds; no parallel state machine is stored.

**Tech Stack:** Laravel 11 (PHP 8.3), Blade + Bootstrap 5, PHPUnit 11 with `RefreshDatabase`, MySQL, vanilla JS (no build step for board/recorder), local faster-whisper + Random Forest behind a Flask service.

**Spec:** `docs/2026-10-09-batch-assessment-plan.html` (rev. 2, 21 Oct 2026)

## Global Constraints

- **Roster cap is 15** learners per batch, from `config('assessment.batch_max_learners')`.
- **Feature ships behind a flag**, default **off**: `config('assessment.batch_enabled')` ← `BATCH_ASSESSMENT_ENABLED`. Off means the entry link is hidden and every batch route returns 404.
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
2. **A comprehension batch entry whose answers never arrive.** `RescoreQueue::run()` skips an unscored assessment that `needsComprehensionTest()` without answers — forever. A self-record child who reads but never answers leaves the batch stuck at `analyzing` with no explanation. *(Task 8)*
3. **A learner leaving the teacher's reach mid-batch.** Class reassignment during a 40-minute batch must not expose that child's result to a teacher who no longer has access, and must not 500 the board. *(Task 8)*
4. **The same learner added to a batch while another assessment of theirs is still unscored.** Two in-flight assessments for one child, both owed a score, both reaching `createResult()` — which replaces. *(Task 6)*
5. **Roster boundaries: 0 learners, exactly 15, exactly 16, and the same learner twice.** *(Task 6)*

---

### Task 1: Schema and the batch model

**Files:**
- Create: `database/migrations/2026_10_21_000000_create_assessment_batches_table.php`
- Create: `database/migrations/2026_10_21_000001_add_batch_columns_to_assessments_table.php`
- Create: `app/Models/AssessmentBatch.php`
- Modify: `app/Models/Assessment.php` — add `batch_id` and `recording_mode` to `$fillable`, add `batch()` relation
- Test: `tests/Feature/AssessmentBatchTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `AssessmentBatch` with `$fillable`: `teacher_id`, `school_class_id`, `title`, `language`, `assessment_type`, `default_material_id`, `status`, `completed_at`, `cancelled_at`
  - Constants `AssessmentBatch::STATUS_COLLECTING = 'collecting'`, `STATUS_ANALYZING = 'analyzing'`, `STATUS_COMPLETED = 'completed'`, `STATUS_CANCELLED = 'cancelled'` — **there is no `engine_unavailable`**
  - Relations: `teacher(): BelongsTo`, `schoolClass(): BelongsTo`, `defaultMaterial(): BelongsTo` (`ReadingMaterial`, FK `default_material_id`), `entries(): HasMany` (`Assessment`, FK `batch_id`)
  - `Assessment::batch(): BelongsTo`
  - Columns added to `assessments`: `batch_id` (nullable FK → `assessment_batches`, indexed, `nullOnDelete`), `recording_mode` (nullable string 8), `analysis_started_at` (nullable timestamp), `analysis_duration_ms` (nullable unsigned int)

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
    $entry = Assessment::factory()->create(['batch_id' => $batch->id, 'recording_mode' => 'teacher']);

    $this->assertTrue($batch->entries->contains($entry));
    $this->assertSame($batch->id, $entry->batch->id);
    $this->assertSame('teacher', $entry->recording_mode);
}

public function test_deleting_a_batch_keeps_its_assessments(): void
{
    $batch = AssessmentBatch::factory()->create();
    $entry = Assessment::factory()->create(['batch_id' => $batch->id]);

    $batch->delete();

    $this->assertDatabaseHas('assessments', ['id' => $entry->id, 'batch_id' => null]);
}
```

Create `database/factories/AssessmentBatchFactory.php` alongside, following the shape of the existing factories in that directory.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=AssessmentBatchTest`
Expected: FAIL — `Class "App\Models\AssessmentBatch" not found`

- [ ] **Step 3: Write the two migrations**

Both must carry a working `down()`. All changes are additive: one new table, four nullable columns. Nothing is renamed, retyped or dropped.

- [ ] **Step 4: Implement `AssessmentBatch` and the `Assessment` additions**

Follow `app/Models/AssessmentSession.php` for model style (`$fillable`, `casts(): array`, relations, scopes). Cast `completed_at` and `cancelled_at` to `datetime`.

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=AssessmentBatchTest`
Expected: PASS

- [ ] **Step 6: Verify the rollback is clean and nothing else broke**

Run: `php artisan migrate:fresh && php artisan migrate:rollback --step=2 && php artisan migrate && php artisan test`
Expected: rollback and re-migrate both succeed; full suite PASS with zero edits to existing test files.

- [ ] **Step 7: Commit**

```bash
git add database/migrations database/factories/AssessmentBatchFactory.php app/Models/AssessmentBatch.php app/Models/Assessment.php tests/Feature/AssessmentBatchTest.php
git commit -m "feat(batch): add assessment batches and the roster columns"
```

---

### Task 2: Claim batch entries for scoring at once

**Files:**
- Modify: `app/Models/Assessment.php:602` — `scopeAwaitingRescore()`
- Test: `tests/Feature/BatchRescoreClaimTest.php`

**Interfaces:**
- Consumes: Task 1's `batch_id` column.
- Produces:
  - no new production signature. `scopeAwaitingRescore()` gains one additive `orWhere` branch: an entry with a non-null `batch_id` and no result is owed a score immediately, without waiting for the `failed` or 15-minutes-stale-in-`processing` conditions.
  - **`tests/Concerns/MakesBatchAssessments.php`** — the shared test helpers every later task uses. Define them here, once:
    - `batchEntryWithAudio(array $attributes = []): Assessment` — a batch entry with a stored fake audio file; creates a batch unless `batch_id` is given
    - `assessmentWithAudio(array $attributes = []): Assessment` — the same without a batch
    - `classifiedAnalysis(array $overrides = []): array` — a minimal valid payload for `createResult()` with `classification_status = 'classified'`
    - `fakeEngine(bool $stt = true, bool $classifier = true): void` — `Http::fake()` for the Flask service, copied from `RescoreQueueTest`'s existing faking rather than reinvented
    - `teacher(): User` / `otherTeacher(): User` — a teacher with one class and reachable learners

Tasks 3, 6, 7, 8, 9 and 10 all `use MakesBatchAssessments;` rather than rolling their own fixtures.

This is the riskiest edit in the plan: the scope is shared with outage recovery that already works in production. It is widened **only** for rows with a `batch_id`.

- [ ] **Step 1: Write the failing test**

```php
public function test_a_batch_entry_is_owed_a_score_as_soon_as_its_audio_is_saved(): void
{
    $entry = $this->batchEntryWithAudio(['status' => Assessment::STATUS_PROCESSING]);

    $this->assertTrue(Assessment::awaitingRescore()->whereKey($entry->id)->exists());
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

public function test_a_batch_entry_that_already_has_a_result_is_not_owed_a_score(): void
{
    $entry = $this->batchEntryWithAudio();
    $entry->createResult($this->classifiedAnalysis());

    $this->assertFalse(Assessment::awaitingRescore()->whereKey($entry->id)->exists());
}

public function test_a_batch_entry_with_no_audio_is_not_owed_a_score(): void
{
    $entry = Assessment::factory()->create(['batch_id' => AssessmentBatch::factory(), 'audio_file' => null]);

    $this->assertFalse(Assessment::awaitingRescore()->whereKey($entry->id)->exists());
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=BatchRescoreClaimTest`
Expected: FAIL on the first test — the batch entry is not yet claimed.

- [ ] **Step 3: Widen the scope and add the test-helper trait**

Add the batch branch inside the existing `$unscored` closure, leaving the `failed` and stale-`processing` conditions exactly as they are. The `whereNotNull('audio_file')` guard at the top of the scope already covers the no-audio case. Create `tests/Concerns/MakesBatchAssessments.php` with the helpers listed in Interfaces.

- [ ] **Step 4: Run the new test and the outage-recovery test together**

Run: `php artisan test --filter="BatchRescoreClaimTest|RescoreQueueTest"`
Expected: PASS, with **zero edits to `RescoreQueueTest`**. If it needed editing, revert and re-scope the change.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Assessment.php tests/Concerns/MakesBatchAssessments.php tests/Feature/BatchRescoreClaimTest.php
git commit -m "feat(batch): owe a score to a batch entry the moment its audio lands"
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
```

---

### Task 4: Tune the lane for a batch arriving at once

**Files:**
- Modify: `routes/console.php`
- Test: `tests/Feature/RescoreScheduleTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: the scheduled command becomes `assessments:rescore --limit=8`, `everyMinute()`, `withoutOverlapping(45)`.

`everyMinute` is deliberate and not a typo: `withoutOverlapping` already prevents a second concurrent run, so a one-minute tick means "start again as soon as the lane is free" instead of idling to the next multiple of five. The command exits immediately when nothing is due, so an idle tick costs nothing.

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

    $this->assertStringContainsString('--limit=8', $event->command);
    $this->assertSame('* * * * *', $event->expression);     // every minute
    $this->assertSame(45, $event->expiresAt);               // minutes
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

At today's values the guard computes `5 × 3 × 1.5 = 22.5` against a `30`-minute lock and would pass, so **confirm it fails for the right reason**: it must be the *first* test that fails, on `--limit=8` being absent. After Step 3 the guard computes `8 × 3 × 1.5 = 36` against `45` and passes with real margin.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=RescoreScheduleTest`
Expected: `test_the_lane_is_tuned_for_a_batch_arriving_at_once` FAILS — `--limit=8` not found in the command. If `assertNotNull` fails instead, the scheduler is not registering the command in the test environment; fix the test's access to the schedule before going further.

- [ ] **Step 3: Apply the new schedule in `routes/console.php`**

Keep the existing explanatory comment and the `health:check` line untouched.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=RescoreScheduleTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add routes/console.php tests/Feature/RescoreScheduleTest.php
git commit -m "feat(batch): tune the rescore lane for 15 recordings at once"
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
    - `POST /assessments/batch/{batch}/entry/{assessment}/upload-audio` → `assessments.batch.upload-audio`
    - `POST /assessments/batch/{batch}/entry/{assessment}/answers` → `assessments.batch.answers`
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
  - `$input` shape: `['title' => ?string, 'language' => string, 'assessment_type' => string, 'default_material_id' => int, 'school_class_id' => ?int, 'entries' => [['learner_id' => int, 'material_id' => ?int, 'recording_mode' => 'self'|'teacher', 'allow_fallback' => bool], ...]]`

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
```

---

### Task 8: The board and its honest chips

**Files:**
- Modify: `app/Http/Controllers/AssessmentBatchController.php` — `board()`, `poll()`
- Create: `app/Services/BatchEntryState.php`
- Create: `resources/views/assessments/batch/board.blade.php`
- Create: `public/js/batch-board.js`
- Test: `tests/Feature/BatchBoardTest.php`

**Interfaces:**
- Consumes: `AnalysisQueueEstimator::forBatch()` (Task 3), the routes from Task 5.
- Produces:
  - `BatchEntryState::for(Assessment $entry): array` → `['chip' => string, 'label' => string, 'tone' => 'good'|'ok'|'help'|'none', 'action' => ?string]`
  - `chip` is one of `waiting`, `owed`, `scoring`, `needs_verdict`, `done`, `given_up`, `needs_answers`
  - `AssessmentBatch::refreshStatus(): void` — recomputes the batch's own status from its entries' chips and saves it. **This is the only thing that writes `assessment_batches.status`** apart from creation (`collecting`) and `cancel()`. Rules: any entry past `waiting` ⇒ `analyzing`; every entry `done` or `given_up` ⇒ `completed` with `completed_at`; a `cancelled` batch never changes. Called from `poll()`, so the board's own polling advances it and no extra scheduled work is needed.
  - `poll()` returns `['entries' => [id => state], 'batch_status' => string, 'estimate' => ['remaining' => int, 'ahead' => int, 'seconds' => int]]`

This task carries four of the five Review Focus items, so its tests are the load-bearing ones in the plan.

**`BatchEntryState` must never render raw `status`.** `RescoreQueue::rescore()` calls `markFailed()` during an engine outage *by design*, leaving the attempt uncounted. An entry whose `status` is `failed` but which `Assessment::awaitingRescore()` still claims is **`owed`, not failed** — it is waiting for the engine, and the board says so. Only `rescore_attempts >= RescoreQueue::MAX_ATTEMPTS` is `given_up`.

- [ ] **Step 1: Write the failing test**

```php
// Review Focus #1 — the one most likely to bite
public function test_an_entry_waiting_for_a_downed_engine_reads_as_waiting_not_failed(): void
{
    $entry = $this->batchEntryWithAudio();
    $entry->markFailed('The speech engine is down.');   // what RescoreQueue does on an outage

    $state = BatchEntryState::for($entry->fresh());

    $this->assertSame('owed', $state['chip']);
    $this->assertStringNotContainsStringIgnoringCase('failed', $state['label']);
}

public function test_an_entry_that_used_up_its_attempts_reads_as_given_up(): void
{
    $entry = $this->batchEntryWithAudio(['rescore_attempts' => RescoreQueue::MAX_ATTEMPTS]);

    $this->assertSame('given_up', BatchEntryState::for($entry->fresh())['chip']);
}

// Review Focus #2
public function test_a_comprehension_entry_missing_its_answers_says_so_rather_than_stalling_silently(): void
{
    $entry = $this->batchEntryWithAudio(['assessment_type' => Assessment::TYPE_COMBINED]);
    // audio saved, no answers recorded — RescoreQueue::run() will skip this forever

    $state = BatchEntryState::for($entry->fresh());

    $this->assertSame('needs_answers', $state['chip']);
    $this->assertNotNull($state['action']);
}

// Review Focus #3
public function test_the_board_omits_a_learner_the_teacher_no_longer_reaches(): void
public function test_the_board_does_not_error_when_every_learner_moved_away(): void

public function test_a_scored_entry_awaiting_a_verdict_reads_as_needs_verdict(): void
public function test_an_entry_with_an_accepted_verdict_reads_as_done(): void
public function test_an_unclassified_result_does_not_read_as_done(): void
public function test_the_board_reads_weaknesses_through_weakness_outcome(): void
public function test_poll_reports_the_estimate_and_every_entry(): void
public function test_another_teacher_cannot_open_or_poll_this_board(): void
public function test_the_batch_completes_when_every_entry_is_done_or_given_up(): void
public function test_a_batch_turns_to_analyzing_once_any_entry_has_audio(): void
public function test_a_cancelled_batch_is_not_revived_by_polling(): void
```

`test_an_unclassified_result_does_not_read_as_done` pins the model-or-nothing rule: a result with `classification_status = 'unavailable'` is still owed a classification, not finished.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=BatchBoardTest`
Expected: FAIL — `BatchEntryState` not found.

- [ ] **Step 3: Implement `BatchEntryState::for()` and `AssessmentBatch::refreshStatus()`**

Derivation order matters — check in this sequence and return the first match:

```
no audio                                        → waiting
needs comprehension test, no answers            → needs_answers
rescore_attempts >= MAX_ATTEMPTS, no result     → given_up
awaitingRescore claims it                       → owed
status = processing, claimed recently           → scoring
result exists, classification unavailable       → owed
result exists, no verdict                       → needs_verdict
result exists, verdict exists                   → done
```

- [ ] **Step 4: Implement `board()`, `poll()`, the view and the polling JS**

Scope entries through the teacher's `accessibleLearnersQuery()` so Review Focus #3 holds. Poll on the same interval pattern as `assessments.session.poll`. The board is a nav-reachable page in its own right but is reached from the setup screen, so give it a back link labelled **"Assessments"**.

**No bulk-accept control** anywhere on this page. Each child's verdict goes through the existing per-child flow.

- [ ] **Step 5: Run the test to verify it passes, then the full suite**

Run: `php artisan test --filter=BatchBoardTest && php artisan test`
Expected: both PASS, zero edits to existing test files.

- [ ] **Step 6: Commit**

```bash
git add app/Services/BatchEntryState.php app/Models/AssessmentBatch.php app/Http/Controllers/AssessmentBatchController.php resources/views/assessments/batch/board.blade.php public/js/batch-board.js tests/Feature/BatchBoardTest.php
git commit -m "feat(batch): add the progress board and honest per-child state"
```

---

### Task 9: Teacher-led recording and comprehension entry

**Files:**
- Modify: `app/Http/Controllers/AssessmentBatchController.php` — `uploadAudio()`, `answers()`
- Create: `public/js/batch-recorder.js`
- Modify: `resources/views/assessments/batch/board.blade.php` — the recording and answer panels
- Test: `tests/Feature/BatchRecordingTest.php`

**Interfaces:**
- Consumes: Task 8's board, Task 5's upload and answers routes.
- Produces: `uploadAudio()` stores the recording against the entry and returns `['success' => true, 'state' => <BatchEntryState::for()>]`; the entry is then owed a score and the teacher is **not** made to wait for scoring.

**One recorder instance for the whole board, bound to the row the teacher is working on.** Teacher-led recording is sequential by definition — one child reads at a time — so a per-row recorder is unnecessary. `public/js/audio-recorder.js` cannot be reused: it is global-function and hard-coded to single-page element ids (`audioPlayback`, the comprehension gate, `window.BIGKAS_LOCAL_FLOW`), and it ends by navigating to a results page. `batch-recorder.js` is a small instance that records one row and posts it, nothing more.

**Ordering obligation, from the spec:** comprehension answers must be recorded **before** the audio is owed a score, via `ComprehensionService::record()`, exactly as `AssessmentController::analyze()` does. Otherwise a dead engine can discard a child's answers. Reuse the extension allow-list in `AssessmentController::keepRecording()` — the audio folder is served publicly, so a file named `recording.php` must never be written as-is.

- [ ] **Step 1: Write the failing test**

```php
public function test_an_uploaded_batch_recording_is_owed_a_score_immediately(): void
public function test_an_uploaded_recording_does_not_block_on_scoring(): void
public function test_a_dangerous_file_extension_is_not_written_as_given(): void
public function test_answers_are_recorded_before_the_entry_is_owed_a_score(): void
public function test_answers_survive_when_no_speech_engine_answers(): void
public function test_a_teacher_cannot_upload_to_another_teachers_batch(): void
public function test_uploading_replaces_an_earlier_recording_for_that_entry(): void
```

`test_answers_survive_when_no_speech_engine_answers` is the regression guard for the ordering obligation: record answers, fake a dead engine, let the lane run, and assert the answers are still there.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=BatchRecordingTest`
Expected: FAIL

- [ ] **Step 3: Implement the two endpoints and the recorder**

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=BatchRecordingTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/AssessmentBatchController.php public/js/batch-recorder.js resources/views/assessments/batch/board.blade.php tests/Feature/BatchRecordingTest.php
git commit -m "feat(batch): record a child from the board and keep answers safe"
```

---

### Task 10: Self-record join codes

**Files:**
- Modify: `app/Http/Controllers/AssessmentBatchController.php` — issue a session per `self` entry
- Modify: `resources/views/assessments/batch/board.blade.php` — show each code
- Test: `tests/Feature/BatchSelfRecordTest.php`

**Interfaces:**
- Consumes: the existing `AssessmentSession` model, `AssessmentSession::generateCode()`, and the existing student join flow.
- Produces: one `AssessmentSession` per `recording_mode = 'self'` entry, created when the batch is created, with `status = 'waiting'` and the batch entry's `assessment_id`.

No new table and no new student-facing flow — a self-record entry is exactly today's live session, created in bulk.

- [ ] **Step 1: Write the failing test**

```php
public function test_a_self_record_entry_gets_its_own_join_code(): void
public function test_a_teacher_led_entry_gets_no_join_code(): void
public function test_every_code_in_a_batch_is_distinct(): void
public function test_a_child_joining_with_their_code_reaches_their_own_entry(): void
public function test_a_childs_own_recording_leaves_the_entry_owed_a_score(): void
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=BatchSelfRecordTest`
Expected: FAIL

- [ ] **Step 3: Issue the sessions and surface the codes**

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=BatchSelfRecordTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/AssessmentBatchController.php resources/views/assessments/batch/board.blade.php tests/Feature/BatchSelfRecordTest.php
git commit -m "feat(batch): issue a join code for every self-record entry"
```

---

### Task 11: Deploy notes and a real 15-child run

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

The flag and its default; `BATCH_ASSESSMENT_ENABLED` needing `config:cache` to take effect; the new `--limit` / tick / lock values and the invariant tying them together; the `schedule:run` cron prerequisite; and all three cache rebuilds, per the project's standing deploy rule.

- [ ] **Step 3: Measure a real batch of 15 against local Whisper**

Record the observed median per-recording duration and the wall-clock time from last recording to last result. Confirm against the spec's stated 25–45 minutes, and confirm no recording was scored twice.

- [ ] **Step 4: Re-tune `--limit` if the measurement disagrees with the estimate**

If the measured worst case per recording exceeds 3 minutes, `--limit=8` breaches the §8 invariant and both it and `withoutOverlapping` must move. `RescoreScheduleTest` from Task 4 should fail if they do not.

- [ ] **Step 5: Commit**

```bash
git add docs/deployment-vps.md
git commit -m "docs(batch): deploy notes for the batch flag and the tuned lane"
```

---

## Notes carried from the spec

- A 15-child batch is **25–45 minutes of transcription after the last child stops reading**. Nothing in this plan makes that shorter; the board makes it visible, interruptible and safe to walk away from.
- Out of scope: parallel Whisper, GPU inference, websockets, batch-level PDF or Form 3 export, cross-batch analytics, bulk verdict acceptance, and the latent `queue:work` timeout in `bigkas_first_day_setup.sh:285`.
- Rollback: tag `pre-batch-assessment` (pushed), branch `feat/batch-assessment`, flag default off, all migrations additive and reversible. Full procedure in the spec, §9.
