<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The funded-ads campaign budget captured on a task when a funded-ads
     * service is selected. This is the CAMPAIGN ad spend, an operational figure
     * only — it is never company revenue, a service price, an invoice line, or
     * an accounting entry. Nullable/additive.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->decimal('ad_budget_amount', 15, 2)->nullable()->after('estimated_minutes');
            $table->string('ad_budget_currency', 3)->nullable()->after('ad_budget_amount');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['ad_budget_amount', 'ad_budget_currency']);
        });
    }
};
