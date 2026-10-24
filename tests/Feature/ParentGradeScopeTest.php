<?php

namespace Tests\Feature;

use App\Models\Learner;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * A parent account belongs to the grade(s) of their children. Only teachers of
 * those grades (and the admin) may list or link that parent.
 */
class ParentGradeScopeTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, array $extra = []): User
    {
        return User::factory()->create($extra + ['role' => $role, 'is_active' => true, 'email_verified_at' => now()]);
    }

    private function teacherOfGrade(int $grade): array
    {
        $school  = School::firstOrCreate(['name' => 'Old Sagay ES']);
        $teacher = $this->user('teacher');
        $class   = SchoolClass::create(['school_id' => $school->id, 'teacher_id' => $teacher->id,
            'grade_level' => $grade, 'section' => "G{$grade}", 'school_year' => '2026-2027', 'is_active' => true]);

        return [$teacher, $class];
    }

    private function parentOfGrades(array $grades): User
    {
        $parent = $this->user('parent');
        $parent->syncParentGrades($grades);

        return $parent;
    }

    private function names($query): array
    {
        return $query->pluck('users.id')->sort()->values()->all();
    }

    public function test_a_teacher_only_sees_parents_of_their_own_grade(): void
    {
        [$teacher4] = $this->teacherOfGrade(4);
        $this->teacherOfGrade(3);

        $four    = $this->parentOfGrades([4]);
        $three   = $this->parentOfGrades([3]);
        $both    = $this->parentOfGrades([3, 4]);
        $noGrade = $this->parentOfGrades([]);

        $seen = $this->names(User::linkableParentsFor($teacher4));

        $this->assertSame([$four->id, $both->id], $seen);
        $this->assertNotContains($three->id, $seen);
        $this->assertNotContains($noGrade->id, $seen);
    }

    public function test_the_admin_sees_every_parent(): void
    {
        $admin = $this->user('admin');
        $a = $this->parentOfGrades([3]);
        $b = $this->parentOfGrades([]);
        $this->user('teacher');   // never listed as a parent

        $this->assertSame([$a->id, $b->id], $this->names(User::linkableParentsFor($admin)));
    }

    public function test_a_parent_already_linked_to_a_learner_the_teacher_can_access_stays_visible(): void
    {
        [$teacher, $class] = $this->teacherOfGrade(4);
        $parent  = $this->parentOfGrades([]);
        $learner = Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 4]);
        $learner->users()->attach($parent->id, ['relationship' => 'parent']);

        $this->assertContains($parent->id, $this->names(User::linkableParentsFor($teacher)));
    }

    public function test_a_teacher_cannot_link_a_parent_from_another_grade_by_posting_the_id(): void
    {
        [$teacher, $class] = $this->teacherOfGrade(3);
        $learner = Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 3]);
        $grade4  = $this->parentOfGrades([4]);

        $this->actingAs($teacher)->put(route('learners.update', $learner), [
            'first_name' => $learner->first_name, 'last_name' => $learner->last_name,
            'grade_level' => 3, 'class_id' => $class->id, 'parent_ids' => [$grade4->id],
        ])->assertForbidden();

        $this->assertFalse($learner->users()->where('users.id', $grade4->id)->exists());
    }

    public function test_linking_a_parent_adds_the_childs_grade_to_that_parent(): void
    {
        [$teacher, $class] = $this->teacherOfGrade(3);
        $learner = Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 3]);
        $parent  = $this->parentOfGrades([4, 3]);   // assigned to both grades up front

        $this->actingAs($teacher)->put(route('learners.update', $learner), [
            'first_name' => $learner->first_name, 'last_name' => $learner->last_name,
            'grade_level' => 3, 'class_id' => $class->id, 'parent_ids' => [$parent->id],
        ])->assertRedirect();

        $this->assertTrue($learner->users()->where('users.id', $parent->id)->exists());
        $this->assertSame([3, 4], $parent->fresh()->grades());
    }

    public function test_only_real_parent_accounts_can_be_linked(): void
    {
        [$teacher, $class] = $this->teacherOfGrade(3);
        $learner = Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 3]);
        $other   = $this->user('teacher');

        $this->actingAs($teacher)->put(route('learners.update', $learner), [
            'first_name' => $learner->first_name, 'last_name' => $learner->last_name,
            'grade_level' => 3, 'class_id' => $class->id, 'parent_ids' => [$other->id],
        ])->assertSessionHasErrors('parent_ids.0');
    }

    public function test_the_import_sheet_assigns_grades_and_the_matched_childs_grade_is_added(): void
    {
        $admin  = $this->user('admin');
        $school = School::firstOrCreate(['name' => 'Old Sagay ES']);
        $learner = Learner::factory()->create(['grade_level' => 4, 'lrn' => '123456789012']);

        $csv = "Parent Name,Grade Level,Learner LRN\n"
             . "Single Grade,Grade 4,\n"
             . "Two Grades,\"3 & 4\",\n"
             . "Sheet Missing Child Grade,3,123456789012\n"
             . "No Grade,,\n";
        $file = UploadedFile::fake()->createWithContent('parents.csv', $csv);

        $response = $this->actingAs($admin)->post(route('admin.users.import.preview'), ['parents_file' => $file])->assertOk();
        $rows = collect($response->viewData('importRows'));

        $this->assertSame([4], $rows[0]['grades']);
        $this->assertSame([3, 4], $rows[1]['grades']);
        $this->assertSame([3, 4], $rows[2]['grades']);       // sheet said 3, the child is in 4
        $this->assertNotNull($rows[2]['grade_note']);
        $this->assertSame([], $rows[3]['grades']);

        $this->actingAs($admin)->post(route('admin.users.import.confirm'), ['rows' => [0, 1, 2, 3]])->assertOk();

        $this->assertSame([3, 4], User::byEmail('Two Grades')->first()->grades());
        $this->assertSame([3, 4], User::byEmail('Sheet Missing Child Grade')->first()->grades());
        $this->assertSame([], User::byEmail('No Grade')->first()->grades());
    }

    public function test_the_admin_can_filter_parents_by_grade_and_see_their_children(): void
    {
        $admin  = $this->user('admin');
        $three  = $this->parentOfGrades([3]);
        $four   = $this->parentOfGrades([4]);
        $none   = $this->parentOfGrades([]);
        $child  = Learner::factory()->create(['grade_level' => 3]);
        $child->users()->attach($three->id, ['relationship' => 'parent']);

        $page = $this->actingAs($admin)->get(route('admin.users', ['grade' => 3]))->assertOk();
        $page->assertSee($three->name)->assertDontSee($four->name)->assertDontSee($none->name);
        $page->assertSee($child->full_name);   // who this parent is assigned to

        $this->actingAs($admin)->get(route('admin.users', ['grade' => 'none']))->assertOk()
            ->assertSee($none->name)->assertDontSee($three->name);
    }

    public function test_the_admin_can_set_a_parents_grades_from_the_edit_form(): void
    {
        $admin  = $this->user('admin');
        $parent = $this->parentOfGrades([3]);

        $this->actingAs($admin)->put(route('admin.users.update', $parent), [
            'role' => 'parent', 'sync_grades' => 1, 'grades' => [4, 5],
        ])->assertRedirect();

        $this->assertSame([4, 5], $parent->fresh()->grades());
    }
}
