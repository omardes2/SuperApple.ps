<?php

namespace App\Policies;

use App\Models\ExchangeRate;
use App\Models\User;

class ExchangeRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('exchange_rates.view');
    }

    public function view(User $user, ExchangeRate $rate): bool
    {
        return $user->can('exchange_rates.view');
    }

    public function manage(User $user): bool
    {
        return $user->can('exchange_rates.manage');
    }
}
