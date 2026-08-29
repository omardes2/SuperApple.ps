<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            // Snapshots (frozen at calculation).
            $table->string('employee_name_snapshot');
            $table->string('department_snapshot')->nullable();
            $table->string('job_title_snapshot')->nullable();
            $table->decimal('base_salary_ils', 15, 2)->default(0);
            // Attendance.
            $table->decimal('working_days', 8, 2)->default(0);
            $table->decimal('attended_days', 8, 2)->default(0);
            $table->decimal('paid_leave_days', 8, 2)->default(0);
            $table->decimal('unpaid_leave_days', 8, 2)->default(0);
            $table->decimal('absent_days', 8, 2)->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            // Earnings.
            $table->decimal('overtime_amount_ils', 15, 2)->default(0);
            $table->decimal('allowances_ils', 15, 2)->default(0);
            $table->decimal('bonuses_ils', 15, 2)->default(0);
            $table->decimal('commissions_ils', 15, 2)->default(0);
            // Deductions.
            $table->decimal('absence_deduction_ils', 15, 2)->default(0);
            $table->decimal('late_deduction_ils', 15, 2)->default(0);
            $table->decimal('unpaid_leave_deduction_ils', 15, 2)->default(0);
            $table->decimal('other_deductions_ils', 15, 2)->default(0);
            $table->decimal('advances_deduction_ils', 15, 2)->default(0);
            // Totals.
            $table->decimal('gross_salary_ils', 15, 2)->default(0);
            $table->decimal('total_deductions_ils', 15, 2)->default(0);
            $table->decimal('net_salary_ils', 15, 2)->default(0);
            $table->decimal('paid_amount_ils', 15, 2)->default(0);
            $table->decimal('remaining_payable_ils', 15, 2)->default(0);
            $table->string('notes')->nullable();
            $table->json('calculation_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_items');
    }
};
