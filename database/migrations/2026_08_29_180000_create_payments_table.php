<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number')->unique();
            $table->foreignId('customer_id')->constrained('customers');
            $table->date('payment_date');
            $table->string('payment_currency', 3)->default('USD');
            // Amount in the payment currency, as actually received.
            $table->decimal('payment_amount', 15, 2)->default(0);
            // USD→ILS rate at payment date (accounting + conversion). Locked at post.
            $table->decimal('exchange_rate', 12, 6)->nullable();
            // USD value of the payment (= amount for USD, amount/rate for ILS).
            $table->decimal('usd_equivalent', 15, 2)->default(0);
            $table->string('payment_method')->default('cash');
            // Nullable link to a cash/bank account (added in Sprint 5). No FK yet.
            $table->unsignedBigInteger('account_id')->nullable()->index();
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('customer_id');
            $table->index('payment_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
