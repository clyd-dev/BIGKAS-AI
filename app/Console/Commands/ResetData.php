<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Clear the pilot data so the system can be populated with real learners.
 *
 * Everything a teacher or learner produced goes: accounts, classes, learners,
 * assessments and their recordings, reports, messages, logs. What stays is the
 * admin accounts named with --keep, the school row, and the seeded catalogue
 * (passages, comprehension questions, badges, interventions, practice items,
 * settings) — that content is real, not test data.
 *
 * This cannot be undone, so it prints the plan and asks before touching
 * anything, --dry-run stops after the plan, and a --keep address that does not
 * resolve to an admin aborts before a single row is deleted. Take a database
 * dump first; the confirmation prompt repeats the command for doing so.
 */
class ResetData extends Command
{
    protected $signature = 'bigkas:reset-data
        {--keep= : Comma-separated emails of the admin accounts to preserve (required)}
        {--wipe-content : Also clear the seeded catalogue (passages, badges, interventions, practice items)}
        {--keep-files : Leave the audio recordings on disk}
        {--dry-run : Show what would be cleared and stop}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Clear pilot/test data, keeping the named admin accounts and the seeded content';

    /** Everything a user of the system produced. Cleared in full. */
    private const PILOT_TABLES = [
        'activity_logs',
        'assessment_batches',
        'assessment_results',
        'assessment_sessions',
        'assessment_verdicts',
        'assessments',
        'class_reports',
        'classes',
        'comprehension_answers',
        'deped_submissions',
        'email_verification_codes',
        'gst_results',
        'intervention_logs',
        'learner_badges',
        'learner_user',
        'learners',
        'messages',
        'notifications',
        'parent_grades',
        'password_reset_tokens',
        'personal_access_tokens',
        'practice_sessions',
        'progress_snapshots',
        'service_health_events',
    ];

    /** Framework bookkeeping. Clearing it costs nothing and avoids stale state. */
    private const RUNTIME_TABLES = [
        'cache',
        'cache_locks',
        'failed_jobs',
        'job_batches',
        'jobs',
        'sessions',
    ];

    /** Seeded content: kept unless --wipe-content. */
    private const CONTENT_TABLES = [
        'badges',
        'comprehension_questions',
        'interventions',
        'practice_items',
        'reading_materials',
    ];

    /** Recordings live beside the rows that reference them. */
    private const AUDIO_DIRECTORIES = [
        'assessments/audio',
        'practice/audio',
        'audio',
    ];

    public function handle(): int
    {
        $emails = collect(explode(',', (string) $this->option('keep')))
            ->map(fn ($email) => trim($email))
            ->filter()
            ->unique();

        if ($emails->isEmpty()) {
            $this->error('Refusing to run: name the admin accounts to keep, e.g. --keep=you@example.com,other@example.com');

            return self::FAILURE;
        }

        // Emails are encrypted at rest and looked up through a blind index, so
        // resolve each one through the model rather than a raw where clause.
        $keep = [];
        foreach ($emails as $email) {
            $user = User::byEmail($email)->first();

            if (! $user) {
                $this->error("Refusing to run: no account found for {$email}. Nothing was deleted.");
                $this->line('  Check the address — a typo here would delete every account.');

                return self::FAILURE;
            }

            if ($user->role !== 'admin') {
                $this->error("Refusing to run: {$email} is a {$user->role}, not an admin. Nothing was deleted.");
                $this->line('  Only admin accounts are meant to survive a reset; re-create staff accounts afterwards.');

                return self::FAILURE;
            }

            $keep[$user->id] = $email;
        }

        $tables = $this->tablesToClear();

        $this->newLine();
        $this->line('<options=bold>Keeping these accounts:</>');
        foreach ($keep as $id => $email) {
            $this->line("  #{$id}  {$email}");
        }

        $this->newLine();
        $this->line('<options=bold>Clearing:</>');
        $rows = 0;
        foreach ($tables as $table) {
            $count = DB::table($table)->count();
            $rows += $count;
            if ($count > 0) {
                $this->line(sprintf('  %-28s %6d row(s)', $table, $count));
            }
        }
        $discarded = User::whereNotIn('id', array_keys($keep))->count();
        $rows += $discarded;
        $this->line(sprintf('  %-28s %6d row(s)', 'users (all but the above)', $discarded));

        if (! $this->option('keep-files')) {
            $this->line(sprintf('  %-28s %6d file(s)', 'audio recordings', $this->countAudioFiles()));
        }

        if (! in_array('reading_materials', $tables, true)) {
            $this->newLine();
            $this->line('Seeded content is kept: passages, comprehension questions, badges, interventions, practice items, settings.');
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info("Dry run — nothing was changed. {$rows} row(s) would be cleared.");

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->newLine();
            $this->warn('This cannot be undone. Take a dump first if you have not:');
            $this->line('  mysqldump -u bigkas -p bigkas_prod | gzip > ~/before-reset.sql.gz');
            $this->newLine();

            if (! $this->confirm("Clear {$rows} row(s) now?", false)) {
                $this->info('Cancelled. Nothing was changed.');

                return self::SUCCESS;
            }
        }

        // One transaction, constraints off: the tables reference each other in
        // both directions (a class points at a teacher, a teacher's logs point
        // back), so no delete order satisfies every constraint on its own.
        //
        // DELETE, never TRUNCATE. On MySQL, TRUNCATE is DDL and performs an
        // implicit commit, which ends this transaction mid-run: every later
        // statement then auto-commits with nothing to roll back, and the final
        // commit fails with "There is no active transaction". SQLite turns
        // truncate into a delete, so only a real MySQL run shows it.
        DB::transaction(function () use ($tables, $keep) {
            Schema::withoutForeignKeyConstraints(function () use ($tables, $keep) {
                foreach ($tables as $table) {
                    DB::table($table)->delete();
                }

                User::whereNotIn('id', array_keys($keep))->delete();
            });
        });

        if (! $this->option('keep-files')) {
            foreach (self::AUDIO_DIRECTORIES as $directory) {
                Storage::disk('public')->deleteDirectory($directory);
            }
        }

        $this->newLine();
        $this->info('Done. The system is empty apart from the accounts above'
            .(in_array('reading_materials', $tables, true) ? '.' : ' and the seeded content.'));
        $this->line('Everyone was signed out (the sessions table was cleared), so sign in again before testing.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function tablesToClear(): array
    {
        $tables = array_merge(self::PILOT_TABLES, self::RUNTIME_TABLES);

        if ($this->option('wipe-content')) {
            $tables = array_merge($tables, self::CONTENT_TABLES);
        }

        // A server behind on migrations will not have every table yet, and a
        // missing one must not abort the run half-way through.
        return array_values(array_filter($tables, fn ($table) => Schema::hasTable($table)));
    }

    private function countAudioFiles(): int
    {
        $disk = Storage::disk('public');

        return collect(self::AUDIO_DIRECTORIES)
            ->sum(fn ($directory) => count($disk->files($directory)));
    }
}
