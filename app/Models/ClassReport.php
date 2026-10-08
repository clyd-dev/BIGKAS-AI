<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassReport extends Model
{
    public const PERIODS = [
        'pre_test'  => 'Pre-Test (Panimulang Pagtatasa)',
        'post_test' => 'Post-Test (Panapos na Pagtatasa)',
    ];

    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_REVIEWED  = 'reviewed';
    public const STATUS_RETURNED  = 'returned';

    protected $fillable = [
        'class_id', 'teacher_id', 'school_year', 'period', 'status',
        'snapshot', 'teacher_note', 'principal_comment',
        'submitted_at', 'reviewed_at', 'reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'snapshot'     => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at'  => 'datetime',
        ];
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function periodLabel(): string
    {
        return self::PERIODS[$this->period] ?? $this->period;
    }

    /**
     * Build the point-in-time reading profile of a section, per language
     * (Phil-IRI reports Filipino and English separately). Each learner is
     * counted once per language using their latest completed assessment.
     */
    public static function buildSnapshot(SchoolClass $class, ?string $period = null): array
    {
        $learners  = $class->learners()->get(['id']);
        $learnerIds = $learners->pluck('id');

        $latest = Assessment::whereIn('learner_id', $learnerIds)
            ->where('status', Assessment::STATUS_COMPLETED)
            ->whereHas('result')
            ->with('result')
            ->orderByDesc('created_at')
            ->get()
            ->unique(fn ($a) => $a->learner_id . '|' . $a->language);

        $languages = [];
        foreach (['fil' => 'Filipino', 'en' => 'English'] as $code => $label) {
            $rows = $latest->where('language', $code);
            $levels = [
                'independent'   => $rows->filter(fn ($a) => $a->result->reading_level === 'independent')->count(),
                'instructional' => $rows->filter(fn ($a) => $a->result->reading_level === 'instructional')->count(),
                'frustration'   => $rows->filter(fn ($a) => $a->result->reading_level === 'frustration')->count(),
            ];
            $languages[$code] = [
                'label'        => $label,
                'assessed'     => $rows->count(),
                'not_assessed' => max(0, $learners->count() - $rows->count()),
                'levels'       => $levels,
                'avg_accuracy' => $rows->isEmpty() ? null : round((float) $rows->avg(fn ($a) => $a->result->accuracy_rate), 1),
                'avg_wpm'      => $rows->isEmpty() ? null : round((float) $rows->avg(fn ($a) => $a->result->words_per_minute), 1),
            ];
        }

        return [
            'grade_level' => $class->grade_level,
            'section'     => $class->section,
            'enrolment'   => $learners->count(),
            'languages'   => $languages,
            // Group Screening Test (Forms 1A/1B) counts for the reporting period.
            'screening'   => $period ? GstResult::summarize($class, $period) : null,
            'generated_at' => now()->toDateTimeString(),
        ];
    }
}
