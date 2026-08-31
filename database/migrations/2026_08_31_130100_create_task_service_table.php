<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Operational many-to-many between tasks and services. A task can involve
     * several services. This pivot is purely operational — it deliberately does
     * NOT store any price/cost; financial data stays in the services table and
     * behind services.view_financial.
     */
    public function up(): void
    {
        Schema::create('task_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_service');
    }
};
