<?php

namespace App\Notifications;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NewMessageReceived extends Notification
{
    use Queueable;

    public function __construct(protected Message $message) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $routeName = $notifiable->role === 'parent' ? 'parent.messages.show' : 'messages.show';
        $thread    = $this->message->parent_message_id ?? $this->message->id;

        return [
            'title'   => 'New Message',
            'message' => "{$this->message->sender?->name}: {$this->message->subject}",
            'url'     => route($routeName, $thread),
        ];
    }
}