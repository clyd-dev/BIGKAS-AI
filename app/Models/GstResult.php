<?php

namespace App\Models;

use App\Services\GstScoring;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GstResult extends Model
{
    public const LANGUAGES = ['fil' => 'Filipino', 'en' => 'English'];

    protected $fillable = [
        'learner_id', 'class_id', 'school_year', 'period', 'language', 'test_level',
        'test_taken', 'literal_correct', 'inferential_correct', 'critical_correct',
        'total_score', 'classification', 'starting_level', 'entered_by',
    ];

    protected function casts(): array
    {
        return [
            'test_taken' => 'boolean',
        ];
    }

    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * Screening summary of one section for a period, per language:
     * enrolment, learners scored, how many reached the passing mark, and how many did not.
     * "Not tested" are learners with no scored result (absent or not yet encoded).
     */
    public static function summarize(SchoolClass $class, string $period): array
    {
        $enrolment = $class->learners()->count();
        $results   = static::where('class_id', $class->id)
            ->where('school_year', $class->school_year)
            ->where('period', $period)
            ->where('test_taken', true)
            ->whereNotNull('total_score')
            ->get()
            ->groupBy('language');

        $summary = [];
        foreach (self::LANGUAGES as $code => $label) {
            $rows = $results->get($code, collect());
            $summary[$code] = [
                'label'      => $label,
                'enrolment'  => $enrolment,
                'tested'     => $rows->count(),
                'at_grade'   => $rows->where('classification', GstScoring::AT_GRADE_LEVEL)->count(),
                'below'      => $rows->where('classification', GstScoring::NEEDS_ASSESSMENT)->count(),
                'not_tested' => max(0, $enrolment - $rows->count()),
            ];
        }

        return $summary;
    }

    /** Recompute total, classification and starting level from the three scores. */
    public function applyScoring(): self
    {
        if (! $this->test_taken) {
            $this->literal_correct = $this->inferential_correct = $this->critical_correct = null;
        }

        $this->total_score    = GstScoring::total($this->literal_correct, $this->inferential_correct, $this->critical_correct);
        $this->classification = GstScoring::classify($this->total_score);
        $this->starting_level = GstScoring::startingLevel((int) $this->test_level, $this->total_score);

        return $this;
    }
}
