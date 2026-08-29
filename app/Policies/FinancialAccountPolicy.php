<?php

namespace App\Policies;

use App\Models\FinancialAccount;
use App\Models\User;

class FinancialAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('financial_accounts.view') || $user->can('accounts.view');
    }

    public function view(User $user, FinancialAccount $account): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('financial_accounts.manage') || $user->can('accounts.manage');
    }

    public function update(User $user, FinancialAccount $account): bool
    {
        return $this->create($user);
    }
}
