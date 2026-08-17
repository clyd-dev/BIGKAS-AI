<?php

namespace App\Notifications;

use App\Models\Assessment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NewAssessmentCompleted extends Notification
{
    use Queueable;

    public function __construct(protected Assessment $assessment) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title'   => 'New Assessment Result',
            'message' => "New reading assessment result available for {$this->assessment->learner?->getFullName()}",
            'url'     => route('parent.children.assessment-detail', [$this->assessment->learner_id, $this->assessment->id]),
            'learner_id' => $this->assessment->learner_id,
        ];
    }
}