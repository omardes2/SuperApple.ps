<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->nullable();
            $table->string('adjustment_type'); // earning|deduction
            $table->string('category');        // bonus|commission|allowance|overtime|penalty|other|...
            $table->decimal('amount_ils', 15, 2);
            $table->date('effective_date');
            $table->date('recurring_end_date')->nullable();
            $table->string('description')->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->foreignId('gl_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->string('status')->default('active'); // active|cancelled
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_adjustments');
    }
};
