<?php

namespace App\Notifications;

use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TaskStatusChanged extends Notification
{
    use Queueable;

    public function __construct(
        public Task $task,
        public TaskStatus $from,
        public TaskStatus $to,
    ) {}

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        $title = match ($this->to) {
            TaskStatus::ChangesRequested => 'مطلوب تعديلات على مهمتك',
            TaskStatus::Completed => 'تم اعتماد مهمتك',
            TaskStatus::InProgress => 'تم إعادة فتح مهمتك',
            TaskStatus::Cancelled => 'تم إلغاء مهمتك',
            default => 'تحديث حالة المهمة',
        };

        return [
            'type' => 'task.'.$this->to->value,
            'title' => $title,
            'message' => "المهمة {$this->task->task_number}: {$this->to->label()}.",
            'task_id' => $this->task->id,
        ];
    }
}
