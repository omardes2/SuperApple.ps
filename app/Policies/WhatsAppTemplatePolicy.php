<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WhatsAppTemplate;

class WhatsAppTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('whatsapp.templates.view') || $user->can('whatsapp.templates.manage');
    }

    public function view(User $user, WhatsAppTemplate $template): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('whatsapp.templates.manage');
    }

    public function update(User $user, WhatsAppTemplate $template): bool
    {
        return $user->can('whatsapp.templates.manage');
    }

    public function delete(User $user, WhatsAppTemplate $template): bool
    {
        return $user->can('whatsapp.templates.manage');
    }
}
