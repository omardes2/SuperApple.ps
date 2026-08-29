<?php

namespace App\Services;

use App\Models\Department;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class DepartmentService
{
    /**
     * @param  array<string,mixed>  $data
     */
    public function create(array $data): Department
    {
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        return Department::create($data);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function update(Department $department, array $data): Department
    {
        $data['updated_by'] = Auth::id();
        $department->update($data);

        return $department;
    }

    public function canDelete(Department $department): bool
    {
        return $department->employees()->count() === 0;
    }

    /**
     * Never hard-delete a department that still has employees; callers should
     * deactivate it instead.
     */
    public function delete(Department $department): void
    {
        if (! $this->canDelete($department)) {
            throw new RuntimeException('لا يمكن حذف قسم مرتبط بموظفين. قم بتعطيله بدلاً من ذلك.');
        }

        $department->delete();
    }
}
