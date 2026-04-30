<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'sender_id',
        'receiver_id',
        'learner_id',
        'subject',
        'body',
        'read_at',
        'parent_message_id',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    // ── Relationships ──

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    public function parentMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'parent_message_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Message::class, 'parent_message_id')->orderBy('created_at');
    }

    // ── Scopes ──

    public function scopeForUser($query, int $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('sender_id', $userId)
              ->orWhere('receiver_id', $userId);
        });
    }

    public function scopeInbox($query, int $userId)
    {
        return $query->where('receiver_id', $userId)->whereNull('parent_message_id');
    }

    public function scopeSent($query, int $userId)
    {
        return $query->where('sender_id', $userId)->whereNull('parent_message_id');
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeThreads($query, int $userId)
    {
        return $query->whereNull('parent_message_id')
            ->where(function ($q) use ($userId) {
                $q->where('sender_id', $userId)
                  ->orWhere('receiver_id', $userId);
            });
    }

    // ── Helpers ──

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function markAsRead(): void
    {
        if (!$this->read_at) {
            $this->update(['read_at' => now()]);
        }
    }

    public function isFromUser(int $userId): bool
    {
        return $this->sender_id === $userId;
    }

    public function getOtherParticipant(int $userId): ?User
    {
        return $this->sender_id === $userId ? $this->receiver : $this->sender;
    }

    public function getThreadCount(): int
    {
        return $this->replies()->count() + 1;
    }

    public function getUnreadCountForUser(int $userId): int
    {
        $count = 0;
        if ($this->receiver_id === $userId && !$this->read_at) {
            $count++;
        }
        $count += $this->replies()->where('receiver_id', $userId)->whereNull('read_at')->count();
        return $count;
    }
}
