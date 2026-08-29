<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\Project;
use App\Models\User;
use App\Services\ExpenseService;
use App\Services\SupplierBillService;
use App\Services\SupplierPaymentService;
use App\Services\SupplierService;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * DEMO suppliers, expenses, vendor bills and supplier payments — balanced,
 * illustrative data only (no real money). Exercises ILS and USD flows including
 * a USD bill paid at a different rate (FX gain/loss).
 */
class Sprint5DemoSeeder extends Seeder
{
    public function run(): void
    {
        Auth::login(User::where('email', 'accountant@superapple.ps')->first() ?? User::first());

        $suppliers = app(SupplierService::class);
        $expenses = app(ExpenseService::class);
        $bills = app(SupplierBillService::class);
        $supplierPayments = app(SupplierPaymentService::class);

        $cashIls = FinancialAccount::where('currency', 'ILS')->where('type', 'cash')->first();
        $bankIls = FinancialAccount::where('currency', 'ILS')->where('type', 'bank')->first();
        $cashUsd = FinancialAccount::where('currency', 'USD')->first();
        $catByName = ExpenseCategory::pluck('id', 'name');
        $project = Project::first();

        // ---- Suppliers ----
        $s1 = $suppliers->create(['name' => 'مطبعة الرواد', 'phone' => '0599111222', 'supplier_type' => 'طباعة']);
        $s2 = $suppliers->create(['name' => 'شركة الاستضافة السحابية', 'phone' => '0599333444', 'supplier_type' => 'خدمات']);
        $suppliers->create(['name' => 'مورد قرطاسية', 'phone' => '0599555666']);

        // ---- Direct paid expenses (immediate) ----
        $e1 = $expenses->createDraft([
            'expense_date' => Carbon::now()->subDays(20)->toDateString(),
            'category_id' => $catByName['إيجار'] ?? $catByName->first(),
            'description' => 'إيجار المكتب - الشهر الحالي',
            'currency' => 'ILS', 'amount' => '3000', 'financial_account_id' => $bankIls?->id,
            'payment_method' => 'bank_transfer',
        ]);
        $expenses->post($e1);

        $e2 = $expenses->createDraft([
            'expense_date' => Carbon::now()->subDays(10)->toDateString(),
            'category_id' => $catByName['اشتراكات برمجية'] ?? $catByName->first(),
            'description' => 'اشتراك Adobe السنوي',
            'currency' => 'USD', 'amount' => '600', 'exchange_rate' => '3.30',
            'financial_account_id' => $cashUsd?->id, 'payment_method' => 'credit_card',
        ]);
        $expenses->post($e2);

        $e3 = $expenses->createDraft([
            'expense_date' => Carbon::now()->subDays(5)->toDateString(),
            'category_id' => $catByName['مواصلات'] ?? $catByName->first(),
            'description' => 'مواصلات وتوصيل',
            'currency' => 'ILS', 'amount' => '450', 'financial_account_id' => $cashIls?->id,
            'project_id' => $project?->id, 'payment_method' => 'cash',
        ]);
        $expenses->post($e3);

        // ---- Supplier bills (accrual, pay later) ----
        // ILS bill, fully then partially paid.
        $b1 = $bills->createDraft([
            'supplier_id' => $s1->id, 'bill_date' => Carbon::now()->subDays(15)->toDateString(),
            'due_date' => Carbon::now()->addDays(15)->toDateString(), 'currency' => 'ILS',
            'reference_number' => 'INV-RAWAD-88',
        ], [
            ['description' => 'طباعة بروشورات', 'quantity' => 1, 'unit_price' => '1200', 'tax' => '0', 'expense_account_id' => Account::where('code', '5700')->value('id')],
        ]);
        $bills->post($b1);

        // USD bill — will be paid later at a different rate → FX gain/loss.
        $b2 = $bills->createDraft([
            'supplier_id' => $s2->id, 'bill_date' => Carbon::now()->subDays(12)->toDateString(),
            'currency' => 'USD', 'exchange_rate' => '3.30', 'reference_number' => 'CLOUD-2026-1',
        ], [
            ['description' => 'استضافة سحابية - ربع سنوي', 'quantity' => 1, 'unit_price' => '300', 'tax' => '0', 'expense_account_id' => Account::where('code', '5400')->value('id')],
        ]);
        $bills->post($b2);

        // ---- Supplier payments ----
        // Pay the ILS bill in full from the bank.
        $sp1 = $supplierPayments->createDraft([
            'supplier_id' => $s1->id, 'payment_date' => Carbon::now()->subDays(3)->toDateString(),
            'currency' => 'ILS', 'amount' => '1200', 'financial_account_id' => $bankIls?->id,
        ]);
        $supplierPayments->post($sp1, [['bill_id' => $b1->id, 'allocated_original' => '1200']]);

        // Pay the USD bill from the USD cash at a lower rate (3.20) → FX gain.
        $sp2 = $supplierPayments->createDraft([
            'supplier_id' => $s2->id, 'payment_date' => Carbon::now()->subDay()->toDateString(),
            'currency' => 'USD', 'amount' => '300', 'exchange_rate' => '3.20',
            'financial_account_id' => $cashUsd?->id,
        ]);
        $supplierPayments->post($sp2, [['bill_id' => $b2->id, 'allocated_original' => '300']]);

        Auth::logout();
    }
}
