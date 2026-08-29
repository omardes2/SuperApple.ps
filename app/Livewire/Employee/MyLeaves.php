<?php

namespace App\Livewire\Employee;

use App\Livewire\Concerns\ResolvesActingEmployee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

#[Layout('layouts.employee')]
#[Title('إجازاتي')]
class MyLeaves extends Component
{
    use ResolvesActingEmployee, WithFileUploads, WithPagination;

    public bool $showForm = false;

    public ?int $leave_type_id = null;

    public ?string $start_date = null;

    public ?string $end_date = null;

    public string $reason = '';

    public $attachment = null;

    public function mount(): void
    {
        $this->authorize('leaves.view_own');
    }

    public function create(): void
    {
        $this->authorize('leaves.create');
        $this->reset(['leave_type_id', 'start_date', 'end_date', 'reason', 'attachment']);
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function submit(LeaveService $service): void
    {
        $this->authorize('leaves.create');

        $data = $this->validate([
            'leave_type_id' => 'required|integer|exists:leave_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'nullable|string|max:1000',
            'attachment' => 'nullable|file|max:10240',
        ]);

        $path = $this->attachment ? $this->attachment->store('leave-attachments', 'local') : null;

        try {
            $service->submit(
                $this->actingEmployee(),
                LeaveType::findOrFail($data['leave_type_id']),
                Carbon::parse($data['start_date']),
                Carbon::parse($data['end_date']),
                $data['reason'] ?: null,
                $path,
            );
        } catch (\RuntimeException $e) {
            $this->addError('start_date', $e->getMessage());

            return;
        }

        $this->showForm = false;
        session()->flash('status', 'تم تقديم طلب الإجازة.');
    }

    public function cancel(int $id, LeaveService $service): void
    {
        $request = LeaveRequest::where('employee_id', $this->actingEmployee()->id)->findOrFail($id);

        try {
            $service->cancelPending($request);
            session()->flash('status', 'تم إلغاء الطلب.');
        } catch (\RuntimeException $e) {
            session()->flash('status', $e->getMessage());
        }
    }

    public function render()
    {
        $employee = $this->actingEmployee();

        return view('livewire.employee.my-leaves', [
            'requests' => LeaveRequest::where('employee_id', $employee->id)
                ->with('leaveType')->orderByDesc('created_at')->paginate(15),
            'leaveTypes' => LeaveType::active()->orderBy('sort_order')->get(),
        ]);
    }
}
