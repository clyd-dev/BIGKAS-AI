<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentVerdict;
use App\Models\Intervention;
use App\Models\InterventionLog;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\ClassWatchlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The teacher dashboard has to answer two questions: who needs attention, and
 * what is happening with the help already assigned. These cover both, plus the
 * regressions that made the old widgets look alive while doing nothing.
 */
class DashboardTrackingTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create([
            'role' => 'teacher',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $school = School::firstOrCreate(
            ['name' => 'Watchlist Test School'],
            ['address' => 'Sagay City', 'division' => 'Sagay City']
        );

        $this->class = SchoolClass::create([
            'school_id' => $school->id,
            'teacher_id' => $this->teacher->id,
            'grade_level' => 4,
            'section' => 'WL-' . $this->teacher->id,
            'school_year' => '2025-2026',
        ]);
    }

    private function learner(array $attributes = []): Learner
    {
        return Learner::factory()->create(array_merge([
            'class_id' => $this->class->id,
            'grade_level' => 4,
        ], $attributes));
    }

    private function material(): ReadingMaterial
    {
        return ReadingMaterial::firstOrCreate(
            ['title' => 'Watchlist Passage'],
            [
                'content' => 'The dog ran fast across the field today.',
                'language' => 'en',
                'grade_level' => 4,
                'difficulty' => 'easy',
                'type' => ReadingMaterial::TYPE_ORAL_READING,
                'word_count' => 8,
            ]
        );
    }

    /** An assessment with a result, dated so trends can be ordered. */
    private function assessmentFor(Learner $learner, float $accuracy, string $level, int $daysAgo = 0): Assessment
    {
        $assessment = Assessment::create([
            'learner_id' => $learner->id,
            'material_id' => $this->material()->id,
            'assessor_id' => $this->teacher->id,
            'language' => 'en',
            'assessment_type' => Assessment::TYPE_ORAL_READING,
            'status' => Assessment::STATUS_COMPLETED,
        ]);

        $assessment->createResult([
            'accuracy_rate' => $accuracy,
            'words_per_minute' => 70,
            'reading_level' => $level,
            'error_count' => 2,
            'total_words' => 8,
            'duration_seconds' => 60,
        ]);

        $assessment->forceFill([
            'created_at' => now()->subDays($daysAgo),
            'updated_at' => now()->subDays($daysAgo),
        ])->save();

        return $assessment->fresh(['result', 'verdict']);
    }

    private function intervention(string $name = 'Sound Blending Drill'): Intervention
    {
        return Intervention::create([
            'name' => $name,
            'description' => 'Practise blending sounds into words.',
            'target_weakness' => 1,
            'activity_type' => 'drill',
            'instructions' => 'Work through the word list together.',
            'grade_level_min' => 1,
            'grade_level_max' => 6,
        ]);
    }

    private function logFor(Learner $learner, string $status = 'pending', int $assignedDaysAgo = 0): InterventionLog
    {
        $log = InterventionLog::create([
            'intervention_id' => $this->intervention()->id,
            'learner_id' => $learner->id,
            'assigned_by' => $this->teacher->id,
            'status' => $status,
        ]);

        $log->forceFill([
            'created_at' => now()->subDays($assignedDaysAgo),
            'updated_at' => now()->subDays($assignedDaysAgo),
        ])->save();

        return $log->fresh();
    }

    private function watchlist(int $limit = 5)
    {
        return app(ClassWatchlistService::class)
            ->build($this->teacher->accessibleLearnersQuery(), $limit);
    }

    private function signalKeysFor($watchlist, Learner $learner): array
    {
        $entry = $watchlist->firstWhere(fn ($e) => $e['learner']->id === $learner->id);

        return $entry ? $entry['signals']->pluck('key')->all() : [];
    }

    // ── Signal 1: frustration level ──

    public function test_frustration_reader_is_on_the_watchlist(): void
    {
        $learner = $this->learner(['reading_level' => 'frustration']);
        $this->assessmentFor($learner, 82.0, 'frustration');

        $this->assertContains('frustration', $this->signalKeysFor($this->watchlist(), $learner));
    }

    public function test_independent_reader_with_steady_scores_is_not_listed(): void
    {
        $learner = $this->learner(['reading_level' => 'independent']);
        $this->assessmentFor($learner, 98.0, 'independent', 10);
        $this->assessmentFor($learner, 98.5, 'independent', 1);

        $this->assertSame([], $this->signalKeysFor($this->watchlist(), $learner));
    }

    // ── Signal 2: falling accuracy ──

    public function test_declining_accuracy_is_flagged_even_above_frustration(): void
    {
        $learner = $this->learner(['reading_level' => 'instructional']);
        $this->assessmentFor($learner, 95.0, 'instructional', 20);
        $this->assessmentFor($learner, 90.0, 'instructional', 2);

        $signals = $this->signalKeysFor($this->watchlist(), $learner);

        $this->assertContains('declining', $signals);
        $this->assertNotContains('frustration', $signals);
    }

    public function test_a_drop_smaller_than_the_threshold_is_not_a_decline(): void
    {
        $learner = $this->learner(['reading_level' => 'instructional']);
        $this->assessmentFor($learner, 95.0, 'instructional', 20);
        $this->assessmentFor($learner, 94.0, 'instructional', 2);

        $this->assertNotContains('declining', $this->signalKeysFor($this->watchlist(), $learner));
    }

    public function test_improving_learner_reports_an_upward_trend(): void
    {
        $learner = $this->learner(['reading_level' => 'frustration']);
        $this->assessmentFor($learner, 80.0, 'frustration', 20);
        $this->assessmentFor($learner, 88.0, 'frustration', 2);

        $entry = $this->watchlist()->firstWhere(fn ($e) => $e['learner']->id === $learner->id);

        $this->assertSame('up', $entry['trend']['direction']);
        $this->assertNotContains('declining', $entry['signals']->pluck('key')->all());
    }

    // ── The verdict outranks the AI, here as everywhere ──

    public function test_trend_follows_the_teachers_corrected_accuracy(): void
    {
        $learner = $this->learner(['reading_level' => 'instructional']);
        $this->assessmentFor($learner, 95.0, 'instructional', 20);
        $latest = $this->assessmentFor($learner, 85.0, 'frustration', 2);

        // The AI said 85%; the teacher re-scored it at 96%, so there is no decline.
        AssessmentVerdict::create([
            'assessment_id' => $latest->id,
            'decided_by' => $this->teacher->id,
            'decided_at' => now(),
            'decision' => AssessmentVerdict::DECISION_OVERRIDDEN,
            'final_accuracy_rate' => 96.0,
            'final_reading_level' => 'instructional',
            'reason' => 'Background noise made the recording mis-transcribe four words.',
        ]);

        $signals = $this->signalKeysFor($this->watchlist(), $learner);

        $this->assertNotContains('declining', $signals);
        $this->assertNotContains('frustration', $signals);
    }

    public function test_invalidated_assessment_is_ignored_when_reading_the_trend(): void
    {
        $learner = $this->learner(['reading_level' => 'instructional']);
        $this->assessmentFor($learner, 95.0, 'instructional', 30);
        $this->assessmentFor($learner, 94.0, 'instructional', 20);
        $broken = $this->assessmentFor($learner, 40.0, 'frustration', 2);

        AssessmentVerdict::create([
            'assessment_id' => $broken->id,
            'decided_by' => $this->teacher->id,
            'decided_at' => now(),
            'decision' => AssessmentVerdict::DECISION_INVALIDATED,
            'reason' => 'The microphone was unplugged, so nothing was captured.',
        ]);

        $entry = $this->watchlist()->firstWhere(fn ($e) => $e['learner']->id === $learner->id);

        // A void recording is not evidence of a collapse in reading.
        $this->assertTrue($entry === null || ! in_array('declining', $entry['signals']->pluck('key')->all(), true));
    }

    // ── Signal 3: stalled intervention ──

    public function test_intervention_left_unstarted_is_flagged_as_stalled(): void
    {
        $learner = $this->learner(['reading_level' => 'instructional']);
        $this->assessmentFor($learner, 93.0, 'instructional', 30);
        $this->logFor($learner, 'pending', 21);

        $this->assertContains('stalled', $this->signalKeysFor($this->watchlist(), $learner));
    }

    public function test_recently_assigned_intervention_is_not_stalled(): void
    {
        $learner = $this->learner(['reading_level' => 'instructional']);
        $this->assessmentFor($learner, 93.0, 'instructional', 30);
        $this->logFor($learner, 'pending', 2);

        $this->assertNotContains('stalled', $this->signalKeysFor($this->watchlist(), $learner));
    }

    public function test_an_old_intervention_already_under_way_is_not_stalled(): void
    {
        $learner = $this->learner(['reading_level' => 'instructional']);
        $this->assessmentFor($learner, 93.0, 'instructional', 30);
        $this->logFor($learner, 'in_progress', 40);

        $this->assertNotContains('stalled', $this->signalKeysFor($this->watchlist(), $learner));
    }

    // ── Signal 4: never assessed ──

    public function test_learner_without_any_assessment_is_listed(): void
    {
        $learner = $this->learner(['reading_level' => null]);

        $this->assertContains('never_assessed', $this->signalKeysFor($this->watchlist(), $learner));
    }

    // ── Ranking ──

    public function test_most_urgent_learner_comes_first(): void
    {
        $neverAssessed = $this->learner(['reading_level' => null]);

        $stalled = $this->learner(['reading_level' => 'instructional']);
        $this->assessmentFor($stalled, 93.0, 'instructional', 30);
        $this->logFor($stalled, 'pending', 30);

        $frustration = $this->learner(['reading_level' => 'frustration']);
        $this->assessmentFor($frustration, 80.0, 'frustration', 1);

        $order = $this->watchlist()->map(fn ($e) => $e['learner']->id)->all();

        $this->assertSame($frustration->id, $order[0]);
        $this->assertContains($stalled->id, $order);
        $this->assertContains($neverAssessed->id, $order);
        $this->assertGreaterThan(
            array_search($stalled->id, $order, true),
            array_search($neverAssessed->id, $order, true),
            'Never-assessed is the weakest signal, so it should rank below a stalled intervention.'
        );
    }

    public function test_watchlist_only_covers_the_teachers_own_class(): void
    {
        $otherTeacher = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $otherClass = SchoolClass::create([
            'school_id' => $this->class->school_id,
            'teacher_id' => $otherTeacher->id,
            'grade_level' => 5,
            'section' => 'Other',
            'school_year' => '2025-2026',
        ]);
        $outsider = Learner::factory()->create([
            'class_id' => $otherClass->id,
            'grade_level' => 5,
            'reading_level' => 'frustration',
        ]);

        $ids = $this->watchlist(20)->map(fn ($e) => $e['learner']->id)->all();

        $this->assertNotContains($outsider->id, $ids);
    }

    // ── Action Center: the dashboard renders open work and its age ──

    public function test_dashboard_shows_open_interventions_with_how_long_they_have_waited(): void
    {
        $learner = $this->learner(['reading_level' => 'frustration']);
        $this->logFor($learner, 'pending', 9);

        $response = $this->actingAs($this->teacher)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('Sound Blending Drill')
            ->assertSee('Assigned 9 days ago, not started')
            ->assertSee('Record progress');
    }

    public function test_dashboard_shows_interventions_already_in_progress(): void
    {
        $learner = $this->learner();
        $log = $this->logFor($learner, 'in_progress', 10);
        $log->forceFill(['started_at' => now()->subDays(4)])->save();

        $response = $this->actingAs($this->teacher)->get(route('dashboard'));

        // The old widget filtered on pending only, so these were invisible.
        $response->assertOk()
            ->assertSee('Started 4 days ago, in progress')
            ->assertSee('1 in progress');
    }

    public function test_dashboard_names_the_intervention_rather_than_a_placeholder(): void
    {
        $learner = $this->learner();
        $this->logFor($learner, 'pending');

        // The widget used to read a `title` field the model does not have.
        $this->actingAs($this->teacher)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Sound Blending Drill')
            ->assertDontSee('Recommended Activity');
    }

    public function test_completed_interventions_leave_the_action_center(): void
    {
        $learner = $this->learner();
        $this->logFor($learner, 'completed', 5);

        $this->actingAs($this->teacher)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Nothing open');
    }

    // ── Recording progress inline ──

    public function test_teacher_can_mark_an_intervention_started(): void
    {
        $log = $this->logFor($this->learner(), 'pending', 3);

        $this->actingAs($this->teacher)
            ->put(route('intervention-logs.update', $log), ['action' => 'start'])
            ->assertRedirect();

        $log->refresh();
        $this->assertSame('in_progress', $log->status);
        $this->assertNotNull($log->started_at);
    }

    public function test_teacher_can_complete_an_intervention_with_a_rating_and_notes(): void
    {
        $log = $this->logFor($this->learner(), 'in_progress', 10);

        $this->actingAs($this->teacher)
            ->put(route('intervention-logs.update', $log), [
                'action' => 'complete',
                'effectiveness_rating' => 8,
                'notes' => 'Blending improved; still slow on three-syllable words.',
            ])
            ->assertRedirect();

        $log->refresh();
        $this->assertSame('completed', $log->status);
        $this->assertNotNull($log->completed_at);
        $this->assertSame(8, $log->effectiveness_rating);
        $this->assertStringContainsString('Blending improved', $log->notes);
    }

    public function test_teacher_can_skip_an_intervention_with_a_reason(): void
    {
        $log = $this->logFor($this->learner(), 'pending', 30);

        $this->actingAs($this->teacher)
            ->put(route('intervention-logs.update', $log), [
                'action' => 'skip',
                'reason' => 'Learner transferred to another school.',
            ])
            ->assertRedirect();

        $log->refresh();
        $this->assertSame('skipped', $log->status);
        $this->assertStringContainsString('transferred', $log->notes);
    }

    public function test_skipping_without_a_reason_is_rejected(): void
    {
        $log = $this->logFor($this->learner(), 'pending');

        $this->actingAs($this->teacher)
            ->put(route('intervention-logs.update', $log), ['action' => 'skip'])
            ->assertSessionHasErrors('reason');

        $this->assertSame('pending', $log->fresh()->status);
    }

    public function test_an_unknown_action_is_rejected_rather_than_silently_ignored(): void
    {
        $log = $this->logFor($this->learner(), 'pending');

        // The learner page used to post `status`, which left `action` empty and
        // produced a success message while changing nothing.
        $this->actingAs($this->teacher)
            ->put(route('intervention-logs.update', $log), ['status' => 'completed'])
            ->assertSessionHasErrors('action');

        $this->assertSame('pending', $log->fresh()->status);
    }

    public function test_rating_outside_one_to_ten_is_rejected(): void
    {
        $log = $this->logFor($this->learner(), 'in_progress');

        $this->actingAs($this->teacher)
            ->put(route('intervention-logs.update', $log), [
                'action' => 'complete',
                'effectiveness_rating' => 42,
            ])
            ->assertSessionHasErrors('effectiveness_rating');

        $this->assertSame('in_progress', $log->fresh()->status);
    }

    public function test_a_teacher_cannot_record_progress_for_another_class(): void
    {
        $otherTeacher = User::factory()->create([
            'role' => 'teacher',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $otherClass = SchoolClass::create([
            'school_id' => $this->class->school_id,
            'teacher_id' => $otherTeacher->id,
            'grade_level' => 6,
            'section' => 'Foreign',
            'school_year' => '2025-2026',
        ]);
        $outsider = Learner::factory()->create(['class_id' => $otherClass->id, 'grade_level' => 6]);
        $log = $this->logFor($outsider, 'pending');

        // updateLog() previously ran no authorization check at all.
        $this->actingAs($this->teacher)
            ->put(route('intervention-logs.update', $log), ['action' => 'complete'])
            ->assertForbidden();

        $this->assertSame('pending', $log->fresh()->status);
    }

    // ── The learner page posts the same action the controller reads ──

    public function test_learner_intervention_page_posts_a_working_action(): void
    {
        $learner = $this->learner();
        $this->logFor($learner, 'pending', 4);

        $this->actingAs($this->teacher)
            ->get(route('learners.interventions', $learner))
            ->assertOk()
            ->assertSee('name="action"', false)
            ->assertDontSee('name="status"', false);
    }

    public function test_learner_intervention_page_shows_the_tracking_line(): void
    {
        $learner = $this->learner();
        $this->logFor($learner, 'pending', 6);

        $this->actingAs($this->teacher)
            ->get(route('learners.interventions', $learner))
            ->assertOk()
            ->assertSee('Assigned 6 days ago, not started');
    }
}
