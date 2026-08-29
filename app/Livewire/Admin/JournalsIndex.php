<?php

namespace App\Livewire\Admin;

use App\Enums\JournalStatus;
use App\Models\JournalEntry;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('القيود المحاسبية')]
class JournalsIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('journals.view');
    }

    public function render()
    {
        $journals = JournalEntry::query()
            ->withCount('lines')
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('journal_number', 'like', "%{$this->search}%")
                ->orWhere('description', 'like', "%{$this->search}%")))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->latest('entry_date')->latest('id')
            ->paginate(20);

        return view('livewire.admin.journals-index', [
            'journals' => $journals,
            'statusOptions' => JournalStatus::options(),
        ]);
    }
}
