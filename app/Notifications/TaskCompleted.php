<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Sent to the task creator (and managers) once every active team member has
 * finished their part and the task itself is Completed.
 */
class TaskCompleted extends Notification
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
            'type' => 'task.completed',
            'title' => 'اكتملت المهمة',
            'message' => "تم إتمام المهمة: {$this->task->title}",
            'task_id' => $this->task->id,
        ];
    }
}
