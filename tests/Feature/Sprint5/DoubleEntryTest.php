<?php

namespace Tests\Feature\Sprint5;

use App\Enums\RoleName;
use App\Enums\SystemAccountKey;
use App\Exceptions\PostedRecordImmutableException;
use App\Models\Account;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class DoubleEntryTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::Accountant));
    }

    private function service(): AccountingService
    {
        return app(AccountingService::class);
    }

    private function ar(): int
    {
        return $this->service()->systemAccountId(SystemAccountKey::AccountsReceivable);
    }

    private function cashIls(): int
    {
        return $this->service()->systemAccountId(SystemAccountKey::DefaultCashIls);
    }

    public function test_posted_journal_requires_equal_debit_and_credit(): void
    {
        $entry = $this->service()->post(['entry_date' => '2026-08-10', 'posting_type' => 'manual'], [
            ['account_id' => $this->cashIls(), 'debit_ils' => 500],
            ['account_id' => $this->ar(), 'credit_ils' => 500],
        ]);

        $this->assertSame('500.00', $entry->totalDebit());
        $this->assertSame('500.00', $entry->totalCredit());
    }

    public function test_unbalanced_journal_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service()->post(['entry_date' => '2026-08-10', 'posting_type' => 'manual'], [
            ['account_id' => $this->cashIls(), 'debit_ils' => 500],
            ['account_id' => $this->ar(), 'credit_ils' => 400],
        ]);
    }

    public function test_posted_journal_is_immutable(): void
    {
        $entry = $this->service()->post(['entry_date' => '2026-08-10', 'posting_type' => 'manual'], [
            ['account_id' => $this->cashIls(), 'debit_ils' => 100],
            ['account_id' => $this->ar(), 'credit_ils' => 100],
        ]);

        $this->expectException(PostedRecordImmutableException::class);
        $entry->update(['entry_date' => '2026-09-01']);
    }

    public function test_reversal_creates_opposite_entry(): void
    {
        $entry = $this->service()->post(['entry_date' => '2026-08-10', 'posting_type' => 'manual'], [
            ['account_id' => $this->cashIls(), 'debit_ils' => 300],
            ['account_id' => $this->ar(), 'credit_ils' => 300],
        ]);

        $reversal = $this->service()->reverse($entry->fresh(), $this->makeUser(RoleName::Accountant), 'خطأ');

        $this->assertTrue($reversal->is_reversal);
        $this->assertSame('300.00', $reversal->totalCredit()); // cash now credited
        $this->assertSame('reversed', $entry->fresh()->status->value);
        $this->assertNotNull($entry->fresh()->reversal_entry_id);
    }

    public function test_source_cannot_post_duplicate_journal(): void
    {
        $header = ['entry_date' => '2026-08-10', 'source_type' => 'invoice', 'source_id' => 999, 'posting_type' => 'invoice_issue'];
        $lines = [['account_id' => $this->cashIls(), 'debit_ils' => 100], ['account_id' => $this->ar(), 'credit_ils' => 100]];

        $this->service()->post($header, $lines);

        $this->expectException(RuntimeException::class);
        $this->service()->post($header, $lines);
    }

    public function test_journal_number_is_unique(): void
    {
        $a = $this->service()->post(['entry_date' => '2026-08-10', 'posting_type' => 'manual'], [
            ['account_id' => $this->cashIls(), 'debit_ils' => 10], ['account_id' => $this->ar(), 'credit_ils' => 10],
        ]);
        $b = $this->service()->post(['entry_date' => '2026-08-10', 'posting_type' => 'manual'], [
            ['account_id' => $this->cashIls(), 'debit_ils' => 20], ['account_id' => $this->ar(), 'credit_ils' => 20],
        ]);

        $this->assertNotSame($a->journal_number, $b->journal_number);
        $this->assertStringStartsWith('JRN-', $a->journal_number);
    }

    public function test_posting_to_a_parent_account_is_rejected(): void
    {
        $parent = Account::where('code', '1000')->firstOrFail(); // Assets parent

        $this->expectException(RuntimeException::class);
        $this->service()->post(['entry_date' => '2026-08-10', 'posting_type' => 'manual'], [
            ['account_id' => $parent->id, 'debit_ils' => 50],
            ['account_id' => $this->ar(), 'credit_ils' => 50],
        ]);
    }
}
