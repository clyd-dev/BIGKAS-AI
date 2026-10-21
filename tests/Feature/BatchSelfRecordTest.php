<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentBatch;
use App\Models\AssessmentSession;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\User;
use App\Services\BatchEntryState;
use App\Services\BatchRosterBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesBatchAssessments;
use Tests\TestCase;

/**
 * A join code for every child in a batch. The code is today's single-child live
 * session, issued in bulk: the child signs in to the student portal and reads
 * the entry that is theirs. Their recording rests at `recorded` exactly like one
 * made on the teacher's laptop - the teacher still listens and sends.
 */
class BatchSelfRecordTest extends TestCase
{
    use RefreshDatabase, MakesBatchAssessments;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['assessment.batch_enabled' => true]);
    }

    private function batchOf(User $teacher, int $children = 3): AssessmentBatch
    {
        $classId = $teacher->taughtClasses()->first()->id;
        $learners = Learner::where('class_id', $classId)->orderBy('id')->get();
        $extra = $children - $learners->count();
        if ($extra > 0) {
            $learners = $learners->merge(
                Learner::factory()->count($extra)->create(['class_id' => $classId, 'grade_level' => 4])
            );
        }
        $material = ReadingMaterial::factory()->create();

        return app(BatchRosterBuilder::class)->build($teacher, [
            'title' => 'Morning group',
            'language' => 'en',
            'assessment_type' => 'oral_reading',
            'default_material_id' => $material->id,
            'school_class_id' => null,
            'entries' => $learners->take($children)->map(fn ($l) => [
                'learner_id' => $l->id, 'material_id' => null, 'allow_fallback' => false,
            ])->all(),
        ]);
    }

    private function asChild(Assessment $entry)
    {
        return $this->withSession(['student_learner_id' => $entry->learner_id]);
    }

    private function recording(): UploadedFile
    {
        return UploadedFile::fake()->create('reading.webm', 10, 'audio/webm');
    }

    public function test_every_entry_in_a_batch_gets_a_join_code(): void
    {
        $batch = $this->batchOf($this->teacher());

        $this->assertCount(3, $batch->entries);
        $this->assertSame(3, AssessmentSession::count());

        foreach ($batch->entries as $entry) {
            $session = AssessmentSession::where('assessment_id', $entry->id)->sole();

            $this->assertSame('waiting', $session->status);
            $this->assertSame($entry->learner_id, $session->learner_id);
            $this->assertSame($batch->teacher_id, $session->teacher_id);
            $this->assertSame($entry->material_id, $session->material_id);
            $this->assertMatchesRegularExpression('/^[A-F0-9]{6}$/', $session->session_code);
        }
    }

    public function test_every_code_in_a_batch_is_distinct(): void
    {
        $batch = $this->batchOf($this->teacher(), 15);

        $codes = AssessmentSession::whereIn('assessment_id', $batch->entries->pluck('id'))->pluck('session_code');

        $this->assertCount(15, $codes);
        $this->assertCount(15, $codes->unique());
    }

    public function test_a_child_joining_with_their_code_reaches_their_own_entry(): void
    {
        $batch = $this->batchOf($this->teacher());
        [$mine, $theirs] = [$batch->entries[0], $batch->entries[1]];

        // The child's way in is the single-child one: the student portal.
        $learner = $mine->learner;
        $learner->pin = $pin = '482916'; // not mass-assignable
        $learner->save();
        $this->post(route('student.login.submit'), ['pin' => $pin])
            ->assertRedirect(route('student.dashboard'));

        // Their dashboard carries the live session issued to them...
        $this->get(route('student.dashboard'))->assertOk();
        $this->assertSame(
            AssessmentSession::where('assessment_id', $mine->id)->value('id'),
            $learner->fresh()->getActiveSession()->id
        );

        // ...and the portal's own lookup hands them their entry, nobody else's.
        $this->getJson(route('student.assessment.pending'))
            ->assertOk()
            ->assertJson(['has_pending' => true, 'assessment_id' => $mine->id]);
        $this->get(route('student.assessment.read', $mine))->assertOk();
        $this->assertNotSame($theirs->id, $mine->id);
    }

    public function test_a_child_cannot_join_another_childs_entry_with_their_own_code(): void
    {
        $batch = $this->batchOf($this->teacher());
        [$mine, $theirs] = [$batch->entries[0], $batch->entries[1]];

        // The other child's entry really is there, pending, with a code of its
        // own - so what is refused below is refused, not merely missing.
        $this->assertSame(Assessment::STATUS_PENDING, $theirs->fresh()->status);
        $this->assertTrue(AssessmentSession::where('assessment_id', $theirs->id)->exists());
        $this->assertNotSame($mine->learner_id, $theirs->learner_id);

        $this->asChild($mine)->get(route('student.assessment.read', $theirs))
            ->assertRedirect(route('student.dashboard'));
        $this->asChild($mine)->postJson(route('student.assessment.start', $theirs))->assertStatus(403);
        $this->asChild($mine)->postJson(route('student.assessment.upload-audio', $theirs), ['audio' => $this->recording()])
            ->assertStatus(403);

        $theirs->refresh();
        $this->assertNull($theirs->audio_file);
        $this->assertSame(Assessment::STATUS_PENDING, $theirs->status);

        // Control: the same child, the same routes, their own entry - admitted.
        $this->asChild($mine)->get(route('student.assessment.read', $mine))->assertOk();
        $this->asChild($mine)->postJson(route('student.assessment.upload-audio', $mine), ['audio' => $this->recording()])
            ->assertOk();
        $this->assertNotNull($mine->fresh()->audio_file);
    }

    public function test_a_childs_own_recording_rests_at_recorded_not_queued(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $batch->entries[0];

        $this->asChild($entry)->postJson(route('student.assessment.upload-audio', $entry), ['audio' => $this->recording()])
            ->assertOk();

        $entry->refresh();

        $this->assertNotNull($entry->audio_file);
        Storage::disk('public')->assertExists($entry->audio_file);
        $this->assertNull($entry->submitted_for_analysis_at);
        $this->assertFalse(Assessment::awaitingRescore()->whereKey($entry->id)->exists());
        $this->assertSame('recorded', BatchEntryState::for($entry)['chip']);

        // The student route used to leave a recording "processing", which the
        // lane claims once it is fifteen minutes old. A child's recording must
        // still be waiting for the teacher long after that.
        $this->travel(2)->hours();
        $this->assertFalse(Assessment::awaitingRescore()->whereKey($entry->id)->exists());
        $this->assertSame('recorded', BatchEntryState::for($entry->fresh())['chip']);
        $this->travelBack();

        // Only the teacher's Send puts it in the lane.
        $this->actingAs($batch->teacher)->postJson(route('assessments.batch.send', [$batch, $entry]))->assertOk();
        $this->assertTrue(Assessment::awaitingRescore()->whereKey($entry->id)->exists());
    }

    public function test_a_cancelled_batch_s_codes_no_longer_admit_a_child(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $batch->entries[0];

        // Set on the model: the cancel action itself is Task 11's.
        $batch->update(['status' => AssessmentBatch::STATUS_CANCELLED]);

        $this->asChild($entry)->getJson(route('student.assessment.pending'))
            ->assertOk()->assertJson(['has_pending' => false]);
        $this->asChild($entry)->get(route('student.assessment.read', $entry))
            ->assertRedirect(route('student.dashboard'));
        $this->asChild($entry)->postJson(route('student.assessment.start', $entry))->assertStatus(403);
        $this->asChild($entry)->postJson(route('student.assessment.upload-audio', $entry), ['audio' => $this->recording()])
            ->assertStatus(403);

        $entry->refresh();
        $this->assertNull($entry->audio_file);
        $this->assertSame(Assessment::STATUS_PENDING, $entry->status);
    }

    public function test_a_failure_part_way_through_leaves_no_batch_no_entry_and_no_session(): void
    {
        $teacher = $this->teacher();
        $count = 0;
        AssessmentSession::creating(function () use (&$count) {
            if (++$count === 2) {
                throw new \RuntimeException('boom on the second code');
            }
        });

        try {
            $this->batchOf($teacher);
            $this->fail('Expected the failure to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom on the second code', $e->getMessage());
        }

        $this->assertSame(2, $count);
        $this->assertSame(0, AssessmentBatch::count());
        $this->assertSame(0, Assessment::count());
        $this->assertSame(0, AssessmentSession::count());
    }

    public function test_the_code_shows_on_the_panel_and_on_a_waiting_card_only(): void
    {
        $batch = $this->batchOf($this->teacher());
        [$waiting, $recorded] = [$batch->entries[0], $batch->entries[1]];
        $recorded->forceFill(['audio_file' => 'assessments/audio/x.webm'])->save();
        $code = fn ($e) => AssessmentSession::where('assessment_id', $e->id)->value('session_code');

        $this->actingAs($batch->teacher)->get(route('assessments.batch.entry', [$batch, $waiting]))
            ->assertOk()->assertSee($code($waiting))->assertDontSee('No join code was issued');

        $board = $this->actingAs($batch->teacher)->get(route('assessments.batch.board', $batch))->assertOk();
        $board->assertSee($code($waiting));
        $board->assertDontSee($code($recorded));
    }
}