<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
#[Title('ملف الموظف')]
class EmployeeProfile extends Component
{
    use WithFileUploads;

    public Employee $employee;

    public string $tab = 'overview';

    // Document upload
    public string $docTitle = '';

    public string $docType = '';

    public string $docNotes = '';

    public $docFile = null;

    public function mount(Employee $employee): void
    {
        $this->authorize('employees.view');
        $this->employee = $employee;
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
    }

    public function addDocument(): void
    {
        $this->authorize('employees.documents');

        $this->validate([
            'docTitle' => 'required|string|max:150',
            'docType' => 'nullable|string|max:60',
            'docNotes' => 'nullable|string|max:500',
            'docFile' => 'required|file|max:10240',
        ]);

        $path = $this->docFile->store("employee-documents/{$this->employee->id}", 'local');

        EmployeeDocument::create([
            'employee_id' => $this->employee->id,
            'title' => $this->docTitle,
            'type' => $this->docType ?: null,
            'disk' => 'local',
            'path' => $path,
            'original_name' => $this->docFile->getClientOriginalName(),
            'mime' => $this->docFile->getMimeType(),
            'size' => $this->docFile->getSize(),
            'notes' => $this->docNotes ?: null,
            'uploaded_by' => Auth::id(),
        ]);

        $this->reset(['docTitle', 'docType', 'docNotes', 'docFile']);
        session()->flash('status', 'تم رفع المستند.');
    }

    public function render()
    {
        $this->employee->loadMissing(['department', 'directManager', 'user']);

        $data = [];

        if ($this->tab === 'attendance') {
            $data['attendance'] = $this->employee->attendanceRecords()
                ->orderByDesc('attendance_date')->limit(60)->get();
        }

        if ($this->tab === 'leaves') {
            $data['leaves'] = $this->employee->leaveRequests()
                ->with('leaveType')->orderByDesc('created_at')->limit(60)->get();
        }

        if ($this->tab === 'documents') {
            $data['documents'] = $this->employee->documents()->latest()->get();
        }

        if ($this->tab === 'activity') {
            $data['activity'] = AuditLog::where('auditable_type', $this->employee->getMorphClass())
                ->where('auditable_id', $this->employee->id)
                ->with('user')->latest('created_at')->limit(50)->get();
        }

        return view('livewire.admin.employee-profile', $data);
    }
}
