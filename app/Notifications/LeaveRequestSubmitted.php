<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LeaveRequestSubmitted extends Notification
{
    use Queueable;

    public function __construct(public LeaveRequest $leaveRequest) {}

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'leave.submitted',
            'title' => 'طلب إجازة جديد',
            'message' => "قدّم {$this->leaveRequest->employee->full_name} طلب إجازة ({$this->leaveRequest->total_days} يوم).",
            'leave_request_id' => $this->leaveRequest->id,
        ];
    }
}
