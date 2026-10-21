<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentBatch;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesBatchAssessments;
use Tests\TestCase;

class BatchSetupTest extends TestCase
{
    use RefreshDatabase, MakesBatchAssessments;

    protected function setUp(): void
    {
        parent::setUp();
        config(['assessment.batch_enabled' => true]);
    }

    private function learnersOf(User $teacher): array
    {
        $classIds = $teacher->taughtClasses()->pluck('id');

        return Learner::whereIn('class_id', $classIds)->orderBy('id')->get()->all();
    }

    private function payload(array $learners, ReadingMaterial $default, array $entryOverrides = []): array
    {
        return [
            'title' => 'Morning group',
            'language' => 'en',
            'assessment_type' => 'oral_reading',
            'default_material_id' => $default->id,
            'school_class_id' => '',
            'entries' => array_map(
                fn ($l, $i) => array_merge(['learner_id' => $l->id, 'material_id' => ''], $entryOverrides[$i] ?? []),
                $learners,
                array_keys($learners)
            ),
        ];
    }

    public function test_the_setup_page_lists_only_learners_the_teacher_can_reach(): void
    {
        $teacher = $this->teacher();
        $other = $this->otherTeacher();
        $mine = $this->learnersOf($teacher)[0];
        $mine->update(['first_name' => 'Reachable', 'last_name' => 'Zebedee']);
        $theirs = $this->learnersOf($other)[0];
        $theirs->update(['first_name' => 'Unreachable', 'last_name' => 'Quimby']);

        // Same school proves nothing: access is per class taught or per pivot row.
        $this->assertFalse($teacher->taughtClasses()->where('id', $theirs->class_id)->exists());
        $this->assertFalse($teacher->learners()->where('learners.id', $theirs->id)->exists());

        $this->actingAs($teacher)->get(route('assessments.batch.create'))
            ->assertOk()
            ->assertSee('Reachable')
            ->assertDontSee('Unreachable')
            ->assertSee('Assessments');
    }

    public function test_a_valid_roster_redirects_to_the_board(): void
    {
        $teacher = $this->teacher();
        $material = ReadingMaterial::factory()->create();

        $response = $this->actingAs($teacher)
            ->post(route('assessments.batch.store'), $this->payload($this->learnersOf($teacher), $material));

        $batch = AssessmentBatch::firstOrFail();
        $response->assertRedirect(route('assessments.batch.board', $batch));
        $this->assertSame($teacher->id, $batch->teacher_id);
        $this->assertSame(3, Assessment::where('batch_id', $batch->id)->count());
    }

    public function test_an_invalid_roster_returns_to_the_form_with_the_input(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $default = ReadingMaterial::factory()->create();
        $wrongGrade = ReadingMaterial::factory()->create(['grade_level' => 6, 'title' => 'Far Grade Passage']);

        $response = $this->actingAs($teacher)
            ->from(route('assessments.batch.create'))
            ->post(route('assessments.batch.store'), $this->payload($learners, $default, [1 => ['material_id' => $wrongGrade->id]]));

        $response->assertRedirect(route('assessments.batch.create'))
            ->assertSessionHasErrors(['entries.1.material_id'])
            ->assertSessionDoesntHaveErrors(['entries.0.material_id', 'entries.2.material_id']);
        $this->assertSame(0, AssessmentBatch::count());
        $this->assertSame(0, Assessment::count());

        // Every child, the title and the choices survive.
        $old = session()->getOldInput();
        $this->assertSame('Morning group', $old['title']);
        $this->assertSame($default->id, (int) $old['default_material_id']);
        $this->assertSame(array_map(fn ($l) => $l->id, $learners), array_map(fn ($e) => (int) $e['learner_id'], $old['entries']));

        // The page rebuilds the roster and puts the message on the right child.
        $html = $this->actingAs($teacher)->get(route('assessments.batch.create'))->assertOk()->getContent();
        foreach ($learners as $i => $l) {
            $this->assertStringContainsString('name="entries[' . $i . '][learner_id]"', $html);
        }
$this->assertSame(1, substr_count($html, 'invalid-feedback d-block">This material is for a different grade level'));
        $this->assertMatchesRegularExpression('/data-entry="1"(?:(?!data-entry=").)*different grade level/s', $html);
    }

    public function test_another_teachers_learner_cannot_be_rostered(): void
    {
        $teacher = $this->teacher();
        $other = $this->otherTeacher();
        $theirs = $this->learnersOf($other)[0];

        $this->assertFalse($teacher->taughtClasses()->where('id', $theirs->class_id)->exists());
        $this->assertFalse($teacher->learners()->where('learners.id', $theirs->id)->exists());

        $this->actingAs($teacher)
            ->post(route('assessments.batch.store'), $this->payload([$theirs], ReadingMaterial::factory()->create()))
            ->assertSessionHasErrors(['entries.0.learner_id']);

        $this->assertSame(0, AssessmentBatch::count());
        $this->assertSame(0, Assessment::count());
    }

    public function test_an_admin_cannot_create_a_batch(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
        $teacher = $this->teacher();

        $this->actingAs($admin)->get(route('assessments.batch.create'))->assertForbidden();
        $this->actingAs($admin)
            ->post(route('assessments.batch.store'), $this->payload($this->learnersOf($teacher), ReadingMaterial::factory()->create()))
            ->assertForbidden();
        $this->assertSame(0, AssessmentBatch::count());
    }

    public function test_the_group_link_is_hidden_on_the_single_learner_page_when_the_flag_is_off(): void
    {
        $teacher = $this->teacher();
        $link = route('assessments.batch.create');

        $this->actingAs($teacher)->get(route('assessments.create'))->assertOk()->assertSee($link, false);

        config(['assessment.batch_enabled' => false]);
        $this->actingAs($teacher)->get(route('assessments.create'))->assertOk()->assertDontSee($link, false);
    }
}
