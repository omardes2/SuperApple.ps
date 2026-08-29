<?php

namespace App\Support;

use App\Models\Customer;

/**
 * Captures the customer details a financial document needs so the historical
 * document never changes if the customer record is later edited.
 */
final class CustomerSnapshot
{
    /**
     * @return array<string,mixed>
     */
    public static function for(Customer $customer): array
    {
        return [
            'customer_number' => $customer->customer_number,
            'customer_name' => $customer->name,
            'contact_person' => $customer->contact_person,
            'phone' => $customer->phone,
            'whatsapp_number' => $customer->whatsapp_number,
            'address' => $customer->address,
            'city' => $customer->city,
            'tax_number' => $customer->tax_number,
            'captured_at' => now()->toDateTimeString(),
        ];
    }
}
