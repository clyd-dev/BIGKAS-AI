<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentBatch;
use App\Models\AssessmentSession;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\BatchEntryState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesBatchAssessments;
use Tests\TestCase;

/**
 * Adding a child to a board that is already running.
 *
 * A batch is set up before the session starts, but a class is not a fixed thing
 * on the day: a child arrives late, an absent child turns up, a teacher finds
 * she has time for one more. Before this, the roster was frozen the moment the
 * batch was created and the only way to assess another child was a second
 * batch.
 *
 * The guarantees pinned here are the ones a second, divergent way of putting a
 * child into a batch would quietly lose: the cap counts the children already on
 * the board, a child cannot be added twice, another teacher's child cannot be
 * added at all, a cancelled batch takes nothing in, and the whole addition is
 * all-or-nothing with one join session per new child.
 */
class BatchAddChildTest extends TestCase
{
    use MakesBatchAssessments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['assessment.batch_enabled' => true]);
    }

    // ── Fixtures ──────────────────────────────────────────────────────

    /** @return Learner[] */
    private function learnersOf(User $teacher): array
    {
        return Learner::whereIn('class_id', $teacher->taughtClasses()->pluck('id'))->orderBy('id')->get()->all();
    }

    /** More children in the teacher's own class. */
    private function moreLearners(User $teacher, int $count, int $grade = 4): array
    {
        $classId = $teacher->taughtClasses()->first()->id;

        return Learner::factory()->count($count)->create(['class_id' => $classId, 'grade_level' => $grade])->all();
    }

    /**
     * A board whose default material is a real Grade 4 passage, holding the
     * given children as plain `waiting` entries.
     *
     * @param  Learner[]  $learners
     */
    private function board(User $teacher, array $learners, array $overrides = []): AssessmentBatch
    {
        $material = ReadingMaterial::factory()->create(['grade_level' => 4, 'language' => 'en']);

        $batch = AssessmentBatch::factory()->create(array_merge([
            'teacher_id' => $teacher->id,
            'language' => 'en',
            'assessment_type' => Assessment::TYPE_ORAL_READING,
            'default_material_id' => $material->id,
        ], $overrides));

        foreach ($learners as $learner) {
            Assessment::factory()->create([
                'batch_id' => $batch->id,
                'learner_id' => $learner->id,
                'assessor_id' => $teacher->id,
                'material_id' => $material->id,
                'language' => 'en',
                'assessment_type' => Assessment::TYPE_ORAL_READING,
                'status' => Assessment::STATUS_PENDING,
                'audio_file' => null,
            ]);
        }

        return $batch->fresh();
    }

    /** The post the add screen makes: entries keyed by learner id, as the setup form does. */
    private function payload(array $learners, array $materialIds = []): array
    {
        $entries = [];

        foreach ($learners as $learner) {
            $entries[$learner->id] = array_filter([
                'learner_id' => $learner->id,
                'material_id' => $materialIds[$learner->id] ?? null,
            ], fn ($value) => $value !== null);
        }

        return ['entries' => $entries];
    }

    // ── The feature ───────────────────────────────────────────────────

    public function test_the_board_offers_add_child(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $batch = $this->board($teacher, [$learners[0], $learners[1]]);

        $this->actingAs($teacher)->get(route('assessments.batch.board', $batch))
            ->assertOk()
            ->assertSee('Add child')
            ->assertSee(route('assessments.batch.add', $batch), false);
    }

    public function test_a_child_is_added_and_their_card_appears_waiting(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $batch = $this->board($teacher, [$learners[0], $learners[1]]);
        $late = $learners[2];

        $this->actingAs($teacher)
            ->post(route('assessments.batch.add.store', $batch), $this->payload([$late]))
            ->assertRedirect(route('assessments.batch.board', $batch));

        $entry = Assessment::where('batch_id', $batch->id)->where('learner_id', $late->id)->firstOrFail();

        $this->assertSame('waiting', BatchEntryState::for($entry->fresh(['result', 'verdict', 'material']))['chip']);
        $this->assertNull($entry->audio_file);
        $this->assertNull($entry->submitted_for_analysis_at);
        $this->assertSame(Assessment::STATUS_PENDING, $entry->status);

        $this->actingAs($teacher)->get(route('assessments.batch.board', $batch))
            ->assertOk()
            ->assertSee($late->full_name);
    }

    public function test_the_add_screen_offers_only_children_not_already_on_the_board(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $batch = $this->board($teacher, [$learners[0], $learners[1]]);

        $this->actingAs($teacher)->get(route('assessments.batch.add', $batch))
            ->assertOk()
            ->assertSee($learners[2]->full_name)
            ->assertDontSee($learners[0]->full_name)
            ->assertDontSee($learners[1]->full_name);
    }

    public function test_a_child_already_on_the_board_cannot_be_forced_in_by_posting_their_id(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $batch = $this->board($teacher, [$learners[0], $learners[1]]);

        $this->actingAs($teacher)
            ->post(route('assessments.batch.add.store', $batch), $this->payload([$learners[0]]))
            ->assertSessionHasErrors('entries.' . $learners[0]->id . '.learner_id');

        $this->assertSame(1, Assessment::where('batch_id', $batch->id)->where('learner_id', $learners[0]->id)->count());
        $this->assertSame(2, Assessment::where('batch_id', $batch->id)->count());
    }

    public function test_another_teachers_child_cannot_be_added(): void
    {
        $teacher = $this->teacher();
        $other = $this->otherTeacher();
        $learners = $this->learnersOf($teacher);
        $batch = $this->board($teacher, [$learners[0], $learners[1]]);
        $stranger = $this->learnersOf($other)[0];

        $this->actingAs($teacher)->get(route('assessments.batch.add', $batch))
            ->assertOk()
            ->assertDontSee($stranger->full_name);

        $this->actingAs($teacher)
            ->post(route('assessments.batch.add.store', $batch), $this->payload([$stranger]))
            ->assertSessionHasErrors('entries.' . $stranger->id . '.learner_id');

        $this->assertSame(2, Assessment::where('batch_id', $batch->id)->count());
    }

    public function test_another_teacher_cannot_add_a_child_to_this_board(): void
    {
        $teacher = $this->teacher();
        $other = $this->otherTeacher();
        $learners = $this->learnersOf($teacher);
        $batch = $this->board($teacher, [$learners[0], $learners[1]]);

        $this->actingAs($other)->get(route('assessments.batch.add', $batch))->assertForbidden();
        $this->actingAs($other)
            ->post(route('assessments.batch.add.store', $batch), $this->payload([$this->learnersOf($other)[0]]))
            ->assertForbidden();

        $this->assertSame(2, Assessment::where('batch_id', $batch->id)->count());
    }

    // ── The cap is for the batch as a whole ───────────────────────────

    public function test_a_batch_of_fifteen_refuses_a_sixteenth_child(): void
    {
        config(['assessment.batch_max_learners' => 15]);

        $teacher = $this->teacher();
        $learners = array_merge($this->learnersOf($teacher), $this->moreLearners($teacher, 13));
        $batch = $this->board($teacher, array_slice($learners, 0, 15));
        $late = $learners[15];

        $this->actingAs($teacher)
            ->post(route('assessments.batch.add.store', $batch), $this->payload([$late]))
            ->assertSessionHasErrors('entries');

        $this->assertSame(15, Assessment::where('batch_id', $batch->id)->count());
    }

    public function test_a_batch_of_fourteen_accepts_one_more_and_refuses_two(): void
    {
        config(['assessment.batch_max_learners' => 15]);

        $teacher = $this->teacher();
        $learners = array_merge($this->learnersOf($teacher), $this->moreLearners($teacher, 13));
        $batch = $this->board($teacher, array_slice($learners, 0, 14));

        // Two at once would make sixteen: refused outright, and neither lands.
        $this->actingAs($teacher)
            ->post(route('assessments.batch.add.store', $batch), $this->payload([$learners[14], $learners[15]]))
            ->assertSessionHasErrors('entries');
        $this->assertSame(14, Assessment::where('batch_id', $batch->id)->count());

        $this->actingAs($teacher)
            ->post(route('assessments.batch.add.store', $batch), $this->payload([$learners[14]]))
            ->assertRedirect(route('assessments.batch.board', $batch));
        $this->assertSame(15, Assessment::where('batch_id', $batch->id)->count());
    }

    public function test_a_detached_child_does_not_count_against_the_cap(): void
    {
        config(['assessment.batch_max_learners' => 3]);

        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $batch = $this->board($teacher, [$learners[0], $learners[1]]);
        $more = $this->moreLearners($teacher, 2);

        // A child taken off the board is no longer in the batch, so the room
        // they used comes back - the count is of entries, not of history.
        Assessment::where('batch_id', $batch->id)->where('learner_id', $learners[1]->id)
            ->first()->forceFill(['batch_id' => null])->save();

        $this->actingAs($teacher)
            ->post(route('assessments.batch.add.store', $batch), $this->payload([$more[0], $more[1]]))
            ->assertRedirect(route('assessments.batch.board', $batch));

        $this->assertSame(3, Assessment::where('batch_id', $batch->id)->count());
    }

    public function test_the_add_screen_says_how_many_more_children_the_batch_can_take(): void
    {
        config(['assessment.batch_max_learners' => 15]);

        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $batch = $this->board($teacher, [$learners[0], $learners[1]]);

        $this->actingAs($teacher)->get(route('assessments.batch.add', $batch))
            ->assertOk()
            ->assertSee('13 more');
    }

    public function test_a_full_board_does_not_offer_add_child_and_the_screen_says_so(): void
    {
        config(['assessment.batch_max_learners' => 2]);

        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $batch = $this->board($teacher, [$learners[0], $learners[1]]);

        $this->actingAs($teacher)->get(route('assessments.batch.board', $batch))
            ->assertOk()
            ->assertDontSee('Add child');

        $this->actingAs($teacher)->get(route('assessments.batch.add', $batch))
            ->assertRedirect(route('assessments.batch.board', $batch))
            ->assertSessionHas('warning');
    }

    // ── A cancelled batch, and a completed one ────────────────────────

    public function test_a_cancelled_batch_takes_no_new_children(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $batch = $this->board($teacher, [$learners[0], $learners[1]], [
            'status' => AssessmentBatch::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        $this->actingAs($teacher)->get(route('assessments.batch.board', $batch))
            ->assertOk()
            ->assertDontSee('Add child');

        $this->actingAs($teacher)->get(route('assessments.batch.add', $batch))
            ->assertRedirect(route('assessments.batch.board', $batch))
            ->assertSessionHas('warning');

        $this->actingAs($teacher)
            ->post(route('assessments.batch.add.store', $batch), $this->payload([$learners[2]]))
            ->assertRedirect(route('assessments.batch.board', $batch))
            ->assertSessionHas('warning');

        $this->assertSame(2, Assessment::where('batch_id', $batch->id)->count());
        $this->assertSame(AssessmentBatch::STATUS_CANCELLED, $batch->fresh()->status);
    }

    /**
     * A finished batch takes a late child, and says it is working again.
     *
     * Refusing would be refusing the teacher's own case: the batch is only
     * `completed` because everyone who was on it is done, and a child turning
     * up five minutes later is the same session. So the batch reopens, and the
     * stored status has to tell the truth about that - `completed_at` cleared,
     * because this batch has not completed.
     */
    public function test_a_completed_batch_accepts_a_late_child_and_stops_claiming_it_is_finished(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $material = ReadingMaterial::factory()->create(['grade_level' => 4, 'language' => 'en']);

        $batch = AssessmentBatch::factory()->create([
            'teacher_id' => $teacher->id,
            'language' => 'en',
            'assessment_type' => Assessment::TYPE_ORAL_READING,
            'default_material_id' => $material->id,
        ]);

        $entry = $this->sentBatchEntry([
            'batch_id' => $batch->id,
            'learner_id' => $learners[0]->id,
            'material_id' => $material->id,
        ]);
        $entry->createResult($this->classifiedAnalysis());
        $entry->verdict()->create($this->acceptedVerdict());

        $before = AssessmentBatch::findOrFail($batch->id);
        $this->assertSame(AssessmentBatch::STATUS_COMPLETED, $before->status);
        $this->assertNotNull($before->completed_at);

        $this->actingAs($teacher)
            ->post(route('assessments.batch.add.store', $batch), $this->payload([$learners[1]]))
            ->assertRedirect(route('assessments.batch.board', $batch));

        $after = AssessmentBatch::findOrFail($batch->id);

        $this->assertNotSame(AssessmentBatch::STATUS_COMPLETED, $after->status);
        $this->assertSame(AssessmentBatch::STATUS_ANALYZING, $after->status);
        $this->assertNull($after->completed_at);
    }

    // ── One session each, and all-or-nothing ──────────────────────────

    public function test_each_added_child_gets_exactly_one_join_session(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $batch = $this->board($teacher, [$learners[0]]);
        $more = $this->moreLearners($teacher, 2);

        $this->actingAs($teacher)
            ->post(route('assessments.batch.add.store', $batch), $this->payload($more))
            ->assertRedirect(route('assessments.batch.board', $batch));

        foreach ($more as $learner) {
            $entry = Assessment::where('batch_id', $batch->id)->where('learner_id', $learner->id)->firstOrFail();
            $sessions = AssessmentSession::where('assessment_id', $entry->id)->get();

            $this->assertCount(1, $sessions);
            $this->assertSame('waiting', $sessions[0]->status);
            $this->assertSame($entry->material_id, $sessions[0]->material_id);
            $this->assertSame($teacher->id, $sessions[0]->teacher_id);
            $this->assertNotEmpty($sessions[0]->session_code);
        }
    }

    public function test_a_failure_part_way_through_adds_neither_child_nor_session(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $batch = $this->board($teacher, [$learners[0]]);
        $more = $this->moreLearners($teacher, 2);

        $entriesBefore = Assessment::where('batch_id', $batch->id)->count();
        $sessionsBefore = AssessmentSession::count();

        $count = 0;
        Assessment::creating(function () use (&$count) {
            if (++$count === 2) {
                throw new \RuntimeException('boom on the second added child');
            }
        });

        try {
            $this->actingAs($teacher)
                ->withoutExceptionHandling()
                ->post(route('assessments.batch.add.store', $batch), $this->payload($more));
            $this->fail('Expected the creation failure to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom on the second added child', $e->getMessage());
        }

        $this->assertSame(2, $count);
        $this->assertSame($entriesBefore, Assessment::where('batch_id', $batch->id)->count());
        $this->assertSame($sessionsBefore, AssessmentSession::count());

        foreach ($more as $learner) {
            $this->assertSame(0, Assessment::where('learner_id', $learner->id)->count());
        }
    }

    // ── What the new child reads ──────────────────────────────────────

    public function test_an_added_child_reads_the_batch_material_by_default(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $batch = $this->board($teacher, [$learners[0]]);

        $this->actingAs($teacher)
            ->post(route('assessments.batch.add.store', $batch), $this->payload([$learners[1]]))
            ->assertRedirect(route('assessments.batch.board', $batch));

        $entry = Assessment::where('batch_id', $batch->id)->where('learner_id', $learners[1]->id)->firstOrFail();

        $this->assertSame($batch->default_material_id, $entry->material_id);
        $this->assertSame($batch->language, $entry->language);
        $this->assertSame($batch->assessment_type, $entry->assessment_type);
        $this->assertSame($teacher->id, $entry->assessor_id);
    }

    public function test_an_added_child_may_be_given_their_own_reading(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $batch = $this->board($teacher, [$learners[0]]);
        $own = ReadingMaterial::factory()->create(['grade_level' => 4, 'language' => 'en']);

        $this->actingAs($teacher)
            ->post(
                route('assessments.batch.add.store', $batch),
                $this->payload([$learners[1]], [$learners[1]->id => $own->id])
            )
            ->assertRedirect(route('assessments.batch.board', $batch));

        $entry = Assessment::where('batch_id', $batch->id)->where('learner_id', $learners[1]->id)->firstOrFail();

        $this->assertSame($own->id, $entry->material_id);
        $this->assertNotSame($batch->default_material_id, $entry->material_id);
        $this->assertSame($own->id, AssessmentSession::where('assessment_id', $entry->id)->firstOrFail()->material_id);
    }

    /**
     * R52: a cross-grade reading is allowed and the gap is stated, never
     * forbidden. A Grade 6 child reading a Grade 2 passage is precisely what
     * Phil-IRI exists to find.
     */
    public function test_a_child_from_another_grade_is_told_to_pick_their_own_reading_and_may_read_any_grade(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $batch = $this->board($teacher, [$learners[0]]);

        $classId = $teacher->taughtClasses()->first()->id;
        $sixth = Learner::factory()->create(['class_id' => $classId, 'grade_level' => 6]);

        // The screen states the gap rather than hiding the child.
        $this->actingAs($teacher)->get(route('assessments.batch.add', $batch))
            ->assertOk()
            ->assertSee($sixth->full_name)
            ->assertSee('Grade 6');

        // The batch material is Grade 4, so it is not quietly handed to them.
        $this->actingAs($teacher)
            ->post(route('assessments.batch.add.store', $batch), $this->payload([$sixth]))
            ->assertSessionHasErrors('entries.' . $sixth->id . '.material_id');
        $this->assertSame(1, Assessment::where('batch_id', $batch->id)->count());

        // Chosen on purpose, any grade is allowed - including two below.
        $grade2 = ReadingMaterial::factory()->create(['grade_level' => 2, 'language' => 'en']);

        $this->actingAs($teacher)
            ->post(
                route('assessments.batch.add.store', $batch),
                $this->payload([$sixth], [$sixth->id => $grade2->id])
            )
            ->assertRedirect(route('assessments.batch.board', $batch));

        $entry = Assessment::where('batch_id', $batch->id)->where('learner_id', $sixth->id)->firstOrFail();
        $this->assertSame($grade2->id, $entry->material_id);
    }

    public function test_a_material_in_the_wrong_language_is_refused(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $batch = $this->board($teacher, [$learners[0]]);
        $filipino = ReadingMaterial::factory()->create(['grade_level' => 4, 'language' => 'fil']);

        $this->actingAs($teacher)
            ->post(
                route('assessments.batch.add.store', $batch),
                $this->payload([$learners[1]], [$learners[1]->id => $filipino->id])
            )
            ->assertSessionHasErrors('entries.' . $learners[1]->id . '.material_id');

        $this->assertSame(1, Assessment::where('batch_id', $batch->id)->count());
    }

    public function test_adding_nobody_is_refused(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $batch = $this->board($teacher, [$learners[0], $learners[1]]);

        $this->actingAs($teacher)
            ->post(route('assessments.batch.add.store', $batch), ['entries' => []])
            ->assertSessionHasErrors('entries');

        $this->assertSame(2, Assessment::where('batch_id', $batch->id)->count());
    }

    // ── The board's own guarantees still hold ─────────────────────────

    /**
     * R39: the board is a fixed number of queries per render, not one per card.
     * An added child must not bring a query with them.
     */
    public function test_the_board_costs_the_same_queries_after_children_are_added(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $batch = $this->board($teacher, [$learners[0]]);

        // One render first, discarded: the very first board of a test run pays
        // for one cache fill that has nothing to do with the cards on it, and
        // this test is about whether the count grows with the cards.
        $this->actingAs($teacher)->get(route('assessments.batch.board', $batch))->assertOk();

        $before = $this->boardQueryCount($teacher, $batch);

        $this->actingAs($teacher)
            ->post(route('assessments.batch.add.store', $batch), $this->payload([$learners[1], $learners[2]]))
            ->assertRedirect(route('assessments.batch.board', $batch));

        $this->assertSame(3, Assessment::where('batch_id', $batch->id)->count());
        $this->assertSame($before, $this->boardQueryCount($teacher, $batch),
            'a board of three costs the same number of queries as a board of one');
    }



    private function boardQueryCount(User $teacher, AssessmentBatch $batch): int
    {
        $count = 0;

        DB::listen(function () use (&$count) {
            $count++;
        });

        $this->actingAs($teacher)->get(route('assessments.batch.board', $batch))->assertOk();

        return $count;
    }

    public function test_poll_still_writes_nothing_after_a_child_is_added(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $batch = $this->board($teacher, [$learners[0]]);

        $this->actingAs($teacher)
            ->post(route('assessments.batch.add.store', $batch), $this->payload([$learners[1]]))
            ->assertRedirect(route('assessments.batch.board', $batch));

        $before = AssessmentBatch::findOrFail($batch->id);
        $statusBefore = $before->status;
        $updatedAtBefore = $before->updated_at->toDateTimeString();
        $entriesBefore = Assessment::where('batch_id', $batch->id)->orderBy('id')
            ->pluck('updated_at', 'id')->map(fn ($at) => (string) $at)->all();

        $this->travelTo(now()->addMinutes(2));

        $this->actingAs($teacher)->get(route('assessments.batch.poll', $batch))->assertOk();

        $after = AssessmentBatch::findOrFail($batch->id);

        $this->assertSame($statusBefore, $after->status);
        $this->assertSame($updatedAtBefore, $after->updated_at->toDateTimeString());
        $this->assertSame($entriesBefore, Assessment::where('batch_id', $batch->id)->orderBy('id')
            ->pluck('updated_at', 'id')->map(fn ($at) => (string) $at)->all());
    }

    public function test_an_added_child_appears_in_the_polls_entries(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $batch = $this->board($teacher, [$learners[0]]);

        $this->actingAs($teacher)
            ->post(route('assessments.batch.add.store', $batch), $this->payload([$learners[1]]))
            ->assertRedirect(route('assessments.batch.board', $batch));

        $entry = Assessment::where('batch_id', $batch->id)->where('learner_id', $learners[1]->id)->firstOrFail();

        $response = $this->actingAs($teacher)->get(route('assessments.batch.poll', $batch))->assertOk();

        $this->assertSame('waiting', $response->json('entries.' . $entry->id . '.chip'));
    }

    /** A name with an apostrophe is copy, not code - on the picker and on the flash. */
    public function test_a_name_with_an_apostrophe_is_safe(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $batch = $this->board($teacher, [$learners[0]]);

        $learners[1]->forceFill(['first_name' => "O'Brien", 'last_name' => "D'Angelo"])->save();

        $this->actingAs($teacher)->get(route('assessments.batch.add', $batch))
            ->assertOk()
            ->assertSee('O&#039;Brien', false)
            ->assertDontSee("O'Brien", false);

        $this->actingAs($teacher)
            ->post(route('assessments.batch.add.store', $batch), $this->payload([$learners[1]]))
            ->assertRedirect(route('assessments.batch.board', $batch));

        $this->actingAs($teacher)->get(route('assessments.batch.board', $batch))
            ->assertOk()
            ->assertSee('O&#039;Brien', false);
    }

    /** The feature is dark with the flag off, like every other batch screen. */
    public function test_the_add_routes_are_dark_while_the_flag_is_off(): void
    {
        config(['assessment.batch_enabled' => false]);

        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $batch = $this->board($teacher, [$learners[0], $learners[1]]);

        $this->actingAs($teacher)->get(route('assessments.batch.add', $batch))->assertNotFound();
        $this->actingAs($teacher)
            ->post(route('assessments.batch.add.store', $batch), $this->payload([$learners[2]]))
            ->assertNotFound();
    }

    /** A child in a class this teacher does not teach is not on the picker. */
    public function test_a_child_from_a_class_the_teacher_does_not_teach_is_not_offered(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $batch = $this->board($teacher, [$learners[0], $learners[1]]);

        $otherClass = SchoolClass::create([
            'school_id' => $teacher->taughtClasses()->first()->school_id,
            'teacher_id' => $this->otherTeacher()->id,
            'grade_level' => 4, 'section' => 'Batch Z', 'school_year' => '2026-2027',
        ]);
        $stranger = Learner::factory()->create(['class_id' => $otherClass->id, 'grade_level' => 4]);

        $this->actingAs($teacher)->get(route('assessments.batch.add', $batch))
            ->assertOk()
            ->assertDontSee($stranger->full_name);
    }
}
