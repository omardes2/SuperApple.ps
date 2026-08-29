<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // A recurring invoice is an ordinary invoice that happens to originate
            // from a subscription. This link is purely informational — the invoice
            // still follows every standard accounting rule.
            $table->foreignId('subscription_id')->nullable()->after('quotation_id')
                ->constrained('subscriptions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_id');
        });
    }
};
