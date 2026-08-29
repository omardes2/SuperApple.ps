<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->cascadeOnDelete();
            $table->string('description')->nullable();
            $table->decimal('debit_ils', 15, 2)->default(0);
            $table->decimal('credit_ils', 15, 2)->default(0);
            // Original-currency provenance when the source operation was in USD.
            $table->string('original_currency', 3)->nullable();
            $table->decimal('original_amount', 15, 2)->nullable();
            $table->decimal('exchange_rate', 12, 6)->nullable();
            // Reporting dimensions.
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->unsignedBigInteger('expense_id')->nullable();
            $table->unsignedBigInteger('supplier_bill_id')->nullable();
            $table->unsignedBigInteger('supplier_payment_id')->nullable();
            $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();
            $table->timestamps();

            $table->index('account_id');
            $table->index(['supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_lines');
    }
};
