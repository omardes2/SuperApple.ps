<?php

namespace App\Livewire\Employee;

use App\Enums\TaskMemberStatus;
use App\Livewire\Shared\TaskShow as SharedTaskShow;
use App\Models\Employee;
use App\Services\TaskMemberService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/**
 * The employee-facing task page with the collaborative workflow: each member
 * has their own start/complete state, the primary assignee/creator can manage
 * participants, and the task closes only when the whole team finishes. Comments,
 * checklist and attachments are inherited from the shared base.
 */
#[Layout('layouts.employee')]
#[Title('المهمة')]
class TaskShow extends SharedTaskShow
{
    public string $participantSearch = '';

    // ---- Personal execution (own state only) ----

    public function startMine(TaskMemberService $members): void
    {
        try {
            $members->start($this->task, Auth::user());
        } catch (\RuntimeException $e) {
            $this->addError('workflow', $e->getMessage());

            return;
        }
        $this->task->refresh();
        session()->flash('status', 'تم بدء المهمة.');
    }

    public function completeMine(TaskMemberService $members): void
    {
        try {
            $members->complete($this->task, Auth::user());
        } catch (\RuntimeException $e) {
            $this->addError('workflow', $e->getMessage());

            return;
        }
        $this->task->refresh();
        session()->flash('status', 'تم إتمام عملك في هذه المهمة.');
    }

    // ---- Participant management (creator / primary assignee) ----

    public function addParticipant(int $employeeId, TaskMemberService $members): void
    {
        $employee = Employee::find($employeeId);
        if ($employee === null) {
            $this->addError('participant', 'الموظف غير موجود.');

            return;
        }

        try {
            $members->addParticipant($this->task, $employee, Auth::user());
        } catch (\RuntimeException $e) {
            $this->addError('participant', $e->getMessage());

            return;
        }

        $this->participantSearch = '';
        $this->task->refresh();
        session()->flash('status', 'تمت إضافة المشارك.');
    }

    public function removeParticipant(int $employeeId, TaskMemberService $members): void
    {
        $employee = Employee::find($employeeId);
        if ($employee === null) {
            return;
        }

        try {
            $members->removeParticipant($this->task, $employee, Auth::user());
        } catch (\RuntimeException $e) {
            $this->addError('participant', $e->getMessage());

            return;
        }

        $this->task->refresh();
    }

    public function render()
    {
        $this->task->loadMissing(['customer', 'services', 'primaryAssignee', 'activeMembers', 'department']);

        $members = app(TaskMemberService::class);
        $user = Auth::user();
        $employee = $user->employee;

        $myMember = $this->task->memberFor($employee);
        $myStatus = $myMember ? TaskMemberStatus::from($myMember->pivot->status) : null;
        $canManageTeam = $members->canManageTeam($this->task, $user);

        // Participant search: active employees not already on the team.
        $participantResults = collect();
        if ($canManageTeam && trim($this->participantSearch) !== '') {
            $term = trim($this->participantSearch);
            $memberIds = $this->task->activeMembers->pluck('id')->all();
            $participantResults = Employee::active()
                ->whereNotIn('id', $memberIds)
                ->where(fn ($q) => $q
                    ->where('full_name', 'like', "%{$term}%")
                    ->orWhere('employee_number', 'like', "%{$term}%")
                    ->orWhere('job_title', 'like', "%{$term}%"))
                ->orderBy('full_name')
                ->limit(10)
                ->get(['id', 'full_name', 'employee_number', 'job_title']);
        }

        return view('livewire.employee.task-show', [
            'comments' => $this->task->comments()->with('user')->get(),
            'checklist' => $this->task->checklistItems()->get(),
            'history' => $this->task->statusHistory()->with('changedBy')->get(),
            'attachments' => $this->task->attachments()->with('uploader')->get(),
            'team' => $this->task->activeMembers,
            'myStatus' => $myStatus,
            'isMember' => $myMember !== null,
            'canManageTeam' => $canManageTeam,
            'participantResults' => $participantResults,
            'canComment' => $user->can('tasks.comment'),
            'canChecklist' => $user->can('tasks.checklist'),
            'canAttach' => $user->can('tasks.attachments'),
        ]);
    }
}
