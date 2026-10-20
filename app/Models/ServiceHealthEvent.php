<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One change in the analysis engine's health (see ServiceHealthMonitor). */
class ServiceHealthEvent extends Model
{
    public const UPDATED_AT = null;

    public const HEALTHY = 'healthy';
    public const DEGRADED = 'degraded';
    public const DOWN = 'down';

    protected $fillable = ['state', 'level', 'message', 'alerted_at'];

    protected function casts(): array
    {
        return [
            'alerted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function isHealthy(): bool
    {
        return $this->level === self::HEALTHY;
    }
}
