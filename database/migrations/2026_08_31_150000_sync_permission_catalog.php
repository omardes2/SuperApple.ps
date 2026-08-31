<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Repair RBAC drift: ensure every permission in the application catalog
     * exists as a row in the permissions table. Over successive sprints the
     * catalog grew (task, invoice, opening-balance permissions, …) but a
     * production database seeded earlier never received the new rows. The role
     * editor lists permissions from the catalog, so granting a catalog
     * permission that has no row made Spatie's syncPermissions() throw and the
     * whole role save was lost — custom roles appeared to "lose" some modules.
     *
     * Additive and idempotent: only missing permissions are created; no
     * permission, role, or user assignment is ever removed. The permission
     * cache is cleared so the fix takes effect immediately.
     */
    public function up(): void
    {
        Permissions::sync();
    }

    public function down(): void
    {
        // Non-destructive: never drop permissions that roles/users may rely on.
    }
};
