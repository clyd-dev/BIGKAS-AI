<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Learner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Issues student-portal PINs to many learners at once.
 *
 * The student login finds a child by PIN alone, so two children must never hold
 * the same one: the second would sign in as the first and see their work. That
 * is the whole job of this class beyond calling save() in a loop. A candidate is
 * therefore rejected if it is
 *
 *   - one of the obvious PINs (Learner::COMMON_PINS),
 *   - already held by another learner whose PIN can be read,
 *   - already handed out earlier in this same batch, or
 *   - the PIN behind an old bcrypt hash that cannot be read back.
 *
 * That last check is the expensive one - a bcrypt comparison costs about a
 * quarter of a second, and every candidate is checked against every such hash -
 * so the loop works against a time budget. When it runs out it stops and hands
 * back the learners it did not reach, and running it again finishes them. It
 * never skips the check to go faster, and it always issues at least one learner
 * per run so that running again always makes progress.
 *
 * Learners being given a new PIN in this batch are dropped from the checks: the
 * PIN they hold is about to stop existing, so it blocks nobody.
 */
class PinIssuer
{
    /** Seconds of PIN-checking per run, kept well inside a web request. */
    public const TIME_BUDGET_SECONDS = 40;

    /**
     * @param  Collection<int, Learner>  $learners  who to give a new PIN, replacing any they hold;
     *                                              choosing who is the caller's job
     * @param  ?callable  $draw  returns a candidate PIN; only tests pass one
     * @return array{issued: Collection<int, Learner>, deferred: Collection<int, Learner>}
     */
    public function issue(
        Collection $learners,
        int $budgetSeconds = self::TIME_BUDGET_SECONDS,
        ?callable $draw = null,
    ): array {
        $draw ??= fn (): string => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $batchIds = $learners->pluck('id')->all();

        // Everyone else's PIN, read once. Decrypting all of them for every
        // candidate (as Learner::generatePin() does) is what makes a loop slow.
        $taken = [];
        $legacyHashes = [];

        Learner::query()->whereNotNull('pin')->whereNotIn('id', $batchIds)->get(['id', 'pin'])
            ->each(function (Learner $holder) use (&$taken, &$legacyHashes) {
                if ($holder->hasLegacyPin()) {
                    $legacyHashes[] = $holder->getRawOriginal('pin');
                } elseif (($plain = $holder->pin) !== null) {
                    $taken[$plain] = true;
                }
            });

        $issued = collect();
        $deferred = collect();
        $startedAt = microtime(true);

        DB::transaction(function () use ($learners, $draw, $budgetSeconds, $startedAt, &$taken, $legacyHashes, &$issued, &$deferred) {
            foreach ($learners as $learner) {
                $outOfTime = $issued->isNotEmpty() && (microtime(true) - $startedAt) >= $budgetSeconds;

                if ($outOfTime) {
                    $deferred->push($learner);

                    continue;
                }

                do {
                    $pin = (string) $draw();
                } while (
                    in_array($pin, Learner::COMMON_PINS, true)
                    || isset($taken[$pin])
                    || $this->matchesAnyHash($pin, $legacyHashes)
                );

                $taken[$pin] = true;

                // pin is not fillable: explicit assignment, encrypted by the cast.
                $learner->pin = $pin;
                $learner->pin_created_at = now();
                $learner->save();

                ActivityLog::log(
                    'generate_pin',
                    "Generated PIN for learner: {$learner->first_name} {$learner->last_name}",
                    'learner',
                    $learner->id
                );

                $issued->push($learner);
            }
        });

        return ['issued' => $issued, 'deferred' => $deferred];
    }

    /** @param array<int, string> $hashes */
    private function matchesAnyHash(string $pin, array $hashes): bool
    {
        foreach ($hashes as $hash) {
            if (Hash::check($pin, $hash)) {
                return true;
            }
        }

        return false;
    }
}
