<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Employee profile operations. Purely operational — no salary or financial
 * data ever lives on the employee record (that belongs to the Payroll module).
 */
class EmployeeService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public function create(array $data): Employee
    {
        return DB::transaction(function () use ($data) {
            $data['employee_number'] = $data['employee_number'] ?? $this->numbers->next('employee');
            $data['created_by'] = Auth::id();
            $data['updated_by'] = Auth::id();

            return Employee::create($data);
        });
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function update(Employee $employee, array $data): Employee
    {
        if (array_key_exists('direct_manager_id', $data)) {
            $this->assertValidManager($employee, $data['direct_manager_id']);
        }

        $data['updated_by'] = Auth::id();
        $employee->update($data);

        return $employee;
    }

    /**
     * Guard against an employee managing themselves or forming a management
     * cycle (A→B→A). Kept deliberately simple: walk up the proposed chain and
     * fail if we meet the employee again.
     */
    public function assertValidManager(Employee $employee, mixed $managerId): void
    {
        if ($managerId === null || $managerId === '') {
            return;
        }

        $managerId = (int) $managerId;

        if ($managerId === (int) $employee->id) {
            throw new RuntimeException('لا يمكن أن يكون الموظف مديراً لنفسه.');
        }

        $seen = [(int) $employee->id];
        $cursor = Employee::find($managerId);

        while ($cursor !== null) {
            if (in_array((int) $cursor->id, $seen, true)) {
                throw new RuntimeException('لا يمكن إنشاء علاقة إدارية دائرية.');
            }
            $seen[] = (int) $cursor->id;
            $cursor = $cursor->direct_manager_id ? Employee::find($cursor->direct_manager_id) : null;
        }
    }
}
