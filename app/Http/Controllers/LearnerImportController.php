<?php

namespace App\Http\Controllers;

use App\Support\Directory;

use App\Models\Learner;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class LearnerImportController extends Controller
{
    /** Column labels (as they commonly appear on a DepEd SF1) we search the header row for. */
    private const HEADER_ALIASES = [
        'lrn'         => ['lrn', 'learner reference no', 'learner reference number'],
        'last_name'   => ['last name', 'surname'],
        'first_name'  => ['first name', 'given name'],
        'middle_name' => ['middle name'],
        'name'        => ['name', "learner's name", 'name (last name, first name, middle name)'],
        'birth_date'  => ['birthdate', 'birth date', 'date of birth'],
        'gender'      => ['sex', 'gender'],
    ];

    /**
     * Step 1: parse the uploaded SF1 workbook and render a review table.
     * Nothing is written to the database here — rows are held in the session
     * until the teacher confirms which ones to import.
     */
    public function preview(Request $request)
    {
        $request->validate([
            'sf1_file' => 'required|file|mimes:xlsx,xls|max:5120',
        ]);

        $user = auth()->user();
        $lockedClass = $user->isAdmin() ? null : $user->taughtClasses()->first();

        if (!$user->isAdmin()) {
            abort_if(!$lockedClass, 403, 'You are not assigned to a class/section yet.');
        }

        try {
            $spreadsheet = IOFactory::load($request->file('sf1_file')->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, false);
        } catch (\Throwable $e) {
            return $this->createViewWithError(
                'Could not read that file. Please upload a valid SF1 Excel (.xlsx) export.'
            );
        }

        $columnMap = $this->detectHeaderRow($rows);

        if ($columnMap === null) {
            return $this->createViewWithError(
                'This doesn\'t look like an SF1 file — no recognizable LRN/Name/Sex header row was found. Please check the file and try again.'
            );
        }

        $parsedRows = $this->parseLearnerRows($rows, $columnMap);

        if (empty($parsedRows)) {
            return $this->createViewWithError(
                'The header row was found, but no learner rows could be read underneath it. Please check the file and try again.'
            );
        }

        // Flag duplicates against existing learners before showing the review table.
        foreach ($parsedRows as &$row) {
            $row['is_duplicate'] = $this->findExistingLearner($row) !== null;
        }
        unset($row);

        $request->session()->put('sf1_import_rows', $parsedRows);
        $request->session()->put('sf1_import_class_id', $lockedClass?->id);

        $createData = $this->createPageData($user);
        $createData['importRows'] = $parsedRows;

        return view('learners.create', $createData);
    }

    /**
     * Step 2: create a Learner for each row the teacher confirmed, all under
     * their own locked class/section (or the chosen class, for an admin).
     */
    public function confirm(Request $request)
    {
        $request->validate([
            'rows'         => 'required|array|min:1',
            'rows.*'       => 'integer',
            'class_id'     => 'nullable|exists:classes,id',
        ]);

        $user = auth()->user();
        $parsedRows = $request->session()->get('sf1_import_rows', []);

        if (empty($parsedRows)) {
            return redirect()->route('learners.create')
                ->with('error', 'Your import session expired. Please re-upload the file.');
        }

        if ($user->isAdmin()) {
            $classId = $request->class_id ?: null;
            $class   = $classId ? SchoolClass::find($classId) : null;
        } else {
            $class   = $user->taughtClasses()->first();
            abort_if(!$class, 403, 'You are not assigned to a class/section yet.');
            $classId = $class->id;
        }

        $schoolId = School::orderBy('id')->value('id');
        $imported = 0;
        $skipped  = 0;

        DB::transaction(function () use ($request, $parsedRows, $class, $classId, $schoolId, $user, &$imported, &$skipped) {
            foreach ($request->input('rows', []) as $index) {
                $row = $parsedRows[$index] ?? null;

                if (!$row || empty($row['first_name']) || empty($row['last_name'])) {
                    $skipped++;
                    continue;
                }

                $learner = Learner::create([
                    'lrn'         => $row['lrn'] ?: null,
                    'first_name'  => $row['first_name'],
                    'last_name'   => $row['last_name'],
                    'middle_name' => $row['middle_name'] ?: null,
                    'birth_date'  => $row['birth_date'] ?: null,
                    'gender'      => $row['gender'] ?: null,
                    'grade_level' => $class?->grade_level,
                    'school_id'   => $schoolId,
                    'class_id'    => $classId,
                ]);

                $learner->users()->attach($user->id, [
                    'relationship' => $user->isTeacher() ? 'teacher' : 'parent',
                ]);

                $imported++;
            }
        });

        $request->session()->forget(['sf1_import_rows', 'sf1_import_class_id']);

        ActivityLog::log('import_learners', "Imported {$imported} learner(s) from SF1" . ($skipped ? ", skipped {$skipped}" : ''), 'learner', null);

        return redirect()->route('learners.index')
            ->with('success', "{$imported} learner(s) imported" . ($skipped ? ", {$skipped} row(s) skipped (missing name)." : '.'));
    }

    /**
     * Search the first ~30 rows for one containing recognizable SF1 column
     * labels, and return a map of field => column index. Returns null if no
     * such row is found.
     */
    private function detectHeaderRow(array $rows): ?array
    {
        $maxScanRows = min(30, count($rows));

        for ($r = 0; $r < $maxScanRows; $r++) {
            $cells = $rows[$r] ?? [];
            $map = [];

            foreach ($cells as $col => $cell) {
                $normalized = strtolower(trim((string) $cell));
                if ($normalized === '') {
                    continue;
                }

                // Pick the longest (most specific) alias match for this cell,
                // so a combined "Name (Last, First, Middle)" header isn't
                // misdetected as just "last_name" via a short substring match.
                $bestField = null;
                $bestLen = 0;

                foreach (self::HEADER_ALIASES as $field => $aliases) {
                    foreach ($aliases as $alias) {
                        if (str_contains($normalized, $alias) && strlen($alias) > $bestLen) {
                            $bestField = $field;
                            $bestLen = strlen($alias);
                        }
                    }
                }

                if ($bestField !== null) {
                    $map[$bestField] = $col;
                }
            }

            // Treat it as the header row once we can identify at least a name
            // column (either combined "name" or separate first/last) plus one
            // other recognizable field.
            $hasName = isset($map['name']) || (isset($map['first_name']) && isset($map['last_name']));
            if ($hasName && count($map) >= 2) {
                $map['_header_row'] = $r;
                return $map;
            }
        }

        return null;
    }

    private function parseLearnerRows(array $rows, array $columnMap): array
    {
        $headerRow = $columnMap['_header_row'];
        unset($columnMap['_header_row']);

        $parsed = [];
        $currentGender = null;

        for ($r = $headerRow + 1; $r < count($rows); $r++) {
            $cells = $rows[$r] ?? [];
            $rowValues = array_map(fn($v) => trim((string) $v), $cells);
            $nonEmpty = array_filter($rowValues, fn($v) => $v !== '');

            if (empty($nonEmpty)) {
                continue;
            }

            // SF1 sheets often separate learners into "MALE" / "FEMALE"
            // section-divider rows instead of a per-row sex column.
            $joined = strtolower(implode(' ', $nonEmpty));
            if ($joined === 'male' || $joined === 'female') {
                $currentGender = $joined;
                continue;
            }

            $get = fn($field) => isset($columnMap[$field]) ? ($rowValues[$columnMap[$field]] ?? '') : '';

            [$lastName, $firstName, $middleName] = $this->resolveName($get, $rowValues);

            if ($lastName === '' && $firstName === '') {
                continue;
            }

            $gender = strtolower($get('gender'));
            if (!in_array($gender, ['male', 'female'], true)) {
                $gender = $currentGender;
            }

            $parsed[] = [
                'lrn'         => preg_replace('/\D/', '', $get('lrn')) ?: '',
                'last_name'   => $lastName,
                'first_name'  => $firstName,
                'middle_name' => $middleName,
                'birth_date'  => $this->parseDate($get('birth_date')),
                'gender'      => $gender,
            ];
        }

        return $parsed;
    }

    private function resolveName(callable $get, array $rowValues): array
    {
        $lastName   = $get('last_name');
        $firstName  = $get('first_name');
        $middleName = $get('middle_name');

        if ($lastName !== '' || $firstName !== '') {
            return [$lastName, $firstName, $middleName];
        }

        // Combined "Last Name, First Name Middle Name" column.
        $combined = $get('name');
        if ($combined === '') {
            return ['', '', ''];
        }

        $parts = array_map('trim', explode(',', $combined, 2));
        $lastName = $parts[0] ?? '';
        $rest = trim($parts[1] ?? '');
        $restParts = preg_split('/\s+/', $rest);
        $firstName = $restParts[0] ?? '';
        $middleName = implode(' ', array_slice($restParts, 1));

        return [$lastName, $firstName, $middleName];
    }

    private function parseDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        // Excel sometimes yields a numeric serial date even via toArray().
        if (is_numeric($value)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }

        try {
            return \Carbon\Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function findExistingLearner(array $row): ?Learner
    {
        if (!empty($row['lrn'])) {
            $match = Learner::byLrn($row['lrn'])->first();
            if ($match) {
                return $match;
            }
        }

        // Names are encrypted, so compare in PHP.
        return Learner::all()->first(fn (Learner $l) => Directory::key($l->first_name) === Directory::key($row['first_name'])
            && Directory::key($l->last_name) === Directory::key($row['last_name'])
            && (! $row['birth_date'] || $l->birth_date?->toDateString() === $row['birth_date']));
    }

    private function createPageData($user): array
    {
        $lockedClass = null;

        if ($user->isAdmin()) {
            $allClasses = SchoolClass::orderBy('grade_level')->orderBy('section')->get();
        } else {
            $lockedClass = $user->taughtClasses()->first();
            $allClasses  = $lockedClass ? collect([$lockedClass]) : collect();
        }

        return [
            'allClasses'  => $allClasses,
            'lockedClass' => $lockedClass,
            'schoolName'  => School::orderBy('id')->value('name') ?? 'Old Sagay Elementary School',
            'parents'     => Directory::sortUsers(\App\Models\User::where('role', 'parent')->get()),
        ];
    }

    private function createViewWithError(string $message)
    {
        $data = $this->createPageData(auth()->user());
        $data['importError'] = $message;

        return view('learners.create', $data);
    }
}
