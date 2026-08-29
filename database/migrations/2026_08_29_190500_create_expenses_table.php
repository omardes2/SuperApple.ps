<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('expense_number')->unique();
            $table->date('expense_date');
            $table->foreignId('category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('description');
            $table->string('currency', 3);            // ILS|USD
            $table->decimal('amount', 15, 2);          // in original currency
            $table->decimal('exchange_rate', 12, 6)->nullable();
            $table->decimal('amount_ils', 15, 2);      // accounting value
            $table->string('payment_method')->nullable();
            $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();
            $table->string('reference_number')->nullable();
            $table->decimal('tax_amount', 15, 2)->nullable();
            $table->string('notes')->nullable();
            $table->string('status')->default('draft'); // draft|approved|posted|cancelled
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'expense_date']);
            $table->index('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
