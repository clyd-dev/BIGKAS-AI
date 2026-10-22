<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One grade level a parent account belongs to (see the parent_grades migration). */
class ParentGrade extends Model
{
    protected $fillable = ['user_id', 'grade_level'];

    protected function casts(): array
    {
        return ['grade_level' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
