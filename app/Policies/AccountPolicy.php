<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('chart_accounts.view') || $user->can('accounting.view');
    }

    public function view(User $user, Account $account): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('chart_accounts.manage');
    }

    public function update(User $user, Account $account): bool
    {
        return $user->can('chart_accounts.manage');
    }

    public function delete(User $user, Account $account): bool
    {
        // System accounts and accounts with activity are never deletable.
        return $user->can('chart_accounts.manage') && ! $account->is_system && ! $account->lines()->exists();
    }
}
