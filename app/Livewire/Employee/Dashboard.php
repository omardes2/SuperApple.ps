<?php

namespace App\Livewire\Employee;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.employee')]
#[Title('الرئيسية')]
class Dashboard extends Component
{
    public function render()
    {
        // Employee dashboard: operational only. No financial data ever.
        return view('livewire.employee.dashboard');
    }
}
