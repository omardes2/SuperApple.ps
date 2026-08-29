<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_salary_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->decimal('base_salary_ils', 15, 2);
            $table->string('salary_type')->default('monthly'); // monthly|daily|hourly
            $table->unsignedSmallInteger('working_days_basis')->nullable();
            $table->decimal('daily_rate', 15, 2)->nullable();
            $table->decimal('hourly_rate', 15, 2)->nullable();
            $table->decimal('overtime_rate', 15, 2)->nullable(); // absolute ILS/hour when set
            $table->string('notes')->nullable();
            $table->string('status')->default('active'); // active|archived
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_salary_profiles');
    }
};
