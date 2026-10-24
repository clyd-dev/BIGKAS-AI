<?php

namespace Tests\Feature;

use App\Models\AssessmentBatch;
use App\Models\Learner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesBatchAssessments;
use Tests\TestCase;

/**
 * The way back to a board.
 *
 * A teacher who closes a batch board and goes somewhere else had no route back
 * but typing the URL. So: a list of her own batches, newest first, and an entry
 * point she can find from the assessments page - which also says plainly when
 * one of her batches is still unfinished, because that is the one she is
 * looking for.
 */
class BatchIndexTest extends TestCase
{
    use MakesBatchAssessments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['assessment.batch_enabled' => true]);
    }

    /** @return Learner[] */
    private function learnersOf(User $teacher): array
    {
        return Learner::whereIn('class_id', $teacher->taughtClasses()->pluck('id'))->orderBy('id')->get()->all();
    }

    // ── the list ──────────────────────────────────────────────────────

    public function test_the_index_lists_this_teachers_batches_newest_first(): void
    {
        $teacher = $this->teacher();

        $older = AssessmentBatch::factory()->create(['teacher_id' => $teacher->id, 'title' => 'Monday morning group']);
        $newer = AssessmentBatch::factory()->create(['teacher_id' => $teacher->id, 'title' => 'Tuesday afternoon group']);

        $html = $this->actingAs($teacher)->get(route('assessments.batch.index'))
            ->assertOk()
            ->assertSee($older->title)
            ->assertSee($newer->title)
            ->assertSee(route('assessments.batch.board', $newer), false)
            ->getContent();

        $this->assertLessThan(
            strpos($html, $older->title),
            strpos($html, $newer->title),
            'the newest batch is not listed first'
        );
    }

    public function test_another_teachers_batch_is_not_listed(): void
    {
        $teacher = $this->teacher();
        $other = $this->otherTeacher();

        AssessmentBatch::factory()->create(['teacher_id' => $teacher->id, 'title' => 'My own group']);
        AssessmentBatch::factory()->create(['teacher_id' => $other->id, 'title' => 'Somebody elses group']);

        $this->actingAs($teacher)->get(route('assessments.batch.index'))
            ->assertOk()
            ->assertSee('My own group')
            ->assertDontSee('Somebody elses group');
    }

    public function test_the_index_summarises_how_many_readings_are_done(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $batch = AssessmentBatch::factory()->create(['teacher_id' => $teacher->id, 'title' => 'Mixed group']);

        // One finished reading, two still waiting for one.
        $finished = $this->sentBatchEntry(['batch_id' => $batch->id, 'learner_id' => $learners[0]->id]);
        $finished->result()->create($this->classifiedAnalysis());
        $finished->verdict()->create($this->acceptedVerdict());
        $this->sentBatchEntry(['batch_id' => $batch->id, 'learner_id' => $learners[1]->id]);
        $this->sentBatchEntry(['batch_id' => $batch->id, 'learner_id' => $learners[2]->id]);

        $this->actingAs($teacher)->get(route('assessments.batch.index'))
            ->assertOk()
            ->assertSee('1 of 3 done');
    }

    public function test_the_index_paginates_without_losing_the_older_batches(): void
    {
        $teacher = $this->teacher();

        for ($i = 1; $i <= 12; $i++) {
            AssessmentBatch::factory()->create(['teacher_id' => $teacher->id, 'title' => 'Group number ' . $i]);
        }

        $this->actingAs($teacher)->get(route('assessments.batch.index'))
            ->assertOk()
            ->assertSee('Group number 12')
            ->assertDontSee('Group number 2')
            ->assertSee('bigkas-pager', false);

        $this->actingAs($teacher)->get(route('assessments.batch.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('Group number 2')
            ->assertSee('Group number 1');
    }

    /** One list, however long: not one query per batch on the page. */
    public function test_the_index_does_not_cost_a_query_per_batch(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);

        $one = AssessmentBatch::factory()->create(['teacher_id' => $teacher->id]);
        $this->sentBatchEntry(['batch_id' => $one->id, 'learner_id' => $learners[0]->id]);

        $first = $this->indexQueryCount($teacher);

        foreach ([2, 3, 4] as $n) {
            $extra = AssessmentBatch::factory()->create(['teacher_id' => $teacher->id]);
            $this->sentBatchEntry(['batch_id' => $extra->id, 'learner_id' => $learners[$n - 1] ?? $learners[0]->id]);
        }

        $this->assertSame($first, $this->indexQueryCount($teacher), 'the index costs more queries as batches are added');
    }

    private function indexQueryCount(User $teacher): int
    {
        $this->actingAs($teacher)->get(route('assessments.batch.index'))->assertOk();

        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $this->actingAs($teacher)->get(route('assessments.batch.index'))->assertOk();

        return $count;
    }

    public function test_the_index_is_dark_while_the_flag_is_off(): void
    {
        config(['assessment.batch_enabled' => false]);

        $teacher = $this->teacher();
        AssessmentBatch::factory()->create(['teacher_id' => $teacher->id]);

        $this->actingAs($teacher)->get(route('assessments.batch.index'))->assertNotFound();
    }

    public function test_an_empty_list_says_so_and_offers_a_new_batch(): void
    {
        $this->actingAs($this->teacher())->get(route('assessments.batch.index'))
            ->assertOk()
            ->assertSee('No group assessments yet')
            ->assertSee(route('assessments.batch.create'), false);
    }

    // ── finding it at all ─────────────────────────────────────────────

    public function test_the_assessments_page_links_to_the_group_assessments_list(): void
    {
        $teacher = $this->teacher();

        $this->actingAs($teacher)->get(route('assessments.index'))
            ->assertOk()
            ->assertSee(route('assessments.batch.index'), false);
    }

    public function test_the_assessments_page_offers_no_group_link_while_the_flag_is_off(): void
    {
        config(['assessment.batch_enabled' => false]);

        $this->actingAs($this->teacher())->get(route('assessments.index'))
            ->assertOk()
            ->assertDontSee('Group assessments');
    }

    /** The batch she is trying to get back to is the one that is not finished. */
    public function test_a_teacher_with_an_unfinished_batch_is_told_so_on_the_assessments_page(): void
    {
        $teacher = $this->teacher();
        $batch = AssessmentBatch::factory()->create([
            'teacher_id' => $teacher->id,
            'title' => 'Reading group B',
            'status' => AssessmentBatch::STATUS_ANALYZING,
        ]);

        $this->actingAs($teacher)->get(route('assessments.index'))
            ->assertOk()
            ->assertSee('Reading group B')
            ->assertSee('still open')
            ->assertSee(route('assessments.batch.board', $batch), false);
    }

    public function test_a_finished_batch_is_not_advertised_as_unfinished(): void
    {
        $teacher = $this->teacher();
        AssessmentBatch::factory()->create([
            'teacher_id' => $teacher->id,
            'title' => 'Finished group',
            'status' => AssessmentBatch::STATUS_COMPLETED,
        ]);
        AssessmentBatch::factory()->create([
            'teacher_id' => $teacher->id,
            'title' => 'Stopped group',
            'status' => AssessmentBatch::STATUS_CANCELLED,
        ]);

        $this->actingAs($teacher)->get(route('assessments.index'))
            ->assertOk()
            ->assertDontSee('still open');
    }

    public function test_another_teachers_unfinished_batch_is_not_advertised(): void
    {
        $teacher = $this->teacher();
        $other = $this->otherTeacher();

        AssessmentBatch::factory()->create([
            'teacher_id' => $other->id,
            'title' => 'Not my group',
            'status' => AssessmentBatch::STATUS_ANALYZING,
        ]);

        $this->actingAs($teacher)->get(route('assessments.index'))
            ->assertOk()
            ->assertDontSee('Not my group')
            ->assertDontSee('still open');
    }

    public function test_the_teacher_nav_offers_the_group_assessments_list(): void
    {
        $teacher = $this->teacher();

        $this->actingAs($teacher)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('assessments.batch.index'), false);
    }

    public function test_the_nav_hides_the_group_assessments_list_while_the_flag_is_off(): void
    {
        config(['assessment.batch_enabled' => false]);

        $this->actingAs($this->teacher())->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('assessments.batch.index'), false);
    }
}
