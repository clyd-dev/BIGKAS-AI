<?php

namespace App\Support;

/**
 * Navigation for the admin / teacher shell (layouts.app). Students and parents have their own layouts.
 *
 * One list per role feeds the desktop sidebar, the hamburger drawer and the
 * phone bottom tab bar, so a link is added in one place. Items flagged `tab`
 * appear in the bottom bar (keep it to four); everything else goes in "More".
 */
class StaffNav
{
    /** @return array<int, array{label: ?string, items: array<int, array<string, mixed>>}> */
    public static function sections(?string $role): array
    {
        $dash = self::item('bi-speedometer2', 'Dashboard', 'dashboard', ['dashboard'], tab: true);

        return match ($role) {
            'admin' => [
                ['label' => null, 'items' => [
                    $dash,
                    self::item('bi-people', 'Learners', 'learners.index', ['learners.*'], tab: true),
                    self::item('bi-clipboard-check', 'Assessments', 'assessments.index', ['assessments.*'], tab: true),
                    self::item('bi-ui-checks-grid', 'Screening Test', 'screening.index', ['screening.*']),
                    self::item('bi-journal-text', 'Materials', 'materials.index', ['materials.*']),
                    self::item('bi-lightbulb', 'Interventions', 'interventions.index', ['interventions.*']),
                    self::item('bi-bar-chart', 'Reports', 'reports.index', ['reports.*']),
                    self::item('bi-envelope', 'Messages', 'messages.index', ['messages.*'], badge: 'msgs', tab: true),
                ]],
                ['label' => 'Administration', 'items' => [
                    self::item('bi-journal-bookmark-fill', 'Phil-IRI Forms', 'admin.phil-iri', ['admin.phil-iri']),
                    self::item('bi-diagram-3', 'Classrooms', 'admin.classes', ['admin.classes']),
                    self::item('bi-person-badge', 'Users', 'admin.users', ['admin.users']),
                    self::item('bi-building', 'Schools', 'admin.schools', ['admin.schools']),
                    self::item('bi-clock-history', 'Activity Logs', 'admin.logs', ['admin.logs']),
                    self::item('bi-sliders', 'Settings', 'admin.settings', ['admin.settings']),
                ]],
            ],
            'teacher' => [
                ['label' => null, 'items' => [
                    $dash,
                    self::item('bi-people', 'Learners', 'learners.index', ['learners.*'], tab: true),
                    self::item('bi-clipboard-check', 'Assessments', 'assessments.index', ['assessments.*'], tab: true),
                    self::item('bi-ui-checks-grid', 'Screening Test', 'screening.index', ['screening.*']),
                    self::item('bi-journal-text', 'Materials', 'materials.index', ['materials.*']),
                    self::item('bi-lightbulb', 'Interventions', 'interventions.index', ['interventions.*']),
                    self::item('bi-controller', 'Practice Center', 'practice.index', ['practice.*']),
                    self::item('bi-bar-chart', 'Reports', 'reports.index', ['reports.*']),
                    self::item('bi-envelope', 'Messages', 'messages.index', ['messages.*'], badge: 'msgs', tab: true),
                ]],
            ],
            default => [ // any other role: just the dashboard
                ['label' => null, 'items' => [$dash]],
            ],
        };
    }

    /** Items shown as bottom tabs, in order. */
    public static function tabs(?string $role): array
    {
        return collect(self::sections($role))->pluck('items')->flatten(1)->where('tab', true)->values()->all();
    }

    /** Items that live in the "More" sheet (everything that is not a tab). */
    public static function more(?string $role): array
    {
        return collect(self::sections($role))->pluck('items')->flatten(1)->where('tab', false)->values()->all();
    }

    private static function item(string $icon, string $label, string $route, array $active, ?string $badge = null, bool $tab = false): array
    {
        return compact('icon', 'label', 'route', 'active', 'badge', 'tab');
    }
}
