<?php

namespace App\Services;

use App\Models\Supplier;
use Illuminate\Support\Facades\Auth;

class SupplierService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public function create(array $data): Supplier
    {
        $supplier = Supplier::create([
            'supplier_number' => $data['supplier_number'] ?? $this->numbers->next('supplier'),
            'name' => $data['name'],
            'contact_person' => $data['contact_person'] ?? null,
            'phone' => $data['phone'] ?? null,
            'whatsapp_number' => $data['whatsapp_number'] ?? null,
            'address' => $data['address'] ?? null,
            'tax_number' => $data['tax_number'] ?? null,
            'supplier_type' => $data['supplier_type'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        $this->audit->log('supplier_created', $supplier, 'Suppliers', description: 'إضافة مورد');

        return $supplier;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function update(Supplier $supplier, array $data): Supplier
    {
        $supplier->update([
            'name' => $data['name'] ?? $supplier->name,
            'contact_person' => $data['contact_person'] ?? $supplier->contact_person,
            'phone' => $data['phone'] ?? $supplier->phone,
            'whatsapp_number' => $data['whatsapp_number'] ?? $supplier->whatsapp_number,
            'address' => $data['address'] ?? $supplier->address,
            'tax_number' => $data['tax_number'] ?? $supplier->tax_number,
            'supplier_type' => $data['supplier_type'] ?? $supplier->supplier_type,
            'notes' => $data['notes'] ?? $supplier->notes,
            'is_active' => $data['is_active'] ?? $supplier->is_active,
            'updated_by' => Auth::id(),
        ]);

        $this->audit->log('supplier_updated', $supplier, 'Suppliers', description: 'تعديل مورد');

        return $supplier;
    }
}
