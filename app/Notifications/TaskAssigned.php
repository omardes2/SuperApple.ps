<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TaskAssigned extends Notification
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
            'type' => 'task.assigned',
            'title' => 'مهمة جديدة مُسندة إليك',
            'message' => "تم إسناد المهمة {$this->task->task_number}: {$this->task->title}.",
            'task_id' => $this->task->id,
        ];
    }
}
