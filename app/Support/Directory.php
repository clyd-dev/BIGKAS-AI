<?php

namespace App\Support;

use App\Models\Learner;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as PaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Names, emails and LRNs are stored encrypted, so the database cannot search or sort them.
 * These helpers do it in PHP on the rows a user is already allowed to see (a school's worth).
 */
class Directory
{
    /** Case- and accent-insensitive sort key. */
    public static function key(?string $value): string
    {
        return Str::ascii(mb_strtolower(trim((string) $value)));
    }

    public static function sortLearners(Collection $learners): Collection
    {
        return $learners
            ->sort(fn (Learner $a, Learner $b) => [self::key($a->last_name), self::key($a->first_name), $a->id]
                <=> [self::key($b->last_name), self::key($b->first_name), $b->id])
            ->values();
    }

    public static function sortUsers(Collection $users): Collection
    {
        return $users
            ->sort(fn (User $a, User $b) => [self::key($a->name), $a->id] <=> [self::key($b->name), $b->id])
            ->values();
    }

    /** Learner matches a search term on first/middle/last name, "first last", or LRN. */
    public static function learnerMatches(Learner $learner, string $term): bool
    {
        $term = self::key($term);
        if ($term === '') {
            return true;
        }

        $haystacks = [
            self::key($learner->first_name),
            self::key($learner->last_name),
            self::key($learner->first_name . ' ' . $learner->last_name),
            self::key($learner->last_name . ' ' . $learner->first_name),
            self::key($learner->lrn),
        ];

        foreach ($haystacks as $h) {
            if ($h !== '' && str_contains($h, $term)) {
                return true;
            }
        }

        return false;
    }

    public static function userMatches(User $user, string $term): bool
    {
        $term = self::key($term);

        return $term === '' || str_contains(self::key($user->name), $term) || str_contains(self::key($user->email), $term);
    }

    /** IDs of learners (within an already access-scoped query) that match the search term. */
    public static function matchingLearnerIds(Builder|\Illuminate\Database\Eloquent\Relations\Relation $scope, string $term): array
    {
        return (clone $scope)->get()
            ->filter(fn (Learner $l) => self::learnerMatches($l, $term))
            ->pluck('id')->all();
    }

    /** Paginate an in-memory collection the way ->paginate() would, keeping the query string. */
    public static function paginate(Collection $items, int $perPage, string $pageName = 'page'): PaginatorContract
    {
        $page = Paginator::resolveCurrentPage($pageName);

        return (new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => $pageName]
        ))->withQueryString();
    }
}
