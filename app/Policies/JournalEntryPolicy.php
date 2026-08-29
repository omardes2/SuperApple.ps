<?php

namespace App\Policies;

use App\Models\JournalEntry;
use App\Models\User;

class JournalEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('journals.view') || $user->can('accounting.view');
    }

    public function view(User $user, JournalEntry $entry): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('journals.manual') || $user->can('journals.create');
    }

    public function post(User $user, JournalEntry $entry): bool
    {
        return $user->can('journals.post');
    }

    public function reverse(User $user, JournalEntry $entry): bool
    {
        return $user->can('journals.reverse') && $entry->isPosted();
    }
}
