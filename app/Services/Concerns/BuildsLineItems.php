<?php

namespace App\Services\Concerns;

use App\Models\Service;
use App\Support\DocumentCalculator;

/**
 * Shared line-item preparation for quotations and invoices: snapshots the
 * service name/price/tax at document time and recomputes every line and the
 * document totals on the backend (frontend values are never trusted).
 */
trait BuildsLineItems
{
    /**
     * Normalise raw line inputs into persistable item rows with computed
     * amounts, and return them together with the document totals.
     *
     * @param  iterable<array<string,mixed>>  $lines
     * @return array{items: list<array<string,mixed>>, totals: array<string,string>}
     */
    protected function prepareItems(iterable $lines): array
    {
        $rows = [];
        $sort = 0;

        foreach ($lines as $line) {
            $sort++;
            $serviceId = $line['service_id'] ?? null;
            $service = $serviceId ? Service::find($serviceId) : null;

            // Snapshot from the catalog when the caller left fields blank.
            $itemName = $line['item_name'] ?? null;
            if (($itemName === null || $itemName === '') && $service) {
                $itemName = $service->name;
            }

            $unitPrice = $line['unit_price_usd'] ?? null;
            if (($unitPrice === null || $unitPrice === '') && $service) {
                $unitPrice = $service->default_price_usd;
            }

            $taxRate = $line['tax_rate'] ?? null;
            if (($taxRate === null || $taxRate === '') && $service) {
                $taxRate = $service->tax_rate;
            }

            $rows[] = [
                'service_id' => $serviceId ?: null,
                'item_name' => $itemName ?: 'بند',
                'description' => $line['description'] ?? null,
                'quantity' => $line['quantity'] ?? 1,
                'unit_price_usd' => $unitPrice ?: 0,
                'discount_type' => $line['discount_type'] ?? null,
                'discount_value' => $line['discount_value'] ?? null,
                'tax_rate' => $taxRate ?: 0,
                'sort_order' => $sort,
            ];
        }

        $calc = app(DocumentCalculator::class)->document($rows);

        return [
            'items' => $calc['lines'],
            'totals' => [
                'subtotal_usd' => $calc['subtotal_usd'],
                'discount_usd' => $calc['discount_usd'],
                'tax_usd' => $calc['tax_usd'],
                'total_usd' => $calc['total_usd'],
            ],
        ];
    }
}
