<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\LearnerMasterList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearnerMasterListTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $teacher;
    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school  = School::create(['name' => 'Old Sagay ES', 'division' => 'Sagay City']);
        $this->teacher = $this->user('teacher');
        $this->class   = SchoolClass::create(['school_id' => $this->school->id, 'teacher_id' => $this->teacher->id,
            'grade_level' => 4, 'section' => 'Rizal', 'school_year' => '2026-2027', 'is_active' => true]);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true, 'email_verified_at' => now()]);
    }

    private function learner(string $first, string $last, ?SchoolClass $class = null, int $grade = 4): Learner
    {
        $class ??= $this->class;

        return Learner::factory()->create(['first_name' => $first, 'last_name' => $last, 'class_id' => $class->id,
            'school_id' => $this->school->id, 'grade_level' => $grade]);
    }

    private function read(Learner $l, float $accuracy, ?float $comp, string $level, string $when, string $lang = 'en'): Assessment
    {
        $m = ReadingMaterial::create(['title' => uniqid('m'), 'content' => 'x', 'language' => $lang, 'grade_level' => 4]);
        $a = Assessment::create(['learner_id' => $l->id, 'material_id' => $m->id, 'assessor_id' => $this->teacher->id,
            'language' => $lang, 'status' => 'completed', 'assessed_at' => $when]);
        AssessmentResult::create(['assessment_id' => $a->id, 'accuracy_rate' => $accuracy, 'comprehension_score' => $comp,
            'words_per_minute' => 80, 'reading_level' => $level]);

        return $a;
    }

    public function test_it_lists_every_learner_by_section_with_levels_and_the_lower_overall_level(): void
    {
        $ana = $this->learner('Ana', 'Cruz');
        $this->learner('Ben', 'Abad');                       // never assessed
        $this->read($ana, 98.0, 60.0, 'instructional', '2026-09-01');   // oral independent, comp instructional

        $list = LearnerMasterList::build('2026-2027');
        $rows = $list['sections'][0]['rows'];

        $this->assertSame('Grade 4 - Rizal', $list['sections'][0]['label']);
        $this->assertSame(['ABAD, Ben', 'CRUZ, Ana'], array_column($rows, 'name'));   // sorted by surname

        $this->assertNull($rows[0]['languages']['en']);
        $this->assertSame('not_assessed', $rows[0]['progress']);

        $en = $rows[1]['languages']['en'];
        $this->assertSame('independent', $en['oral']);
        $this->assertSame('instructional', $en['comp']);
        $this->assertSame('instructional', $en['overall']);      // the weaker of the two
        $this->assertSame('one_reading', $rows[1]['progress']);
        $this->assertSame(1, $list['sections'][0]['summary']['assessed']);
        $this->assertSame(2, $list['totals']['learners']);
    }

    public function test_progress_compares_first_and_latest_reading_in_the_same_language(): void
    {
        $up   = $this->learner('Up', 'Zed');
        $down = $this->learner('Down', 'Yan');
        $this->read($up, 80, null, 'frustration', '2026-07-01');
        $this->read($up, 98, null, 'independent', '2026-10-01');
        $this->read($down, 98, null, 'independent', '2026-07-01');
        $this->read($down, 70, null, 'frustration', '2026-10-01');

        $s = LearnerMasterList::build('2026-2027')['sections'][0];
        $byName = collect($s['rows'])->keyBy('name');

        $this->assertSame('up', $byName['ZED, Up']['progress']);
        $this->assertSame('down', $byName['YAN, Down']['progress']);
        $this->assertSame(1, $s['summary']['up']);
        $this->assertSame(1, $s['summary']['down']);
    }

    public function test_filters_narrow_the_sections_and_a_learner_without_a_section_is_still_listed(): void
    {
        $other = SchoolClass::create(['school_id' => $this->school->id, 'teacher_id' => $this->user('teacher')->id,
            'grade_level' => 5, 'section' => 'Bonifacio', 'school_year' => '2026-2027', 'is_active' => true]);
        $this->learner('Eve', 'Dela', $other, 5);
        Learner::factory()->create(['first_name' => 'Lost', 'last_name' => 'Boy', 'class_id' => null,
            'school_id' => $this->school->id, 'grade_level' => 4]);

        $grade4 = LearnerMasterList::build('2026-2027', 4);
        $this->assertSame(['Grade 4 - Rizal', 'Grade 4 - No section yet'], array_column($grade4['sections'], 'label'));

        $oneSection = LearnerMasterList::build('2026-2027', null, $other->id, 'fil');
        $this->assertCount(1, $oneSection['sections']);
        $this->assertSame(['fil' => 'Filipino'], $oneSection['languages']);
    }

    public function test_only_the_principal_can_open_it_and_printing_is_logged(): void
    {
        $this->learner('Ana', 'Cruz');
        $admin = $this->user('admin');

        $this->actingAs($this->teacher)->get(route('reports.master-list.index'))->assertForbidden();
        $this->actingAs($this->user('parent'))->get(route('reports.master-list.print', ['school_year' => '2026-2027']))->assertForbidden();

        $this->actingAs($admin)->get(route('reports.master-list.index'))
            ->assertOk()->assertSee('CRUZ, Ana')->assertSee('Grade 4 - Rizal');

        $this->assertSame(0, ActivityLog::where('action', 'print_master_list')->count());   // viewing is not printing
        $this->actingAs($admin)->get(route('reports.master-list.print', ['school_year' => '2026-2027']))
            ->assertOk()->assertSee('CONFIDENTIAL')->assertSee('CRUZ, Ana')->assertSee('Old Sagay ES');
        $this->assertSame(1, ActivityLog::where('action', 'print_master_list')->count());

        $this->actingAs($admin)->get(route('reports.master-list.pdf', ['school_year' => '2026-2027']))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertSame(2, ActivityLog::where('action', 'print_master_list')->count());
    }

    private function otherSection(): SchoolClass
    {
        $other = SchoolClass::create(['school_id' => $this->school->id, 'teacher_id' => $this->user('teacher')->id,
            'grade_level' => 5, 'section' => 'Bonifacio', 'school_year' => '2026-2027', 'is_active' => true]);
        $this->learner('Eve', 'Dela', $other, 5);

        return $other;
    }

    public function test_a_teacher_gets_only_their_own_section_whatever_the_url_says(): void
    {
        $this->learner('Ana', 'Cruz');
        $other = $this->otherSection();

        $this->actingAs($this->teacher)->get(route('learners.master-list'))
            ->assertOk()->assertSee('CRUZ, Ana')->assertSee('Grade 4 - Rizal')
            ->assertDontSee('DELA, Eve')->assertDontSee('Bonifacio');

        // Forged grade / section / school-wide filters are ignored.
        $this->actingAs($this->teacher)->get(route('learners.master-list.print', ['class_id' => $other->id, 'grade' => 5]))
            ->assertOk()->assertSee('CRUZ, Ana')->assertDontSee('DELA, Eve');
        $this->actingAs($this->teacher)->get(route('learners.master-list.pdf', ['class_id' => $other->id]))->assertOk();

        $this->assertSame(2, ActivityLog::where('action', 'print_master_list')->count());
    }

    public function test_the_teacher_copy_is_closed_to_others_and_to_a_teacher_without_a_section(): void
    {
        $this->learner('Ana', 'Cruz');
        $this->otherSection();
        $unassigned = $this->user('teacher');

        $this->actingAs($this->user('admin'))->get(route('learners.master-list'))->assertForbidden();
        $this->actingAs($this->user('parent'))->get(route('learners.master-list'))->assertForbidden();

        // No section: sent back, never shown the school's learners.
        $this->actingAs($unassigned)->get(route('learners.master-list'))->assertRedirect(route('dashboard'));
        $this->actingAs($unassigned)->get(route('learners.master-list.print'))->assertRedirect(route('dashboard'));
    }

    public function test_a_section_id_that_matches_nothing_never_widens_to_the_whole_school(): void
    {
        $this->learner('Ana', 'Cruz');
        $this->otherSection();

        $list = LearnerMasterList::build('2026-2027', null, 0);

        $this->assertSame([], $list['sections']);
        $this->assertSame(0, $list['totals']['learners']);
    }
}
