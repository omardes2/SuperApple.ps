<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('service_code')->unique();
            $table->string('name');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->string('service_type')->default('one_time');
            // Financial fields — only exposed to holders of services.view_financial.
            $table->decimal('default_price_usd', 15, 2)->nullable();
            $table->decimal('estimated_cost_ils', 15, 2)->nullable();
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('service_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
