<?php

namespace App\Http\Controllers;

use App\Models\PayrollItem;
use App\Services\Settings;
use Illuminate\View\View;

/**
 * Printable payslip (A4, RTL). Enforces payslip privacy via the PayrollItem
 * policy: an employee may only print their own payslip. Shows earnings,
 * deductions, advances and net — never GL accounts, company totals, or other
 * employees' pay.
 */
class PayslipController extends Controller
{
    public function show(PayrollItem $item, Settings $settings): View
    {
        $this->authorize('view', $item);

        $item->loadMissing(['payrollRun', 'employee.department']);

        return view('print.payslip', [
            'item' => $item,
            'company' => [
                'name' => $settings->get('company', 'name', config('app.name')),
                'phone' => $settings->get('company', 'phone', ''),
                'address' => $settings->get('company', 'address', ''),
            ],
        ]);
    }
}
