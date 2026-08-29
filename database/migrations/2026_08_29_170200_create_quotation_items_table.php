<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            // service_id is only a reference; name/price/tax are snapshotted below.
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->string('item_name');
            $table->text('description')->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price_usd', 15, 4)->default(0);
            $table->decimal('line_subtotal_usd', 15, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 15, 4)->nullable();
            $table->decimal('discount_usd', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_usd', 15, 2)->default(0);
            $table->decimal('line_total_usd', 15, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('quotation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
    }
};
