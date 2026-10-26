<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Learner;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Take in a folder written by bigkas:export-data.
 *
 * Everything is matched by natural key and created only if missing, so the
 * same folder can be imported twice without producing a second copy of a
 * child: a learner is found by LRN, or by name within the grade when there is
 * no LRN; an account by its email (or the name it logs in with); a class by
 * grade, section and school year; a passage by language, type, grade and
 * title. Row ids from the sending side are never used.
 *
 * Names, LRNs and PINs arrive in plaintext and are written through the models,
 * so they are re-encrypted and re-indexed under *this* installation's APP_KEY.
 * That is the whole point: the two databases do not share a key.
 *
 * Nothing is written until the plan has been shown and confirmed, and the
 * whole import is one transaction — a failure half-way leaves no partial class.
 */
class ImportData extends Command
{
    protected $signature = 'bigkas:import-data
        {path : The exported folder (or its manifest.json)}
        {--dry-run : Show what would be created and stop}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Import learners, assessments and recordings exported from another installation';

    private array $userIds = [];

    private array $classIds = [];

    private array $materialIds = [];

    private array $learnerIds = [];

    private array $created = [
        'users' => 0, 'classes' => 0, 'materials' => 0, 'learners' => 0,
        'assessments' => 0, 'results' => 0, 'verdicts' => 0, 'answers' => 0,
        'badges' => 0, 'links' => 0, 'recordings' => 0,
    ];

    private array $matched = ['users' => 0, 'classes' => 0, 'materials' => 0, 'learners' => 0];

    private array $skipped = [];

    public function handle(): int
    {
        $path = rtrim((string) $this->argument('path'), '/\\');
        $directory = is_file($path) ? dirname($path) : $path;
        $manifestPath = is_file($path) ? $path : $directory.DIRECTORY_SEPARATOR.'manifest.json';

        if (! is_file($manifestPath)) {
            $this->error("No manifest found at {$manifestPath}.");
            $this->line('  Pass the exported folder, or its manifest.json.');

            return self::FAILURE;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($manifest) || ($manifest['format'] ?? null) !== ExportData::FORMAT) {
            $this->error('That file is not a '.ExportData::FORMAT.' manifest.');

            return self::FAILURE;
        }

        if (! School::query()->exists()) {
            $this->error('Refusing to run: this installation has no school row yet. Seed or create one first.');

            return self::FAILURE;
        }

        $this->plan($manifest);

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('Dry run — nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Import now?', false)) {
            $this->info('Cancelled. Nothing was changed.');

            return self::SUCCESS;
        }

        DB::transaction(fn () => $this->import($manifest, $directory));

        $this->report();

        ActivityLog::log(
            'admin_import_data',
            sprintf('Imported %d learner(s) and %d assessment(s) from an exported folder',
                $this->created['learners'], $this->created['assessments']),
            'learner',
            null
        );

        return self::SUCCESS;
    }

    /** What is already here, and what is about to arrive. */
    private function plan(array $manifest): void
    {
        $this->newLine();
        $this->line('<options=bold>From '.($manifest['source']['app_url'] ?? 'unknown')
            .', exported '.($manifest['exported_at'] ?? '?').'</>');
        $this->line('Grades: '.implode(', ', $manifest['grades'] ?? []));

        $this->newLine();
        $this->line('<options=bold>Contents:</>');
        foreach (['learners', 'assessments', 'users', 'classes', 'materials'] as $key) {
            $this->line(sprintf('  %-22s %d', $key === 'users' ? 'accounts' : $key, count($manifest[$key] ?? [])));
        }

        $existing = collect($manifest['learners'] ?? [])
            ->filter(fn ($l) => $this->findLearner($l) !== null)->count();

        $this->newLine();
        if ($existing > 0) {
            $this->line("  {$existing} of these learners already exist here and will be matched, not duplicated.");
        } else {
            $this->line('  None of these learners exist here yet — all will be created.');
        }
    }

    private function import(array $manifest, string $directory): void
    {
        $schoolId = School::orderBy('id')->value('id');

        foreach ($manifest['users'] ?? [] as $row) {
            $this->userIds[$row['ref']] = $this->upsertUser($row, $schoolId);
        }
        foreach ($manifest['classes'] ?? [] as $row) {
            $this->classIds[$row['ref']] = $this->upsertClass($row, $schoolId);
        }
        foreach ($manifest['materials'] ?? [] as $row) {
            $this->materialIds[$row['ref']] = $this->upsertMaterial($row);
        }
        foreach ($manifest['learners'] ?? [] as $row) {
            $this->learnerIds[$row['ref']] = $this->upsertLearner($row, $schoolId);
        }
        foreach ($manifest['assessments'] ?? [] as $row) {
            $this->createAssessment($row, $directory);
        }
    }

    private function upsertUser(array $row, ?int $schoolId): int
    {
        if ($found = User::byEmail($row['ref'])->first()) {
            $this->matched['users']++;

            return $found->id;
        }

        $user = new User;
        $user->fill(collect($row)->only(['name', 'email', 'phone'])->all());
        $user->school_id = $schoolId;
        // Already a bcrypt hash: the 'hashed' cast leaves a hashed value alone,
        // so the account keeps the password its owner already knows.
        $user->password = $row['password'] ?? bcrypt(str()->random(32));
        $user->role = $row['role'] ?? User::ROLE_PARENT;
        $user->is_active = (bool) ($row['is_active'] ?? true);
        $user->email_verified_at = $row['email_verified_at'] ?? now();
        $user->save();

        $this->created['users']++;

        return $user->id;
    }

    private function upsertClass(array $row, ?int $schoolId): int
    {
        $class = SchoolClass::where('grade_level', $row['grade_level'])
            ->where('section', $row['section'])
            ->where('school_year', $row['school_year'])
            ->first();

        if ($class) {
            $this->matched['classes']++;

            return $class->id;
        }

        $teacherId = $this->userIds[$row['teacher_ref']] ?? null;
        $class = SchoolClass::create([
            'school_id' => $schoolId,
            'teacher_id' => $teacherId,
            'grade_level' => $row['grade_level'],
            'section' => $row['section'],
            'school_year' => $row['school_year'],
            'is_active' => $row['is_active'] ?? true,
        ]);
        $this->created['classes']++;

        return $class->id;
    }

    private function upsertMaterial(array $row): int
    {
        $found = DB::table('reading_materials')
            ->where('title', $row['title'])
            ->where('language', $row['language'])
            ->where('type', $row['type'])
            ->where('grade_level', $row['grade_level'])
            ->first();

        if ($found) {
            $this->matched['materials']++;

            return $found->id;
        }

        $questions = $row['questions'] ?? [];
        $materialId = DB::table('reading_materials')->insertGetId(
            collect($row)->except(['ref', 'questions'])->all()
        );

        foreach ($questions as $question) {
            DB::table('comprehension_questions')->insert(['material_id' => $materialId] + $question);
        }

        $this->created['materials']++;

        return $materialId;
    }

    private function upsertLearner(array $row, ?int $schoolId): int
    {
        if ($found = $this->findLearner($row)) {
            $this->matched['learners']++;
            $this->attachPeople($found->id, $row['people'] ?? []);
            $this->awardBadges($found->id, $row['badges'] ?? []);

            return $found->id;
        }

        $learner = new Learner;
        $learner->fill(collect($row)->only([
            'lrn', 'first_name', 'last_name', 'middle_name', 'birth_date', 'gender',
            'grade_level', 'reading_level', 'mother_tongue', 'notes', 'avatar',
            'last_activity_date', 'pin_created_at',
        ])->all());
        $learner->class_id = $this->classIds[$row['class_ref']] ?? null;
        $learner->school_id = $schoolId;
        // Guarded: the PIN is the child's only credential, and the streaks and
        // XP are theirs too - a transfer should not reset what they earned.
        if (filled($row['pin'] ?? null)) {
            $learner->pin = $row['pin'];
        }
        $learner->is_active = (bool) ($row['is_active'] ?? true);
        $learner->current_streak = $row['current_streak'] ?? 0;
        $learner->longest_streak = $row['longest_streak'] ?? 0;
        $learner->total_xp = $row['total_xp'] ?? 0;
        $learner->save();

        $this->created['learners']++;
        $this->attachPeople($learner->id, $row['people'] ?? []);
        $this->awardBadges($learner->id, $row['badges'] ?? []);

        return $learner->id;
    }

    /** LRN first; otherwise the name within the grade, as the parent import matches a child. */
    private function findLearner(array $row): ?Learner
    {
        if (filled($row['lrn'] ?? null)) {
            return Learner::byLrn($row['lrn'])->first();
        }

        return Learner::where('grade_level', $row['grade_level'] ?? null)->get()
            ->first(fn (Learner $l) => $this->sameName($l, $row));
    }

    private function sameName(Learner $learner, array $row): bool
    {
        $normalize = fn ($value) => preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $value)));

        return $normalize($learner->first_name) === $normalize($row['first_name'] ?? '')
            && $normalize($learner->last_name) === $normalize($row['last_name'] ?? '');
    }

    private function attachPeople(int $learnerId, array $people): void
    {
        foreach ($people as $person) {
            $userId = $this->userIds[$person['user_ref']] ?? null;
            if (! $userId) {
                continue;
            }

            $exists = DB::table('learner_user')
                ->where('learner_id', $learnerId)->where('user_id', $userId)->exists();

            if (! $exists) {
                DB::table('learner_user')->insert([
                    'learner_id' => $learnerId,
                    'user_id' => $userId,
                    'relationship' => $person['relationship'] ?? 'parent',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->created['links']++;
            }
        }
    }

    private function awardBadges(int $learnerId, array $badges): void
    {
        if (! Schema::hasTable('learner_badges')) {
            return;
        }

        foreach ($badges as $badge) {
            $badgeId = DB::table('badges')->where('slug', $badge['slug'])->value('id');
            if (! $badgeId) {
                $this->skipped[] = "badge {$badge['slug']} (not in this installation's catalogue)";
                continue;
            }

            $exists = DB::table('learner_badges')
                ->where('learner_id', $learnerId)->where('badge_id', $badgeId)->exists();

            if (! $exists) {
                DB::table('learner_badges')->insert([
                    'learner_id' => $learnerId,
                    'badge_id' => $badgeId,
                    'earned_at' => $badge['earned_at'] ?? now(),
                    'context' => $badge['context'] ?? null,
                ]);
                $this->created['badges']++;
            }
        }
    }

    private function createAssessment(array $row, string $directory): void
    {
        $learnerId = $this->learnerIds[$row['learner_ref']] ?? null;
        $materialId = $this->materialIds[$row['material_ref']] ?? null;
        $assessorId = $this->userIds[$row['assessor_ref']] ?? null;

        if (! $learnerId || ! $materialId || ! $assessorId) {
            $this->skipped[] = 'an assessment for '.($row['learner_ref'] ?? '?').' (learner, passage or assessor could not be resolved)';

            return;
        }

        // Same child, same passage, same moment: this folder has been imported
        // before. Leave the existing row alone rather than scoring it twice.
        $alreadyHere = DB::table('assessments')
            ->where('learner_id', $learnerId)
            ->where('material_id', $materialId)
            ->where('created_at', $row['created_at'] ?? null)
            ->exists();

        if ($alreadyHere) {
            return;
        }

        $audioPath = $this->storeAudio($row, $directory, $learnerId);

        $assessmentId = DB::table('assessments')->insertGetId(
            collect($row)->except([
                'learner_ref', 'material_ref', 'assessor_ref', 'audio', 'audio_source',
                'result', 'verdict', 'comprehension_answers',
            ])->merge([
                'learner_id' => $learnerId,
                'material_id' => $materialId,
                'assessor_id' => $assessorId,
                'batch_id' => null,
                'audio_file' => $audioPath,
            ])->all()
        );
        $this->created['assessments']++;

        if ($result = $row['result'] ?? null) {
            DB::table('assessment_results')->insert(['assessment_id' => $assessmentId] + $result);
            $this->created['results']++;
        }

        if ($verdict = $row['verdict'] ?? null) {
            $decidedBy = $this->userIds[$verdict['decided_by_ref'] ?? ''] ?? $assessorId;
            DB::table('assessment_verdicts')->insert(
                ['assessment_id' => $assessmentId, 'decided_by' => $decidedBy]
                + collect($verdict)->except(['decided_by_ref'])->all()
            );
            $this->created['verdicts']++;
        }

        foreach ($row['comprehension_answers'] ?? [] as $answer) {
            // Questions are matched by their text: the ids differ between the
            // two installations, and the column is nullable for exactly this.
            // The answer keeps its own copy of the wording in question_text,
            // while the question itself stores it in `question`.
            $questionId = DB::table('comprehension_questions')
                ->where('material_id', $materialId)
                ->where('question', $answer['question_text'] ?? '')
                ->value('id');

            DB::table('comprehension_answers')->insert(
                ['assessment_id' => $assessmentId, 'question_id' => $questionId] + $answer
            );
            $this->created['answers']++;
        }
    }

    /** Recordings are re-filed under a path of this installation's own making. */
    private function storeAudio(array $row, string $directory, int $learnerId): ?string
    {
        $relative = $row['audio'] ?? null;
        if (! $relative) {
            return null;
        }

        $source = $directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (! is_file($source)) {
            $this->skipped[] = 'recording '.basename($relative).' (not in the folder)';

            return null;
        }

        $extension = pathinfo($relative, PATHINFO_EXTENSION) ?: 'webm';
        $path = sprintf('assessments/audio/imported_%d_%s.%s', $learnerId, uniqid(), $extension);

        Storage::disk('public')->put($path, (string) file_get_contents($source));
        $this->created['recordings']++;

        return $path;
    }

    private function report(): void
    {
        $this->newLine();
        $this->info('Imported.');
        foreach ($this->created as $what => $count) {
            if ($count > 0) {
                $this->line(sprintf('  created %-14s %d', $what, $count));
            }
        }
        foreach ($this->matched as $what => $count) {
            if ($count > 0) {
                $this->line(sprintf('  matched %-14s %d (already here)', $what, $count));
            }
        }

        if ($this->skipped !== []) {
            $this->newLine();
            $this->warn(count($this->skipped).' thing(s) were skipped:');
            foreach (array_slice($this->skipped, 0, 10) as $note) {
                $this->line('  '.$note);
            }
        }

        $this->newLine();
        $this->line('Delete the exported folder now — it holds names, LRNs and PINs in the clear.');
    }
}
