<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\Supplier;
use App\Services\SupplierBalanceService;
use App\Services\SupplierBillService;
use App\Services\SupplierPaymentService;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('ملف المورد')]
class SupplierProfile extends Component
{
    public Supplier $supplier;

    public string $tab = 'overview';

    public function mount(Supplier $supplier): void
    {
        $this->authorize('suppliers.view');
        $this->supplier = $supplier;
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
    }

    public function newBill(SupplierBillService $service)
    {
        $this->authorize('supplier_bills.create');
        $bill = $service->createDraft([
            'supplier_id' => $this->supplier->id,
            'bill_date' => now()->toDateString(),
            'currency' => 'ILS',
        ], []);

        return redirect()->route('admin.supplier-bills.show', $bill);
    }

    public function newPayment(SupplierPaymentService $service)
    {
        $this->authorize('supplier_payments.create');
        $payment = $service->createDraft([
            'supplier_id' => $this->supplier->id,
            'payment_date' => now()->toDateString(),
            'currency' => 'ILS',
            'amount' => 0,
        ]);

        return redirect()->route('admin.supplier-payments.show', $payment);
    }

    public function render(SupplierBalanceService $balances)
    {
        $data = ['balance' => $balances->summary($this->supplier)];

        if ($this->tab === 'bills') {
            $data['bills'] = $this->supplier->bills()->latest('bill_date')->latest('id')->get();
        }
        if ($this->tab === 'payments') {
            $data['payments'] = $this->supplier->payments()->latest('payment_date')->latest('id')->get();
        }
        if ($this->tab === 'expenses') {
            $data['expenses'] = $this->supplier->expenses()->with('category')->latest('expense_date')->get();
        }
        if ($this->tab === 'statement') {
            $data['statement'] = $this->buildStatement();
        }
        if ($this->tab === 'activity') {
            $data['activity'] = AuditLog::where('auditable_type', $this->supplier->getMorphClass())
                ->where('auditable_id', $this->supplier->id)
                ->with('user')->latest('created_at')->limit(50)->get();
        }

        return view('livewire.admin.supplier-profile', $data);
    }

    /**
     * A simple ILS payables statement: bills as debits (we owe), payments as
     * credits (we paid), with a running balance.
     *
     * @return array{entries:list<array<string,mixed>>, closing:string}
     */
    private function buildStatement(): array
    {
        $rows = [];
        foreach ($this->supplier->bills()->where('status', '!=', 'draft')->where('status', '!=', 'cancelled')->get() as $bill) {
            $rows[] = [
                'date' => $bill->bill_date, 'sort' => $bill->bill_date->format('Y-m-d').'-1-'.$bill->id,
                'ref' => $bill->bill_number, 'desc' => 'فاتورة مورد ('.$bill->currency.')',
                'debit' => Money::money($bill->total_ils), 'credit' => '0.00',
            ];
        }
        foreach ($this->supplier->payments()->posted()->get() as $payment) {
            $rows[] = [
                'date' => $payment->payment_date, 'sort' => $payment->payment_date->format('Y-m-d').'-2-'.$payment->id,
                'ref' => $payment->payment_number, 'desc' => 'دفعة مورد ('.$payment->currency.')',
                'debit' => '0.00', 'credit' => Money::money($payment->amount_ils),
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a['sort'], $b['sort']));

        $balance = '0.00';
        $entries = [];
        foreach ($rows as $row) {
            $balance = Money::add(Money::subtract($balance, $row['credit']), $row['debit']);
            unset($row['sort']);
            $row['balance'] = $balance;
            $entries[] = $row;
        }

        return ['entries' => $entries, 'closing' => $balance];
    }
}
