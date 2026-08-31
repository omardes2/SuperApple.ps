<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give every task member (primary assignee + participants) an independent
     * execution state directly on the pivot. This is the single source of truth
     * for "who has started / finished their part". Additive and production-safe:
     * existing rows default to not-started and active.
     */
    public function up(): void
    {
        Schema::table('task_assignees', function (Blueprint $table) {
            $table->string('status')->default('not_started')->after('role');
            $table->timestamp('started_at')->nullable()->after('status');
            $table->timestamp('completed_at')->nullable()->after('started_at');
            $table->foreignId('added_by')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('added_by');
        });
    }

    public function down(): void
    {
        Schema::table('task_assignees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('added_by');
            $table->dropColumn(['status', 'started_at', 'completed_at', 'is_active']);
        });
    }
};
