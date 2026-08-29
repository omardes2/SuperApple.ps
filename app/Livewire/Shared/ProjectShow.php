<?php

namespace App\Livewire\Shared;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Shared project detail logic. Admin\ProjectShow and Employee\ProjectShow
 * subclass this only to bind the correct RTL layout; all behaviour and the
 * visibility guard live here.
 */
abstract class ProjectShow extends Component
{
    use WithFileUploads;

    public Project $project;

    public string $tab = 'overview';

    public ?int $newMemberId = null;

    public string $newMemberRole = '';

    public string $attachTitle = '';

    public $attachFile = null;

    public function mount(Project $project): void
    {
        // Real, query-level authorization: the user must be allowed to see this
        // exact project (all-access permission, membership, or a task in it).
        abort_unless($project->isVisibleTo(Auth::user()), 403);

        $this->project = $project;
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
    }

    public function addMember(ProjectService $service): void
    {
        $this->authorize('projects.members');

        $this->validate(['newMemberId' => 'required|integer|exists:employees,id']);

        try {
            $service->addMember($this->project, Employee::findOrFail($this->newMemberId), $this->newMemberRole ?: null);
            session()->flash('status', 'تمت إضافة العضو.');
        } catch (\RuntimeException $e) {
            $this->addError('newMemberId', $e->getMessage());

            return;
        }

        $this->reset(['newMemberId', 'newMemberRole']);
    }

    public function removeMember(int $employeeId, ProjectService $service): void
    {
        $this->authorize('projects.members');
        $service->removeMember($this->project, Employee::findOrFail($employeeId));
    }

    public function addAttachment(): void
    {
        $this->authorize('projects.attachments');

        $this->validate([
            'attachTitle' => 'nullable|string|max:150',
            'attachFile' => 'required|file|max:10240',
        ]);

        $path = $this->attachFile->store("project-attachments/{$this->project->id}", 'local');

        $this->project->attachments()->create([
            'title' => $this->attachTitle ?: $this->attachFile->getClientOriginalName(),
            'disk' => 'local',
            'path' => $path,
            'original_name' => $this->attachFile->getClientOriginalName(),
            'mime' => $this->attachFile->getMimeType(),
            'size' => $this->attachFile->getSize(),
            'uploaded_by' => Auth::id(),
        ]);

        $this->reset(['attachTitle', 'attachFile']);
        session()->flash('status', 'تم رفع الملف.');
    }

    public function render()
    {
        $this->project->loadMissing(['customer', 'projectManager', 'department']);

        $data = [
            'canManageMembers' => Auth::user()->can('projects.members'),
            'canAttach' => Auth::user()->can('projects.attachments'),
            'availableEmployees' => Employee::active()->orderBy('full_name')->get(['id', 'full_name']),
        ];

        if ($this->tab === 'tasks') {
            $data['tasks'] = $this->project->tasks()
                ->visibleTo(Auth::user())
                ->with('primaryAssignee')->latest()->get();
        }

        if ($this->tab === 'team') {
            $data['members'] = $this->project->memberships()->with('employee')->get();
        }

        if ($this->tab === 'files') {
            $data['attachments'] = $this->project->attachments()->with('uploader')->get();
        }

        if ($this->tab === 'activity') {
            $data['activity'] = AuditLog::where('auditable_type', $this->project->getMorphClass())
                ->where('auditable_id', $this->project->id)
                ->with('user')->latest('created_at')->limit(50)->get();
        }

        return view('livewire.shared.project-show', $data);
    }
}
