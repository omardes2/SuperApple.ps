<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A customer's pre-system balance, recorded as a real accounting document
        // (not an invoice). USD is the official amount; ILS is snapshotted at the
        // manually-entered historical rate. A debit balance is a receivable the
        // customer owes; a credit balance is money owed to the customer.
        Schema::create('customer_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->date('balance_date');
            $table->string('type'); // debit (customer owes) | credit (in customer's favour)
            $table->decimal('amount_usd', 15, 2);
            $table->decimal('exchange_rate', 12, 6);
            $table->decimal('amount_ils', 15, 2);
            // For debit balances: the outstanding portion payments can reduce.
            $table->decimal('paid_usd_equivalent', 15, 2)->default(0);
            $table->decimal('remaining_usd', 15, 2)->default(0);
            $table->string('status')->default('posted'); // posted | reversed
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('customer_id');
            $table->index('status');
        });

        // Payment allocations can now target either an invoice OR an opening
        // balance (both are Accounts-Receivable documents). invoice_id becomes
        // nullable and a nullable opening_balance_id is added.
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->change();
            $table->foreignId('opening_balance_id')->nullable()->after('invoice_id')
                ->constrained('customer_opening_balances')->nullOnDelete();
            $table->index('opening_balance_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opening_balance_id');
        });
        Schema::dropIfExists('customer_opening_balances');
    }
};
