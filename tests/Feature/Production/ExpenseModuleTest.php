<?php

namespace Tests\Feature\Production;

use App\Enums\FinancialAccountType;
use App\Enums\RoleName;
use App\Livewire\Admin\ExpenseShow;
use App\Livewire\Admin\ExpensesIndex;
use App\Models\Account;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\ExpenseCategoryService;
use App\Services\ExpenseService;
use App\Services\FinancialAccountService;
use Database\Seeders\ExpenseCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The expenses module: creating an expense no longer 422s (the real cause was
 * an empty expense_categories table in production), plus accounting-correct
 * expense-category management mapped to GL expense accounts.
 */
class ExpenseModuleTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->seed(ExpenseCategorySeeder::class);
    }

    private function accountant(): User
    {
        return $this->makeUser(RoleName::Accountant);
    }

    private function bankAccount(): FinancialAccount
    {
        $gl = Account::where('code', '1130')->firstOrFail();

        return app(FinancialAccountService::class)->create([
            'name' => 'بنك اختبار '.str()->random(4),
            'type' => FinancialAccountType::Bank,
            'currency' => 'ILS',
            'gl_account_id' => $gl->id,
            'opening_balance' => '0',
            'opening_balance_date' => '2026-07-01',
        ]);
    }

    private function draft(): Expense
    {
        return app(ExpenseService::class)->createDraft([
            'category_id' => ExpenseCategory::active()->first()->id,
            'currency' => 'ILS', 'amount' => 0, 'description' => '',
        ]);
    }

    // ---- The 422 fix ----

    public function test_authorized_user_can_open_expense_create_without_422(): void
    {
        Livewire::actingAs($this->accountant())->test(ExpensesIndex::class)
            ->call('create')
            ->assertRedirect();

        $this->assertTrue(Expense::query()->exists());
    }

    public function test_create_with_no_active_categories_flashes_error_not_422(): void
    {
        ExpenseCategory::query()->update(['is_active' => false]);

        Livewire::actingAs($this->accountant())->test(ExpensesIndex::class)
            ->call('create')
            ->assertNoRedirect()          // gracefully handled, not an abort
            ->assertHasNoErrors();

        $this->assertSame(0, Expense::count());
    }

    public function test_unauthorized_user_cannot_open_expenses(): void
    {
        [$employee] = $this->makeStaff();
        Livewire::actingAs($employee)->test(ExpensesIndex::class)->assertForbidden();
    }

    public function test_required_validation_appears_in_form(): void
    {
        $expense = $this->actingAs($this->accountant())->draft();

        Livewire::actingAs($this->accountant())->test(ExpenseShow::class, ['expense' => $expense])
            ->set('description', '')
            ->set('amount', '0')
            ->call('save')
            ->assertHasErrors(['description', 'amount']);
    }

    public function test_expense_can_be_created_with_valid_data(): void
    {
        $this->actingAs($this->accountant());
        $expense = $this->draft();

        Livewire::actingAs($this->accountant())->test(ExpenseShow::class, ['expense' => $expense])
            ->set('description', 'إنترنت الشهر')
            ->set('amount', '300')
            ->set('category_id', ExpenseCategory::where('name', 'إنترنت واتصالات')->first()->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('300.00', $expense->fresh()->amount_ils);
    }

    // ---- Category selector ----

    public function test_active_categories_appear_in_filter(): void
    {
        Livewire::actingAs($this->accountant())->test(ExpensesIndex::class)
            ->assertSee('إيجار المكتب')
            ->assertSee('برامج واشتراكات');
    }

    public function test_inactive_category_is_hidden_from_new_expense_selector(): void
    {
        $cat = ExpenseCategory::where('name', 'ضيافة')->first();
        $cat->update(['is_active' => false]);

        $expense = $this->actingAs($this->accountant())->draft(); // uses an active category

        Livewire::actingAs($this->accountant())->test(ExpenseShow::class, ['expense' => $expense])
            ->assertDontSee('ضيافة');
    }

    public function test_historical_expense_with_inactive_category_still_renders(): void
    {
        $this->actingAs($this->accountant());
        $cat = ExpenseCategory::where('name', 'طباعة')->first();
        $expense = app(ExpenseService::class)->createDraft([
            'category_id' => $cat->id, 'currency' => 'ILS', 'amount' => 100, 'description' => 'طباعة كتيبات',
        ]);
        $cat->update(['is_active' => false]);

        Livewire::actingAs($this->accountant())->test(ExpenseShow::class, ['expense' => $expense])
            ->assertOk()
            ->assertSee('طباعة'); // its own (now inactive) category still shows
    }

    // ---- Category management ----

    public function test_admin_can_create_category(): void
    {
        $accId = Account::where('code', '5100')->value('id');

        Livewire::actingAs($this->accountant())->test(ExpensesIndex::class)
            ->call('openCategories')
            ->set('categoryName', 'فئة جديدة للاختبار')
            ->set('categoryAccountId', $accId)
            ->call('saveCategory')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('expense_categories', ['name' => 'فئة جديدة للاختبار', 'default_expense_account_id' => $accId]);
    }

    public function test_admin_can_edit_category(): void
    {
        $cat = ExpenseCategory::where('name', 'صيانة')->first();

        Livewire::actingAs($this->accountant())->test(ExpensesIndex::class)
            ->call('openCategories')
            ->call('editCategory', $cat->id)
            ->set('categoryName', 'صيانة وإصلاحات')
            ->call('saveCategory')
            ->assertHasNoErrors();

        $this->assertSame('صيانة وإصلاحات', $cat->fresh()->name);
    }

    public function test_admin_can_deactivate_and_reactivate_category(): void
    {
        $cat = ExpenseCategory::where('name', 'ضيافة')->first();

        $c = Livewire::actingAs($this->accountant())->test(ExpensesIndex::class)
            ->call('openCategories')
            ->call('toggleCategory', $cat->id);
        $this->assertFalse($cat->fresh()->is_active);

        $c->call('toggleCategory', $cat->id);
        $this->assertTrue($cat->fresh()->is_active);
    }

    public function test_duplicate_category_name_is_rejected(): void
    {
        $accId = Account::where('code', '5100')->value('id');

        Livewire::actingAs($this->accountant())->test(ExpensesIndex::class)
            ->call('openCategories')
            ->set('categoryName', 'إيجار المكتب') // already exists
            ->set('categoryAccountId', $accId)
            ->call('saveCategory')
            ->assertHasErrors('categoryName');
    }

    public function test_category_must_map_to_expense_account(): void
    {
        // Revenue account (4100) must be rejected.
        $revenue = Account::where('code', '4100')->value('id');

        Livewire::actingAs($this->accountant())->test(ExpensesIndex::class)
            ->call('openCategories')
            ->set('categoryName', 'فئة خاطئة')
            ->set('categoryAccountId', $revenue)
            ->call('saveCategory')
            ->assertHasErrors('categoryAccountId');

        $this->assertDatabaseMissing('expense_categories', ['name' => 'فئة خاطئة']);
    }

    public function test_cash_bank_revenue_accounts_are_not_eligible(): void
    {
        $eligible = app(ExpenseCategoryService::class)->eligibleAccounts()->pluck('code')->all();

        foreach (['1110', '1130', '1200', '2100', '3100', '4100'] as $forbidden) {
            $this->assertNotContains($forbidden, $eligible, "account {$forbidden} must not be selectable");
        }
        // Real expense accounts are eligible.
        $this->assertContains('5100', $eligible);
    }

    // ---- Accounting ----

    public function test_posting_debits_the_category_gl_account_and_credits_cash(): void
    {
        $this->actingAs($this->accountant());
        $cash = $this->makeCashAccount('ILS');
        $rent = ExpenseCategory::where('name', 'إيجار المكتب')->first(); // → 5100

        $expense = app(ExpenseService::class)->createDraft([
            'category_id' => $rent->id, 'currency' => 'ILS', 'amount' => '3000',
            'description' => 'إيجار', 'financial_account_id' => $cash->id,
        ]);
        app(ExpenseService::class)->post($expense);

        $entry = JournalEntry::with('lines')->where('source_type', 'expense')
            ->where('source_id', $expense->id)->firstOrFail();

        $rentLine = $entry->lines->firstWhere('account_id', Account::where('code', '5100')->value('id'));
        $this->assertSame('3000.00', $rentLine->debit_ils);
        $cashLine = $entry->lines->firstWhere('financial_account_id', $cash->id);
        $this->assertSame('3000.00', $cashLine->credit_ils);
    }

    public function test_bank_payment_credits_the_bank_account(): void
    {
        $this->actingAs($this->accountant());
        $bank = $this->bankAccount();
        $cat = ExpenseCategory::where('name', 'برامج واشتراكات')->first();

        $expense = app(ExpenseService::class)->createDraft([
            'category_id' => $cat->id, 'currency' => 'ILS', 'amount' => '500',
            'description' => 'اشتراك برنامج', 'financial_account_id' => $bank->id,
        ]);
        app(ExpenseService::class)->post($expense);

        $entry = JournalEntry::with('lines')->where('source_type', 'expense')->where('source_id', $expense->id)->firstOrFail();
        $bankLine = $entry->lines->firstWhere('financial_account_id', $bank->id);
        $this->assertSame('500.00', $bankLine->credit_ils);
    }

    public function test_expense_journal_entry_is_balanced(): void
    {
        $this->actingAs($this->accountant());
        $cash = $this->makeCashAccount('ILS');
        $cat = ExpenseCategory::where('name', 'نقل ومواصلات')->first();

        $expense = app(ExpenseService::class)->createDraft([
            'category_id' => $cat->id, 'currency' => 'ILS', 'amount' => '250',
            'description' => 'تكسي', 'financial_account_id' => $cash->id,
        ]);
        app(ExpenseService::class)->post($expense);

        $entry = JournalEntry::with('lines')->where('source_type', 'expense')->where('source_id', $expense->id)->firstOrFail();
        $this->assertSame(
            number_format((float) $entry->lines->sum('debit_ils'), 2, '.', ''),
            number_format((float) $entry->lines->sum('credit_ils'), 2, '.', ''),
        );
    }

    // ---- Misc ----

    public function test_no_project_field_in_expense_form(): void
    {
        $expense = $this->actingAs($this->accountant())->draft();

        Livewire::actingAs($this->accountant())->test(ExpenseShow::class, ['expense' => $expense])
            ->assertOk()
            ->assertDontSee('المشروع');
    }

    public function test_expense_page_renders(): void
    {
        Livewire::actingAs($this->accountant())->test(ExpensesIndex::class)->assertOk();
    }

    public function test_category_management_modal_renders(): void
    {
        Livewire::actingAs($this->accountant())->test(ExpensesIndex::class)
            ->call('openCategories')
            ->assertSee('اسم التصنيف')
            ->assertSee('حساب الأستاذ')
            ->assertSee('إيجار المكتب');
    }

    public function test_employee_without_finance_permission_cannot_manage_categories(): void
    {
        [$employee] = $this->makeStaff();
        Livewire::actingAs($employee)->test(ExpensesIndex::class)->assertForbidden();
    }

    public function test_audit_log_records_expense_and_category_actions(): void
    {
        $this->actingAs($this->accountant());
        $expense = $this->draft();
        $this->assertDatabaseHas('audit_logs', ['action' => 'expense_created', 'auditable_id' => $expense->id]);

        $accId = Account::where('code', '5100')->value('id');
        $cat = app(ExpenseCategoryService::class)->create(['name' => 'فئة تدقيق', 'default_expense_account_id' => $accId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'expense_category_created', 'auditable_id' => $cat->id]);
    }

    public function test_expense_list_avoids_n_plus_one(): void
    {
        $this->actingAs($this->accountant());
        $cash = $this->makeCashAccount('ILS');
        $cat = ExpenseCategory::active()->first();

        $seed = function (int $n) use ($cat, $cash): void {
            for ($i = 0; $i < $n; $i++) {
                app(ExpenseService::class)->createDraft([
                    'category_id' => $cat->id, 'currency' => 'ILS', 'amount' => '10',
                    'description' => 'x', 'financial_account_id' => $cash->id,
                ]);
            }
        };

        $seed(3);
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->accountant())->test(ExpensesIndex::class)->assertOk();
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();

        $seed(9);
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->accountant())->test(ExpensesIndex::class)->assertOk();
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($small + 2, $large, "query count grew {$small}→{$large} — N+1 regression");
    }
}
