<?php

use App\Http\Controllers\CustomerImportTemplateController;
use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\InvoicePrintController;
use App\Http\Controllers\PaymentReceiptController;
use App\Http\Controllers\PayslipController;
use App\Http\Controllers\WhatsAppWebhookController;
use App\Livewire\Admin\ActivityFeed;
use App\Livewire\Admin\ArAgingReport;
use App\Livewire\Admin\AttendanceIndex;
use App\Livewire\Admin\AuditLogPage;
use App\Livewire\Admin\BalanceSheetReport;
use App\Livewire\Admin\CashBanksIndex;
use App\Livewire\Admin\ChartOfAccounts;
use App\Livewire\Admin\CustomerProfile;
use App\Livewire\Admin\CustomersImport;
use App\Livewire\Admin\CustomersIndex;
use App\Livewire\Admin\CustomersReport;
use App\Livewire\Admin\CustomerStatement;
use App\Livewire\Admin\Dashboard as AdminDashboard;
use App\Livewire\Admin\DepartmentsIndex;
use App\Livewire\Admin\EmployeeAdvancesIndex;
use App\Livewire\Admin\EmployeePayroll;
use App\Livewire\Admin\EmployeeProfile;
use App\Livewire\Admin\EmployeesIndex;
use App\Livewire\Admin\ExchangeGainLossReport;
use App\Livewire\Admin\ExpenseShow;
use App\Livewire\Admin\ExpensesIndex;
use App\Livewire\Admin\GeneralLedgerReport;
use App\Livewire\Admin\InvoiceShow;
use App\Livewire\Admin\InvoicesIndex;
use App\Livewire\Admin\JournalShow;
use App\Livewire\Admin\JournalsIndex;
use App\Livewire\Admin\LeavesIndex;
use App\Livewire\Admin\NotificationCenter;
use App\Livewire\Admin\PaymentCreate;
use App\Livewire\Admin\PaymentShow;
use App\Livewire\Admin\PaymentsIndex;
use App\Livewire\Admin\PayrollIndex;
use App\Livewire\Admin\PayrollReports;
use App\Livewire\Admin\PayrollShow;
use App\Livewire\Admin\ProductionReadiness;
use App\Livewire\Admin\ProfitLossReport;
use App\Livewire\Admin\ReconciliationReport;
use App\Livewire\Admin\ReminderRulesIndex;
use App\Livewire\Admin\ReportsCenter;
use App\Livewire\Admin\RolesPermissions;
use App\Livewire\Admin\ServicesIndex;
use App\Livewire\Admin\SettingsPage;
use App\Livewire\Admin\SupplierBillShow;
use App\Livewire\Admin\SupplierPaymentShow;
use App\Livewire\Admin\SupplierProfile;
use App\Livewire\Admin\SuppliersIndex;
use App\Livewire\Admin\TaskShow as AdminTaskShow;
use App\Livewire\Admin\TasksIndex;
use App\Livewire\Admin\TrialBalanceReport;
use App\Livewire\Admin\UsersIndex;
use App\Livewire\Admin\WhatsAppDashboard;
use App\Livewire\Admin\WhatsAppReport;
use App\Livewire\Admin\WhatsAppTemplatesIndex;
use App\Livewire\Auth\Login;
use App\Livewire\Employee\Dashboard as EmployeeDashboard;
use App\Livewire\Employee\MyAttendance;
use App\Livewire\Employee\MyLeaves;
use App\Livewire\Employee\MyPayslips;
use App\Livewire\Employee\MyTasks;
use App\Livewire\Employee\Notifications as EmployeeNotifications;
use App\Livewire\Employee\TaskShow as EmployeeTaskShow;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (! Auth::check()) {
        return redirect()->route('login');
    }

    return redirect()->route(
        Auth::user()->usesAdminExperience() ? 'admin.dashboard' : 'employee.dashboard'
    );
});

// ---- Public WhatsApp Cloud API webhook (Meta calls it; no app auth) ----
Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify']);
Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive']);

// ---- Guest ----
Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
});

// ---- Authenticated ----
Route::middleware('auth')->group(function () {
    Route::post('/logout', function (AuditLogger $audit) {
        $audit->log('logout', Auth::user(), 'Auth', description: 'تسجيل خروج');
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');

    // ---- Admin / back-office ----
    Route::prefix('admin')->name('admin.')->middleware('admin.area')->group(function () {
        Route::get('/', AdminDashboard::class)->middleware('can:dashboard.view')->name('dashboard');

        // HR — Sprint 1
        Route::get('/departments', DepartmentsIndex::class)->middleware('can:departments.view')->name('departments');
        Route::get('/employees', EmployeesIndex::class)->middleware('can:employees.view')->name('employees');
        Route::get('/employees/{employee}', EmployeeProfile::class)->middleware('can:employees.view')->name('employees.show');
        Route::get('/attendance', AttendanceIndex::class)->middleware('can:attendance.view')->name('attendance');
        Route::get('/leaves', LeavesIndex::class)->middleware('can:leaves.view')->name('leaves');

        // Operational core — Sprint 2
        Route::get('/customers', CustomersIndex::class)->middleware('can:customers.view')->name('customers');
        // Bulk import (customers + opening balances) — registered before the
        // {customer} wildcard so "import" is not swallowed as a model binding.
        Route::get('/customers/import', CustomersImport::class)->middleware('can:customers.import')->name('customers.import');
        // The Excel template is a plain binary download (not a Livewire action).
        Route::get('/customers/import/template', [CustomerImportTemplateController::class, 'download'])->middleware('can:customers.import')->name('customers.import.template');
        Route::get('/customers/{customer}', CustomerProfile::class)->middleware('can:customers.view')->name('customers.show');
        Route::get('/services', ServicesIndex::class)->middleware('can:services.view')->name('services');
        Route::get('/tasks', TasksIndex::class)->middleware('can:tasks.view')->name('tasks');
        Route::get('/tasks/{task}', AdminTaskShow::class)->middleware('can:tasks.view')->name('tasks.show');

        // Billing — Sprint 3
        Route::get('/invoices', InvoicesIndex::class)->middleware('can:invoices.view')->name('invoices');
        Route::get('/invoices/{invoice}', InvoiceShow::class)->middleware('can:invoices.view')->name('invoices.show');
        Route::get('/invoices/{invoice}/print', [InvoicePrintController::class, 'invoice'])->middleware('can:invoices.print')->name('invoices.print');
        Route::get('/invoices/{invoice}/pdf', [InvoicePdfController::class, 'download'])->middleware('can:invoices.print')->name('invoices.pdf');

        // Payments & collection — Sprint 4
        Route::get('/payments', PaymentsIndex::class)->middleware('can:payments.view')->name('payments');
        Route::get('/payments/create', PaymentCreate::class)->middleware('can:payments.create')->name('payments.create');
        Route::get('/payments/{payment}', PaymentShow::class)->middleware('can:payments.view')->name('payments.show')->whereNumber('payment');
        Route::get('/payments/{payment}/receipt', [PaymentReceiptController::class, 'receipt'])->middleware('can:payments.print')->name('payments.receipt');
        Route::get('/customers/{customer}/statement', CustomerStatement::class)->middleware('can:customer_statements.view')->name('customers.statement');
        Route::get('/reports/exchange-gain-loss', ExchangeGainLossReport::class)->middleware('can:exchange_gain_loss.view')->name('reports.exchange-gain-loss');

        // Accounting, expenses, suppliers, cash & banks — Sprint 5
        Route::get('/expenses', ExpensesIndex::class)->middleware('can:expenses.view')->name('expenses');
        Route::get('/expenses/{expense}', ExpenseShow::class)->middleware('can:expenses.view')->name('expenses.show');
        Route::get('/suppliers', SuppliersIndex::class)->middleware('can:suppliers.view')->name('suppliers');
        Route::get('/suppliers/{supplier}', SupplierProfile::class)->middleware('can:suppliers.view')->name('suppliers.show');
        Route::get('/supplier-bills/{bill}', SupplierBillShow::class)->middleware('can:supplier_bills.view')->name('supplier-bills.show');
        Route::get('/supplier-payments/{payment}', SupplierPaymentShow::class)->middleware('can:supplier_payments.view')->name('supplier-payments.show');
        Route::get('/cash-banks', CashBanksIndex::class)->middleware('can:financial_accounts.view')->name('cash-banks');
        Route::get('/accounting/chart', ChartOfAccounts::class)->middleware('can:chart_accounts.view')->name('accounting.chart');
        Route::get('/accounting/journals', JournalsIndex::class)->middleware('can:journals.view')->name('journals');
        Route::get('/accounting/journals/{journal}', JournalShow::class)->middleware('can:journals.view')->name('journals.show');
        Route::get('/accounting/general-ledger', GeneralLedgerReport::class)->middleware('can:reports.gl')->name('reports.gl');
        Route::get('/accounting/trial-balance', TrialBalanceReport::class)->middleware('can:reports.trial_balance')->name('reports.trial-balance');
        Route::get('/accounting/profit-loss', ProfitLossReport::class)->middleware('can:reports.profit_loss')->name('reports.profit-loss');
        Route::get('/accounting/balance-sheet', BalanceSheetReport::class)->middleware('can:reports.balance_sheet')->name('reports.balance-sheet');
        Route::get('/accounting/reconciliation', ReconciliationReport::class)->middleware('can:reports.reconciliation')->name('reports.reconciliation');

        // Payroll — Sprint 6
        Route::get('/payroll', PayrollIndex::class)->middleware('can:payroll.view')->name('payroll');
        Route::get('/payroll/reports', PayrollReports::class)->middleware('can:payroll.reports')->name('payroll.reports');
        Route::get('/payroll/{run}', PayrollShow::class)->middleware('can:payroll.view')->name('payroll.show');
        Route::get('/advances', EmployeeAdvancesIndex::class)->middleware('can:advances.view')->name('advances');
        Route::get('/employees/{employee}/payroll', EmployeePayroll::class)->middleware('can:salary_profiles.view')->name('employees.payroll');

        // Reports centre — Sprint 8
        Route::get('/reports', ReportsCenter::class)->name('reports');
        Route::get('/reports/ar-aging', ArAgingReport::class)->middleware('can:reports.ar_aging')->name('reports.ar-aging');
        Route::get('/reports/customers', CustomersReport::class)->middleware('can:reports.customers')->name('reports.customers');
        Route::get('/reports/whatsapp', WhatsAppReport::class)->middleware('can:reports.whatsapp')->name('reports.whatsapp');

        // Subscriptions & recurring invoices — Sprint 7

        // WhatsApp — Sprint 7
        Route::get('/whatsapp', WhatsAppDashboard::class)->middleware('can:whatsapp.view')->name('whatsapp');
        Route::get('/whatsapp/templates', WhatsAppTemplatesIndex::class)->middleware('can:whatsapp.templates.view')->name('whatsapp.templates');
        Route::get('/whatsapp/reminders', ReminderRulesIndex::class)->middleware('can:whatsapp.reminders.view')->name('whatsapp.reminders');

        // Notification centre + activity feed — Sprint 8
        Route::get('/notifications', NotificationCenter::class)->middleware('can:notifications.view')->name('notifications');
        Route::get('/activity', ActivityFeed::class)->name('activity');

        // Users & roles — Sprint 8
        Route::get('/users', UsersIndex::class)->middleware('can:users.view')->name('users');
        Route::get('/roles', RolesPermissions::class)->middleware('can:roles.manage')->name('roles');
        Route::get('/production-readiness', ProductionReadiness::class)->name('production-readiness');

        Route::get('/settings', SettingsPage::class)->middleware('can:settings.view')->name('settings');
        Route::get('/audit-log', AuditLogPage::class)->middleware('can:audit.view')->name('audit');
    });

    // ---- Employee / operational ----
    Route::prefix('employee')->name('employee.')->group(function () {
        Route::get('/', EmployeeDashboard::class)->middleware('can:dashboard.view')->name('dashboard');
        Route::get('/attendance', MyAttendance::class)->middleware('can:attendance.view_own')->name('attendance');
        Route::get('/leaves', MyLeaves::class)->middleware('can:leaves.view_own')->name('leaves');
        Route::get('/tasks', MyTasks::class)->middleware('can:tasks.view_own')->name('tasks');
        Route::get('/tasks/{task}', EmployeeTaskShow::class)->middleware('can:tasks.view_own')->name('tasks.show');
        Route::get('/payslips', MyPayslips::class)->middleware('can:payslips.view_own')->name('payslips');
        Route::get('/notifications', EmployeeNotifications::class)->middleware('can:notifications.view')->name('notifications');
    });

    // Payslip print — reachable by admins and by the employee themself; the
    // PayrollItem policy enforces that an employee only opens their own.
    Route::get('/payslips/{item}/print', [PayslipController::class, 'show'])->name('payslips.print');
});
