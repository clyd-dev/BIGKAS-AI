<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Learner;
use App\Services\PinIssuer;
use App\Support\Directory;
use Illuminate\Http\Request;

/**
 * Issue student-portal PINs to many learners at once, and print them.
 *
 * Teacher only, and only for the teacher's own active learners - the same scope
 * as issuing one PIN from a learner's page. The page itself never prints a PIN;
 * they appear only on the printable sheet, which is logged.
 */
class LearnerPinController extends Controller
{
    /** More than a section; a guard against a runaway request, not a target. */
    private const MAX_PER_REQUEST = 200;

    public function index(Request $request)
    {
        $user = $request->user();

        $classes = $user->taughtClasses()->where('is_active', true)
            ->orderBy('grade_level')->orderBy('section')->get();

        $everyone = $this->scope($request)->with('schoolClass')->get();

        $learners = $request->filled('class_id')
            ? $everyone->where('class_id', (int) $request->class_id)
            : $everyone;

        return view('learners.pins', [
            'learners' => Directory::sortLearners($learners),
            'classes' => $classes,
            'classId' => $request->filled('class_id') ? (int) $request->class_id : null,
            // Counted over the whole filtered list, so the tiles match the table.
            'counts' => [
                'none' => $learners->reject->hasPin()->count(),
                'readable' => $learners->filter(fn (Learner $l) => $l->hasPin() && ! $l->hasLegacyPin())->count(),
                'legacy' => $learners->filter->hasLegacyPin()->count(),
            ],
        ]);
    }

    public function store(Request $request, PinIssuer $issuer)
    {
        $data = $request->validate([
            'learner_ids' => ['required', 'array', 'min:1', 'max:' . self::MAX_PER_REQUEST],
            'learner_ids.*' => ['integer'],
            'replace_existing' => ['nullable', 'boolean'],
        ]);

        // Whatever ids were posted, only this teacher's own active learners count.
        $chosen = $this->scope($request)->whereIn('learners.id', $data['learner_ids'])
            ->orderBy('learners.id')->get();

        $replace = $request->boolean('replace_existing');

        // A child who already has a PIN has probably been told it. Replacing it
        // makes theirs stop working, so it only happens when asked for.
        $toIssue = $replace ? $chosen : $chosen->reject->hasPin()->values();
        $kept = $chosen->count() - $toIssue->count();

        if ($toIssue->isEmpty()) {
            return back()->with('warning', $chosen->isEmpty()
                ? 'None of those learners could be found.'
                : 'Nothing to issue: ' . $this->already($kept) . ' Tick "Replace existing PINs" to give them a new one.');
        }

        // Checking against old unreadable PINs is slow (see PinIssuer), so this
        // request may run longer than PHP's default.
        @set_time_limit(PinIssuer::TIME_BUDGET_SECONDS + 30);

        $result = $issuer->issue($toIssue);
        $issued = $result['issued'];
        $deferred = $result['deferred'];

        $warning = [];
        if ($kept > 0) {
            $warning[] = $this->already($kept) . ' Tick "Replace existing PINs" to give them a new one.';
        }
        if ($deferred->isNotEmpty()) {
            $warning[] = "The time limit was reached, so {$deferred->count()} "
                . ($deferred->count() === 1 ? 'learner still has' : 'learners still have')
                . ' no PIN. Press Generate again to finish them.';
        }

        $redirect = redirect()
            ->route('learners.pins.sheet', ['ids' => $issued->pluck('id')->all()])
            ->with('success', $issued->count() . ' ' . ($issued->count() === 1 ? 'PIN was' : 'PINs were')
                . ' issued. Print the slips and give each child only their own.');

        return $warning ? $redirect->with('warning', implode(' ', $warning)) : $redirect;
    }

    /**
     * Printable slips - name and PIN - for the chosen learners, one section, or
     * everyone. A PIN that cannot be read back (issued before PINs could be
     * viewed) is left off and counted instead.
     */
    public function sheet(Request $request)
    {
        $request->validate([
            'ids' => ['nullable', 'array', 'max:500'],
            'ids.*' => ['integer'],
            'class_id' => ['nullable', 'integer'],
        ]);

        $query = $this->scope($request)->with(['schoolClass', 'school']);

        if ($request->filled('ids')) {
            $query->whereIn('learners.id', $request->input('ids'));
        } elseif ($request->filled('class_id')) {
            $query->where('class_id', (int) $request->class_id);
        }

        $found = Directory::sortLearners($query->get());
        $slips = $found->filter(fn (Learner $l) => $l->pin !== null)->values();

        // It lists children by name beside the key to their account.
        ActivityLog::log(
            'print_pin_sheet',
            "Opened the PIN sheet for {$slips->count()} learner" . ($slips->count() === 1 ? '' : 's'),
            'learner',
            null
        );

        return response()
            ->view('learners.pin-sheet', [
                'slips' => $slips,
                'withheld' => $found->count() - $slips->count() - $found->reject->hasPin()->count(),
                'missing' => $found->reject->hasPin()->count(),
            ])
            ->header('Cache-Control', 'no-store, private');
    }

    /** The teacher's own active learners. */
    private function scope(Request $request)
    {
        return $request->user()->accessibleLearnersQuery()->where('learners.is_active', true);
    }

    private function already(int $count): string
    {
        return $count . ($count === 1 ? ' learner already has a PIN and was' : ' learners already have a PIN and were')
            . ' left as they are.';
    }
}
