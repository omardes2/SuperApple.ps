<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices');
            $table->decimal('allocated_usd', 15, 2)->default(0);
            // Snapshots so exchange gain/loss is reproducible without live rates.
            $table->decimal('invoice_exchange_rate', 12, 6)->nullable();
            $table->decimal('payment_exchange_rate', 12, 6)->nullable();
            $table->decimal('invoice_accounting_value_ils', 15, 2)->default(0);
            $table->decimal('payment_accounting_value_ils', 15, 2)->default(0);
            // payment ILS − invoice ILS on the allocated portion. + gain, − loss.
            $table->decimal('exchange_difference_ils', 15, 2)->default(0);
            $table->string('status')->default('active'); // active | reversed
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            $table->index('payment_id');
            $table->index('invoice_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
