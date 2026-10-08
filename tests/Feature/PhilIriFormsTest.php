<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\GstResult;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Form3Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhilIriFormsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true, 'email_verified_at' => now()]);
    }

    private function section(User $teacher, int $grade = 4, string $name = 'Rizal'): SchoolClass
    {
        $school = School::firstOrCreate(['name' => 'Old Sagay ES']);

        return SchoolClass::create(['school_id' => $school->id, 'teacher_id' => $teacher->id,
            'grade_level' => $grade, 'section' => $name, 'school_year' => '2026-2027', 'is_active' => true]);
    }

    private function completed(Learner $l, User $teacher, string $lang = 'en'): Assessment
    {
        $m = ReadingMaterial::create(['title' => 'The Kite', 'content' => 'x', 'language' => $lang, 'grade_level' => 4, 'word_count' => 100]);
        $a = Assessment::create(['learner_id' => $l->id, 'material_id' => $m->id, 'assessor_id' => $teacher->id,
            'language' => $lang, 'status' => 'completed', 'assessed_at' => '2026-10-01']);
        AssessmentResult::create(['assessment_id' => $a->id, 'accuracy_rate' => 96, 'words_per_minute' => 50,
            'omissions' => 2, 'substitutions' => 1, 'insertions' => 0, 'repetitions' => 1, 'error_count' => 3, 'reading_level' => 'instructional']);

        return $a;
    }

    // ── Hub ──

    public function test_hub_lists_real_forms_and_paginated_sections_without_old_fake_forms(): void
    {
        $teacher = $this->user('teacher');
        foreach (range(1, 12) as $i) {
            $this->section($teacher, 3 + $i % 4, "Sec{$i}");
        }

        $this->actingAs($this->user('admin'))->get(route('admin.phil-iri'))
            ->assertOk()
            ->assertSee('Phil-IRI Forms')
            ->assertSee('Group Screening Test Class Record')
            ->assertSee('School Reading Profile')
            ->assertSee('Grade Level Passage Rating Sheet')
            ->assertSee('Individual Summary Record')
            ->assertSee('Showing 1–10 of 12')
            ->assertDontSee('Form 4 &mdash; School Reading Profile', false);
    }

    // ── Form 1A / 1B class record ──

    public function test_form_1a_1b_record_print_and_pdf_with_access_rules(): void
    {
        $teacher = $this->user('teacher');
        $class = $this->section($teacher);
        $girl = Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 4, 'first_name' => 'Ana', 'last_name' => 'Cruz', 'gender' => 'female']);
        $boy = Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 4, 'first_name' => 'Ben', 'last_name' => 'Reyes', 'gender' => 'male']);
        foreach ([[$girl, 5, 5, 4], [$boy, 2, 2, 2]] as [$l, $a, $b, $c]) {
            GstResult::create(['learner_id' => $l->id, 'class_id' => $class->id, 'school_year' => '2026-2027', 'period' => 'pre_test',
                'language' => 'fil', 'test_level' => 4, 'test_taken' => true, 'literal_correct' => $a, 'inferential_correct' => $b,
                'critical_correct' => $c])->applyScoring()->save();
        }

        $params = ['period' => 'pre_test', 'language' => 'fil'];
        foreach ([$teacher, $this->user('admin')] as $u) {
            $this->actingAs($u)->get(route('screening.record.print', [$class] + $params))->assertOk()
                ->assertSee('Talaan ng Pangkatang Pagtatasa ng Klase')
                ->assertSee('Cruz, Ana')->assertSee('14 / 20')->assertSee('Discontinue')->assertSee('Grade 1');
            $this->actingAs($u)->get(route('screening.record.pdf', [$class] + $params))->assertOk()->assertHeader('content-type', 'application/pdf');
        }
        $this->actingAs($this->user('teacher'))->get(route('screening.record.print', [$class] + $params))->assertForbidden();

        $this->actingAs($teacher)->get(route('screening.record.print', [$class, 'period' => 'pre_test', 'language' => 'en']))
            ->assertOk()->assertSee('Screening Test Class Reading Record');
    }

    // ── Form 3A / 3B ──

    public function test_form3_builder_computes_time_miscues_and_word_reading_level(): void
    {
        $teacher = $this->user('teacher');
        $class = $this->section($teacher);
        $l = Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 4, 'first_name' => 'Ana', 'last_name' => 'Cruz']);
        $form = Form3Builder::build($this->completed($l, $teacher, 'fil'));

        $this->assertSame('3A', $form['formNo']);
        $this->assertSame('IV', $form['level']);
        $this->assertSame(100, $form['words']);
        $this->assertSame([2, 0], [$form['minutes'], $form['seconds']]);  // 100 words at 50 wpm = 2:00
        $this->assertSame(50.0, $form['wpm']);
        $this->assertSame(4, $form['total_miscues']);                     // omission 2 + substitution 1 + repetition 1
        $this->assertSame(96.0, $form['word_score']);
        $this->assertSame('instructional', $form['word_level']);
        $this->assertNull($form['miscues'][0][2]);                        // mispronunciation not recorded
    }

    public function test_form3_pages_pdf_and_access(): void
    {
        $teacher = $this->user('teacher');
        $class = $this->section($teacher);
        $l = Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 4, 'first_name' => 'Ana', 'last_name' => 'Cruz']);
        $a = $this->completed($l, $teacher, 'en');

        foreach ([$teacher, $this->user('admin')] as $u) {
            $this->actingAs($u)->get(route('assessments.form3', $a))->assertOk()
                ->assertSee('Grade Level Passage Rating Sheet')->assertSee('The Kite')->assertSee('Instructional');
            $this->actingAs($u)->get(route('assessments.form3.pdf', $a))->assertOk()->assertHeader('content-type', 'application/pdf');
        }
        $this->actingAs($this->user('teacher'))->get(route('assessments.form3', $a))->assertForbidden();

        $a->update(['status' => 'pending']);
        $this->actingAs($teacher)->get(route('assessments.form3', $a))->assertNotFound();
    }

    public function test_history_page_offers_form3_button(): void
    {
        $teacher = $this->user('teacher');
        $class = $this->section($teacher);
        $l = Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 4]);
        $a = $this->completed($l, $teacher, 'fil');

        $this->actingAs($this->user('admin'))->get(route('assessments.learner-history', $l))
            ->assertOk()->assertSee('Form 3A')->assertSee(route('assessments.form3', $a));
    }
}
