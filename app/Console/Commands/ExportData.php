<?php

namespace App\Console\Commands;

use App\Models\Assessment;
use App\Models\Learner;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Package a grade's learners, their assessments and their recordings so another
 * BIGKAS-AI installation can take them in (bigkas:import-data).
 *
 * Why not a mysqldump: learner names, LRNs, PINs and user names/emails are
 * stored encrypted, and are looked up through a blind index keyed on APP_KEY
 * (see App\Support\BlindIndex). Rows copied between two installations with
 * different keys arrive unreadable. So this writes those fields *decrypted*
 * into the manifest and lets the receiving side re-encrypt under its own key.
 * Row ids are left out entirely and every reference is a natural key, because
 * the two databases number their rows independently.
 *
 * The manifest therefore holds personal data in the clear. Treat the folder
 * like a printed class list: move it over scp, import it, delete it.
 */
class ExportData extends Command
{
    protected $signature = 'bigkas:export-data
        {--grade=* : Grade level(s) to export (required)}
        {--section= : Only this section, e.g. --section=Dove}
        {--out= : Where to write the folder (default: storage/app/exports)}
        {--zip : Also produce a .zip beside the folder, for scp}';

    protected $description = 'Package learners, assessments and recordings for transfer to another installation';

    public const FORMAT = 'bigkas-data-transfer/1';

    /** Columns the receiving side assigns itself, or that are derived from an encrypted value. */
    private const DROP_LEARNER = ['id', 'class_id', 'school_id', 'lrn_index'];

    private const DROP_USER = ['id', 'school_id', 'email_index', 'remember_token'];

    /**
     * Which columns are encrypted is asked of the model rather than listed
     * here. A hand-kept list missed birth_date, whose cast is the custom
     * EncryptedDate rather than the string 'encrypted', and one ciphertext
     * blob in the manifest is a date the far side can never read.
     *
     * @return list<string>
     */
    private function encryptedColumns(\Illuminate\Database\Eloquent\Model $model): array
    {
        return collect($model->getCasts())
            ->filter(fn ($cast) => is_string($cast) && str_contains(mb_strtolower($cast), 'encrypted'))
            ->keys()->all();
    }

    /** A Carbon from a date cast must land in the manifest as a plain date, not an object. */
    private function plain(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
    }

    public function handle(): int
    {
        $grades = array_map('intval', (array) $this->option('grade'));
        $grades = array_values(array_filter($grades, fn ($g) => $g >= 1 && $g <= 12));

        if ($grades === []) {
            $this->error('Refusing to run: name the grade(s), e.g. --grade=5 --grade=6');

            return self::FAILURE;
        }

        $learners = Learner::with('schoolClass')
            ->whereIn('grade_level', $grades)
            ->when($this->option('section'), fn ($q, $section) => $q->whereHas(
                'schoolClass',
                fn ($c) => $c->where('section', $section)
            ))
            ->orderBy('id')
            ->get();

        if ($learners->isEmpty()) {
            $this->error('Nothing to export: no learners in grade '.implode(', ', $grades)
                .($this->option('section') ? ' section '.$this->option('section') : '').'.');

            return self::FAILURE;
        }

        $manifest = $this->buildManifest($learners, $grades);
        $directory = $this->resolveOutputDirectory($grades);

        $this->writeManifest($directory, $manifest);
        $copied = $this->copyAudio($directory, $manifest['assessments']);

        $this->report($directory, $manifest, $copied);

        if ($this->option('zip')) {
            $this->zip($directory);
        }

        return self::SUCCESS;
    }

    /** @param \Illuminate\Support\Collection<int, Learner> $learners */
    private function buildManifest($learners, array $grades): array
    {
        $assessments = Assessment::whereIn('learner_id', $learners->pluck('id'))->orderBy('id')->get();

        // Everyone the rows point at: the assessor, whoever signed off a
        // verdict, and every parent or teacher linked to a learner. All of them
        // have to exist on the far side before an assessment can reference one.
        $userIds = collect()
            ->merge($assessments->pluck('assessor_id'))
            ->merge(DB::table('assessment_verdicts')->whereIn('assessment_id', $assessments->pluck('id'))->pluck('decided_by'))
            ->merge(DB::table('learner_user')->whereIn('learner_id', $learners->pluck('id'))->pluck('user_id'))
            ->merge($learners->pluck('schoolClass.teacher_id'))
            ->filter()->unique();

        $users = User::whereIn('id', $userIds)->orderBy('id')->get();
        $userRefs = $users->mapWithKeys(fn (User $u) => [$u->id => $this->userRef($u)]);

        return [
            'format' => self::FORMAT,
            'exported_at' => now()->toIso8601String(),
            'source' => ['app_url' => config('app.url'), 'env' => config('app.env')],
            'grades' => $grades,
            'users' => $users->map(fn (User $u) => $this->userRow($u))->values()->all(),
            'classes' => $this->classRows($learners, $userRefs),
            'materials' => $this->materialRows($assessments),
            'learners' => $this->learnerRows($learners, $userRefs),
            'assessments' => $this->assessmentRows($assessments, $learners, $userRefs),
        ];
    }

    /** An email (or, for a name login, the name) identifies an account on either side. */
    private function userRef(User $user): string
    {
        return (string) $user->email;
    }

    /**
     * LRN when there is one. Otherwise the name within the grade, which is the
     * same rule the parent import uses to find a child, so both sides agree.
     */
    private function learnerRef(Learner $learner): string
    {
        return filled($learner->lrn)
            ? 'lrn:'.preg_replace('/\D/', '', (string) $learner->lrn)
            : 'name:'.mb_strtolower(trim($learner->first_name.' '.$learner->last_name)).'|g'.$learner->grade_level;
    }

    private function classRef(?object $class): ?string
    {
        return $class ? "g{$class->grade_level}|{$class->section}|{$class->school_year}" : null;
    }

    private function materialRef(?object $material): ?string
    {
        return $material
            ? "{$material->language}|{$material->type}|g{$material->grade_level}|{$material->title}"
            : null;
    }

    private function userRow(User $user): array
    {
        $row = (array) DB::table('users')->find($user->id);

        foreach (self::DROP_USER as $column) {
            unset($row[$column]);
        }
        foreach ($this->encryptedColumns($user) as $column) {
            $row[$column] = $this->plain($user->{$column});
        }

        // The bcrypt hash travels as-is: it is not tied to APP_KEY, so the
        // account keeps working with the password its owner already knows.
        return ['ref' => $this->userRef($user)] + $row;
    }

    private function classRows($learners, $userRefs): array
    {
        return $learners->pluck('schoolClass')->filter()->unique('id')
            ->map(fn ($class) => [
                'ref' => $this->classRef($class),
                'grade_level' => $class->grade_level,
                'section' => $class->section,
                'school_year' => $class->school_year,
                'is_active' => (bool) $class->is_active,
                'teacher_ref' => $userRefs[$class->teacher_id] ?? null,
            ])->values()->all();
    }

    /**
     * The passages a reading was measured against. The seeded catalogue is the
     * same on both sides, so these usually match on arrival; they are carried
     * in full so an assessment is never orphaned when one is missing.
     */
    private function materialRows($assessments): array
    {
        return DB::table('reading_materials')
            ->whereIn('id', $assessments->pluck('material_id')->filter()->unique())
            ->orderBy('id')->get()
            ->map(function ($material) {
                $row = (array) $material;
                $questions = DB::table('comprehension_questions')
                    ->where('material_id', $material->id)->orderBy('id')->get()
                    ->map(fn ($q) => collect((array) $q)->except(['id', 'material_id'])->all())->all();
                unset($row['id'], $row['created_by']);

                return ['ref' => $this->materialRef($material), 'questions' => $questions] + $row;
            })->values()->all();
    }

    private function learnerRows($learners, $userRefs): array
    {
        return $learners->map(function (Learner $learner) use ($userRefs) {
            $row = (array) DB::table('learners')->find($learner->id);

            foreach (self::DROP_LEARNER as $column) {
                unset($row[$column]);
            }
            foreach ($this->encryptedColumns($learner) as $column) {
                $row[$column] = $this->plain($learner->{$column});
            }

            $links = DB::table('learner_user')->where('learner_id', $learner->id)->get()
                ->map(fn ($l) => [
                    'user_ref' => $userRefs[$l->user_id] ?? null,
                    'relationship' => $l->relationship,
                ])->filter(fn ($l) => $l['user_ref'] !== null)->values()->all();

            $badges = DB::table('learner_badges')->where('learner_id', $learner->id)
                ->join('badges', 'badges.id', '=', 'learner_badges.badge_id')
                ->get(['badges.slug', 'learner_badges.earned_at', 'learner_badges.context'])
                ->map(fn ($b) => (array) $b)->all();

            return [
                'ref' => $this->learnerRef($learner),
                'class_ref' => $this->classRef($learner->schoolClass),
                'people' => $links,
                'badges' => $badges,
            ] + $row;
        })->values()->all();
    }

    private function assessmentRows($assessments, $learners, $userRefs): array
    {
        $learnerRefs = $learners->mapWithKeys(fn (Learner $l) => [$l->id => $this->learnerRef($l)]);
        $materials = DB::table('reading_materials')->get()->keyBy('id');

        return $assessments->map(function (Assessment $assessment) use ($learnerRefs, $userRefs, $materials) {
            $row = (array) DB::table('assessments')->find($assessment->id);
            $audio = $row['audio_file'] ?? null;
            unset($row['id'], $row['learner_id'], $row['material_id'], $row['assessor_id'], $row['batch_id'], $row['audio_file']);

            $verdict = DB::table('assessment_verdicts')->where('assessment_id', $assessment->id)->first();

            return [
                'learner_ref' => $learnerRefs[$assessment->learner_id] ?? null,
                'material_ref' => $this->materialRef($materials[$assessment->material_id] ?? null),
                'assessor_ref' => $userRefs[$assessment->assessor_id] ?? null,
                'audio' => $audio ? 'audio/'.basename($audio) : null,
                'audio_source' => $audio,
                'result' => $this->rowOrNull('assessment_results', $assessment->id, ['id', 'assessment_id']),
                'verdict' => $verdict ? collect((array) $verdict)->except(['id', 'assessment_id', 'decided_by'])
                    ->put('decided_by_ref', $userRefs[$verdict->decided_by] ?? null)->all() : null,
                'comprehension_answers' => DB::table('comprehension_answers')
                    ->where('assessment_id', $assessment->id)->orderBy('id')->get()
                    ->map(fn ($a) => collect((array) $a)->except(['id', 'assessment_id', 'question_id'])->all())->all(),
            ] + $row;
        })->values()->all();
    }

    private function rowOrNull(string $table, int $assessmentId, array $except): ?array
    {
        $row = DB::table($table)->where('assessment_id', $assessmentId)->first();

        return $row ? collect((array) $row)->except($except)->all() : null;
    }

    private function resolveOutputDirectory(array $grades): string
    {
        $name = 'grade-'.implode('-', $grades)
            .($this->option('section') ? '-'.str($this->option('section'))->slug() : '')
            .'-'.now()->format('Ymd-His');

        $directory = rtrim((string) ($this->option('out') ?: storage_path('app/exports')), '/\\').DIRECTORY_SEPARATOR.$name;

        if (! is_dir($directory.DIRECTORY_SEPARATOR.'audio')) {
            mkdir($directory.DIRECTORY_SEPARATOR.'audio', 0775, true);
        }

        return $directory;
    }

    private function writeManifest(string $directory, array $manifest): void
    {
        file_put_contents(
            $directory.DIRECTORY_SEPARATOR.'manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /** @return array{copied:int, missing:list<string>} */
    private function copyAudio(string $directory, array $assessments): array
    {
        $disk = Storage::disk('public');
        $copied = 0;
        $missing = [];

        foreach ($assessments as $assessment) {
            $source = $assessment['audio_source'] ?? null;
            if (! $source) {
                continue;
            }

            if (! $disk->exists($source)) {
                $missing[] = $source;
                continue;
            }

            file_put_contents(
                $directory.DIRECTORY_SEPARATOR.'audio'.DIRECTORY_SEPARATOR.basename($source),
                $disk->get($source)
            );
            $copied++;
        }

        return ['copied' => $copied, 'missing' => $missing];
    }

    private function report(string $directory, array $manifest, array $audio): void
    {
        $this->newLine();
        $this->info('Exported to '.$directory);
        $this->line(sprintf('  %-22s %d', 'learners', count($manifest['learners'])));
        $this->line(sprintf('  %-22s %d', 'assessments', count($manifest['assessments'])));
        $this->line(sprintf('  %-22s %d', 'accounts', count($manifest['users'])));
        $this->line(sprintf('  %-22s %d', 'classes', count($manifest['classes'])));
        $this->line(sprintf('  %-22s %d', 'passages', count($manifest['materials'])));
        $this->line(sprintf('  %-22s %d', 'recordings', $audio['copied']));

        if ($audio['missing'] !== []) {
            $this->newLine();
            $this->warn(count($audio['missing']).' recording(s) are referenced but not on disk:');
            foreach (array_slice($audio['missing'], 0, 5) as $path) {
                $this->line('  '.$path);
            }
            $this->line('  Those assessments import without audio; their scores are unaffected.');
        }

        $this->newLine();
        $this->warn('manifest.json holds names, LRNs and PINs in the clear — that is what makes it portable.');
        $this->line('Move it over scp, import it, then delete it from both machines.');
    }

    private function zip(string $directory): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->warn('Skipping --zip: the zip extension is not installed. Use: tar -czf '.basename($directory).'.tar.gz -C '.dirname($directory).' '.basename($directory));

            return;
        }

        $path = $directory.'.zip';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach (['manifest.json'] as $file) {
            $zip->addFile($directory.DIRECTORY_SEPARATOR.$file, $file);
        }
        foreach (glob($directory.DIRECTORY_SEPARATOR.'audio'.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            $zip->addFile($file, 'audio/'.basename($file));
        }

        $zip->close();
        $this->info('Zipped to '.$path.' ('.round(filesize($path) / 1048576, 1).' MB)');
    }
}
