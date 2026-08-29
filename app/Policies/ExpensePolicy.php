<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('expenses.view');
    }

    public function view(User $user, Expense $expense): bool
    {
        return $user->can('expenses.view');
    }

    public function create(User $user): bool
    {
        return $user->can('expenses.create');
    }

    public function update(User $user, Expense $expense): bool
    {
        return $user->can('expenses.edit') && ! $expense->isPosted() && ! $expense->isCancelled();
    }

    public function approve(User $user, Expense $expense): bool
    {
        return $user->can('expenses.approve') && $expense->isDraft();
    }

    public function post(User $user, Expense $expense): bool
    {
        return $user->can('expenses.post') && ! $expense->isPosted() && ! $expense->isCancelled();
    }

    public function cancel(User $user, Expense $expense): bool
    {
        return $user->can('expenses.cancel') && ! $expense->isCancelled();
    }
}
