<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('سجل العمليات')]
class AuditLogPage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $module = '';

    public function mount(): void
    {
        $this->authorize('audit.view');
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'module'], true)) {
            $this->resetPage();
        }
    }

    public function render()
    {
        $logs = AuditLog::query()
            ->with('user')
            ->when($this->module !== '', fn ($q) => $q->where('module', $this->module))
            ->when($this->search !== '', function ($q) {
                $q->where(function ($q) {
                    $q->where('action', 'like', "%{$this->search}%")
                        ->orWhere('description', 'like', "%{$this->search}%")
                        ->orWhere('module', 'like', "%{$this->search}%");
                });
            })
            ->latest('created_at')
            ->paginate(20);

        $modules = AuditLog::query()->distinct()->orderBy('module')->pluck('module')->filter()->values();

        return view('livewire.admin.audit-log-page', compact('logs', 'modules'));
    }
}
