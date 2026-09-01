<?php

namespace App\Services;

use App\Enums\JournalStatus;
use App\Enums\SystemAccountKey;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\SystemAccount;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The central double-entry posting engine. Every GL journal is created here so
 * the invariants hold in one place: balanced debits/credits (in ILS), postings
 * only to active leaf accounts, source idempotency, and immutable posted
 * entries (corrections via reverse()). ILS is the base ledger currency; the
 * original currency/amount/rate are preserved on lines when the source was USD.
 */
class AccountingService
{
    /** @var array<string,int> */
    private array $systemAccountCache = [];

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Resolve a system account by its stable key. Throws if unmapped (the chart
     * seeder must map every key).
     */
    public function systemAccount(SystemAccountKey $key): Account
    {
        if (! isset($this->systemAccountCache[$key->value])) {
            $map = SystemAccount::where('key', $key->value)->first();
            if ($map === null) {
                throw new RuntimeException("الحساب النظامي [{$key->value}] غير مُعرّف في دليل الحسابات.");
            }
            $this->systemAccountCache[$key->value] = $map->account_id;
        }

        return Account::findOrFail($this->systemAccountCache[$key->value]);
    }

    public function systemAccountId(SystemAccountKey $key): int
    {
        return $this->systemAccount($key)->id;
    }

    /**
     * Has ANY journal (posted OR reversed) already been created for this source?
     * This is the "already processed" test the historical backfill relies on so
     * it never re-creates a document's journals — including a document whose
     * journal was later reversed (e.g. a cancelled payment).
     *
     * To decide whether a NEW live journal may be posted, use hasLivePosting():
     * a reversed journal does not block a legitimate re-post (e.g. re-issuing an
     * invoice after it was reverted to draft).
     */
    public function hasPosted(?string $sourceType, ?int $sourceId, string $postingType): bool
    {
        return JournalEntry::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('posting_type', $postingType)
            ->exists();
    }

    /**
     * Is there currently a LIVE (posted, non-reversal) journal for this source +
     * posting type? At most one may exist at a time (enforced by the
     * active_source_key unique index). A reversed original or a reversal mirror
     * does NOT count, so a re-post after reversal is permitted.
     */
    public function hasLivePosting(?string $sourceType, ?int $sourceId, string $postingType): bool
    {
        return JournalEntry::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('posting_type', $postingType)
            ->where('status', JournalStatus::Posted->value)
            ->where('is_reversal', false)
            ->exists();
    }

    /**
     * Post a balanced journal atomically.
     *
     * @param  array{entry_date:mixed,description?:string,source_type?:?string,source_id?:?int,posting_type?:string,is_reversal?:bool,manual?:bool}  $header
     * @param  list<array<string,mixed>>  $rawLines  each: account_id, debit_ils, credit_ils, + optional dimensions
     */
    public function post(array $header, array $rawLines): JournalEntry
    {
        $postingType = $header['posting_type'] ?? 'manual';
        $sourceType = $header['source_type'] ?? null;
        $sourceId = $header['source_id'] ?? null;
        $manual = $header['manual'] ?? false;

        return DB::transaction(function () use ($header, $rawLines, $postingType, $sourceType, $sourceId, $manual) {
            // Idempotency guard (belt-and-braces with the active_source_key unique
            // index): block only a duplicate LIVE posting. A reversed original does
            // not block a legitimate re-post (e.g. an invoice re-issued after being
            // reverted to draft). Reversal mirrors carry is_reversal and are exempt.
            if ($sourceType !== null && ! ($header['is_reversal'] ?? false)
                && $this->hasLivePosting($sourceType, $sourceId, $postingType)) {
                throw new RuntimeException("يوجد قيد مُرحّل مسبقاً لهذا المستند ({$postingType}).");
            }

            $lines = $this->normaliseLines($rawLines, $manual);

            if ($lines === []) {
                throw new RuntimeException('لا يمكن ترحيل قيد بدون سطور.');
            }

            $totalDebit = Money::sum(array_map(fn ($l) => $l['debit_ils'], $lines));
            $totalCredit = Money::sum(array_map(fn ($l) => $l['credit_ils'], $lines));

            if (! Money::equals($totalDebit, $totalCredit)) {
                throw new RuntimeException("القيد غير متوازن: مدين {$totalDebit} ≠ دائن {$totalCredit}.");
            }
            if (Money::isZeroOrNegative($totalDebit)) {
                throw new RuntimeException('لا يمكن ترحيل قيد بقيمة صفرية.');
            }

            $isReversal = (bool) ($header['is_reversal'] ?? false);

            // The uniqueness discriminator: set ONLY for a live source posting so
            // at most one such journal can exist per (source, posting_type).
            // Reversal mirrors and null-source (manual) entries stay NULL, and a
            // reversed original is cleared to NULL (see reverse()), which frees the
            // key for a legitimate re-post.
            $activeSourceKey = ($sourceType !== null && ! $isReversal)
                ? $sourceType.'|'.$sourceId.'|'.$postingType
                : null;

            $entry = JournalEntry::create([
                'journal_number' => $this->numbers->next('journal'),
                'entry_date' => $header['entry_date'],
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'posting_type' => $postingType,
                'active_source_key' => $activeSourceKey,
                'description' => $header['description'] ?? null,
                'status' => JournalStatus::Posted,
                'is_reversal' => $isReversal,
                'posted_at' => now(),
                'created_by' => Auth::id(),
                'posted_by' => Auth::id(),
            ]);

            foreach ($lines as $line) {
                $entry->lines()->create($line);
            }

            $this->audit->log('journal_posted', $entry, 'Accounting',
                new: ['debit' => $totalDebit, 'credit' => $totalCredit, 'posting_type' => $postingType],
                description: "ترحيل قيد {$entry->journal_number} بقيمة {$totalDebit} ILS");

            return $entry;
        });
    }

    /**
     * Reverse a posted journal by creating a mirror entry (debits/credits
     * swapped) and marking the original reversed. History is preserved.
     */
    public function reverse(JournalEntry $entry, ?User $actor = null, ?string $reason = null, mixed $entryDate = null): JournalEntry
    {
        if (! $entry->isPosted()) {
            throw new RuntimeException('لا يمكن عكس قيد غير مُرحّل.');
        }

        return DB::transaction(function () use ($entry, $reason, $entryDate) {
            $entry->loadMissing('lines');

            $reversalLines = $entry->lines->map(fn ($l) => [
                'account_id' => $l->account_id,
                'description' => 'عكس: '.(string) $l->description,
                'debit_ils' => $l->credit_ils,   // swap
                'credit_ils' => $l->debit_ils,
                'original_currency' => $l->original_currency,
                'original_amount' => $l->original_amount,
                'exchange_rate' => $l->exchange_rate,
                'customer_id' => $l->customer_id,
                'supplier_id' => $l->supplier_id,
                'project_id' => $l->project_id,
                'invoice_id' => $l->invoice_id,
                'payment_id' => $l->payment_id,
                'expense_id' => $l->expense_id,
                'supplier_bill_id' => $l->supplier_bill_id,
                'supplier_payment_id' => $l->supplier_payment_id,
                'financial_account_id' => $l->financial_account_id,
            ])->all();

            $reversal = $this->post([
                'entry_date' => $entryDate ?? now()->toDateString(),
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'posting_type' => $entry->posting_type.'_reversal',
                'description' => 'عكس القيد '.$entry->journal_number.($reason ? ' — '.$reason : ''),
                'is_reversal' => true,
                'manual' => false, // engine-driven: not subject to the manual-posting gate
            ], $reversalLines);

            // Clearing active_source_key frees the (source, posting_type) slot so
            // the document can be re-posted later (e.g. an invoice re-issued).
            $entry->update([
                'status' => JournalStatus::Reversed,
                'reversed_at' => now(),
                'reversal_entry_id' => $reversal->id,
                'active_source_key' => null,
            ]);

            $this->audit->log('journal_reversed', $entry, 'Accounting',
                new: ['reversal' => $reversal->journal_number, 'reason' => $reason],
                description: "عكس القيد {$entry->journal_number}");

            return $reversal;
        });
    }

    /**
     * Validate + clean a set of raw lines: drop empty lines, enforce a single
     * side per line, and check each account is a postable leaf.
     *
     * @param  list<array<string,mixed>>  $rawLines
     * @return list<array<string,mixed>>
     */
    private function normaliseLines(array $rawLines, bool $manual): array
    {
        $clean = [];
        foreach ($rawLines as $raw) {
            $debit = Money::money($raw['debit_ils'] ?? 0);
            $credit = Money::money($raw['credit_ils'] ?? 0);

            // Skip fully-empty lines (e.g. a zero FX difference).
            if (Money::isZeroOrNegative($debit) && Money::isZeroOrNegative($credit)) {
                continue;
            }
            if (Money::isPositive($debit) && Money::isPositive($credit)) {
                throw new RuntimeException('لا يمكن أن يكون السطر مديناً ودائناً في آن واحد.');
            }

            $account = Account::find($raw['account_id']);
            if ($account === null) {
                throw new RuntimeException('حساب غير موجود في القيد.');
            }
            if (! $account->canReceivePosting()) {
                throw new RuntimeException("لا يمكن الترحيل إلى الحساب [{$account->code} {$account->name}] (حساب رئيسي أو غير نشط).");
            }
            if ($manual && ! $account->allow_manual_posting) {
                throw new RuntimeException("الحساب [{$account->code}] لا يسمح بالترحيل اليدوي.");
            }

            $clean[] = [
                'account_id' => $account->id,
                'description' => $raw['description'] ?? null,
                'debit_ils' => $debit,
                'credit_ils' => $credit,
                'original_currency' => $raw['original_currency'] ?? null,
                'original_amount' => isset($raw['original_amount']) ? Money::money($raw['original_amount']) : null,
                'exchange_rate' => isset($raw['exchange_rate']) ? Money::rate($raw['exchange_rate']) : null,
                'customer_id' => $raw['customer_id'] ?? null,
                'supplier_id' => $raw['supplier_id'] ?? null,
                'project_id' => $raw['project_id'] ?? null,
                'invoice_id' => $raw['invoice_id'] ?? null,
                'payment_id' => $raw['payment_id'] ?? null,
                'expense_id' => $raw['expense_id'] ?? null,
                'supplier_bill_id' => $raw['supplier_bill_id'] ?? null,
                'supplier_payment_id' => $raw['supplier_payment_id'] ?? null,
                'financial_account_id' => $raw['financial_account_id'] ?? null,
            ];
        }

        return $clean;
    }
}
