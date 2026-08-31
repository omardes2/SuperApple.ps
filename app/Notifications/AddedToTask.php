<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Sent to an employee when they are added as a participant to a task.
 */
class AddedToTask extends Notification
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
            'type' => 'task.participant_added',
            'title' => 'تمت إضافتك إلى مهمة',
            'message' => "تمت إضافتك إلى المهمة: {$this->task->title}",
            'task_id' => $this->task->id,
        ];
    }
}
