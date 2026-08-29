<?php

namespace App\Livewire\Concerns;

use App\Models\Employee;
use Illuminate\Support\Facades\Auth;

trait ResolvesActingEmployee
{
    /**
     * The employee profile linked to the logged-in user. Throws a 403 when the
     * account has no employee profile, since the self-service pages are
     * meaningless without one.
     */
    protected function actingEmployee(): Employee
    {
        $employee = Auth::user()?->employee;

        abort_if($employee === null, 403, 'لا يوجد ملف موظف مرتبط بحسابك.');

        return $employee;
    }
}
