<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('journal_number')->unique();
            $table->date('entry_date');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('posting_type')->nullable(); // issue|payment|reversal|expense|bill|supplier_payment|opening_balance|transfer|manual
            $table->string('description')->nullable();
            $table->string('status')->default('draft'); // draft|posted|reversed
            $table->boolean('is_reversal')->default(false);
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Idempotency: at most one journal per (source document, posting type).
            // NULL source (manual journals) are treated as distinct, so multiple
            // manual entries are allowed.
            $table->unique(['source_type', 'source_id', 'posting_type'], 'journal_source_unique');
            $table->index(['status', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
