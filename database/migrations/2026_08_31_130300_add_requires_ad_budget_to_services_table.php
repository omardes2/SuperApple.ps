<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A stable, admin-controllable flag identifying "funded ads" style services
     * that carry a campaign budget. We deliberately do NOT match on the service
     * name (which can change) — this boolean is the source of truth. Existing
     * advertising-category services are backfilled to true.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('requires_ad_budget')->default(false)->after('service_type');
        });

        // Backfill: existing advertising services carry a campaign budget.
        DB::table('services')->where('category', 'إعلانات')->update(['requires_ad_budget' => true]);
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('requires_ad_budget');
        });
    }
};
