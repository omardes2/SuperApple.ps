<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_advance_recoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_advance_id')->constrained('employee_advances')->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('payroll_item_id')->constrained('payroll_items')->cascadeOnDelete();
            $table->decimal('amount_ils', 15, 2);
            $table->string('status')->default('active'); // active|reversed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_advance_recoveries');
    }
};
