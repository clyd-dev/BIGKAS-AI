<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Form4Builder;
use App\Services\PhilIriLevels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Form4Test extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true, 'email_verified_at' => now()]);
    }

    private function learnerFor(User $teacher): Learner
    {
        $school = School::create(['name' => 'Old Sagay ES']);
        $class = SchoolClass::create(['school_id' => $school->id, 'teacher_id' => $teacher->id,
            'grade_level' => 4, 'section' => 'Rizal', 'school_year' => '2026-2027', 'is_active' => true]);

        return Learner::factory()->create([
            'class_id' => $class->id, 'school_id' => $school->id, 'grade_level' => 4,
            'first_name' => 'Ana', 'last_name' => 'Cruz', 'birth_date' => now()->subYears(10)->toDateString(),
        ]);
    }

    private function assess(Learner $l, User $teacher, int $passageLevel, float $accuracy, string $lang = 'en', string $when = 'now'): Assessment
    {
        $material = ReadingMaterial::create(['title' => "L{$passageLevel}-" . uniqid(), 'content' => 'x', 'language' => $lang, 'grade_level' => $passageLevel]);
        $a = Assessment::create(['learner_id' => $l->id, 'material_id' => $material->id, 'assessor_id' => $teacher->id,
            'language' => $lang, 'status' => 'completed', 'assessed_at' => $when]);
        AssessmentResult::create(['assessment_id' => $a->id, 'accuracy_rate' => $accuracy, 'words_per_minute' => 80, 'reading_level' => 'instructional']);

        return $a;
    }

    public function test_word_reading_rule_matches_the_workbook(): void
    {
        $this->assertSame('independent', PhilIriLevels::wordReading(97.0));
        $this->assertSame('instructional', PhilIriLevels::wordReading(96.9));
        $this->assertSame('instructional', PhilIriLevels::wordReading(90.0));
        $this->assertSame('frustration', PhilIriLevels::wordReading(89.9));
        $this->assertNull(PhilIriLevels::wordReading(null));

        $this->assertSame('independent', PhilIriLevels::comprehension(80));
        $this->assertSame('instructional', PhilIriLevels::comprehension(79));
        $this->assertSame('instructional', PhilIriLevels::comprehension(59));
        $this->assertSame('frustration', PhilIriLevels::comprehension(58));
    }

    public function test_builder_fills_levels_started_and_dates(): void
    {
        $teacher = $this->user('teacher');
        $l = $this->learnerFor($teacher);

        $this->assess($l, $teacher, 3, 85.0, 'en', '2026-06-20');   // first = level started
        $this->assess($l, $teacher, 4, 92.0, 'en', '2026-08-15');
        $this->assess($l, $teacher, 4, 98.0, 'en', '2026-10-10');   // latest at level IV wins
        $this->assess($l, $teacher, 3, 99.0, 'fil', '2026-07-01');

        $form = Form4Builder::build($l);
        $en = $form['languages']['en']['levels'];

        $this->assertSame('Ana Cruz', $form['name']);
        $this->assertSame(10, $form['age']);
        $this->assertSame('Rizal', $form['section']);
        $this->assertSame('Old Sagay ES', $form['school']);
        $this->assertSame($teacher->name, $form['teacher']);

        $this->assertTrue($en[3]['started']);
        $this->assertFalse($en[4]['started']);
        $this->assertSame('frustration', $en[3]['word']);
        $this->assertSame('independent', $en[4]['word']);       // 98 (latest), not 92
        $this->assertSame('Oct 10, 2026', $en[4]['date']);
        $this->assertNull($en[5]['word']);

        $this->assertSame('independent', $form['languages']['fil']['levels'][3]['word']);
    }

    public function test_learner_without_assessments_gets_blank_form_for_both_languages(): void
    {
        $teacher = $this->user('teacher');
        $form = Form4Builder::build($this->learnerFor($teacher));

        $this->assertFalse($form['any_data']);
        $this->assertSame(['en', 'fil'], array_keys($form['languages']));
    }

    public function test_pages_pdf_and_access_rules(): void
    {
        $teacher = $this->user('teacher');
        $l = $this->learnerFor($teacher);
        $this->assess($l, $teacher, 3, 98.0);

        foreach ([$teacher, $this->user('admin')] as $u) {
            $this->actingAs($u)->get(route('learners.form4', $l))->assertOk()
                ->assertSee('Individual Summary Record')->assertSee('Ana Cruz');
            $this->actingAs($u)->get(route('learners.form4.print', $l))->assertOk()->assertSee('Oral Reading Observation Checklist');
            $this->actingAs($u)->get(route('learners.form4.pdf', $l))->assertOk()->assertHeader('content-type', 'application/pdf');
        }

        $this->actingAs($this->user('teacher'))->get(route('learners.form4', $l))->assertForbidden();
        $this->actingAs($this->user('parent'))->get(route('learners.form4', $l))->assertForbidden();
    }
}
