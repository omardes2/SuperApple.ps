<?php

namespace App\Policies;

use App\Models\PaymentReminderRule;
use App\Models\User;

class PaymentReminderRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('whatsapp.reminders.view') || $user->can('whatsapp.reminders.manage');
    }

    public function view(User $user, PaymentReminderRule $rule): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('whatsapp.reminders.manage');
    }

    public function update(User $user, PaymentReminderRule $rule): bool
    {
        return $user->can('whatsapp.reminders.manage');
    }

    public function delete(User $user, PaymentReminderRule $rule): bool
    {
        return $user->can('whatsapp.reminders.manage');
    }
}
