<?php

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;

class SubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('subscriptions.view');
    }

    public function view(User $user, Subscription $subscription): bool
    {
        return $user->can('subscriptions.view');
    }

    public function create(User $user): bool
    {
        return $user->can('subscriptions.create');
    }

    public function update(User $user, Subscription $subscription): bool
    {
        return $user->can('subscriptions.edit') && ! $subscription->isCancelled();
    }

    public function activate(User $user, Subscription $subscription): bool
    {
        return $user->can('subscriptions.activate');
    }

    public function pause(User $user, Subscription $subscription): bool
    {
        return $user->can('subscriptions.pause') && $subscription->isActive();
    }

    public function resume(User $user, Subscription $subscription): bool
    {
        return $user->can('subscriptions.resume') && $subscription->isPaused();
    }

    public function cancel(User $user, Subscription $subscription): bool
    {
        return $user->can('subscriptions.cancel') && ! $subscription->isCancelled();
    }

    public function bill(User $user, Subscription $subscription): bool
    {
        return $user->can('subscriptions.bill');
    }

    /** Prices are financial: only users with the finance-side permission see them. */
    public function viewPrices(User $user): bool
    {
        return $user->can('subscriptions.manage') || $user->can('invoices.view');
    }
}
