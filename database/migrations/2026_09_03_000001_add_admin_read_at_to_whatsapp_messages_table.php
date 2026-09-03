<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks when a back-office operator has read an inbound WhatsApp reply.
 * An inbound message with a null admin_read_at is "unread" and counts toward
 * the sidebar Inbox badge. Outbound messages never set this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->timestamp('admin_read_at')->nullable()->after('read_at');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn('admin_read_at');
        });
    }
};
