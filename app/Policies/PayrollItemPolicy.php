<?php

namespace App\Policies;

use App\Models\PayrollItem;
use App\Models\User;

/**
 * Governs a single payslip (payroll item). This is the payslip-privacy policy:
 * an employee may open only their OWN payslip; viewing anyone's payslip
 * requires payslips.view_all. Enforced regardless of any hidden UI.
 */
class PayrollItemPolicy
{
    public function view(User $user, PayrollItem $item): bool
    {
        if ($user->can('payslips.view_all')) {
            return true;
        }

        return $user->can('payslips.view_own')
            && $item->employee !== null
            && (int) $item->employee->user_id === (int) $user->id;
    }
}
