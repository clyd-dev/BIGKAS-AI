<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PracticeItem extends Model
{
    use HasFactory;

    public $timestamps = true;

    protected $fillable = [
        'type',
        'content',
        'grade_level',
        'language',
        'metadata',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'grade_level' => 'integer',
            'metadata' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeForGrade($query, int $gradeLevel)
    {
        return $query->where('grade_level', $gradeLevel);
    }

    public function scopeForLanguage($query, string $language)
    {
        return $query->where('language', $language);
    }
}
