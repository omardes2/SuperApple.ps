<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Task;
use App\Models\User;
use App\Services\CustomerOpeningBalanceService;
use App\Services\ExpenseService;
use App\Services\LedgerPostingService;
use App\Services\PaymentService;
use App\Services\TaskService;
use Database\Seeders\ExpenseCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The safe test-data purge command: it removes trial customers/invoices/
 * payments/expenses/opening-balances and their full accounting footprint,
 * leaves all reference/setup data and unrelated journals intact, previews by
 * default, and only deletes with --execute + the PURGE confirmation.
 */
class PurgeTestBusinessDataTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->seed(ExpenseCategorySeeder::class);
    }

    /** Build a realistic slice of business data with journals. */
    private function seedBusinessData(): array
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));

        $customer = $this->makeCustomer();

        // Opening balance (posts an AR/OBE journal).
        app(CustomerOpeningBalanceService::class)->create($customer, [
            'type' => 'debit', 'amount_usd' => '1000', 'exchange_rate' => '3.10', 'balance_date' => '2026-01-01',
        ]);

        // Issued invoice (posts an AR/Revenue journal + items).
        $invoice = $this->makeIssuedInvoice($customer, '500', '3.20');

        // Payment against the invoice (posts a Cash/AR journal + allocation).
        $cash = $this->makeCashAccount('USD');
        $payment = app(PaymentService::class)->createDraft([
            'customer_id' => $customer->id, 'payment_date' => '2026-02-01',
            'payment_currency' => 'USD', 'payment_amount' => '100', 'exchange_rate' => '3.20',
            'account_id' => $cash->id, 'payment_method' => 'cash',
        ]);
        app(PaymentService::class)->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '100']]);

        // Expense (posts an Expense/Cash journal).
        $ilsCash = $this->makeCashAccount('ILS');
        $expense = app(ExpenseService::class)->createDraft([
            'category_id' => ExpenseCategory::active()->first()->id, 'currency' => 'ILS',
            'amount' => '300', 'description' => 'اختبار', 'financial_account_id' => $ilsCash->id,
        ]);
        app(ExpenseService::class)->post($expense);

        // A WhatsApp message tied to the customer/invoice.
        DB::table('whatsapp_messages')->insert([
            'customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'phone' => '0599',
            'message_body' => 'x', 'provider' => 'null', 'direction' => 'outbound', 'status' => 'sent',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // An UNRELATED accounting journal that must survive: a financial-account
        // opening balance (source_type = financial_account).
        $bank = $this->makeCashAccount('ILS', '5000');
        app(LedgerPostingService::class)->postOpeningBalance($bank->refresh());

        $task = $this->seedTaskGraph($customer);

        return compact('customer', 'invoice', 'payment', 'expense', 'bank', 'task');
    }

    /** A task with its full dependent graph + a task notification and a kept one. */
    private function seedTaskGraph(Customer $customer): Task
    {
        $service = Service::firstOrCreate(
            ['service_code' => 'TSK-SVC'],
            ['name' => 'خدمة مهمة', 'category' => 'تصميم', 'service_type' => 'custom', 'is_active' => true],
        );
        [$user, $employee] = $this->makeStaff(RoleName::Employee);
        $this->actingAs($user);

        $task = app(TaskService::class)->create([
            'title' => 'مهمة تجريبية', 'customer_id' => $customer->id,
            'service_ids' => [$service->id], 'primary_assignee_id' => $employee->id,
            'start_date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'priority' => 'normal',
        ]);

        app(TaskService::class)->addComment($task, 'تعليق تجريبي');
        app(TaskService::class)->addChecklistItem($task, 'بند تحقق');
        DB::table('task_status_history')->insert([
            'task_id' => $task->id, 'to_status' => 'in_progress', 'created_at' => now(),
        ]);
        $tagId = DB::table('tags')->insertGetId(['name' => 'وسم', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('task_tag')->insert(['task_id' => $task->id, 'tag_id' => $tagId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('attachments')->insert([
            'attachable_type' => Task::class, 'attachable_id' => $task->id,
            'path' => 'x.pdf', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // A task notification (must be cleaned) and a non-task one (must remain).
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\TaskAssigned',
            'notifiable_type' => User::class, 'notifiable_id' => $user->id,
            'data' => json_encode(['type' => 'task.assigned', 'task_id' => $task->id]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\Welcome',
            'notifiable_type' => User::class, 'notifiable_id' => $user->id,
            'data' => json_encode(['type' => 'system.welcome', 'message' => 'مرحباً']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->makeUser(RoleName::SuperAdmin)); // restore actor

        return $task->fresh();
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $this->seedBusinessData();
        $before = DB::table('customers')->count();

        $this->artisan('app:purge-test-business-data')
            ->expectsOutputToContain('No data was deleted. Use --execute to continue.')
            ->assertExitCode(0);

        $this->assertSame($before, DB::table('customers')->count());
        $this->assertGreaterThan(0, DB::table('invoices')->count());
        $this->assertGreaterThan(0, DB::table('payments')->count());
    }

    public function test_wrong_confirmation_aborts_without_deleting(): void
    {
        $this->seedBusinessData();

        $this->artisan('app:purge-test-business-data --execute')
            ->expectsQuestion('اكتب PURGE للمتابعة (Type PURGE to continue)', 'nope')
            ->assertExitCode(0);

        $this->assertGreaterThan(0, DB::table('customers')->count());
        $this->assertGreaterThan(0, DB::table('invoices')->count());
    }

    public function test_execute_purges_business_data_and_footprint(): void
    {
        $data = $this->seedBusinessData();

        // Sanity: everything exists first.
        $this->assertGreaterThan(0, DB::table('customers')->count());
        $this->assertGreaterThan(0, DB::table('invoice_items')->count());
        $this->assertGreaterThan(0, DB::table('payment_allocations')->count());
        $this->assertGreaterThan(0, JournalEntry::whereIn('source_type', ['invoice', 'payment', 'expense', 'customer_opening_balance'])->count());

        $this->artisan('app:purge-test-business-data --execute')
            ->expectsQuestion('اكتب PURGE للمتابعة (Type PURGE to continue)', 'PURGE')
            ->assertExitCode(0);

        // ---- Business data gone ----
        foreach (['customers', 'invoices', 'invoice_items', 'payments', 'payment_allocations', 'expenses', 'customer_opening_balances', 'whatsapp_messages'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} must be empty");
        }

        // ---- Accounting footprint gone (no orphan movement) ----
        $this->assertSame(0, JournalEntry::whereIn('source_type', ['invoice', 'payment', 'expense', 'customer_opening_balance'])->count());
        $this->assertSame(0, DB::table('journal_entry_lines')->whereNotNull('invoice_id')->count());
        $this->assertSame(0, DB::table('journal_entry_lines')->whereNotNull('payment_id')->count());

        // ---- Unrelated journal (financial-account opening) survives ----
        $this->assertSame(1, JournalEntry::where('source_type', 'financial_account')->count());
    }

    public function test_reference_and_setup_data_is_preserved(): void
    {
        $this->seedBusinessData();
        $departmentsBefore = DB::table('departments')->count();

        $this->artisan('app:purge-test-business-data --execute')
            ->expectsQuestion('اكتب PURGE للمتابعة (Type PURGE to continue)', 'PURGE')
            ->assertExitCode(0);

        $this->assertGreaterThan(0, User::count());
        $this->assertGreaterThan(0, Employee::count());
        $this->assertGreaterThan(0, Service::count());
        $this->assertGreaterThan(0, Role::count());
        $this->assertGreaterThan(0, Permission::count());
        $this->assertGreaterThan(0, ExpenseCategory::count());
        $this->assertGreaterThan(0, FinancialAccount::count());
        $this->assertGreaterThan(0, DB::table('chart_of_accounts')->count());
        $this->assertGreaterThan(0, DB::table('system_accounts')->count());
        $this->assertSame($departmentsBefore, DB::table('departments')->count());
        // Attendance/leaves/payroll infrastructure untouched (tables still present & queryable).
        $this->assertSame(0, DB::table('tasks')->count()); // all tasks purged
    }

    public function test_tasks_and_their_full_graph_are_purged_services_kept(): void
    {
        $data = $this->seedBusinessData();
        $servicesBefore = Service::count();

        // The whole task graph exists first.
        $this->assertGreaterThan(0, DB::table('tasks')->count());
        $this->assertGreaterThan(0, DB::table('task_assignees')->count());
        $this->assertGreaterThan(0, DB::table('task_service')->count());
        $this->assertGreaterThan(0, DB::table('task_comments')->count());
        $this->assertGreaterThan(0, DB::table('task_checklist_items')->count());
        $this->assertGreaterThan(0, DB::table('task_status_history')->count());
        $this->assertGreaterThan(0, DB::table('task_tag')->count());
        $this->assertGreaterThan(0, DB::table('attachments')->where('attachable_type', Task::class)->count());

        $this->artisan('app:purge-test-business-data --execute')
            ->expectsQuestion('اكتب PURGE للمتابعة (Type PURGE to continue)', 'PURGE')
            ->assertExitCode(0);

        foreach (['tasks', 'task_assignees', 'task_service', 'task_comments', 'task_checklist_items', 'task_status_history', 'task_tag'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} must be empty");
        }
        $this->assertSame(0, DB::table('attachments')->where('attachable_type', Task::class)->count());

        // Services (and the tags catalogue) are kept — only pivots were removed.
        $this->assertSame($servicesBefore, Service::count());
        $this->assertGreaterThan(0, DB::table('tags')->count());
    }

    public function test_task_notifications_cleaned_but_others_kept(): void
    {
        $this->seedBusinessData();
        $this->assertGreaterThan(0, DB::table('notifications')->whereRaw("json_extract(data, '\$.task_id') is not null")->count());

        $this->artisan('app:purge-test-business-data --execute')
            ->expectsQuestion('اكتب PURGE للمتابعة (Type PURGE to continue)', 'PURGE')
            ->assertExitCode(0);

        // Task notifications gone; the non-task (welcome) notification remains.
        $this->assertSame(0, DB::table('notifications')->whereRaw("json_extract(data, '\$.task_id') is not null")->count());
        $this->assertSame(1, DB::table('notifications')->where('type', 'App\\Notifications\\Welcome')->count());
    }

    public function test_running_twice_is_idempotent(): void
    {
        $this->seedBusinessData();

        $this->artisan('app:purge-test-business-data --execute')
            ->expectsQuestion('اكتب PURGE للمتابعة (Type PURGE to continue)', 'PURGE')
            ->assertExitCode(0);

        // Second run finds nothing and reports so.
        $this->artisan('app:purge-test-business-data --execute')
            ->expectsOutputToContain('لا توجد بيانات تجريبية للحذف.')
            ->assertExitCode(0);
    }

    public function test_integrity_and_health_pass_after_purge(): void
    {
        $this->seedBusinessData();

        $this->artisan('app:purge-test-business-data --execute')
            ->expectsQuestion('اكتب PURGE للمتابعة (Type PURGE to continue)', 'PURGE')
            ->assertExitCode(0);

        $this->artisan('app:verify-integrity')->assertExitCode(0);
        $this->artisan('app:health-check')->assertExitCode(0);
    }
}
