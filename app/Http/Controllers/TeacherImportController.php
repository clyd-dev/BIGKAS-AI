<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ImportsNameLogins;
use App\Models\ActivityLog;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Bulk-create teacher accounts from a sheet, the same way ParentImportController
 * does for parents: each teacher logs in with their full name and the shared
 * pilot password (see that class for the trade-off), and is attached to the
 * grade & section named on their row when that section exists and is still free.
 *
 * Two-step flow: preview (nothing written) then confirm.
 */
class TeacherImportController extends Controller
{
    use ImportsNameLogins;

    private const HEADER_ALIASES = [
        'teacher_name' => ['teacher name', "teacher's name", 'adviser name', 'adviser', 'name'],
        'grade'        => ['grade level', 'grade', 'year level'],
        'section'      => ['section'],
        'phone'        => ['phone', 'contact no', 'contact number', 'mobile'],
    ];

    public function form()
    {
        return view('admin.teachers-import');
    }

    /** Step 1: parse the uploaded file and render a review table. Nothing is saved here. */
    public function preview(Request $request)
    {
        $request->validate([
            'teachers_file' => 'required|file|mimes:csv,txt,xlsx,xls|max:2048',
        ]);

        try {
            $spreadsheet = IOFactory::load($request->file('teachers_file')->getRealPath());
            $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not read that file. Please upload a CSV or Excel file.');
        }

        $columnMap = $this->detectHeaderRow($rows, self::HEADER_ALIASES, 'teacher_name');
        if ($columnMap === null) {
            return back()->with('error', 'No "Teacher Name" column was found. Please check the file has a header row.');
        }

        $headerRow = $columnMap['_header_row'];
        unset($columnMap['_header_row']);

        $classes = SchoolClass::with('teacher')->get();
        $claimed = [];   // class ids already given to an earlier row in this file
        $parsed  = [];

        for ($r = $headerRow + 1; $r < count($rows); $r++) {
            $cells = array_map(fn ($v) => trim((string) $v), $rows[$r] ?? []);
            if (empty(array_filter($cells, fn ($v) => $v !== ''))) {
                continue;
            }

            $get = fn ($field) => isset($columnMap[$field]) ? ($cells[$columnMap[$field]] ?? '') : '';

            $name = $get('teacher_name');
            if ($name === '') {
                continue;
            }

            $grade   = (int) (preg_match('/\d+/', $get('grade'), $m) ? $m[0] : 0);
            $section = $get('section');

            [$class, $classNote] = $this->matchClass($classes, $grade, $section, $claimed);
            if ($class) {
                $claimed[] = $class->id;
            }

            $parsed[] = [
                'name'        => $name,
                'phone'       => $get('phone') ?: null,
                'grade'       => $grade ?: null,
                'section'     => $section,
                'class_id'    => $class?->id,
                'class_label' => $class ? "Grade {$class->grade_level} – {$class->section}" : null,
                'class_note'  => $classNote,
                'login'       => $this->generateUniqueLogin($name),
                'password'    => ParentImportController::DEFAULT_PASSWORD,
            ];
        }

        if (empty($parsed)) {
            return back()->with('error', 'The header row was found, but no rows could be read underneath it.');
        }

        $request->session()->put('teacher_import_rows', $parsed);

        return view('admin.teachers-import', ['importRows' => $parsed]);
    }

    /** Step 2: create an account for each confirmed row and show the one-time credentials sheet. */
    public function confirm(Request $request)
    {
        $request->validate([
            'rows'   => 'required|array|min:1',
            'rows.*' => 'integer',
        ]);

        $parsedRows = $request->session()->get('teacher_import_rows', []);
        if (empty($parsedRows)) {
            return redirect()->route('admin.teachers.import')
                ->with('error', 'Your import session expired. Please re-upload the file.');
        }

        $schoolId = School::orderBy('id')->value('id');
        $created  = [];
        $skipped  = 0;

        DB::transaction(function () use ($request, $parsedRows, $schoolId, &$created, &$skipped) {
            foreach (array_unique($request->input('rows', [])) as $index) {
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
                // role, is_active and email_verified_at are guarded — explicit assignment only.
                $user->role = User::ROLE_TEACHER;
                $user->is_active = true;
                $user->email_verified_at = now();
                $user->save();

                // Re-check at write time: the section may have been taken since the preview.
                $classLabel = null;
                if (!empty($row['class_id'])) {
                    $assigned = SchoolClass::where('id', $row['class_id'])->whereNull('teacher_id')
                        ->update(['teacher_id' => $user->id]);
                    $classLabel = $assigned ? $row['class_label'] : null;
                }

                $created[] = [
                    'name'        => $row['name'],
                    'login'       => $row['login'],
                    'password'    => $row['password'],
                    'class_label' => $classLabel,
                ];
            }
        });

        $request->session()->forget('teacher_import_rows');

        ActivityLog::log(
            'admin_import_teachers',
            'Bulk-created ' . count($created) . ' teacher account(s)' . ($skipped ? ", skipped {$skipped}" : ''),
            'user',
            null
        );

        if (empty($created)) {
            return redirect()->route('admin.teachers.import')->with('error', 'No accounts were created.');
        }

        // Shown once, from this response only — the plain passwords are never stored or logged.
        return view('admin.teachers-import-result', ['created' => $created]);
    }

    /**
     * Find the free section a row names. Returns [class|null, note|null]; a teacher
     * with no match is still created and shows up in the "needs a grade & section" banner.
     */
    private function matchClass($classes, int $grade, string $section, array $claimed): array
    {
        if ($grade === 0 && $section === '') {
            return [null, null];
        }
        if ($grade === 0 || $section === '') {
            return [null, 'Needs both a grade and a section to be assigned.'];
        }

        $wanted = mb_strtolower(preg_replace('/\s+/', ' ', $section));
        $class = $classes->first(fn ($c) => (int) $c->grade_level === $grade
            && mb_strtolower(preg_replace('/\s+/', ' ', trim($c->section))) === $wanted);

        if (!$class) {
            return [null, "No section \"{$section}\" in Grade {$grade} — create it first, then assign from Manage Users."];
        }
        if (in_array($class->id, $claimed, true)) {
            return [null, 'Another row in this file already takes that section.'];
        }
        if ($class->teacher_id) {
            return [null, "Already has a teacher ({$class->teacher?->name})."];
        }

        return [$class, null];
    }
}
