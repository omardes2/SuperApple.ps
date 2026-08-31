<?php

use App\Models\Service;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Correct existing service records so funded-ads services carry
     * requires_ad_budget = true. The first backfill only matched the exact
     * category "إعلانات"; a funded-ads service created with a different category
     * or the hamza-less spelling ("اعلانات ممولة") was missed, so the ad-budget
     * field never appeared for it. Idempotent and additive — no data is removed,
     * and re-running affects nothing.
     */
    public function up(): void
    {
        Service::flagFundedAds();
    }

    public function down(): void
    {
        // Non-destructive: leave the corrected flags in place.
    }
};
