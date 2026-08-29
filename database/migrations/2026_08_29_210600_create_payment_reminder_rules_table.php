<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reminder_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedSmallInteger('offset_days')->default(0);
            $table->string('timing_type')->default('before_due'); // before_due|due_date|after_due
            $table->foreignId('template_id')->nullable()->constrained('whatsapp_templates')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->string('send_time')->nullable(); // optional HH:MM hint
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'timing_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reminder_rules');
    }
};
