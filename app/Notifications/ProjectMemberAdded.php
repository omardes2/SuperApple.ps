<?php

namespace App\Notifications;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ProjectMemberAdded extends Notification
{
    use Queueable;

    public function __construct(public Project $project) {}

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'project.member_added',
            'title' => 'تمت إضافتك إلى مشروع',
            'message' => "تمت إضافتك إلى المشروع {$this->project->project_number}: {$this->project->name}.",
            'project_id' => $this->project->id,
        ];
    }
}
