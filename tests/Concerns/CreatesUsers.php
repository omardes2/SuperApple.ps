<?php

namespace Tests\Concerns;

use App\Enums\FinancialAccountType;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\AttendanceRecord;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeSalaryProfile;
use App\Models\ExchangeRate;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\User;
use App\Services\FinancialAccountService;
use App\Services\InvoiceService;
use App\Services\PayrollService;
use App\Services\SalaryProfileService;
use App\Services\Settings;
use App\Services\SubscriptionService;
use App\Services\SupplierService;
use App\Services\WhatsApp\FakeWhatsAppProvider;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

trait CreatesUsers
{
    protected function seedRoles(): void
    {
        $this->seed(RolePermissionSeeder::class);
        // The chart of accounts is foundational for any financial flow (issuing
        // an invoice or posting a payment now writes GL journals), so every test
        // that seeds roles also gets the accounting backbone.
        $this->seed(ChartOfAccountsSeeder::class);
    }

    protected function makeUser(RoleName $role, array $attributes = []): User
    {
        $user = User::create(array_merge([
            'name' => $role->value,
            'email' => str()->random(8).'@test.local',
            'password' => Hash::make('password'),
            'is_active' => true,
            'locale' => 'ar',
        ], $attributes));

        $user->assignRole($role->value);

        return $user;
    }

    protected function makeDepartment(array $attributes = []): Department
    {
        return Department::create(array_merge([
            'name' => 'قسم تجريبي',
            'code' => 'D'.str()->upper(str()->random(4)),
            'is_active' => true,
        ], $attributes));
    }

    /**
     * Create an employee profile, optionally linked to a user (which also sets
     * the reverse users.employee_id link).
     */
    protected function makeEmployee(?User $user = null, array $attributes = []): Employee
    {
        $employee = Employee::create(array_merge([
            'employee_number' => 'E'.str()->upper(str()->random(6)),
            'full_name' => 'موظف تجريبي',
            'user_id' => $user?->id,
            'employment_status' => 'active',
            'employment_type' => 'full_time',
            'working_hours_per_day' => 8,
            'is_active' => true,
        ], $attributes));

        $user?->update(['employee_id' => $employee->id]);

        return $employee;
    }

    /**
     * A logged-in employee: a user with the given role plus a linked profile.
     *
     * @return array{0: User, 1: Employee}
     */
    protected function makeStaff(RoleName $role = RoleName::Employee, array $employeeAttributes = []): array
    {
        $user = $this->makeUser($role);
        $employee = $this->makeEmployee($user, $employeeAttributes);

        return [$user, $employee];
    }

    protected function makeCustomer(array $attributes = []): Customer
    {
        static $seq = 0;
        $seq++;

        return Customer::create(array_merge([
            'customer_number' => 'CUS-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            'name' => 'عميل تجريبي '.$seq,
            'phone' => '0591000000',
            'status' => 'active',
            'is_active' => true,
        ], $attributes));
    }

    protected function makeProject(?Customer $customer = null, array $attributes = []): Project
    {
        static $seq = 0;
        $seq++;
        $customer ??= $this->makeCustomer();

        return Project::create(array_merge([
            'project_number' => 'PRJ-'.now()->year.'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'name' => 'مشروع تجريبي '.$seq,
            'priority' => 'normal',
            'status' => 'active',
        ], $attributes));
    }

    protected function makeTask(array $attributes = []): Task
    {
        static $seq = 0;
        $seq++;

        return Task::create(array_merge([
            'task_number' => 'TSK-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
            'title' => 'مهمة تجريبية '.$seq,
            'priority' => 'normal',
            'status' => 'new',
        ], $attributes));
    }

    /**
     * An issued invoice for a given customer with a single USD line.
     * Requires an authenticated user (created_by). $rate is the invoice rate.
     */
    protected function makeIssuedInvoice(Customer $customer, string $totalUsd, string $rate = '3.20'): Invoice
    {
        $invoice = app(InvoiceService::class)->createDraft(
            ['customer_id' => $customer->id, 'invoice_date' => '2026-08-01', 'exchange_rate' => $rate],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => $totalUsd, 'tax_rate' => 0]],
        );

        return app(InvoiceService::class)->issue($invoice);
    }

    /**
     * An invoice with an explicit taxable line: unit price + tax rate (percent).
     */
    protected function makeTaxedInvoice(Customer $customer, string $unitPrice, float $taxRate, string $rate = '3.30'): Invoice
    {
        $invoice = app(InvoiceService::class)->createDraft(
            ['customer_id' => $customer->id, 'invoice_date' => '2026-08-01', 'exchange_rate' => $rate],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => $unitPrice, 'tax_rate' => $taxRate]],
        );

        return app(InvoiceService::class)->issue($invoice);
    }

    /** A cash financial account backed by a GL account. */
    protected function makeCashAccount(string $currency = 'ILS', string $opening = '0'): FinancialAccount
    {
        $code = $currency === 'USD' ? '1120' : '1110';
        $gl = Account::where('code', $code)->firstOrFail();

        return app(FinancialAccountService::class)->create([
            'name' => 'صندوق اختبار '.$currency.' '.str()->random(4),
            'type' => FinancialAccountType::Cash,
            'currency' => $currency,
            'gl_account_id' => $gl->id,
            'opening_balance' => $opening,
            'opening_balance_date' => '2026-07-01',
        ]);
    }

    protected function makeSupplier(array $attributes = []): Supplier
    {
        return app(SupplierService::class)->create(array_merge([
            'name' => 'مورد تجريبي '.str()->random(4),
            'phone' => '0599000000',
        ], $attributes));
    }

    protected function expenseCategory(): ExpenseCategory
    {
        $account = Account::where('code', '5900')->first();

        return ExpenseCategory::firstOrCreate(
            ['name' => 'فئة تجريبية'],
            ['default_expense_account_id' => $account?->id, 'is_active' => true],
        );
    }

    // ---- Payroll (Sprint 6) ----

    protected function makeSalaryProfile(Employee $employee, string $base, string $from = '2026-01-01', ?string $overtimeRate = null): EmployeeSalaryProfile
    {
        return app(SalaryProfileService::class)->setSalary($employee, [
            'base_salary_ils' => $base,
            'effective_from' => $from,
            'overtime_rate' => $overtimeRate,
        ]);
    }

    protected function makeAttendanceDay(Employee $employee, string $date, string $status = 'present', int $late = 0, int $overtime = 0): AttendanceRecord
    {
        return AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => $date,
            'status' => $status,
            'late_minutes' => $late,
            'overtime_minutes' => $overtime,
            'worked_minutes' => 480,
        ]);
    }

    protected function makeApprovedLeave(Employee $employee, bool $isPaid, string $start, string $end): LeaveRequest
    {
        static $seq = 0;
        $seq++;
        $type = LeaveType::firstOrCreate(
            ['code' => $isPaid ? 'PAID' : 'UNPAID'],
            ['name' => $isPaid ? 'إجازة مدفوعة' : 'إجازة غير مدفوعة', 'is_paid' => $isPaid, 'is_active' => true],
        );

        return LeaveRequest::create([
            'reference_no' => 'LV-T-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => $start,
            'end_date' => $end,
            'total_days' => Carbon::parse($start)->diffInDays($end) + 1,
            'status' => 'approved',
        ]);
    }

    protected function makePayrollRun(int $year = 2026, int $month = 8): PayrollRun
    {
        return app(PayrollService::class)->createRun($year, $month);
    }

    // ---- Subscriptions & WhatsApp (Sprint 7) ----

    /** Ensure a USD→ILS rate exists so auto-issue can snapshot one. */
    protected function seedExchangeRate(string $date = '2026-08-01', string $rate = '3.60'): ExchangeRate
    {
        return ExchangeRate::firstOrCreate(
            ['rate_date' => $date, 'base_currency' => 'USD', 'quote_currency' => 'ILS'],
            ['rate' => $rate, 'source' => 'manual'],
        );
    }

    /**
     * Create a subscription (draft) via the real service.
     *
     * @param  array<string,mixed>  $attributes
     * @param  list<array<string,mixed>>|null  $items
     */
    protected function makeSubscription(?Customer $customer = null, array $attributes = [], ?array $items = null): Subscription
    {
        $customer ??= $this->makeCustomer();
        $items ??= [['item_name' => 'باقة', 'quantity' => 1, 'unit_price_usd' => '600', 'tax_rate' => 0]];

        return app(SubscriptionService::class)->create(array_merge([
            'customer_id' => $customer->id,
            'name' => 'اشتراك تجريبي',
            'billing_cycle' => 'monthly',
            'billing_interval' => 1,
            'start_date' => '2026-08-01',
        ], $attributes), $items);
    }

    /** Create + activate a subscription in one step. */
    protected function makeActiveSubscription(?Customer $customer = null, array $attributes = [], ?array $items = null): Subscription
    {
        $sub = $this->makeSubscription($customer, $attributes, $items);
        app(SubscriptionService::class)->activate($sub->fresh());

        return $sub->fresh();
    }

    /** Turn on WhatsApp with the offline Fake provider and return it. */
    protected function useFakeWhatsApp(): FakeWhatsAppProvider
    {
        app(Settings::class)->set('whatsapp', 'enabled', true, 'bool');
        app(Settings::class)->set('whatsapp', 'provider', 'fake', 'string');
        app(Settings::class)->set('whatsapp', 'default_country_code', '970', 'string');
        $fake = app(FakeWhatsAppProvider::class);
        $fake->reset();

        return $fake;
    }

    /** Mark every working day (sun–thu) of a run's month attended for an employee. */
    protected function fillFullAttendance(Employee $employee, PayrollRun $run): void
    {
        for ($d = $run->period_start->copy(); $d->lte($run->period_end); $d->addDay()) {
            if (in_array($d->dayOfWeek, [0, 1, 2, 3, 4], true)) {
                $this->makeAttendanceDay($employee, $d->toDateString());
            }
        }
    }
}
