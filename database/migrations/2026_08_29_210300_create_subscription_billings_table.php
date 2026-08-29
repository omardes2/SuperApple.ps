<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_billings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('billing_date');
            $table->string('status')->default('generated'); // generated|issued|skipped|failed
            $table->string('error_message')->nullable();
            $table->timestamps();

            // Idempotency: a subscription can never be billed twice for the same
            // period. This unique index is the last line of defence against
            // duplicate recurring invoices under concurrency.
            $table->unique(['subscription_id', 'period_start', 'period_end'], 'sub_billing_period_unique');
            $table->index(['subscription_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_billings');
    }
};
