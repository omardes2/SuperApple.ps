<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records the file name of the PDF (or other media) attached to a WhatsApp
     * message. Additive and nullable so existing rows (text-only sends) are
     * unaffected. No financial data is stored here — only the document label.
     */
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->string('document_name')->nullable()->after('message_body');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn('document_name');
        });
    }
};
