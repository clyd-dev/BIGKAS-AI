<?php

namespace Tests\Feature;

use App\Models\ClassReport;
use App\Models\DepedSubmission;
use App\Models\Learner;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Form2Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Form2Test extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true, 'email_verified_at' => now()]);
    }

    /** A Grade-$grade section whose teacher scored learners and submitted a pre-test report. */
    private function reportedSection(School $school, int $grade, string $name, array $scoresEn): SchoolClass
    {
        $teacher = $this->user('teacher');
        $class = SchoolClass::create([
            'school_id' => $school->id, 'teacher_id' => $teacher->id,
            'grade_level' => $grade, 'section' => $name, 'school_year' => '2026-2027', 'is_active' => true,
        ]);

        $rows = [];
        foreach ($scoresEn as $i => [$l, $inf, $c]) {
            $learner = Learner::factory()->create(['class_id' => $class->id, 'grade_level' => $grade]);
            $rows[$learner->id] = ['taken' => '1', 'literal' => $l, 'inferential' => $inf, 'critical' => $c];
        }
        Learner::factory()->create(['class_id' => $class->id, 'grade_level' => $grade]); // enrolled, not tested

        $this->actingAs($teacher)->post(route('screening.store'), ['period' => 'pre_test', 'language' => 'en', 'rows' => $rows]);
        $this->actingAs($teacher)->post(route('reports.submissions.store'), ['period' => 'pre_test']);

        return $class;
    }

    public function test_builder_consolidates_submitted_reports_and_flags_missing_sections(): void
    {
        $school = School::create(['name' => 'Test ES', 'division' => 'Negros Occidental', 'district' => 'Sagay', 'region' => 'VI']);
        $this->reportedSection($school, 4, 'Rizal', [[5, 5, 4], [1, 1, 1]]);   // 14 pass, 3 below
        $this->reportedSection($school, 4, 'Bonifacio', [[7, 7, 6]]);           // 20 pass
        SchoolClass::create(['school_id' => $school->id, 'teacher_id' => $this->user('teacher')->id,
            'grade_level' => 5, 'section' => 'Mabini', 'school_year' => '2026-2027', 'is_active' => true]); // no report

        $form = Form2Builder::build('2026-2027', 'pre_test');
        $en = $form['languages']['en'];

        $this->assertSame(['Grade 5 – Mabini'], $form['missing']);
        $this->assertSame('IV', $en['grades'][4]['roman']);
        $this->assertSame(2, count($en['grades'][4]['sections']));
        $this->assertSame(5, $en['total']['enrolment']); // (2 scored + 1 absent) + (1 scored + 1 absent)
        $this->assertSame(2, $en['total']['at_grade']);
        $this->assertSame(1, $en['total']['below']);
        $this->assertSame(2, $en['total']['not_tested']);
        $this->assertSame('Test ES', $form['school']['name']);
    }

    public function test_returned_reports_are_not_counted(): void
    {
        $school = School::create(['name' => 'Test ES']);
        $class = $this->reportedSection($school, 3, 'Luna', [[7, 7, 6]]);
        ClassReport::where('class_id', $class->id)->update(['status' => 'returned']);

        $form = Form2Builder::build('2026-2027', 'pre_test');
        $this->assertSame(0, $form['languages']['en']['total']['enrolment']);
        $this->assertSame(['Grade 3 – Luna'], $form['missing']);
    }

    public function test_principal_views_prints_downloads_and_records_submission(): void
    {
        $school = School::create(['name' => 'Test ES', 'division' => 'Negros Occidental', 'district' => 'Sagay', 'region' => 'VI', 'principal_name' => 'Dr. Principal']);
        $this->reportedSection($school, 6, 'Diamond', [[5, 5, 4]]);
        $admin = $this->user('admin');

        $this->actingAs($admin)->get(route('reports.form2.index'))
            ->assertOk()->assertSee('School Reading Profile')->assertSee('Test ES');
        $this->actingAs($admin)->get(route('reports.form2.print'))
            ->assertOk()->assertSee('Talaan ng Paaralan sa Pagbabasa')->assertSee('Dr. Principal');
        $this->actingAs($admin)->get(route('reports.form2.pdf'))
            ->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->actingAs($admin)->post(route('reports.form2.submit', ['school_year' => '2026-2027', 'period' => 'pre_test']), [
            'submitted_on' => now()->toDateString(), 'reference' => 'DepEd Div. Office #123',
        ])->assertRedirect();

        $sub = DepedSubmission::firstOrFail();
        $this->assertSame('DepEd Div. Office #123', $sub->reference);
        $this->assertSame(1, $sub->form_data['languages']['en']['total']['at_grade']);
        $this->actingAs($admin)->get(route('reports.form2.show', $sub))->assertOk()->assertSee('School Reading Profile');

        // future dates rejected
        $this->actingAs($admin)->post(route('reports.form2.submit', ['school_year' => '2026-2027', 'period' => 'pre_test']), [
            'submitted_on' => now()->addDay()->toDateString(),
        ])->assertSessionHasErrors('submitted_on');
    }

    public function test_only_admin_can_access_form2(): void
    {
        $teacher = $this->user('teacher');
        $this->actingAs($teacher)->get(route('reports.form2.index'))->assertForbidden();
        $this->actingAs($teacher)->post(route('reports.form2.submit'), [])->assertForbidden();
    }
}
