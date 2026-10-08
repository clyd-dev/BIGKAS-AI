<?php

namespace Tests\Feature;

use App\Models\ClassReport;
use App\Models\GstResult;
use App\Models\Learner;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\GstScoring;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class GstScreeningTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true, 'email_verified_at' => now()]);
    }

    private function section(User $teacher, int $grade = 5): SchoolClass
    {
        $school = School::create(['name' => 'Test ES']);

        return SchoolClass::create([
            'school_id' => $school->id, 'teacher_id' => $teacher->id,
            'grade_level' => $grade, 'section' => 'Rizal', 'school_year' => '2026-2027', 'is_active' => true,
        ]);
    }

    private function learner(SchoolClass $class, string $first, string $last): Learner
    {
        return Learner::factory()->create([
            'class_id' => $class->id, 'grade_level' => $class->grade_level,
            'first_name' => $first, 'last_name' => $last,
        ]);
    }

    // ── Scoring rule ──

    public function test_scoring_rule_matches_form_1a_1b(): void
    {
        $this->assertSame(20, GstScoring::maxScore());
        $this->assertSame('at_grade_level', GstScoring::classify(14));
        $this->assertSame('needs_assessment', GstScoring::classify(13));
        $this->assertNull(GstScoring::startingLevel(5, 14));   // discontinue
        $this->assertSame(3, GstScoring::startingLevel(5, 13)); // 8..13 -> grade - 2
        $this->assertSame(3, GstScoring::startingLevel(5, 8));
        $this->assertSame(2, GstScoring::startingLevel(5, 7));  // 0..7 -> grade - 3
        $this->assertSame(0, GstScoring::startingLevel(3, 7));  // floors at Kindergarten
        $this->assertSame('Kindergarten', GstScoring::startingLevelLabel(0));
        $this->assertSame('Discontinue', GstScoring::startingLevelLabel(null));
    }

    // ── Teacher entry ──

    public function test_teacher_saves_scores_and_server_computes_result(): void
    {
        $teacher = $this->user('teacher');
        $class = $this->section($teacher);
        $a = $this->learner($class, 'Ana', 'Cruz');
        $b = $this->learner($class, 'Ben', 'Reyes');
        $c = $this->learner($class, 'Cy', 'Lopez');

        $this->actingAs($teacher)->post(route('screening.store'), [
            'period' => 'pre_test', 'language' => 'en',
            'rows' => [
                $a->id => ['taken' => '1', 'literal' => 5, 'inferential' => 5, 'critical' => 4], // 14
                $b->id => ['taken' => '1', 'literal' => 3, 'inferential' => 3, 'critical' => 3], // 9
                $c->id => ['taken' => '0', 'literal' => 7, 'inferential' => 7, 'critical' => 6], // absent: ignored
            ],
        ])->assertRedirect();

        $ra = GstResult::where('learner_id', $a->id)->firstOrFail();
        $this->assertSame(14, $ra->total_score);
        $this->assertSame('at_grade_level', $ra->classification);
        $this->assertNull($ra->starting_level);

        $rb = GstResult::where('learner_id', $b->id)->firstOrFail();
        $this->assertSame(9, $rb->total_score);
        $this->assertSame(3, $rb->starting_level);

        $rc = GstResult::where('learner_id', $c->id)->firstOrFail();
        $this->assertFalse($rc->test_taken);
        $this->assertNull($rc->total_score);

        $summary = GstResult::summarize($class, 'pre_test')['en'];
        $this->assertSame([3, 2, 1, 1, 1], [$summary['enrolment'], $summary['tested'], $summary['at_grade'], $summary['below'], $summary['not_tested']]);
    }

    public function test_item_limits_are_enforced_and_resaving_updates(): void
    {
        $teacher = $this->user('teacher');
        $class = $this->section($teacher);
        $a = $this->learner($class, 'Ana', 'Cruz');

        $this->actingAs($teacher)->post(route('screening.store'), [
            'period' => 'pre_test', 'language' => 'fil',
            'rows' => [$a->id => ['taken' => '1', 'literal' => 8, 'inferential' => 1, 'critical' => 1]],
        ])->assertSessionHasErrors('rows.*.literal');
        $this->assertSame(0, GstResult::count());

        foreach ([[7, 7, 6], [1, 1, 1]] as [$l, $i, $k]) {
            $this->actingAs($teacher)->post(route('screening.store'), [
                'period' => 'pre_test', 'language' => 'fil',
                'rows' => [$a->id => ['taken' => '1', 'literal' => $l, 'inferential' => $i, 'critical' => $k]],
            ]);
        }
        $this->assertSame(1, GstResult::count());
        $this->assertSame(3, GstResult::first()->total_score);
    }

    public function test_teacher_cannot_score_other_sections_or_ineligible_grades(): void
    {
        $teacher = $this->user('teacher');
        $mine = $this->section($teacher);
        $other = $this->section($this->user('teacher'));
        $stranger = $this->learner($other, 'Zed', 'Other');

        $this->actingAs($teacher)->post(route('screening.store'), [
            'period' => 'pre_test', 'language' => 'fil',
            'rows' => [$stranger->id => ['taken' => '1', 'literal' => 1, 'inferential' => 1, 'critical' => 1]],
        ]);
        $this->assertSame(0, GstResult::count());

        $g1 = $this->user('teacher');
        $this->section($g1, 1);
        $this->actingAs($g1)->get(route('screening.index'))->assertOk()->assertSee('Grades 3 to 6');
        $this->actingAs($g1)->post(route('screening.store'), ['period' => 'pre_test', 'language' => 'fil', 'rows' => [1 => []]])->assertForbidden();
    }

    public function test_import_reads_form_1a_1b_class_profile_into_the_grid(): void
    {
        $teacher = $this->user('teacher');
        $class = $this->section($teacher);
        $this->learner($class, 'Ana', 'Cruz');
        $this->learner($class, 'Ben', 'Reyes');

        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('CLASS PROFILE');
        // English: taken in G, correct answers in I/K/M. Rows start at 10.
        $sheet->setCellValue('B10', 'Cruz, Ana');   $sheet->setCellValue('G10', 'YES');
        $sheet->setCellValue('I10', 5); $sheet->setCellValue('K10', 5); $sheet->setCellValue('M10', 4);
        $sheet->setCellValue('B11', 'Ben Reyes');   $sheet->setCellValue('G11', 'NO');
        $sheet->setCellValue('B12', 'Nobody Here'); $sheet->setCellValue('G12', 'YES');
        $path = tempnam(sys_get_temp_dir(), 'gst') . '.xlsx';
        (new Xlsx($book))->save($path);

        $this->actingAs($teacher)->post(route('screening.import'), [
            'period' => 'pre_test', 'language' => 'en',
            'file' => new UploadedFile($path, 'form1b.xlsx', null, null, true),
        ])->assertOk()
            ->assertSee('Nobody Here')            // unmatched name reported
            ->assertSee('2 learner(s) read from the file');

        $this->assertSame(0, GstResult::count()); // nothing saved until the teacher presses Save
    }

    // ── Admin read-only ──

    public function test_admin_can_view_but_not_enter_scores(): void
    {
        $teacher = $this->user('teacher');
        $class = $this->section($teacher);
        $this->learner($class, 'Ana', 'Cruz');
        $admin = $this->user('admin');

        $this->actingAs($admin)->get(route('screening.index'))->assertOk()->assertSee('Grade 5 – Rizal');
        $this->actingAs($admin)->get(route('screening.section', $class))->assertOk()->assertSee('Cruz');
        $this->actingAs($admin)->post(route('screening.store'), [])->assertForbidden();
        $this->actingAs($admin)->post(route('screening.import'), [])->assertForbidden();
        $this->actingAs($teacher)->get(route('screening.section', $class))->assertForbidden();
    }

    // ── Report snapshot ──

    public function test_report_snapshot_includes_screening_counts(): void
    {
        $teacher = $this->user('teacher');
        $class = $this->section($teacher);
        $a = $this->learner($class, 'Ana', 'Cruz');

        $this->actingAs($teacher)->post(route('screening.store'), [
            'period' => 'pre_test', 'language' => 'fil',
            'rows' => [$a->id => ['taken' => '1', 'literal' => 7, 'inferential' => 7, 'critical' => 6]],
        ]);
        $this->actingAs($teacher)->post(route('reports.submissions.store'), ['period' => 'pre_test']);

        $snap = ClassReport::firstOrFail()->snapshot;
        $this->assertSame(1, $snap['screening']['fil']['at_grade']);
        $this->assertSame(0, $snap['screening']['en']['tested']);
    }
}
