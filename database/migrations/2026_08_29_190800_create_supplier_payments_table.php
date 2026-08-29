<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number')->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->date('payment_date');
            $table->string('currency', 3);            // ILS|USD
            $table->decimal('amount', 15, 2);          // original currency
            $table->decimal('exchange_rate', 12, 6)->nullable();
            $table->decimal('amount_ils', 15, 2);      // accounting value at payment rate
            $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();
            $table->string('reference_number')->nullable();
            $table->string('notes')->nullable();
            $table->string('status')->default('draft'); // draft|posted|cancelled
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'payment_date']);
        });

        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_payment_id')->constrained('supplier_payments')->cascadeOnDelete();
            $table->foreignId('supplier_bill_id')->constrained('supplier_bills')->restrictOnDelete();
            $table->decimal('allocated_original', 15, 2);        // in bill/payment original currency
            $table->decimal('bill_accounting_value_ils', 15, 2); // allocated_original × bill_rate (AP reduction)
            $table->decimal('payment_accounting_value_ils', 15, 2); // allocated_original × payment_rate (cash out)
            $table->decimal('exchange_difference_ils', 15, 2)->default(0); // + gain (paid less), − loss
            $table->string('status')->default('active'); // active|reversed
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reversal_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
        Schema::dropIfExists('supplier_payments');
    }
};
