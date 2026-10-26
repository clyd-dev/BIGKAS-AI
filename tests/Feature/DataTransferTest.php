<?php

namespace Tests\Feature;

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
 * Moving a grade that was assessed on a laptop onto the server.
 *
 * The thing that makes this hard is not volume, it is identity: names, LRNs and
 * PINs are encrypted under APP_KEY and the two installations do not share one,
 * and both number their rows from 1. So the tests below check the round trip
 * end to end, that the manifest carries plaintext rather than ciphertext, and
 * that importing the same folder twice does not give a child a second record.
 */
class DataTransferTest extends TestCase
{
    use RefreshDatabase;

    private string $exports;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->exports = storage_path('framework/testing/transfer-'.uniqid());
    }

    protected function tearDown(): void
    {
        if (is_dir($this->exports)) {
            $this->deleteDirectory($this->exports);
        }
        parent::tearDown();
    }

    private function deleteDirectory(string $path): void
    {
        foreach (glob($path.'/*') ?: [] as $entry) {
            is_dir($entry) ? $this->deleteDirectory($entry) : @unlink($entry);
        }
        @rmdir($path);
    }

    /** One teacher, one parent, one Grade 5 child with a scored reading and a recording. */
    private function seedGradeFive(): array
    {
        $school = School::firstOrCreate(['name' => 'Old Sagay ES'], ['address' => 'Sagay', 'division' => 'Sagay']);

        $teacher = User::factory()->create([
            'name' => 'Ale Rivera', 'email' => 'rivera@example.com',
            'role' => 'teacher', 'is_active' => true, 'email_verified_at' => now(),
        ]);
        $parent = User::factory()->create([
            'name' => 'Venus Clarin', 'email' => 'Venus Clarin',  // a name login, as the import creates
            'role' => 'parent', 'is_active' => true, 'email_verified_at' => now(),
        ]);

        $class = SchoolClass::create([
            'school_id' => $school->id, 'teacher_id' => $teacher->id,
            'grade_level' => 5, 'section' => 'Dove', 'school_year' => '2026-2027', 'is_active' => true,
        ]);

        $learner = Learner::factory()->create([
            'class_id' => $class->id, 'grade_level' => 5, 'lrn' => null,
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'reading_level' => Learner::LEVEL_INSTRUCTIONAL,
            'birth_date' => '2015-04-03', 'middle_name' => 'Santos', 'mother_tongue' => 'Hiligaynon',
        ]);
        $learner->pin = '4321';
        $learner->total_xp = 250;
        $learner->current_streak = 3;
        $learner->save();

        $learner->users()->attach($parent->id, ['relationship' => 'parent']);
        $learner->users()->attach($teacher->id, ['relationship' => 'teacher']);

        $material = ReadingMaterial::create([
            'title' => 'Ang Aso', 'content' => 'Tumakbo ang aso.', 'language' => 'fil',
            'grade_level' => 5, 'difficulty' => 'easy',
            'type' => ReadingMaterial::TYPE_ORAL_READING, 'word_count' => 3,
        ]);
        $questionId = DB::table('comprehension_questions')->insertGetId([
            'material_id' => $material->id, 'question' => 'Sino ang tumakbo?',
            'question_type' => 'literal', 'correct_answer' => 'Aso',
            'option_a' => 'Aso', 'option_b' => 'Pusa', 'option_c' => 'Ibon', 'option_d' => 'Isda',
            'sort_order' => 1, 'created_at' => now(),
        ]);

        $assessment = Assessment::create([
            'learner_id' => $learner->id, 'material_id' => $material->id, 'assessor_id' => $teacher->id,
            'language' => 'fil', 'assessment_type' => Assessment::TYPE_ORAL_READING,
            'status' => Assessment::STATUS_COMPLETED,
            'transcription' => 'tumakbo ang aso',
            'audio_file' => 'assessments/audio/assessment_1_1111.webm',
        ]);
        Storage::disk('public')->put($assessment->audio_file, 'fake-audio-bytes');

        DB::table('assessment_results')->insert([
            'assessment_id' => $assessment->id, 'accuracy_rate' => 96.5, 'words_per_minute' => 72,
            'error_count' => 1, 'reading_level' => Learner::LEVEL_INSTRUCTIONAL,
            'primary_weakness' => 'fluency', 'comprehension_score' => 75,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('comprehension_answers')->insert([
            'assessment_id' => $assessment->id, 'question_id' => $questionId,
            'question_text' => 'Sino ang tumakbo?', 'selected_option' => 'a',
            'selected_text' => 'Aso', 'correct_text' => 'Aso', 'is_correct' => true, 'created_at' => now(),
        ]);

        $badge = Badge::create([
            'slug' => 'first-read', 'name' => 'First Read', 'description' => 'seeded',
            'icon' => 'star', 'color' => 'warning', 'category' => 'assessment',
            'criteria' => ['type' => 'assessment_count', 'value' => 1],
        ]);
        DB::table('learner_badges')->insert([
            'learner_id' => $learner->id, 'badge_id' => $badge->id, 'earned_at' => now(),
        ]);

        return compact('school', 'teacher', 'parent', 'class', 'learner', 'material', 'assessment', 'badge');
    }

    private function export(array $options = []): string
    {
        $this->artisan('bigkas:export-data', ['--grade' => [5], '--out' => $this->exports] + $options)
            ->assertExitCode(0);

        $folders = glob($this->exports.'/grade-5-*');
        $this->assertNotEmpty($folders, 'The export wrote no folder.');

        return $folders[0];
    }

    private function manifest(string $folder): array
    {
        return json_decode((string) file_get_contents($folder.'/manifest.json'), true);
    }

    /**
     * Everything the receiving side has to re-encrypt must leave here readable.
     * A manifest full of ciphertext would import as unreadable blobs on a
     * server whose APP_KEY differs — the whole reason this is not a mysqldump.
     */
    public function test_the_manifest_carries_plaintext_not_ciphertext(): void
    {
        $this->seedGradeFive();
        $manifest = $this->manifest($this->export());

        $this->assertSame('Juan', $manifest['learners'][0]['first_name']);
        $this->assertSame('4321', $manifest['learners'][0]['pin']);
        $this->assertSame('Ale Rivera', collect($manifest['users'])->firstWhere('role', 'teacher')['name']);

        $this->assertSame('2015-04-03', $manifest['learners'][0]['birth_date'],
            'birth_date is encrypted through a custom cast; a hand-kept list of encrypted columns missed it.');

        $encrypted = collect((new Learner)->getCasts())
            ->filter(fn ($cast) => is_string($cast) && str_contains(mb_strtolower($cast), 'encrypted'))
            ->keys();
        $this->assertNotEmpty($encrypted);
        foreach ($encrypted as $column) {
            $this->assertStringNotContainsString('eyJpdiI6', (string) ($manifest['learners'][0][$column] ?? ''),
                "learners.{$column} is encrypted at rest and must be exported in the clear.");
        }

        $json = json_encode($manifest);
        $this->assertStringNotContainsString('eyJpdiI6', $json, 'A Laravel ciphertext blob leaked into the manifest.');
        $this->assertStringNotContainsString('lrn_index', $json, 'A blind index is keyed on APP_KEY and must be recomputed, not copied.');
        $this->assertStringNotContainsString('email_index', $json);
    }

    public function test_row_ids_never_travel(): void
    {
        $this->seedGradeFive();
        $manifest = $this->manifest($this->export());

        $this->assertArrayNotHasKey('id', $manifest['learners'][0]);
        $this->assertArrayNotHasKey('class_id', $manifest['learners'][0]);
        $this->assertArrayNotHasKey('learner_id', $manifest['assessments'][0]);
        $this->assertArrayNotHasKey('assessor_id', $manifest['assessments'][0]);
        $this->assertSame('rivera@example.com', $manifest['assessments'][0]['assessor_ref']);
    }

    public function test_the_recording_is_packaged_with_the_assessment(): void
    {
        $this->seedGradeFive();
        $folder = $this->export();

        $audio = $this->manifest($folder)['assessments'][0]['audio'];
        $this->assertSame('audio/assessment_1_1111.webm', $audio);
        $this->assertFileExists($folder.'/'.$audio);
        $this->assertSame('fake-audio-bytes', file_get_contents($folder.'/'.$audio));
    }

    /**
     * The real move: export, then import into an installation that has the
     * school and the seeded catalogue but none of these people.
     */
    public function test_a_grade_arrives_whole_on_the_other_side(): void
    {
        $source = $this->seedGradeFive();
        $folder = $this->export();

        $this->emptyTheReceivingSide();

        $this->artisan('bigkas:import-data', ['path' => $folder, '--force' => true])->assertExitCode(0);

        $learner = Learner::sole();
        $this->assertSame('Juan', $learner->first_name);
        $this->assertSame('Dela Cruz', $learner->last_name);
        $this->assertSame(5, (int) $learner->grade_level);
        $this->assertSame(250, (int) $learner->total_xp, 'XP the child earned is theirs to keep.');
        $this->assertSame('2015-04-03', $learner->birth_date->toDateString());
        $this->assertSame('Santos', $learner->middle_name);
        $this->assertSame('Hiligaynon', $learner->mother_tongue);
        $this->assertSame('Dove', $learner->schoolClass->section);

        // The PIN is the child's only credential, so it has to survive the move.
        $this->assertTrue($learner->checkPin('4321'), 'The PIN must work after the transfer.');

        $assessment = Assessment::sole();
        $this->assertSame($learner->id, $assessment->learner_id);
        $this->assertSame('tumakbo ang aso', $assessment->transcription);
        $this->assertSame('Ale Rivera', $assessment->assessor->name);
        $this->assertSame(96.5, (float) $assessment->result->accuracy_rate);
        $this->assertSame(1, DB::table('comprehension_answers')->where('assessment_id', $assessment->id)->count());

        $this->assertNotNull($assessment->audio_file);
        $this->assertTrue(Storage::disk('public')->exists($assessment->audio_file));
        $this->assertSame('fake-audio-bytes', Storage::disk('public')->get($assessment->audio_file));

        $this->assertSame(1, DB::table('learner_badges')->where('learner_id', $learner->id)->count());
        $this->assertEqualsCanonicalizing(
            ['parent', 'teacher'],
            DB::table('learner_user')->where('learner_id', $learner->id)->pluck('relationship')->all()
        );

        // Re-encrypted under this installation's key: the lookup index has to
        // have been recomputed, not copied, or the child cannot be found.
        $this->assertNotNull(User::byEmail('rivera@example.com')->first());
        $this->assertSame($source['learner']->first_name, $learner->first_name);
    }

    public function test_importing_the_same_folder_twice_adds_nothing(): void
    {
        $this->seedGradeFive();
        $folder = $this->export();
        $this->emptyTheReceivingSide();

        $this->artisan('bigkas:import-data', ['path' => $folder, '--force' => true])->assertExitCode(0);
        $this->artisan('bigkas:import-data', ['path' => $folder, '--force' => true])->assertExitCode(0);

        $this->assertSame(1, Learner::count(), 'A second import must not give the child a second record.');
        $this->assertSame(1, Assessment::count());
        $this->assertSame(1, DB::table('assessment_results')->count());
        $this->assertSame(2, DB::table('learner_user')->count());
        $this->assertSame(1, DB::table('learner_badges')->count());
    }

    /** A child already on the server (SF1 import) gets the readings attached, not a twin. */
    public function test_a_learner_already_here_is_matched_by_name_within_the_grade(): void
    {
        $this->seedGradeFive();
        $folder = $this->export();
        $this->emptyTheReceivingSide();

        $class = SchoolClass::create([
            'school_id' => School::value('id'), 'grade_level' => 5,
            'section' => 'Dove', 'school_year' => '2026-2027', 'is_active' => true,
        ]);
        $existing = Learner::factory()->create([
            'class_id' => $class->id, 'grade_level' => 5, 'lrn' => null,
            'first_name' => 'juan', 'last_name' => 'DELA CRUZ',   // same child, typed differently
        ]);

        $this->artisan('bigkas:import-data', ['path' => $folder, '--force' => true])->assertExitCode(0);

        $this->assertSame(1, Learner::count(), 'Case and spacing must not create a duplicate child.');
        $this->assertSame($existing->id, Assessment::sole()->learner_id);
    }

    /** An LRN is the better key when the sheet has one. */
    public function test_a_learner_with_an_lrn_is_matched_by_it(): void
    {
        $data = $this->seedGradeFive();
        $data['learner']->lrn = '123456789012';
        $data['learner']->save();

        $folder = $this->export();
        $this->emptyTheReceivingSide();

        $class = SchoolClass::create([
            'school_id' => School::value('id'), 'grade_level' => 5,
            'section' => 'Dove', 'school_year' => '2026-2027', 'is_active' => true,
        ]);
        $existing = Learner::factory()->create([
            'class_id' => $class->id, 'grade_level' => 5, 'lrn' => '123456789012',
            'first_name' => 'Renamed', 'last_name' => 'Differently',
        ]);

        $this->artisan('bigkas:import-data', ['path' => $folder, '--force' => true])->assertExitCode(0);

        $this->assertSame(1, Learner::count());
        $this->assertSame($existing->id, Assessment::sole()->learner_id);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->seedGradeFive();
        $folder = $this->export();
        $this->emptyTheReceivingSide();

        $this->artisan('bigkas:import-data', ['path' => $folder, '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertSame(0, Learner::count());
        $this->assertSame(0, Assessment::count());
    }

    public function test_an_account_already_here_is_reused_rather_than_duplicated(): void
    {
        $this->seedGradeFive();
        $folder = $this->export();
        $this->emptyTheReceivingSide();

        $existing = User::factory()->create([
            'name' => 'Ale Rivera', 'email' => 'rivera@example.com',
            'role' => 'teacher', 'is_active' => true, 'email_verified_at' => now(),
        ]);

        $this->artisan('bigkas:import-data', ['path' => $folder, '--force' => true])->assertExitCode(0);

        $this->assertSame(1, User::where('role', 'teacher')->count());
        $this->assertSame($existing->id, Assessment::sole()->assessor_id);
    }

    /** An imported parent must be able to log in with the password they were given. */
    public function test_an_imported_account_keeps_its_password(): void
    {
        $this->seedGradeFive();
        User::byEmail('Venus Clarin')->first()->forceFill(['password' => bcrypt('Bigkas123')])->save();

        $folder = $this->export();
        $this->emptyTheReceivingSide();

        $this->artisan('bigkas:import-data', ['path' => $folder, '--force' => true])->assertExitCode(0);

        $this->assertTrue(
            auth()->attempt(['email' => 'Venus Clarin', 'password' => 'Bigkas123']),
            'The bcrypt hash is not tied to APP_KEY, so it must travel as-is.'
        );
    }

    public function test_a_passage_missing_on_the_far_side_is_created_with_its_questions(): void
    {
        $this->seedGradeFive();
        $folder = $this->export();
        $this->emptyTheReceivingSide();
        ReadingMaterial::query()->delete();   // the far side has not been seeded with it

        $this->artisan('bigkas:import-data', ['path' => $folder, '--force' => true])->assertExitCode(0);

        $material = ReadingMaterial::sole();
        $this->assertSame('Ang Aso', $material->title);
        $this->assertSame(1, DB::table('comprehension_questions')->where('material_id', $material->id)->count());
        $this->assertNotNull(
            DB::table('comprehension_answers')->first()->question_id,
            'The answer must point at the question on this side, matched by its text.'
        );
    }

    public function test_it_refuses_a_path_with_no_manifest(): void
    {
        $this->artisan('bigkas:import-data', ['path' => $this->exports.'/nope'])
            ->expectsOutputToContain('No manifest found')
            ->assertExitCode(1);
    }

    public function test_it_refuses_a_grade_with_no_learners(): void
    {
        $this->artisan('bigkas:export-data', ['--grade' => [11], '--out' => $this->exports])
            ->expectsOutputToContain('Nothing to export')
            ->assertExitCode(1);
    }

    public function test_it_refuses_with_no_grade(): void
    {
        $this->seedGradeFive();

        $this->artisan('bigkas:export-data', ['--out' => $this->exports])->assertExitCode(1);
    }

    /**
     * Stand in for the receiving installation: same school and seeded
     * catalogue, none of the people or readings.
     */
    private function emptyTheReceivingSide(): void
    {
        Storage::fake('public');   // the far side has none of the recordings

        DB::table('comprehension_answers')->delete();
        DB::table('assessment_results')->delete();
        DB::table('assessment_verdicts')->delete();
        DB::table('assessments')->delete();
        DB::table('learner_badges')->delete();
        DB::table('learner_user')->delete();
        DB::table('learners')->delete();
        DB::table('classes')->delete();
        DB::table('users')->delete();
    }
}
