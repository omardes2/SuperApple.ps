<?php

namespace App\Notifications;

use App\Models\AttendanceRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AttendanceAdjusted extends Notification
{
    use Queueable;

    public function __construct(public AttendanceRecord $record) {}

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'attendance.adjusted',
            'title' => 'تعديل سجل الدوام',
            'message' => "تم تعديل سجل دوامك بتاريخ {$this->record->attendance_date->format('Y-m-d')}.",
            'attendance_record_id' => $this->record->id,
        ];
    }
}
