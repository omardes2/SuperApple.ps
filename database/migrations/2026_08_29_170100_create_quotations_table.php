<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('quotation_number')->unique();
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->date('quotation_date');
            $table->date('valid_until')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->decimal('subtotal_usd', 15, 2)->default(0);
            $table->decimal('discount_usd', 15, 2)->default(0);
            $table->decimal('tax_usd', 15, 2)->default(0);
            $table->decimal('total_usd', 15, 2)->default(0);
            $table->string('status')->default('draft');
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            // Historical snapshot of the customer at send/accept time.
            $table->json('customer_snapshot')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            // Revision lineage + conversion link.
            $table->foreignId('revision_of')->nullable()->constrained('quotations')->nullOnDelete();
            $table->foreignId('converted_invoice_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
