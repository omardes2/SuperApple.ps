<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('subscription_number')->unique(); // SUB-YYYY-####
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();

            // Cadence.
            $table->string('billing_cycle')->default('monthly'); // weekly|monthly|quarterly|semi_annual|yearly|custom
            $table->unsignedSmallInteger('billing_interval')->default(1); // "every N cycles"
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('next_billing_date')->nullable();
            $table->timestamp('last_billed_at')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable(); // overrides default due days

            // Snapshotted contract totals (USD is the invoicing currency).
            $table->string('currency', 3)->default('USD');
            $table->decimal('subtotal_usd', 15, 2)->default(0);
            $table->decimal('discount_usd', 15, 2)->default(0);
            $table->decimal('tax_usd', 15, 2)->default(0);
            $table->decimal('total_usd', 15, 2)->default(0);

            // Automation flags (independent of each other).
            $table->boolean('auto_generate_invoice')->default(true);
            $table->boolean('auto_issue_invoice')->default(false);

            $table->string('status')->default('draft'); // draft|active|paused|cancelled|expired
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();

            // Lifecycle metadata.
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'next_billing_date']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
