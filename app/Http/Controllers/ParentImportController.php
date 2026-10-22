<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ImportsNameLogins;
use App\Models\ActivityLog;
use App\Models\Learner;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Bulk-create parent accounts for families with no email address (common for
 * a whole-school pilot). Each parent logs in with their own full name instead
 * of an email — the login field is matched by a blind-index lookup (see
 * BlindIndexUserProvider), which works for any string, not only real emails.
 * All accounts share one fixed password for this pilot: every parent sees
 * only their own linked child regardless, but since the password is shared,
 * anyone with the printed name list could type another parent's name and
 * get into that account. That trade-off is accepted for a short, supervised
 * pilot — don't reuse this path for anything longer-lived.
 *
 * Two-step flow, mirroring LearnerImportController: preview (nothing written)
 * then confirm (creates the accounts and links each to its child).
 */
class ParentImportController extends Controller
{
    use ImportsNameLogins;

    /** Every bulk-imported parent gets this password (see the class note above). */
    public const DEFAULT_PASSWORD = 'Bigkas123';

    private const HEADER_ALIASES = [
        'parent_name'  => ['parent name', "parent's name", 'guardian name', 'parent/guardian', 'name'],
        'phone'        => ['phone', 'contact no', 'contact number', 'mobile'],
        'lrn'          => ['lrn', 'learner lrn', 'learner reference no', 'learner reference number', 'child lrn'],
        'learner_name' => ['learner name', "learner's name", 'child name', "child's name", 'student name'],
        'grade'        => ['grade level', 'grade', 'year level'],
    ];

    /** Step 0: the upload form. */
    public function form()
    {
        return view('admin.parents-import');
    }

    /** Step 1: parse the uploaded file and render a review table. Nothing is saved here. */
    public function preview(Request $request)
    {
        $request->validate([
            'parents_file' => 'required|file|mimes:csv,txt,xlsx,xls|max:2048',
        ]);

        try {
            $spreadsheet = IOFactory::load($request->file('parents_file')->getRealPath());
            $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not read that file. Please upload a CSV or Excel file.');
        }

        $columnMap = $this->detectHeaderRow($rows, self::HEADER_ALIASES, 'parent_name');
        if ($columnMap === null) {
            return back()->with('error', 'No "Parent Name" column was found. Please check the file has a header row.');
        }

        $learnersByLrn  = [];
        $learnersByName = $this->indexLearnersByName();

        $parsed = [];
        $headerRow = $columnMap['_header_row'];
        unset($columnMap['_header_row']);

        for ($r = $headerRow + 1; $r < count($rows); $r++) {
            $cells = array_map(fn ($v) => trim((string) $v), $rows[$r] ?? []);
            if (empty(array_filter($cells, fn ($v) => $v !== ''))) {
                continue;
            }

            $get = fn ($field) => isset($columnMap[$field]) ? ($cells[$columnMap[$field]] ?? '') : '';

            $name  = $get('parent_name');
            $phone = $get('phone');
            $lrn   = preg_replace('/\D/', '', $get('lrn')) ?: '';
            $learnerNameRaw = $get('learner_name');

            if ($name === '') {
                continue;
            }

            $learner = null;
            if ($lrn !== '') {
                $learner = $learnersByLrn[$lrn] ??= Learner::byLrn($lrn)->first();
            }
            if (!$learner && $learnerNameRaw !== '') {
                $learner = $learnersByName[$this->normalizeName($learnerNameRaw)] ?? null;
            }

            $alreadyLinked = $learner && $learner->users()->wherePivot('relationship', 'parent')->exists();

            // Grades from the sheet ("4", "Grade 4", "3 & 4"), plus the matched child's own grade —
            // a parent always belongs to the grade of every child linked to them.
            $sheetGrades = $this->parseGrades($get('grade'));
            $childGrade  = $learner?->grade_level ? (int) $learner->grade_level : null;
            $grades      = collect($sheetGrades)->when($childGrade, fn ($c) => $c->push($childGrade))->unique()->sort()->values()->all();
            $gradeNote   = ($childGrade && $sheetGrades && !in_array($childGrade, $sheetGrades, true))
                ? "Child is in Grade {$childGrade}, which isn't in the sheet — added."
                : null;

            $parsed[] = [
                'name'           => $name,
                'phone'          => $phone ?: null,
                'lrn_or_name'    => $lrn !== '' ? $lrn : $learnerNameRaw,
                'learner_id'     => $learner?->id,
                'learner_label'  => $learner?->full_name,
                'already_linked' => $alreadyLinked,
                'grades'         => $grades,
                'grade_note'     => $gradeNote,
                'login'          => $this->generateUniqueLogin($name),
                'password'       => self::DEFAULT_PASSWORD,
            ];
        }

        if (empty($parsed)) {
            return back()->with('error', 'The header row was found, but no rows could be read underneath it.');
        }

        $request->session()->put('parent_import_rows', $parsed);

        return view('admin.parents-import', ['importRows' => $parsed]);
    }

    /** Step 2: create an account for each row the admin confirmed, and show the one-time credentials sheet. */
    public function confirm(Request $request)
    {
        $request->validate([
            'rows'   => 'required|array|min:1',
            'rows.*' => 'integer',
        ]);

        $parsedRows = $request->session()->get('parent_import_rows', []);
        if (empty($parsedRows)) {
            return redirect()->route('admin.users.import')
                ->with('error', 'Your import session expired. Please re-upload the file.');
        }

        $schoolId = School::orderBy('id')->value('id');
        $created  = [];
        $skipped  = 0;

        DB::transaction(function () use ($request, $parsedRows, $schoolId, &$created, &$skipped) {
            foreach ($request->input('rows', []) as $index) {
                $row = $parsedRows[$index] ?? null;
                if (!$row || empty($row['name'])) {
                    $skipped++;
                    continue;
                }

                $user = User::create([
                    'name'      => $row['name'],
                    'email'     => $row['login'],
                    'password'  => Hash::make($row['password']),
                    'school_id' => $schoolId,
                    'phone'     => $row['phone'],
                ]);
                // role and email_verified_at are guarded — explicit assignment only.
                $user->role = User::ROLE_PARENT;
                $user->email_verified_at = now();
                $user->save();

                if (!empty($row['learner_id'])) {
                    $learner = Learner::find($row['learner_id']);
                    $learner?->users()->attach($user->id, ['relationship' => 'parent']);
                }

                // Only teachers of these grade(s) will see this parent when linking children.
                $user->syncParentGrades($row['grades'] ?? []);

                $created[] = [
                    'name'          => $row['name'],
                    'login'         => $row['login'],
                    'password'      => $row['password'],
                    'learner_label' => $row['learner_label'] ?? null,
                    'grades'        => $row['grades'] ?? [],
                ];
            }
        });

        $request->session()->forget('parent_import_rows');

        ActivityLog::log(
            'admin_import_parents',
            'Bulk-created ' . count($created) . ' parent account(s)' . ($skipped ? ", skipped {$skipped}" : ''),
            'user',
            null
        );

        if (empty($created)) {
            return redirect()->route('admin.users.import')->with('error', 'No accounts were created.');
        }

        // Shown once, from this response only — the plain passwords are never stored or logged.
        return view('admin.parents-import-result', ['created' => $created]);
    }

    /** All learners, keyed by normalized "first last" name, for the name-fallback match. */
    private function indexLearnersByName(): array
    {
        $index = [];
        // grade_level is needed: a parent inherits the grade of each matched
        // child, and without it a name-matched parent is created with no grade
        // at all — invisible to every teacher.
        Learner::select('id', 'first_name', 'last_name', 'grade_level')->get()->each(function (Learner $l) use (&$index) {
            $index[$this->normalizeName($l->first_name . ' ' . $l->last_name)] = $l;
        });

        return $index;
    }

    private function normalizeName(string $name): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim($name))) ?? '';
    }

    /** Grade numbers found in a cell like "4", "Grade 4", "3 & 4", "3, 4" or "3/4". */
    private function parseGrades(string $cell): array
    {
        preg_match_all('/\d+/', $cell, $matches);

        return collect($matches[0])
            ->map(fn ($g) => (int) $g)
            ->filter(fn ($g) => $g >= 1 && $g <= 12)
            ->unique()->sort()->values()->all();
    }
}
