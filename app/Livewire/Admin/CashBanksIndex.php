<?php

namespace App\Livewire\Admin;

use App\Enums\FinancialAccountType;
use App\Models\Account;
use App\Models\FinancialAccount;
use App\Services\AccountingReportService;
use App\Services\AccountTransferService;
use App\Services\FinancialAccountService;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('الصندوق والبنوك')]
class CashBanksIndex extends Component
{
    public ?int $statementAccount = null;

    // Create form
    public bool $showCreate = false;

    public string $name = '';

    public string $type = 'cash';

    public string $currency = 'ILS';

    public ?int $gl_account_id = null;

    public string $opening_balance = '0';

    public ?string $opening_balance_date = null;

    // Transfer form
    public bool $showTransfer = false;

    public ?int $from_account_id = null;

    public ?int $to_account_id = null;

    public string $transfer_amount = '0';

    // Lifecycle confirmation modal
    public bool $showConfirm = false;

    public string $confirmAction = '';

    public ?int $confirmAccountId = null;

    public string $confirmName = '';

    public function mount(): void
    {
        $this->authorize('financial_accounts.view');
    }

    public function openCreate(): void
    {
        $this->authorize('financial_accounts.manage');
        $this->reset(['name', 'type', 'currency', 'gl_account_id', 'opening_balance', 'opening_balance_date']);
        $this->showCreate = true;
    }

    public function saveAccount(FinancialAccountService $service): void
    {
        $this->authorize('financial_accounts.manage');
        $this->validate([
            'name' => 'required|string|max:150',
            'type' => 'required|string',
            'currency' => 'required|in:ILS,USD',
            'gl_account_id' => 'required|integer|exists:chart_of_accounts,id',
            'opening_balance' => 'nullable|numeric',
        ]);

        $service->create([
            'name' => $this->name,
            'type' => $this->type,
            'currency' => $this->currency,
            'gl_account_id' => $this->gl_account_id,
            'opening_balance' => $this->opening_balance ?: 0,
            'opening_balance_date' => $this->opening_balance_date,
        ]);

        $this->showCreate = false;
        session()->flash('status', 'تم إنشاء الحساب وقيد رصيده الافتتاحي.');
    }

    public function openTransfer(): void
    {
        $this->authorize('financial_accounts.manage');
        $this->reset(['from_account_id', 'to_account_id', 'transfer_amount']);
        $this->showTransfer = true;
    }

    public function saveTransfer(AccountTransferService $service): void
    {
        $this->authorize('financial_accounts.manage');
        $this->validate([
            'from_account_id' => 'required|integer|different:to_account_id',
            'to_account_id' => 'required|integer',
            'transfer_amount' => 'required|numeric|gt:0',
        ]);

        try {
            $service->transfer(
                FinancialAccount::findOrFail($this->from_account_id),
                FinancialAccount::findOrFail($this->to_account_id),
                $this->transfer_amount,
            );
        } catch (\RuntimeException $e) {
            $this->addError('transfer_amount', $e->getMessage());

            return;
        }

        $this->showTransfer = false;
        session()->flash('status', 'تم تنفيذ التحويل.');
    }

    public function showStatement(int $id): void
    {
        $this->statementAccount = $id;
    }

    /** Open the confirmation modal for a lifecycle action (deactivate/activate/delete). */
    public function confirm(string $action, int $id): void
    {
        $this->authorize('financial_accounts.manage');
        abort_unless(in_array($action, ['deactivate', 'activate', 'delete'], true), 400);

        $account = FinancialAccount::findOrFail($id);
        $this->confirmAction = $action;
        $this->confirmAccountId = $account->id;
        $this->confirmName = $account->name;
        $this->showConfirm = true;
    }

    public function runConfirm(FinancialAccountService $service): void
    {
        $this->authorize('financial_accounts.manage');
        $account = FinancialAccount::findOrFail($this->confirmAccountId);
        $action = $this->confirmAction;

        try {
            match ($action) {
                'deactivate' => $service->deactivate($account),
                'activate' => $service->activate($account),
                'delete' => $service->delete($account),
                default => abort(400),
            };
        } catch (\RuntimeException $e) {
            $this->showConfirm = false;
            $this->addError('lifecycle', $e->getMessage());

            return;
        }

        // A deleted account should not linger as the open statement.
        if ($action === 'delete' && $this->statementAccount === $account->id) {
            $this->statementAccount = null;
        }

        $this->showConfirm = false;
        $this->reset(['confirmAction', 'confirmAccountId', 'confirmName']);
        session()->flash('status', match ($action) {
            'deactivate' => 'تم تعطيل الحساب.',
            'activate' => 'تم تفعيل الحساب.',
            'delete' => 'تم حذف الحساب.',
            default => 'تم.',
        });
    }

    public function render(FinancialAccountService $balances, AccountingReportService $reports)
    {
        // All accounts (active + inactive) are shown as cards; only active ones
        // are offered as transfer source/destination for new movements.
        $accounts = FinancialAccount::with('glAccount')->orderBy('name')->get();
        $rows = $accounts->map(fn ($a) => [
            'account' => $a,
            'balance_ils' => $balances->balanceIls($a),
            'balance_original' => $balances->balanceOriginal($a),
            'can_delete' => $balances->canDelete($a),
            'is_default' => $balances->isSystemDefault($a),
        ]);
        $transferAccounts = $accounts->where('is_active', true)->values();

        $statement = null;
        if ($this->statementAccount) {
            $account = FinancialAccount::find($this->statementAccount);
            if ($account) {
                $statement = [
                    'account' => $account,
                    'ledger' => $reports->generalLedger($account->gl_account_id, now()->startOfYear()->toDateString(), now()->toDateString()),
                ];
            }
        }

        return view('livewire.admin.cash-banks-index', [
            'rows' => $rows,
            'accounts' => $transferAccounts,
            'cashGlAccounts' => Account::where('account_type', 'asset')->postable()->orderBy('code')->get(),
            'typeOptions' => FinancialAccountType::options(),
            'statement' => $statement,
            'totalIls' => Money::sum($rows->pluck('balance_ils')),
        ]);
    }
}
