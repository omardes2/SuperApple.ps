<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WhatsAppMessage;

class WhatsAppMessagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('whatsapp.view');
    }

    public function view(User $user, WhatsAppMessage $message): bool
    {
        return $user->can('whatsapp.view') || $user->can('whatsapp.history.view');
    }

    public function send(User $user): bool
    {
        return $user->can('whatsapp.send');
    }

    public function retry(User $user, WhatsAppMessage $message): bool
    {
        return $user->can('whatsapp.retry') && $message->isRetryable();
    }
}
