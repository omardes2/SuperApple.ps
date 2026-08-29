<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('reminder_rule_id')->nullable()->constrained('payment_reminder_rules')->nullOnDelete();
            $table->foreignId('whatsapp_message_id')->nullable()->constrained('whatsapp_messages')->nullOnDelete();
            $table->date('due_date'); // the invoice due date this reminder was computed against
            $table->date('sent_on');
            $table->string('status')->default('sent'); // sent|skipped|failed
            $table->string('note')->nullable();
            $table->timestamps();

            // A rule fires at most once per invoice per due date — prevents
            // duplicate reminders even if the command runs multiple times a day.
            $table->unique(['invoice_id', 'reminder_rule_id', 'due_date'], 'reminder_dedupe_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reminder_logs');
    }
};
