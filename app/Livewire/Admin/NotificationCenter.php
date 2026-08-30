<?php

namespace App\Livewire\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('مركز الإشعارات')]
class NotificationCenter extends Component
{
    use WithPagination;

    public string $tab = 'all'; // all|unread|tasks|hr|finance|whatsapp

    public bool $showPrefs = false;

    /** @var array<string,bool> */
    public array $prefs = [];

    public function mount(): void
    {
        $this->authorize('notifications.view');
        $stored = Auth::user()->notification_preferences ?? [];
        foreach (User::NOTIFICATION_CATEGORIES as $cat) {
            $this->prefs[$cat] = (bool) ($stored[$cat] ?? true);
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->resetPage();
    }

    public function markRead(string $id): void
    {
        Auth::user()->notifications()->where('id', $id)->update(['read_at' => now()]);
    }

    public function markAllRead(): void
    {
        Auth::user()->unreadNotifications->markAsRead();
    }

    public function savePrefs(): void
    {
        Auth::user()->update(['notification_preferences' => $this->prefs]);
        $this->showPrefs = false;
        session()->flash('status', 'تم حفظ تفضيلات الإشعارات.');
    }

    /** Map a notification type to its category. */
    public static function categoryOf(?string $type): string
    {
        $type = (string) $type;

        return match (true) {
            str_starts_with($type, 'task') => 'tasks',
            str_starts_with($type, 'leave'), str_starts_with($type, 'attendance'), str_starts_with($type, 'payroll'), str_starts_with($type, 'advance') => 'hr',
            str_starts_with($type, 'invoice'), str_starts_with($type, 'payment'), str_starts_with($type, 'expense'), str_starts_with($type, 'supplier') => 'finance',
            // Subscriptions module retired; legacy subscription notifications fold here and stay hidden.
            str_starts_with($type, 'subscription') => 'subscriptions',
            str_starts_with($type, 'whatsapp') => 'whatsapp',
            default => 'other',
        };
    }

    /** May the current user see notifications in this category right now? */
    private function canSeeCategory(string $category): bool
    {
        $u = Auth::user();

        return match ($category) {
            'finance' => $u->canAny(['payments.view', 'invoices.view', 'accounting.view', 'expenses.view', 'suppliers.view']),
            'hr' => $u->canAny(['employees.view', 'payroll.view', 'leaves.view', 'attendance.view']),
            'subscriptions' => false, // module retired — legacy notifications stay hidden
            'whatsapp' => $u->can('whatsapp.view'),
            'tasks' => $u->canAny(['tasks.view', 'tasks.view_own']),
            default => true,
        };
    }

    public function render()
    {
        $query = Auth::user()->notifications();
        if ($this->tab === 'unread') {
            $query->whereNull('read_at');
        }

        // Fetch a page, then filter by category + permission in PHP (data is JSON).
        $all = $query->latest()->get()->filter(function ($n) {
            $cat = self::categoryOf($n->data['type'] ?? null);
            if (! $this->canSeeCategory($cat)) {
                return false; // never show data the user is no longer allowed to see
            }
            if (in_array($this->tab, ['tasks', 'hr', 'finance', 'whatsapp'], true)) {
                return $cat === $this->tab;
            }

            return true;
        })->values();

        $unreadCount = Auth::user()->unreadNotifications->filter(
            fn ($n) => $this->canSeeCategory(self::categoryOf($n->data['type'] ?? null))
        )->count();

        return view('livewire.admin.notification-center', [
            'notifications' => $all->take(60),
            'unreadCount' => $unreadCount,
        ]);
    }
}
