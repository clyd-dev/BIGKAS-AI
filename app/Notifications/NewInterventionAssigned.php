<?php

namespace App\Notifications;

use App\Models\InterventionLog;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NewInterventionAssigned extends Notification
{
    use Queueable;

    public function __construct(protected InterventionLog $log) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title'   => 'New Home Activity Assigned',
            'message' => "{$this->log->intervention?->name} assigned for {$this->log->learner?->getFullName()}",
            'url'     => route('parent.children.interventions', $this->log->learner_id),
            'learner_id' => $this->log->learner_id,
        ];
    }
}