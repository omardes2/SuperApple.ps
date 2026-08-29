<?php

namespace App\Livewire\Admin;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('الرئيسية')]
class Dashboard extends Component
{
    public function render()
    {
        // Sprint 0 baseline dashboard. Financial cards are wired up in later
        // sprints and are always gated behind the relevant permission.
        return view('livewire.admin.dashboard');
    }
}
