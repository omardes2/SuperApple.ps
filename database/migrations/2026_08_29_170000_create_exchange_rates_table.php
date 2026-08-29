<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->date('rate_date');
            $table->string('base_currency', 3)->default('USD');
            $table->string('quote_currency', 3)->default('ILS');
            // 1 base = <rate> quote. Never float. Precision to 6 decimals.
            $table->decimal('rate', 12, 6);
            $table->string('source')->default('manual');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One authoritative rate per day per currency pair.
            $table->unique(['rate_date', 'base_currency', 'quote_currency']);
            $table->index('rate_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
