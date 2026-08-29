<?php

namespace App\Livewire\Admin;

use App\Enums\RoleName;
use App\Livewire\Concerns\ExportsCsv;
use App\Models\AuditLog;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('سجل العمليات')]
class AuditLogPage extends Component
{
    use ExportsCsv, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $module = '';

    #[Url]
    public string $action = '';

    public ?int $userId = null;

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $recordId = '';

    public function mount(): void
    {
        $this->authorize('audit.view');
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'module', 'action', 'userId', 'dateFrom', 'dateTo', 'recordId'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'module', 'action', 'userId', 'dateFrom', 'dateTo', 'recordId']);
        $this->resetPage();
    }

    private function baseQuery()
    {
        return AuditLog::query()
            ->with('user')
            ->when($this->module !== '', fn ($q) => $q->where('module', $this->module))
            ->when($this->action !== '', fn ($q) => $q->where('action', $this->action))
            ->when($this->userId, fn ($q) => $q->where('user_id', $this->userId))
            ->when($this->dateFrom !== '', fn ($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->when($this->recordId !== '', fn ($q) => $q->where('auditable_id', $this->recordId))
            ->when($this->search !== '', function ($q) {
                $q->where(function ($q) {
                    $q->where('action', 'like', "%{$this->search}%")
                        ->orWhere('description', 'like', "%{$this->search}%")
                        ->orWhere('module', 'like', "%{$this->search}%");
                });
            });
    }

    /** Super Admin may export the audit trail as CSV. */
    public function export()
    {
        abort_unless(auth()->user()->hasRole(RoleName::SuperAdmin->value), 403);
        $rows = $this->baseQuery()->latest('created_at')->limit(5000)->get()->map(fn ($l) => [
            $l->created_at?->format('Y-m-d H:i'), $l->user?->name ?? 'النظام',
            $l->module, $l->action, $l->auditable_type, $l->auditable_id, $l->description,
        ]);

        return $this->streamCsv('audit-log.csv',
            ['التاريخ', 'المستخدم', 'الوحدة', 'العملية', 'النوع', 'المعرّف', 'الوصف'], $rows);
    }

    public function render()
    {
        $logs = $this->baseQuery()->latest('created_at')->paginate(20);
        $modules = AuditLog::query()->distinct()->orderBy('module')->pluck('module')->filter()->values();
        $actions = AuditLog::query()->distinct()->orderBy('action')->pluck('action')->filter()->values();
        $users = User::orderBy('name')->get(['id', 'name']);

        return view('livewire.admin.audit-log-page', [
            'logs' => $logs,
            'modules' => $modules,
            'actions' => $actions,
            'users' => $users,
            'canExport' => auth()->user()->hasRole(RoleName::SuperAdmin->value),
        ]);
    }
}
