<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix the journal idempotency guard so a source document can be RE-POSTED after
 * its previous journal was reversed (e.g. an invoice reverted to draft and then
 * re-issued posts a fresh issue journal).
 *
 * The old hard unique index (source_type, source_id, posting_type) forbade a
 * second live journal for the same document+type even when the first had been
 * reversed — which silently left a re-issued invoice with NO general-ledger
 * entry, drifting AR GL below the customer sub-ledger.
 *
 * The correct rule is: AT MOST ONE LIVE (posted, non-reversal) journal per
 * (source_type, source_id, posting_type). Reversed originals and reversal
 * mirror entries do not participate. We model this with a maintained
 * `active_source_key` that is set only for live source postings and NULL
 * otherwise; a unique index over it enforces the rule on every database
 * (MySQL treats multiple NULLs as distinct), no partial index needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('active_source_key')->nullable()->after('posting_type');
        });

        // Backfill: only live (posted, non-reversal) entries with a source get a
        // key. Chunked in PHP so it is portable across SQLite/MySQL.
        DB::table('journal_entries')
            ->select('id', 'source_type', 'source_id', 'posting_type', 'status', 'is_reversal')
            ->orderBy('id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    $live = $row->status === 'posted'
                        && (int) $row->is_reversal === 0
                        && $row->source_type !== null;
                    if (! $live) {
                        continue;
                    }
                    DB::table('journal_entries')->where('id', $row->id)->update([
                        'active_source_key' => $row->source_type.'|'.$row->source_id.'|'.$row->posting_type,
                    ]);
                }
            });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropUnique('journal_source_unique');
            $table->unique('active_source_key', 'journal_active_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropUnique('journal_active_source_unique');
            $table->unique(['source_type', 'source_id', 'posting_type'], 'journal_source_unique');
            $table->dropColumn('active_source_key');
        });
    }
};
