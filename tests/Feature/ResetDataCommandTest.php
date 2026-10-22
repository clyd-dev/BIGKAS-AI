<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Assessment;
use App\Models\Badge;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Clearing the pilot data before the system is populated with real learners.
 *
 * The only things that must survive are the named admin accounts and the
 * seeded content (passages, badges, interventions, practice items, settings).
 * Getting this wrong is unrecoverable, so the command shows the plan unless
 * told otherwise, and every guard below is a case that would otherwise have
 * destroyed something it was never asked to touch.
 */
class ResetDataCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $keeper;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->keeper = User::factory()->create([
            'name' => 'Real Admin', 'email' => 'keep@example.com',
            'role' => 'admin', 'is_active' => true, 'email_verified_at' => now(),
        ]);
    }

    /** A school, a class, a teacher, a learner, an assessment and a log entry. */
    private function seedPilotData(): array
    {
        $school = School::firstOrCreate(['name' => 'Pilot School'], ['address' => 'Sagay', 'division' => 'Sagay']);
        $teacher = User::factory()->create(['role' => 'teacher', 'is_active' => true, 'email_verified_at' => now()]);
        $class = SchoolClass::create([
            'school_id' => $school->id, 'teacher_id' => $teacher->id,
            'grade_level' => 3, 'section' => 'Fake', 'school_year' => '2026-2027',
        ]);
        $learner = Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 3]);
        $material = ReadingMaterial::create([
            'title' => 'Seeded Passage', 'content' => 'The dog ran.', 'language' => 'en',
            'grade_level' => 3, 'difficulty' => 'easy',
            'type' => ReadingMaterial::TYPE_ORAL_READING, 'word_count' => 3,
        ]);
        $assessment = Assessment::create([
            'learner_id' => $learner->id, 'material_id' => $material->id, 'assessor_id' => $teacher->id,
            'language' => 'en', 'assessment_type' => Assessment::TYPE_ORAL_READING,
            'status' => Assessment::STATUS_COMPLETED,
        ]);
        ActivityLog::create([
            'user_id' => $teacher->id, 'action' => 'login', 'description' => 'fake',
            'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'created_at' => now(),
        ]);
        Badge::create([
            'slug' => 'first-read', 'name' => 'First Read', 'description' => 'seeded',
            'icon' => '⭐', 'color' => 'warning', 'category' => 'assessment',
            'criteria' => ['type' => 'assessment_count', 'value' => 1],
        ]);

        Storage::disk('public')->put('assessments/audio/fake.webm', 'x');
        Storage::disk('public')->put('practice/audio/fake.webm', 'x');

        return compact('school', 'teacher', 'class', 'learner', 'material', 'assessment');
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->seedPilotData();

        $this->artisan('bigkas:reset-data --keep=keep@example.com --dry-run')->assertExitCode(0);

        $this->assertDatabaseCount('learners', 1);
        $this->assertDatabaseCount('assessments', 1);
        $this->assertSame(2, User::count());
        Storage::disk('public')->assertExists('assessments/audio/fake.webm');
    }

    public function test_it_clears_pilot_data_and_keeps_the_named_admin(): void
    {
        $this->seedPilotData();

        $this->artisan('bigkas:reset-data --keep=keep@example.com --force')->assertExitCode(0);

        $this->assertDatabaseCount('learners', 0);
        $this->assertDatabaseCount('assessments', 0);
        $this->assertDatabaseCount('classes', 0);
        $this->assertDatabaseCount('activity_logs', 0);

        $this->assertSame(1, User::count(), 'Only the named admin should remain.');
        $this->assertSame('keep@example.com', User::sole()->email);
    }

    public function test_seeded_content_survives(): void
    {
        $this->seedPilotData();

        $this->artisan('bigkas:reset-data --keep=keep@example.com --force')->assertExitCode(0);

        $this->assertDatabaseCount('reading_materials', 1);
        $this->assertDatabaseCount('badges', 1);
        $this->assertDatabaseCount('schools', 1);
    }

    public function test_wipe_content_also_clears_the_catalogue(): void
    {
        $this->seedPilotData();

        $this->artisan('bigkas:reset-data --keep=keep@example.com --wipe-content --force')->assertExitCode(0);

        $this->assertDatabaseCount('reading_materials', 0);
        $this->assertDatabaseCount('badges', 0);
    }

    public function test_recordings_are_deleted_with_the_assessments(): void
    {
        $this->seedPilotData();

        $this->artisan('bigkas:reset-data --keep=keep@example.com --force')->assertExitCode(0);

        Storage::disk('public')->assertMissing('assessments/audio/fake.webm');
        Storage::disk('public')->assertMissing('practice/audio/fake.webm');
    }

    public function test_keep_files_leaves_the_recordings_alone(): void
    {
        $this->seedPilotData();

        $this->artisan('bigkas:reset-data --keep=keep@example.com --keep-files --force')->assertExitCode(0);

        Storage::disk('public')->assertExists('assessments/audio/fake.webm');
    }

    /** An unknown address would wipe every account, including the real admins. */
    public function test_it_refuses_an_email_that_matches_no_account(): void
    {
        $this->seedPilotData();

        $this->artisan('bigkas:reset-data --keep=keep@example.com,typo@example.com --force')
            ->expectsOutputToContain('typo@example.com')
            ->assertExitCode(1);

        $this->assertDatabaseCount('learners', 1); // a wrong keep address must delete nothing
    }

    public function test_it_refuses_to_keep_an_account_that_is_not_an_admin(): void
    {
        $data = $this->seedPilotData();

        $this->artisan('bigkas:reset-data --keep='.$data['teacher']->email.' --force')->assertExitCode(1);

        $this->assertDatabaseCount('learners', 1);
    }

    public function test_it_refuses_with_no_keep_list(): void
    {
        $this->seedPilotData();

        $this->artisan('bigkas:reset-data --force')->assertExitCode(1);

        $this->assertDatabaseCount('learners', 1);
    }

    /** Two admins is the real case here. */
    public function test_it_keeps_several_admins(): void
    {
        User::factory()->create([
            'email' => 'second@example.com', 'role' => 'admin',
            'is_active' => true, 'email_verified_at' => now(),
        ]);
        $this->seedPilotData();

        $this->artisan('bigkas:reset-data --keep=keep@example.com,second@example.com --force')
            ->assertExitCode(0);

        $this->assertSame(2, User::count());
        $this->assertEqualsCanonicalizing(
            ['keep@example.com', 'second@example.com'],
            User::pluck('email')->map(fn ($e) => (string) $e)->all()
        );
    }

    public function test_sessions_and_queue_tables_are_cleared(): void
    {
        DB::table('sessions')->insert(['id' => 'abc', 'payload' => 'x', 'last_activity' => time()]);
        $this->seedPilotData();

        $this->artisan('bigkas:reset-data --keep=keep@example.com --force')->assertExitCode(0);

        $this->assertDatabaseCount('sessions', 0);
    }

    /**
     * The first run on the real server died with "There is no active
     * transaction". TRUNCATE is DDL on MySQL and performs an implicit commit,
     * which ended the transaction mid-wipe: everything after it auto-committed
     * and the final commit had nothing to commit. SQLite rewrites truncate as
     * a delete, so no amount of testing here would have shown it — this test
     * pins the statement itself instead.
     */
    public function test_the_wipe_never_issues_a_truncate(): void
    {
        $this->seedPilotData();

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = strtolower(trim($query->sql));
        });

        $this->artisan('bigkas:reset-data --keep=keep@example.com --force')->assertExitCode(0);

        $truncates = array_values(array_filter($statements, fn ($sql) => str_starts_with($sql, 'truncate')));
        $this->assertSame([], $truncates, 'TRUNCATE implicitly commits on MySQL and breaks the transaction.');
    }

    /** Because the wipe is one transaction, a failure part-way must undo it all. */
    public function test_a_failure_part_way_through_leaves_everything_intact(): void
    {
        $this->seedPilotData();
        $learnersBefore = Learner::count();
        $usersBefore = User::count();

        // Blow up once the wipe reaches the learners table, after earlier
        // tables have already been emptied inside the transaction.
        DB::listen(function ($query) {
            if (str_contains(strtolower($query->sql), 'delete from "learners"')) {
                throw new \RuntimeException('simulated failure mid-wipe');
            }
        });

        try {
            $this->artisan('bigkas:reset-data --keep=keep@example.com --force')->run();
            $this->fail('The simulated failure should have propagated.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('simulated failure', $e->getMessage());
        }

        $this->assertSame($learnersBefore, Learner::count(), 'A failed wipe must roll back completely.');
        $this->assertSame($usersBefore, User::count());
        $this->assertDatabaseCount('activity_logs', 1);
    }
}
