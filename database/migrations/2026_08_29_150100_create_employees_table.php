<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_number')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('full_name');
            $table->string('phone')->nullable();
            $table->string('job_title')->nullable();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('direct_manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('hire_date')->nullable();
            $table->string('employment_status')->default('active');
            $table->string('employment_type')->default('full_time');
            $table->decimal('working_hours_per_day', 4, 2)->default(8);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('department_id');
            $table->index('employment_status');
        });

        // Note: departments.manager_id and users.employee_id reference employees,
        // but those tables are created before employees. SQLite cannot ALTER a
        // table to add a foreign key, so these two links stay indexed columns
        // with integrity enforced at the application/service layer (see
        // DepartmentService / EmployeeService). employees.direct_manager_id is a
        // real self-referencing FK because it is declared in this CREATE.
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
