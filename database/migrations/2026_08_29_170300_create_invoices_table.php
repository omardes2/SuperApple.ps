<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();
            // A quotation backs at most one invoice — unique guards double-conversion.
            $table->foreignId('quotation_id')->nullable()->unique()->constrained('quotations')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->string('currency', 3)->default('USD');

            // USD is the official receivable currency.
            $table->decimal('subtotal_usd', 15, 2)->default(0);
            $table->decimal('discount_usd', 15, 2)->default(0);
            $table->decimal('tax_usd', 15, 2)->default(0);
            $table->decimal('total_usd', 15, 2)->default(0);

            // ILS accounting snapshot — locked at issue, never recomputed later.
            $table->decimal('exchange_rate', 12, 6)->nullable();
            $table->decimal('total_ils_at_issue', 15, 2)->nullable();

            // Payment tracking (populated in Sprint 4; initialised at issue).
            $table->decimal('paid_usd_equivalent', 15, 2)->default(0);
            $table->decimal('remaining_usd', 15, 2)->default(0);

            $table->string('status')->default('draft');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();

            $table->json('customer_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('customer_id');
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
