<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use Illuminate\Support\Facades\Auth;

/**
 * Customer (CRM) operations. Operational data only — no financial balances,
 * invoices or payments live here (those arrive in later sprints behind the
 * finance permissions).
 */
class CustomerService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public function create(array $data): Customer
    {
        $data['customer_number'] = $data['customer_number'] ?? $this->numbers->next('customer');
        $data['status'] = $data['status'] ?? CustomerStatus::Lead->value;
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        return Customer::create($data);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function update(Customer $customer, array $data): Customer
    {
        $data['updated_by'] = Auth::id();
        $customer->update($data);

        return $customer;
    }

    /**
     * Archive instead of hard-deleting so history (projects/tasks) is preserved.
     */
    public function archive(Customer $customer): Customer
    {
        $customer->update([
            'status' => CustomerStatus::Archived,
            'is_active' => false,
            'updated_by' => Auth::id(),
        ]);

        return $customer;
    }

    public function restore(Customer $customer): Customer
    {
        $customer->update([
            'status' => CustomerStatus::Active,
            'is_active' => true,
            'updated_by' => Auth::id(),
        ]);

        return $customer;
    }
}
