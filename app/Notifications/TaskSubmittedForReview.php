<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TaskSubmittedForReview extends Notification
{
    use Queueable;

    public function __construct(public Task $task) {}

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'task.waiting_review',
            'title' => 'مهمة بانتظار المراجعة',
            'message' => "المهمة {$this->task->task_number} بانتظار مراجعتك.",
            'task_id' => $this->task->id,
        ];
    }
}
