<?php

namespace Tests\Feature\Sprint1;

use App\Enums\RoleName;
use App\Services\DepartmentService;
use App\Services\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class EmployeeManagementTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_employee_number_is_auto_generated(): void
    {
        $service = app(EmployeeService::class);

        $employee = $service->create(['full_name' => 'أحمد']);

        $this->assertNotNull($employee->employee_number);
        $this->assertStringStartsWith('EMP-', $employee->employee_number);
    }

    public function test_employee_cannot_be_their_own_manager(): void
    {
        $service = app(EmployeeService::class);
        $employee = $service->create(['full_name' => 'أحمد']);

        $this->expectException(RuntimeException::class);
        $service->update($employee, ['direct_manager_id' => $employee->id]);
    }

    public function test_circular_management_relation_is_prevented(): void
    {
        $service = app(EmployeeService::class);

        $ahmed = $service->create(['full_name' => 'أحمد']);
        $mohammed = $service->create(['full_name' => 'محمد', 'direct_manager_id' => $ahmed->id]);

        // Ahmed → Mohammed would create a cycle (Ahmed already manages Mohammed).
        $this->expectException(RuntimeException::class);
        $service->update($ahmed, ['direct_manager_id' => $mohammed->id]);
    }

    public function test_valid_manager_assignment_succeeds(): void
    {
        $service = app(EmployeeService::class);

        $manager = $service->create(['full_name' => 'المدير']);
        $employee = $service->create(['full_name' => 'الموظف']);

        $service->update($employee, ['direct_manager_id' => $manager->id]);

        $this->assertSame($manager->id, $employee->fresh()->direct_manager_id);
    }

    public function test_department_with_employees_cannot_be_deleted(): void
    {
        $deptService = app(DepartmentService::class);
        $department = $this->makeDepartment();
        $this->makeEmployee(null, ['department_id' => $department->id]);

        $this->assertFalse($deptService->canDelete($department));

        $this->expectException(RuntimeException::class);
        $deptService->delete($department);
    }

    public function test_empty_department_can_be_deleted(): void
    {
        $deptService = app(DepartmentService::class);
        $department = $this->makeDepartment();

        $this->assertTrue($deptService->canDelete($department));
        $deptService->delete($department);

        $this->assertDatabaseMissing('departments', ['id' => $department->id]);
    }

    public function test_creating_and_updating_employee_writes_audit_log(): void
    {
        $service = app(EmployeeService::class);
        $admin = $this->makeUser(RoleName::SuperAdmin);
        $this->actingAs($admin);

        $employee = $service->create(['full_name' => 'أحمد']);
        $service->update($employee, ['job_title' => 'مصمم']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'created', 'auditable_id' => $employee->id, 'module' => 'Employee']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'updated', 'auditable_id' => $employee->id]);
    }
}
