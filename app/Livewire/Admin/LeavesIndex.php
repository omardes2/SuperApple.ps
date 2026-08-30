<?php

namespace App\Livewire\Admin;

use App\Enums\LeaveStatus;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('الإجازات')]
class LeavesIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    public ?int $reviewId = null;

    public string $reviewNotes = '';

    public string $reviewAction = '';

    public bool $showReview = false;

    // ---- Leave-type management (leaves.manage) ----
    public bool $showTypes = false;

    public bool $showTypeForm = false;

    public ?int $typeId = null;

    public string $typeName = '';

    public string $typeCode = '';

    public bool $typeIsPaid = true;

    public bool $typeRequiresAttachment = false;

    public bool $typeIsActive = true;

    public function mount(): void
    {
        $this->authorize('leaves.view');
    }

    public function openTypes(): void
    {
        $this->authorize('leaves.manage');
        $this->showTypeForm = false;
        $this->showTypes = true;
    }

    public function newType(): void
    {
        $this->authorize('leaves.manage');
        $this->reset(['typeId', 'typeName', 'typeCode', 'typeRequiresAttachment']);
        $this->typeIsPaid = true;
        $this->typeIsActive = true;
        $this->showTypeForm = true;
    }

    public function editType(int $id): void
    {
        $this->authorize('leaves.manage');
        $type = LeaveType::findOrFail($id);
        $this->typeId = $type->id;
        $this->typeName = $type->name;
        $this->typeCode = $type->code;
        $this->typeIsPaid = $type->is_paid;
        $this->typeRequiresAttachment = $type->requires_attachment;
        $this->typeIsActive = $type->is_active;
        $this->showTypeForm = true;
    }

    public function saveType(): void
    {
        $this->authorize('leaves.manage');
        $this->validate([
            'typeName' => 'required|string|max:120',
            'typeCode' => ['required', 'string', 'max:40', Rule::unique('leave_types', 'code')->ignore($this->typeId)],
        ]);

        $data = [
            'name' => $this->typeName,
            'code' => $this->typeCode,
            'is_paid' => $this->typeIsPaid,
            'requires_attachment' => $this->typeRequiresAttachment,
            'is_active' => $this->typeIsActive,
        ];

        if ($this->typeId) {
            LeaveType::findOrFail($this->typeId)->update($data);
        } else {
            LeaveType::create($data);
        }

        $this->showTypeForm = false;
        session()->flash('status', 'تم حفظ نوع الإجازة.');
    }

    /** Activate/deactivate — never a hard delete, so historical requests stay valid. */
    public function toggleTypeActive(int $id): void
    {
        $this->authorize('leaves.manage');
        $type = LeaveType::findOrFail($id);
        $type->update(['is_active' => ! $type->is_active]);
        session()->flash('status', $type->is_active ? 'تم تفعيل النوع.' : 'تم تعطيل النوع.');
    }

    public function updating($name): void
    {
        if (in_array($name, ['status', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function openReview(int $id, string $action): void
    {
        $this->authorize($action === 'reject' ? 'leaves.reject' : 'leaves.approve');
        $this->reviewId = $id;
        $this->reviewAction = $action;
        $this->reviewNotes = '';
        $this->showReview = true;
    }

    public function confirmReview(LeaveService $service): void
    {
        $request = LeaveRequest::findOrFail($this->reviewId);

        try {
            if ($this->reviewAction === 'approve') {
                $this->authorize('leaves.approve');
                $service->approve($request, Auth::user(), $this->reviewNotes ?: null);
                session()->flash('status', 'تم اعتماد الإجازة.');
            } elseif ($this->reviewAction === 'reject') {
                $this->authorize('leaves.reject');
                $service->reject($request, Auth::user(), $this->reviewNotes ?: null);
                session()->flash('status', 'تم رفض الإجازة.');
            } elseif ($this->reviewAction === 'reverse') {
                $this->authorize('leaves.manage');
                $service->reverseApproved($request, $this->reviewNotes ?: null);
                session()->flash('status', 'تم عكس/إلغاء الإجازة.');
            }
        } catch (\RuntimeException $e) {
            $this->addError('reviewNotes', $e->getMessage());

            return;
        }

        $this->showReview = false;
    }

    public function render()
    {
        $leaves = LeaveRequest::query()
            ->with(['employee.department', 'leaveType', 'reviewer'])
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->search !== '', fn ($q) => $q->whereHas('employee', fn ($q) => $q
                ->where('full_name', 'like', "%{$this->search}%")
                ->orWhere('employee_number', 'like', "%{$this->search}%")))
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('livewire.admin.leaves-index', [
            'leaves' => $leaves,
            'statusOptions' => LeaveStatus::options(),
            'canManageTypes' => auth()->user()->can('leaves.manage'),
            'leaveTypes' => $this->showTypes ? LeaveType::orderBy('sort_order')->orderBy('name')->get() : collect(),
        ]);
    }
}
