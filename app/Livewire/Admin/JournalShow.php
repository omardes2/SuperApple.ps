<?php

namespace App\Livewire\Admin;

use App\Models\JournalEntry;
use App\Services\AccountingService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('تفاصيل القيد')]
class JournalShow extends Component
{
    public JournalEntry $journal;

    public bool $showReverse = false;

    public string $reverseReason = '';

    public function mount(JournalEntry $journal): void
    {
        $this->authorize('view', $journal);
        $this->journal = $journal;
    }

    public function openReverse(): void
    {
        $this->authorize('reverse', $this->journal);
        $this->reverseReason = '';
        $this->showReverse = true;
    }

    public function confirmReverse(AccountingService $accounting): void
    {
        $this->authorize('reverse', $this->journal);

        try {
            $accounting->reverse($this->journal, Auth::user(), $this->reverseReason ?: null);
        } catch (\RuntimeException $e) {
            $this->addError('reverseReason', $e->getMessage());

            return;
        }

        $this->showReverse = false;
        $this->journal->refresh();
        session()->flash('status', 'تم عكس القيد بنجاح.');
    }

    public function render()
    {
        $this->journal->loadMissing(['lines.account', 'reversalEntry']);

        return view('livewire.admin.journal-show');
    }
}
