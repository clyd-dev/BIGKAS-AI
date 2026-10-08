<?php

namespace Tests\Feature;

use App\Models\Intervention;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every admin/teacher page renders the one shared <x-page-header>, so a header
 * cannot drift back to a hand-rolled layout or lose its back link unnoticed.
 */
class PageHeaderTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true, 'email_verified_at' => now()]);
    }

    private function learnerFor(User $teacher): Learner
    {
        $school = School::firstOrCreate(['name' => 'Header Test School'], ['division' => 'Sagay City']);
        $class = SchoolClass::create([
            'school_id' => $school->id,
            'teacher_id' => $teacher->id,
            'grade_level' => 4,
            'section' => 'Hdr-' . $teacher->id,
            'school_year' => '2026-2027',
        ]);

        return Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 4]);
    }

    private function material(): ReadingMaterial
    {
        return ReadingMaterial::create([
            'title' => 'Header Passage',
            'content' => 'The cat sat on the mat and looked at the bird.',
            'language' => 'en',
            'grade_level' => 4,
            'difficulty' => 'easy',
            'type' => ReadingMaterial::TYPE_ORAL_READING,
            'word_count' => 10,
        ]);
    }

    /** Pages reachable straight from the nav: header, but no back link. */
    public function test_top_level_pages_have_a_header_and_no_back_link(): void
    {
        $admin = $this->user('admin');

        foreach ([
            'dashboard', 'learners.index', 'assessments.index', 'materials.index',
            'interventions.index', 'reports.index', 'messages.index', 'notifications.index',
            'profile.show', 'admin.users', 'admin.schools', 'admin.logs', 'admin.settings',
            'admin.classes', 'admin.phil-iri', 'screening.index',
        ] as $route) {
            $html = $this->actingAs($admin)->get(route($route))->assertOk()->getContent();

            $this->assertStringContainsString('pg-head', $html, "$route is missing the shared page header");
            $this->assertStringNotContainsString('pg-back', $html, "$route is in the nav and should not show a back link");
        }
    }

    /** Drill-down pages name where the back link goes, never a bare "Back". */
    public function test_drill_down_pages_have_a_named_back_link(): void
    {
        $teacher = $this->user('teacher');
        $learner = $this->learnerFor($teacher);
        $material = $this->material();
        $intervention = Intervention::create([
            'name' => 'Sound blending drill',
            'description' => 'Blend the sounds.',
            'instructions' => 'Say each sound, then blend them together.',
            'target_weakness' => 1,
            'grade_level' => 4,
            'type' => 'activity',
            'is_active' => true,
        ]);

        $cases = [
            ['learners.show', [$learner], 'Learners'],
            ['learners.edit', [$learner], 'Learner profile'],
            ['learners.create', [], 'Learners'],
            ['learners.progress', [$learner], 'Learner profile'],
            ['learners.form4', [$learner], 'Learner profile'],
            ['assessments.create', [], 'Assessments'],
            ['assessments.start', [$learner], 'Learner profile'],
            ['materials.show', [$material], 'Materials'],
            ['materials.edit', [$material], 'Material'],
            ['materials.create', [], 'Materials'],
            ['interventions.show', [$intervention], 'Interventions'],
            ['interventions.edit', [$intervention], 'Intervention'],
            ['interventions.create', [], 'Interventions'],
            ['messages.create', [], 'Messages'],
            ['practice.phonemic', [], 'Practice Center'],
            ['practice.sight-words', [], 'Practice Center'],
            ['practice.reading', [], 'Practice Center'],
            ['reports.learner', [$learner], 'Learner profile'],
            ['reports.submissions.index', [], 'Reports'],
            ['reports.submissions.create', [], 'My Reports'],
        ];

        foreach ($cases as [$route, $params, $label]) {
            $response = $this->actingAs($teacher)->get(route($route, $params));
            $this->assertSame(200, $response->status(), "$route returned {$response->status()}");
            $html = $response->getContent();

            $this->assertStringContainsString('pg-back', $html, "$route is missing a back link");
            $this->assertStringContainsString(">{$label}</span>", $html, "$route back link should say \"$label\"");
            $this->assertStringNotContainsString('>Back</span>', $html, "$route still uses a bare \"Back\" label");
        }
    }

    /** Admin-only drill-downs (DepEd Form 2 is a school-level report). */
    public function test_admin_drill_down_pages_have_a_named_back_link(): void
    {
        $admin = $this->user('admin');

        foreach ([['reports.form2.index', 'Reports'], ['reports.submissions.index', 'Reports']] as [$route, $label]) {
            $html = $this->actingAs($admin)->get(route($route))->assertOk()->getContent();

            $this->assertStringContainsString('pg-back', $html, "$route is missing a back link");
            $this->assertStringContainsString(">{$label}</span>", $html, "$route back link should say \"$label\"");
        }
    }

    public function test_header_renders_title_subtitle_and_actions(): void
    {
        $teacher = $this->user('teacher');
        $learner = $this->learnerFor($teacher);

        $this->actingAs($teacher)->get(route('learners.progress', $learner))->assertOk()
            ->assertSee('pg-title', false)
            ->assertSee($learner->full_name)
            ->assertSee('Progress')
            ->assertSee('pg-actions', false)
            ->assertSee('Full Report');
    }

    /** The layout already prints flash messages; a page must not repeat them. */
    public function test_flash_message_is_not_rendered_twice(): void
    {
        $admin = $this->user('admin');

        foreach (['admin.users', 'admin.schools', 'admin.settings'] as $route) {
            $html = $this->actingAs($admin)
                ->withSession(['success' => 'Saved it.'])
                ->get(route($route))->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, 'Saved it.'), "$route shows the flash message twice");
        }
    }
}
