<?php

namespace App\Livewire\Admin;

use App\Enums\LeaveStatus;
use App\Models\LeaveRequest;
use App\Services\LeaveService;
use Illuminate\Support\Facades\Auth;
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

    public function mount(): void
    {
        $this->authorize('leaves.view');
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
        ]);
    }
}
