<?php

namespace App\Livewire\Admin;

use App\Models\WhatsAppMessage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A central inbox of every inbound WhatsApp reply. Unread replies (no
 * admin_read_at) are highlighted; opening one or "mark all" clears it, which
 * also drops the red sidebar badge. Gated on whatsapp.view.
 */
#[Layout('layouts.app')]
#[Title('الصندوق الوارد')]
class WhatsAppInbox extends Component
{
    use WithPagination;

    /** Filter: 'unread' (default) or 'all'. */
    #[Url]
    public string $filter = 'unread';

    public function mount(): void
    {
        $this->authorize('whatsapp.view');
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['unread', 'all'], true) ? $filter : 'unread';
        $this->resetPage();
    }

    public function markRead(int $id): void
    {
        $this->authorize('whatsapp.view');
        $message = WhatsAppMessage::inbound()->findOrFail($id);
        if ($message->admin_read_at === null) {
            $message->update(['admin_read_at' => now()]);
        }
    }

    public function markAllRead(): void
    {
        $this->authorize('whatsapp.view');
        WhatsAppMessage::query()->unreadByAdmin()->update(['admin_read_at' => now()]);
        session()->flash('status', 'تم تعليم كل الرسائل الواردة كمقروءة.');
    }

    public function render()
    {
        $query = WhatsAppMessage::inbound()->with('customer')->latest('id');
        if ($this->filter === 'unread') {
            $query->whereNull('admin_read_at');
        }

        return view('livewire.admin.whatsapp-inbox', [
            'messages' => $query->paginate(20),
            'unreadCount' => WhatsAppMessage::unreadInboxCount(),
        ]);
    }
}
