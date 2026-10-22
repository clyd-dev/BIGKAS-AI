<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AssessmentSession;
use App\Models\AssessmentVerdict;
use App\Models\ComprehensionAnswer;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Destroying a reading, on the learner's own history screen.
 *
 * This is the **only** place in the application where a measured reading can be
 * destroyed, and the screen has to earn it. Task 11 deliberately refused to put
 * this on the batch board: there a teacher is moving fast through fifteen cards
 * and one misplaced tap is one child's record gone. Here she is in front of one
 * child's own history, one row at a time.
 *
 * Why it is dangerous, stated once: deleting an Assessment cascade-deletes its
 * assessment_result, its assessment_verdict, its comprehension_answers and its
 * assessment_sessions - and Learner::readingLevelFromAssessments() derives the
 * child's reading level from their assessments. So the delete silently changes
 * what the parent portal shows, what the Practice Center recommends, which
 * interventions are chosen for that child and what the admin chart counts.
 *
 * Hence the three things pinned here beyond "the row goes":
 *
 *  - The confirmation shows the reading it would destroy - date, material,
 *    level, accuracy - and names the consequences. Never a bare "are you sure".
 *  - An assessment carrying a verdict is a reviewed record with an audit trail,
 *    so destroying it needs the learner's **typed** name, not a click.
 *  - The deletion is written to the activity log *before* the row goes, in a
 *    line that still means something once the assessment no longer exists, and
 *    the learner's reading level is recomputed afterwards so it never reflects
 *    a reading that is gone.
 *
 * This is outside the batch feature and outside config('assessment.batch_enabled'):
 * it is a gap in the existing app that the batch work surfaced.
 */
class AssessmentDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests delete audio files; without this they would be deleting
        // real recordings off the developer's disk.
        Storage::fake('public');
    }

    // ── Fixtures ──────────────────────────────────────────────────────

    private function teacherWithClass(string $section = 'Delete A'): User
    {
        $teacher = User::factory()->create([
            'role' => User::ROLE_TEACHER,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $school = School::firstOrCreate(['name' => 'Delete School'], ['address' => 'Sagay', 'division' => 'Sagay']);
        SchoolClass::create([
            'school_id' => $school->id,
            'teacher_id' => $teacher->id,
            'grade_level' => 4,
            'section' => $section,
            'school_year' => '2026-2027',
        ]);

        return $teacher;
    }

    private function learnerOf(User $teacher, array $attributes = []): Learner
    {
        return Learner::factory()->create(array_merge([
            'class_id' => $teacher->taughtClasses()->first()->id,
            'grade_level' => 4,
        ], $attributes));
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    /** A completed reading with stored audio, a measured result, and no verdict yet. */
    private function scoredReading(Learner $learner, User $assessor, array $result = [], array $attributes = []): Assessment
    {
        $path = 'assessments/audio/' . Str::uuid() . '.webm';
        Storage::disk('public')->put($path, 'audio');

        $assessment = Assessment::factory()->create(array_merge([
            'learner_id' => $learner->id,
            'assessor_id' => $assessor->id,
            'material_id' => ReadingMaterial::factory()->create(['title' => 'The Brown Dog'])->id,
            'status' => Assessment::STATUS_COMPLETED,
            'audio_file' => $path,
        ], $attributes));

        AssessmentResult::create(array_merge([
            'assessment_id' => $assessment->id,
            'accuracy_rate' => 95.5,
            'words_per_minute' => 80,
            'fluency_score' => 75,
            'reading_level' => Learner::LEVEL_INDEPENDENT,
            'confidence_score' => 0.8,
            'classification_status' => AssessmentResult::STATUS_CLASSIFIED,
            'transcribed_text' => 'I have a dog his name is Max',
        ], $result));

        return $assessment->fresh();
    }

    private function verdictOn(Assessment $assessment, User $teacher, array $overrides = []): AssessmentVerdict
    {
        return AssessmentVerdict::create(array_merge([
            'assessment_id' => $assessment->id,
            'decision' => AssessmentVerdict::DECISION_ACCEPTED,
            'decided_by' => $teacher->id,
            'decided_at' => now(),
        ], $overrides));
    }

    // ── The tests the brief asks for ──────────────────────────────────

    public function test_a_teacher_can_delete_an_assessment_from_the_learners_history(): void
    {
        $teacher = $this->teacherWithClass();
        $learner = $this->learnerOf($teacher);
        $assessment = $this->scoredReading($learner, $teacher);
        $audio = $assessment->audio_file;

        $this->actingAs($teacher)
            ->delete(route('assessments.destroy', $assessment))
            ->assertRedirect(route('assessments.learner-history', $learner))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('assessments', ['id' => $assessment->id]);

        // The recording goes with the row; an orphaned audio file on disk is a
        // child's voice nothing refers to any more.
        $this->assertFalse(Storage::disk('public')->exists($audio));
    }

    public function test_deleting_an_assessment_removes_its_result_verdict_and_answers(): void
    {
        $teacher = $this->teacherWithClass();
        $learner = $this->learnerOf($teacher);
        $assessment = $this->scoredReading($learner, $teacher);
        $this->verdictOn($assessment, $teacher);

        ComprehensionAnswer::create([
            'assessment_id' => $assessment->id,
            'question_text' => 'What colour was the dog?',
            'selected_option' => 'a',
            'selected_text' => 'Brown',
            'correct_text' => 'Brown',
            'is_correct' => true,
            'created_at' => now(),
        ]);

        AssessmentSession::create([
            'assessment_id' => $assessment->id,
            'learner_id' => $learner->id,
            'teacher_id' => $teacher->id,
            'material_id' => $assessment->material_id,
            'session_code' => 'ZZZ111',
            'status' => 'completed',
        ]);

        $this->actingAs($teacher)
            ->delete(route('assessments.destroy', $assessment), ['learner_name' => $learner->getFullName()])
            ->assertRedirect(route('assessments.learner-history', $learner));

        $this->assertDatabaseMissing('assessments', ['id' => $assessment->id]);
        $this->assertDatabaseMissing('assessment_results', ['assessment_id' => $assessment->id]);
        $this->assertDatabaseMissing('assessment_verdicts', ['assessment_id' => $assessment->id]);
        $this->assertDatabaseMissing('comprehension_answers', ['assessment_id' => $assessment->id]);
        $this->assertDatabaseMissing('assessment_sessions', ['assessment_id' => $assessment->id]);
    }

    public function test_deleting_an_assessment_recomputes_the_learners_reading_level(): void
    {
        $teacher = $this->teacherWithClass();
        $learner = $this->learnerOf($teacher);

        // An older frustration reading, then a newer independent one. The level
        // the learner carries is the newer one's.
        $older = $this->scoredReading($learner, $teacher,
            ['reading_level' => Learner::LEVEL_FRUSTRATION],
            ['created_at' => now()->subDays(3)]);
        $newer = $this->scoredReading($learner, $teacher,
            ['reading_level' => Learner::LEVEL_INDEPENDENT],
            ['created_at' => now()->subDay()]);

        $learner->updateReadingLevel();
        $this->assertSame(Learner::LEVEL_INDEPENDENT, $learner->fresh()->reading_level);

        // Delete the most recent reading: the level must fall back to the one
        // the remaining reading supports, not stay at the deleted reading's.
        $this->actingAs($teacher)->delete(route('assessments.destroy', $newer))->assertRedirect();

        $this->assertSame(Learner::LEVEL_FRUSTRATION, $learner->fresh()->reading_level);

        // And deleting the only reading left clears it, rather than leaving a
        // level that no reading on record supports.
        $this->actingAs($teacher)->delete(route('assessments.destroy', $older))->assertRedirect();

        $this->assertNull($learner->fresh()->reading_level);
    }

    public function test_deleting_a_reviewed_assessment_requires_the_learners_typed_name(): void
    {
        $teacher = $this->teacherWithClass();
        $learner = $this->learnerOf($teacher, ['first_name' => 'Juan', 'last_name' => 'Cruz', 'middle_name' => 'Dela']);
        $assessment = $this->scoredReading($learner, $teacher);
        $this->verdictOn($assessment, $teacher);

        // A blind confirm-through: the button clicked, nothing typed.
        $this->actingAs($teacher)
            ->from(route('assessments.learner-history', $learner))
            ->delete(route('assessments.destroy', $assessment))
            ->assertRedirect(route('assessments.learner-history', $learner))
            ->assertSessionHasErrors('learner_name');
        $this->assertDatabaseHas('assessments', ['id' => $assessment->id]);

        // A name that is not this child's.
        $this->actingAs($teacher)
            ->from(route('assessments.learner-history', $learner))
            ->delete(route('assessments.destroy', $assessment), ['learner_name' => 'Maria Cruz'])
            ->assertSessionHasErrors('learner_name');
        $this->assertDatabaseHas('assessments', ['id' => $assessment->id]);

        // Half of it is not it either.
        $this->actingAs($teacher)
            ->from(route('assessments.learner-history', $learner))
            ->delete(route('assessments.destroy', $assessment), ['learner_name' => 'Juan'])
            ->assertSessionHasErrors('learner_name');
        $this->assertDatabaseHas('assessments', ['id' => $assessment->id]);

        // Forgiving about what a teacher's hands do to a name she can see:
        // stray whitespace, case, and the middle initial's full stop.
        $this->actingAs($teacher)
            ->delete(route('assessments.destroy', $assessment), ['learner_name' => '  juan   cruz '])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('assessments', ['id' => $assessment->id]);
    }

    public function test_a_deletion_is_written_to_the_activity_log(): void
    {
        $teacher = $this->teacherWithClass();
        $learner = $this->learnerOf($teacher, ['first_name' => 'Juan', 'last_name' => 'Cruz', 'middle_name' => null]);
        $assessment = $this->scoredReading($learner, $teacher, [], ['created_at' => now()->subDay()]);

        $this->actingAs($teacher)->delete(route('assessments.destroy', $assessment))->assertRedirect();

        $log = ActivityLog::latest('id')->first();

        $this->assertNotNull($log, 'The deletion was not written to the activity log at all.');
        $this->assertSame($teacher->id, $log->user_id);

        // The line has to still mean something once the assessment is gone, so
        // "deleted assessment 41" is not enough: the child, the date and the
        // score have to be in the text itself.
        $this->assertStringContainsString('Juan Cruz', $log->description);
        $this->assertStringContainsString($assessment->created_at->format('M d, Y'), $log->description);
        $this->assertStringContainsString('95.5', $log->description);
        $this->assertStringContainsString('The Brown Dog', $log->description);

        // And it points at something that still exists: the learner, not the
        // assessment id it would otherwise dangle on.
        $this->assertSame('learner', $log->subject_type);
        $this->assertSame($learner->id, $log->subject_id);
    }

    public function test_an_admin_cannot_delete_an_assessment(): void
    {
        $teacher = $this->teacherWithClass();
        $learner = $this->learnerOf($teacher);
        $assessment = $this->scoredReading($learner, $teacher);
        $admin = $this->admin();

        // Per the LearnerAdminReadOnlyTest precedent: the admin (principal)
        // browses, the assessing teacher decides.
        $this->actingAs($admin)
            ->delete(route('assessments.destroy', $assessment), ['learner_name' => $learner->getFullName()])
            ->assertForbidden();

        $this->assertDatabaseHas('assessments', ['id' => $assessment->id]);
        $this->assertDatabaseHas('assessment_results', ['assessment_id' => $assessment->id]);

        // Nor is the control offered to them on the history screen.
        $this->actingAs($admin)
            ->get(route('assessments.learner-history', $learner))
            ->assertOk()
            ->assertDontSee('Delete this reading');
    }

    public function test_a_teacher_cannot_delete_another_teachers_learners_assessment(): void
    {
        $teacher = $this->teacherWithClass('Delete A');
        $learner = $this->learnerOf($teacher);
        $assessment = $this->scoredReading($learner, $teacher);

        // Scoped per pivot row or per class taught - never per school. These two
        // teachers share a school and that must not be enough.
        $other = $this->teacherWithClass('Delete B');
        $this->assertSame($teacher->taughtClasses()->first()->school_id, $other->taughtClasses()->first()->school_id);

        $this->actingAs($other)
            ->delete(route('assessments.destroy', $assessment), ['learner_name' => $learner->getFullName()])
            ->assertForbidden();

        $this->assertDatabaseHas('assessments', ['id' => $assessment->id]);
        $this->assertDatabaseHas('assessment_results', ['assessment_id' => $assessment->id]);
    }

    public function test_the_confirmation_names_the_reading_being_deleted(): void
    {
        $teacher = $this->teacherWithClass();
        $learner = $this->learnerOf($teacher, ['first_name' => 'Juan', 'last_name' => 'Cruz', 'middle_name' => null]);
        $assessment = $this->scoredReading($learner, $teacher, [], ['created_at' => now()->subDay()]);

        $page = $this->actingAs($teacher)->get(route('assessments.learner-history', $learner));

        $page->assertOk()
            // What is being deleted: the date, the material, the level, the score.
            ->assertSee($assessment->created_at->format('M d, Y'))
            ->assertSee('The Brown Dog')
            ->assertSee('Independent')
            ->assertSee('95.5% accuracy')
            // What goes with it, named rather than implied.
            ->assertSee('result', false)
            ->assertSee('comprehension answers')
            ->assertSee('reading level will be recomputed')
            // And the door itself.
            ->assertSee('Delete this reading')
            ->assertSee(route('assessments.destroy', $assessment), false);

        // A reviewed reading says, on the screen, that it will ask for the name.
        $reviewed = $this->scoredReading($learner, $teacher);
        $this->verdictOn($reviewed, $teacher);

        $this->actingAs($teacher)->get(route('assessments.learner-history', $learner))
            ->assertOk()
            ->assertSee('Juan Cruz')
            ->assertSee('type the learner’s name');
    }

    // ── The bug this screen is most likely to ship ────────────────────

    /**
     * O'Brien must not be able to defeat the confirmation.
     *
     * Task 11 shipped exactly this bug on the batch board: the child's name was
     * interpolated into an inline confirm() handler, Blade rendered the
     * apostrophe as &#039;, the browser decoded the entity before JS parsed it,
     * the handler became a syntax error - and the destructive form submitted
     * with no dialog at all.
     *
     * This screen avoids the whole class of bug by confirming in a Bootstrap
     * modal with the name in the escaped body text instead of in a handler. So
     * what is asserted is the absence of the shape that breaks: no inline
     * handler carries the name, and the destructive form has no onsubmit of its
     * own. A rendering assertion alone would have missed it.
     */
    public function test_an_apostrophe_in_the_childs_name_cannot_break_the_confirmation(): void
    {
        $teacher = $this->teacherWithClass();
        $learner = $this->learnerOf($teacher, ['first_name' => 'Maeve', 'last_name' => "O'Brien", 'middle_name' => null]);
        $assessment = $this->scoredReading($learner, $teacher);
        $this->verdictOn($assessment, $teacher);

        $html = $this->actingAs($teacher)->get(route('assessments.learner-history', $learner))
            ->assertOk()
            ->assertSee("O'Brien")
            ->getContent();

        // No JS handler on this screen carries the name at all.
        $this->assertStringNotContainsString('confirm(', $html);
        $this->assertDoesNotMatchRegularExpression('/onsubmit\s*=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/onclick\s*=\s*"[^"]*O&#039;Brien/i', $html);

        // And the typed name still works for the name the screen shows.
        $this->actingAs($teacher)
            ->delete(route('assessments.destroy', $assessment), ['learner_name' => "Maeve O'Brien"])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('assessments', ['id' => $assessment->id]);
    }

    /** Deleting twice: the second request finds nothing to delete and says so, rather than 500ing. */
    public function test_a_double_submitted_delete_does_not_explode(): void
    {
        $teacher = $this->teacherWithClass();
        $learner = $this->learnerOf($teacher);
        $assessment = $this->scoredReading($learner, $teacher);
        $id = $assessment->id;

        $this->actingAs($teacher)->delete(route('assessments.destroy', $assessment))->assertRedirect();
        $this->actingAs($teacher)->delete(route('assessments.destroy', $id))->assertNotFound();

        $this->assertSame(1, ActivityLog::where('action', 'Assessment Deleted')->count());
    }

    /** An unmeasured reading is deletable with one click: there is no reviewed record to protect. */
    public function test_an_unreviewed_reading_needs_no_typed_name(): void
    {
        $teacher = $this->teacherWithClass();
        $learner = $this->learnerOf($teacher);
        $assessment = Assessment::factory()->create([
            'learner_id' => $learner->id,
            'assessor_id' => $teacher->id,
            'status' => Assessment::STATUS_PENDING,
        ]);

        $this->actingAs($teacher)->delete(route('assessments.destroy', $assessment))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('assessments', ['id' => $assessment->id]);
    }
}
