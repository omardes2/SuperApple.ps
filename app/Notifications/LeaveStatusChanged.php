<?php

namespace App\Notifications;

use App\Enums\LeaveStatus;
use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LeaveStatusChanged extends Notification
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
        $status = $this->leaveRequest->status;
        $title = match ($status) {
            LeaveStatus::Approved => 'تم اعتماد إجازتك',
            LeaveStatus::Rejected => 'تم رفض طلب الإجازة',
            LeaveStatus::Cancelled => 'تم إلغاء الإجازة',
            default => 'تحديث طلب الإجازة',
        };

        return [
            'type' => 'leave.'.$status->value,
            'title' => $title,
            'message' => "طلب الإجازة {$this->leaveRequest->reference_no}: {$status->label()}.",
            'leave_request_id' => $this->leaveRequest->id,
        ];
    }
}
