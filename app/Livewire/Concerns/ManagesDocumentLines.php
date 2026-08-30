<?php

namespace App\Livewire\Concerns;

use App\Models\Service;
use App\Support\DocumentCalculator;

/**
 * Shared line-item editing for the quotation/invoice draft editors. Holds the
 * editable `$lines` array and provides a live preview computed by the same
 * backend DocumentCalculator that persists the document — so what the user sees
 * always matches what is saved.
 */
trait ManagesDocumentLines
{
    /** @var array<int,array<string,mixed>> */
    public array $lines = [];

    protected function blankLine(): array
    {
        return [
            'service_id' => '',
            'item_name' => '',
            'description' => '',
            'quantity' => 1,
            'unit_price_usd' => 0,
            'discount_type' => '',
            'discount_value' => '',
            'tax_rate' => 0,
        ];
    }

    public function addLine(): void
    {
        $this->lines[] = $this->blankLine();
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    /**
     * When a service is picked, snapshot its name/price/tax into the line so the
     * user can then override the price for this document only.
     */
    public function updatedLines($value, $key): void
    {
        if (! str_ends_with((string) $key, '.service_id')) {
            return;
        }

        [$index] = explode('.', (string) $key);
        $index = (int) $index;

        if (! empty($this->lines[$index]['service_id'])) {
            $service = Service::find($this->lines[$index]['service_id']);
            if ($service) {
                // A service's price and tax_rate are nullable — snapshot them as
                // "0" rather than "" so the inputs and the live preview stay
                // numeric (an empty string would otherwise reach the calculator).
                $this->lines[$index]['item_name'] = $service->name;
                $this->lines[$index]['unit_price_usd'] = (string) ($service->default_price_usd ?? 0);
                $this->lines[$index]['tax_rate'] = (string) ($service->tax_rate ?? 0);
            }
        }
    }

    /**
     * Live document preview (never trusted for persistence — the service
     * recomputes on save).
     *
     * @return array{lines: list<array<string,mixed>>, subtotal_usd:string, discount_usd:string, tax_usd:string, total_usd:string}
     */
    public function preview(): array
    {
        return app(DocumentCalculator::class)->document($this->lines);
    }

    /**
     * Normalise the editable lines into service input rows.
     *
     * @return list<array<string,mixed>>
     */
    protected function lineInputs(): array
    {
        return collect($this->lines)->map(fn ($l) => [
            'service_id' => $l['service_id'] ?: null,
            'item_name' => $l['item_name'] ?? '',
            'description' => $l['description'] ?? null,
            'quantity' => $l['quantity'] ?? 1,
            'unit_price_usd' => $l['unit_price_usd'] ?? 0,
            'discount_type' => $l['discount_type'] ?: null,
            'discount_value' => $l['discount_value'] !== '' ? $l['discount_value'] : null,
            'tax_rate' => $l['tax_rate'] ?? 0,
        ])->all();
    }

    protected function loadLinesFrom(iterable $items): void
    {
        $this->lines = collect($items)->map(fn ($item) => [
            'service_id' => $item->service_id ?? '',
            'item_name' => $item->item_name,
            'description' => $item->description,
            'quantity' => (string) $item->quantity,
            'unit_price_usd' => (string) $item->unit_price_usd,
            'discount_type' => $item->discount_type?->value ?? '',
            'discount_value' => $item->discount_value !== null ? (string) $item->discount_value : '',
            'tax_rate' => (string) $item->tax_rate,
        ])->values()->all();

        if (empty($this->lines)) {
            $this->lines = [$this->blankLine()];
        }
    }

    /**
     * @return array<string,string>
     */
    protected function lineRules(): array
    {
        return [
            'lines' => 'required|array|min:1',
            'lines.*.item_name' => 'nullable|string|max:200',
            'lines.*.quantity' => 'required|numeric|min:0.01',
            'lines.*.unit_price_usd' => 'required|numeric|min:0',
            'lines.*.discount_type' => 'nullable|in:percentage,fixed',
            'lines.*.discount_value' => 'nullable|numeric|min:0',
            'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
        ];
    }
}
