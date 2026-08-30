<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Server-side global search. Every category is gated by the same permission that
 * guards its module, so results never leak a record the user cannot already
 * open — searching an exact invoice/payment number as an unauthorised user
 * returns nothing. Queries are limited per category and never
 * load whole tables.
 */
class GlobalSearchService
{
    /**
     * @return list<array{key:string,label:string,items:list<array{title:string,subtitle:?string,route:string,id:int}>}>
     */
    public function search(User $user, string $term, int $perCategory = 6): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $like = '%'.$term.'%';
        $groups = [];

        if ($user->can('customers.view')) {
            $groups[] = $this->group('customers', 'العملاء', 'admin.customers.show',
                Customer::query()->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('customer_number', 'like', $like)->orWhere('whatsapp_number', 'like', $like))
                    ->limit($perCategory)->get()->map(fn ($m) => [$m->id, $m->name, $m->customer_number]));
        }

        if ($user->can('tasks.view')) {
            $groups[] = $this->group('tasks', 'المهام', 'admin.tasks.show',
                Task::query()->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('task_number', 'like', $like))
                    ->limit($perCategory)->get()->map(fn ($m) => [$m->id, $m->title, $m->task_number]));
        }

        if ($user->can('employees.view')) {
            $groups[] = $this->group('employees', 'الموظفون', 'admin.employees.show',
                Employee::query()->where(fn ($q) => $q->where('full_name', 'like', $like)->orWhere('employee_number', 'like', $like))
                    ->limit($perCategory)->get()->map(fn ($m) => [$m->id, $m->full_name, $m->employee_number]));
        }

        if ($user->can('invoices.view')) {
            $groups[] = $this->group('invoices', 'الفواتير', 'admin.invoices.show',
                Invoice::query()->where('invoice_number', 'like', $like)
                    ->limit($perCategory)->get()->map(fn ($m) => [$m->id, $m->invoice_number, $m->customer?->name]));
        }

        if ($user->can('payments.view')) {
            $groups[] = $this->group('payments', 'الدفعات', 'admin.payments.show',
                Payment::query()->where('payment_number', 'like', $like)
                    ->limit($perCategory)->get()->map(fn ($m) => [$m->id, $m->payment_number, $m->customer?->name]));
        }

        if ($user->can('suppliers.view')) {
            $groups[] = $this->group('suppliers', 'الموردون', 'admin.suppliers.show',
                Supplier::query()->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('supplier_number', 'like', $like))
                    ->limit($perCategory)->get()->map(fn ($m) => [$m->id, $m->name, $m->supplier_number]));
            $groups[] = $this->group('supplier_bills', 'فواتير الموردين', 'admin.supplier-bills.show',
                SupplierBill::query()->where('bill_number', 'like', $like)
                    ->limit($perCategory)->get()->map(fn ($m) => [$m->id, $m->bill_number, $m->supplier?->name]));
        }

        if ($user->can('expenses.view')) {
            $groups[] = $this->group('expenses', 'المصاريف', 'admin.expenses.show',
                Expense::query()->where('expense_number', 'like', $like)
                    ->limit($perCategory)->get()->map(fn ($m) => [$m->id, $m->expense_number, null]));
        }

        // Drop empty categories.
        return array_values(array_filter($groups, fn ($g) => count($g['items']) > 0));
    }

    /**
     * @param  Collection<int,array{0:int,1:?string,2:?string}>  $rows
     * @return array{key:string,label:string,items:list<array{title:string,subtitle:?string,route:string,id:int}>}
     */
    private function group(string $key, string $label, string $route, $rows): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'items' => $rows->map(fn ($r) => [
                'id' => $r[0],
                'title' => (string) ($r[1] ?? '—'),
                'subtitle' => $r[2] ?? null,
                'route' => $route,
            ])->all(),
        ];
    }
}
