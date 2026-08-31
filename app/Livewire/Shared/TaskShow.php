<?php

namespace App\Livewire\Shared;

use App\Enums\TaskStatus;
use App\Models\Employee;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Services\TaskService;
use App\Services\TaskWorkflowService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Shared task-detail logic (workflow, comments, checklist, attachments,
 * assignees). Admin\TaskShow and Employee\TaskShow subclass this to bind the
 * right layout. The visibility guard and every authorization check live here,
 * so the page is safe regardless of which route reaches it.
 */
abstract class TaskShow extends Component
{
    use WithFileUploads;

    public Task $task;

    public string $tab = 'details';

    public string $newComment = '';

    public string $newChecklistItem = '';

    public ?int $newAssigneeId = null;

    public $attachFile = null;

    // Reason modal for transitions that require one.
    public bool $showReason = false;

    public string $pendingTransition = '';

    public string $reason = '';

    public function mount(Task $task): void
    {
        abort_unless($task->isVisibleTo(Auth::user()), 403);

        $this->task = $task;
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
    }

    // ---- Workflow ----

    public function startTransition(string $to): void
    {
        $target = TaskStatus::from($to);

        // Transitions that carry a mandatory reason go through the modal.
        if (in_array($target, [TaskStatus::ChangesRequested], true)
            || ($this->task->status === TaskStatus::Completed && $target === TaskStatus::InProgress)) {
            $this->pendingTransition = $to;
            $this->reason = '';
            $this->showReason = true;

            return;
        }

        $this->runTransition($to, null);
    }

    public function confirmReason(): void
    {
        $this->runTransition($this->pendingTransition, $this->reason);
    }

    private function runTransition(string $to, ?string $reason): void
    {
        $workflow = app(TaskWorkflowService::class);

        try {
            $workflow->transition($this->task, TaskStatus::from($to), Auth::user(), $reason);
        } catch (\RuntimeException $e) {
            $this->addError('workflow', $e->getMessage());

            return;
        }

        $this->task->refresh();
        $this->showReason = false;
        $this->reset(['pendingTransition', 'reason']);
        session()->flash('status', 'تم تحديث حالة المهمة.');
    }

    // ---- Comments ----

    public function addComment(TaskService $service): void
    {
        $this->authorize('tasks.comment');
        $this->validate(['newComment' => 'required|string|max:5000']);

        $service->addComment($this->task, $this->newComment);
        $this->reset('newComment');
    }

    // ---- Checklist ----

    public function addChecklistItem(TaskService $service): void
    {
        $this->authorize('tasks.checklist');
        $this->validate(['newChecklistItem' => 'required|string|max:200']);

        $service->addChecklistItem($this->task, $this->newChecklistItem);
        $this->reset('newChecklistItem');
    }

    public function toggleChecklistItem(int $itemId, TaskService $service): void
    {
        $this->authorize('tasks.checklist');
        $item = $this->task->checklistItems()->findOrFail($itemId);
        $service->toggleChecklistItem($item);
    }

    public function deleteChecklistItem(int $itemId): void
    {
        $this->authorize('tasks.checklist');
        TaskChecklistItem::where('task_id', $this->task->id)->where('id', $itemId)->delete();
    }

    // ---- Assignees ----

    public function addAssignee(TaskService $service): void
    {
        $this->authorize('tasks.assign');
        $this->validate(['newAssigneeId' => 'required|integer|exists:employees,id']);

        try {
            $service->addAssignee($this->task, Employee::findOrFail($this->newAssigneeId));
        } catch (\RuntimeException $e) {
            $this->addError('newAssigneeId', $e->getMessage());

            return;
        }

        $this->reset('newAssigneeId');
    }

    public function removeAssignee(int $employeeId, TaskService $service): void
    {
        $this->authorize('tasks.assign');
        $service->removeAssignee($this->task, Employee::findOrFail($employeeId));
    }

    // ---- Attachments ----

    public function addAttachment(): void
    {
        $this->authorize('tasks.attachments');
        $this->validate(['attachFile' => 'required|file|max:10240']);

        $path = $this->attachFile->store("task-attachments/{$this->task->id}", 'local');

        $this->task->attachments()->create([
            'title' => $this->attachFile->getClientOriginalName(),
            'disk' => 'local',
            'path' => $path,
            'original_name' => $this->attachFile->getClientOriginalName(),
            'mime' => $this->attachFile->getMimeType(),
            'size' => $this->attachFile->getSize(),
            'uploaded_by' => Auth::id(),
        ]);

        $this->reset('attachFile');
    }

    /**
     * The workflow buttons the current user may press right now.
     *
     * @return array<string,string> [statusValue => label]
     */
    public function availableActions(): array
    {
        $workflow = app(TaskWorkflowService::class);
        $user = Auth::user();
        $labels = [
            TaskStatus::InProgress->value => $this->task->status === TaskStatus::Completed ? 'إعادة فتح' : ($this->task->status === TaskStatus::ChangesRequested ? 'استئناف العمل' : 'بدء المهمة'),
            TaskStatus::WaitingReview->value => 'إرسال للمراجعة',
            TaskStatus::Completed->value => 'اعتماد وإنهاء',
            TaskStatus::ChangesRequested->value => 'طلب تعديلات',
            TaskStatus::Cancelled->value => 'إلغاء المهمة',
        ];

        $actions = [];
        foreach ($labels as $status => $label) {
            if ($workflow->canTransition($this->task, TaskStatus::from($status), $user)) {
                $actions[$status] = $label;
            }
        }

        return $actions;
    }

    public function render()
    {
        $this->task->loadMissing(['customer', 'project', 'department', 'primaryAssignee', 'assignees', 'services']);

        return view('livewire.shared.task-show', [
            'comments' => $this->task->comments()->with('user')->get(),
            'checklist' => $this->task->checklistItems()->get(),
            'history' => $this->task->statusHistory()->with('changedBy')->get(),
            'attachments' => $this->task->attachments()->with('uploader')->get(),
            'actions' => $this->availableActions(),
            'availableEmployees' => Employee::active()->orderBy('full_name')->get(['id', 'full_name']),
            'canComment' => Auth::user()->can('tasks.comment'),
            'canChecklist' => Auth::user()->can('tasks.checklist'),
            'canAssign' => Auth::user()->can('tasks.assign'),
            'canAttach' => Auth::user()->can('tasks.attachments'),
        ]);
    }
}
