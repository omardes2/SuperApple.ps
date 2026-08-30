<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\CashBanksIndex;
use App\Livewire\Admin\PaymentShow;
use App\Services\AccountTransferService;
use App\Services\FinancialAccountService;
use App\Services\PaymentService;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Lifecycle of cash/bank accounts: deactivate/activate (never destructive) and
 * hard-delete only for a never-used account. Deactivated accounts leave new
 * financial pickers but keep all history; used accounts can never be deleted.
 */
class FinancialAccountLifecycleTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function service(): FinancialAccountService
    {
        return app(FinancialAccountService::class);
    }

    // ---- Deactivate / activate ----

    public function test_deactivate_account_succeeds_and_keeps_data(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $account = $this->makeCashAccount('ILS', '500'); // has a journal opening balance

        $this->service()->deactivate($account);

        $account->refresh();
        $this->assertFalse($account->is_active);
        // History untouched: the opening-balance journal line still exists.
        $this->assertTrue($this->service()->hasActivity($account));
    }

    public function test_deactivated_account_hidden_from_payment_deposit_picker(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $active = $this->makeCashAccount('ILS', '0');
        $inactive = $this->makeCashAccount('ILS', '0');
        $this->service()->deactivate($inactive);

        $payment = app(PaymentService::class)->createDraft([
            'customer_id' => $this->makeCustomer()->id,
            'payment_currency' => 'ILS', 'payment_amount' => 0,
        ]);

        Livewire::actingAs($gm)->test(PaymentShow::class, ['payment' => $payment])
            ->assertViewHas('depositAccounts', function ($accounts) use ($active, $inactive) {
                $ids = $accounts->pluck('id');

                return $ids->contains($active->id) && ! $ids->contains($inactive->id);
            });
    }

    public function test_deactivated_account_hidden_from_new_transfers_but_visible_in_cards(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $active = $this->makeCashAccount('ILS', '0');
        $inactive = $this->makeCashAccount('ILS', '0');
        $this->service()->deactivate($inactive);

        Livewire::actingAs($gm)->test(CashBanksIndex::class)
            // Transfer dropdowns: active only.
            ->assertViewHas('accounts', function ($accounts) use ($active, $inactive) {
                $ids = collect($accounts)->pluck('id');

                return $ids->contains($active->id) && ! $ids->contains($inactive->id);
            })
            // Cards grid: both still visible (statement/reactivation stay reachable).
            ->assertViewHas('rows', function ($rows) use ($inactive) {
                return collect($rows)->pluck('account.id')->contains($inactive->id);
            });
    }

    public function test_deactivated_account_statement_still_renders(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $account = $this->makeCashAccount('ILS', '750');
        $this->service()->deactivate($account);

        Livewire::actingAs($gm)->test(CashBanksIndex::class)
            ->call('showStatement', $account->id)
            ->assertViewHas('statement', fn ($s) => $s !== null && $s['account']->id === $account->id)
            ->assertHasNoErrors();
    }

    public function test_reactivating_account_returns_it_to_pickers(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $account = $this->makeCashAccount('ILS', '0');
        $this->service()->deactivate($account);
        $this->assertFalse($account->fresh()->is_active);

        $this->service()->activate($account);
        $this->assertTrue($account->fresh()->is_active);

        Livewire::actingAs($gm)->test(CashBanksIndex::class)
            ->assertViewHas('accounts', fn ($accounts) => collect($accounts)->pluck('id')->contains($account->id));
    }

    // ---- Delete guards ----

    public function test_unused_account_can_be_deleted(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $account = $this->makeCashAccount('ILS', '0'); // opening 0 → no journal, no activity

        $this->assertTrue($this->service()->canDelete($account));
        $this->service()->delete($account);

        $this->assertDatabaseMissing('financial_accounts', ['id' => $account->id]);
    }

    public function test_account_with_journal_activity_cannot_be_deleted(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $account = $this->makeCashAccount('ILS', '500'); // opening balance posts a journal line

        $this->assertFalse($this->service()->canDelete($account));
        $this->assertContains('حركات في الأستاذ العام', $this->service()->deleteBlockReasons($account));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('لا يمكن حذف هذا الحساب لوجود حركات مالية مرتبطة به');
        $this->service()->delete($account);
    }

    public function test_account_linked_to_a_payment_cannot_be_deleted(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $account = $this->makeCashAccount('ILS', '0'); // no activity by itself

        // A draft payment referencing it (account_id) is enough to block deletion.
        app(PaymentService::class)->createDraft([
            'customer_id' => $this->makeCustomer()->id,
            'payment_currency' => 'ILS', 'payment_amount' => '10', 'exchange_rate' => '3.5',
            'account_id' => $account->id,
        ]);

        $this->assertFalse($this->service()->canDelete($account));
        $this->assertContains('دفعات مرتبطة', $this->service()->deleteBlockReasons($account));
    }

    public function test_account_linked_to_a_transfer_cannot_be_deleted(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $from = $this->makeCashAccount('ILS', '1000');
        $to = $this->makeCashAccount('ILS', '0');

        app(AccountTransferService::class)->transfer($from, $to, '100');

        $this->assertFalse($this->service()->canDelete($to));
        $this->assertContains('تحويلات مرتبطة', $this->service()->deleteBlockReasons($to));
    }

    // ---- System-default guard ----

    public function test_system_default_account_cannot_be_deactivated_or_deleted(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $account = $this->makeCashAccount('ILS', '0'); // otherwise deletable
        app(Settings::class)->set('finance', FinancialAccountService::DEFAULT_ACCOUNT_SETTING, (string) $account->id, 'string');

        $this->assertTrue($this->service()->isSystemDefault($account));
        $this->assertFalse($this->service()->canDelete($account));
        $this->assertContains('حساب افتراضي في الإعدادات', $this->service()->deleteBlockReasons($account));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('معيّن كحساب افتراضي');
        $this->service()->deactivate($account);
    }

    // ---- Authorization ----

    public function test_employee_cannot_manage_account_lifecycle(): void
    {
        // Create the account as a manager first.
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $acc = $this->makeCashAccount('ILS', '0');

        // A plain employee cannot even open the cash/banks screen, let alone act.
        [$employee] = $this->makeStaff();
        Livewire::actingAs($employee)->test(CashBanksIndex::class)->assertForbidden();

        // And a direct lifecycle call is refused at the service authorization layer.
        $this->actingAs($employee);
        $this->assertFalse($employee->can('financial_accounts.manage'));
        $this->assertTrue($this->service()->hasActivity($acc) === false); // account still intact
        $this->assertDatabaseHas('financial_accounts', ['id' => $acc->id, 'is_active' => true]);
    }

    public function test_super_admin_can_deactivate_via_component(): void
    {
        $sa = $this->makeUser(RoleName::SuperAdmin);
        $this->actingAs($sa);
        $account = $this->makeCashAccount('ILS', '0');

        Livewire::actingAs($sa)->test(CashBanksIndex::class)
            ->call('confirm', 'deactivate', $account->id)
            ->call('runConfirm')
            ->assertHasNoErrors();

        $this->assertFalse($account->fresh()->is_active);
    }

    public function test_component_delete_blocks_used_account_with_message(): void
    {
        $sa = $this->makeUser(RoleName::SuperAdmin);
        $this->actingAs($sa);
        $account = $this->makeCashAccount('ILS', '500'); // has activity

        // The delete button is hidden in the UI, but even a crafted call is refused.
        Livewire::actingAs($sa)->test(CashBanksIndex::class)
            ->call('confirm', 'delete', $account->id)
            ->call('runConfirm')
            ->assertHasErrors('lifecycle');

        $this->assertDatabaseHas('financial_accounts', ['id' => $account->id]);
    }
}
