<?php

namespace App\Support;

use App\Enums\DiscountType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The single source of truth for quotation/invoice money math. Frontend values
 * are never trusted — the backend recomputes every line and document total from
 * (quantity, unit_price, discount, tax_rate) using this calculator.
 *
 * Per-line order (docs/CURRENCY.md §calculation):
 *   gross      = quantity × unit_price
 *   discount   = percentage ? gross × value/100 : fixed value   (capped at gross)
 *   taxable    = gross − discount
 *   tax        = taxable × tax_rate/100
 *   line_total = taxable + tax
 * Each line's amounts are rounded to 2dp (HALF_UP); document totals are the sum
 * of the rounded line amounts, so displayed lines always add up to the totals.
 */
final class DocumentCalculator
{
    /**
     * Compute one line. Returns money-scale strings.
     *
     * @return array{
     *   line_subtotal_usd:string, discount_usd:string, tax_usd:string, line_total_usd:string
     * }
     */
    public function line(
        int|string|float $quantity,
        int|string|float $unitPrice,
        ?DiscountType $discountType = null,
        int|string|float|null $discountValue = null,
        int|string|float $taxRate = 0,
    ): array {
        $qty = Money::of($quantity);
        $unit = Money::of($unitPrice);

        $gross = $qty->multipliedBy($unit);

        $discount = $this->discountFor($gross, $discountType, $discountValue);
        // A discount can never exceed the gross line amount.
        if ($discount->isGreaterThan($gross)) {
            $discount = $gross;
        }

        $taxable = $gross->minus($discount);
        $tax = $taxable->multipliedBy(Money::of($taxRate))->dividedBy(100, Money::MONEY_SCALE + 6, RoundingMode::HalfUp);
        $lineTotal = $taxable->plus($tax);

        return [
            'line_subtotal_usd' => Money::money($gross),
            'discount_usd' => Money::money($discount),
            'tax_usd' => Money::money($tax),
            'line_total_usd' => Money::money($lineTotal),
        ];
    }

    private function discountFor(BigDecimal $gross, ?DiscountType $type, int|string|float|null $value): BigDecimal
    {
        if ($type === null || $value === null || $value === '') {
            return BigDecimal::zero();
        }

        $val = Money::of($value);

        return match ($type) {
            DiscountType::Percentage => $gross->multipliedBy($val)->dividedBy(100, Money::MONEY_SCALE + 6, RoundingMode::HalfUp),
            DiscountType::Fixed => $val,
        };
    }

    /**
     * Recompute a whole document from raw line inputs. Each input row may carry
     * anything; only quantity/unit_price_usd/discount_type/discount_value/tax_rate
     * are read. Returns the computed lines (merged back onto the inputs) plus the
     * document totals.
     *
     * @param  iterable<array<string,mixed>>  $lines
     * @return array{
     *   lines: list<array<string,mixed>>,
     *   subtotal_usd:string, discount_usd:string, tax_usd:string, total_usd:string
     * }
     */
    public function document(iterable $lines): array
    {
        $computed = [];
        $subtotal = BigDecimal::zero();
        $discount = BigDecimal::zero();
        $tax = BigDecimal::zero();
        $total = BigDecimal::zero();

        foreach ($lines as $row) {
            $type = $this->resolveType($row['discount_type'] ?? null);
            // These rows come straight from the untrusted line editor, where a
            // field can arrive as null, "" (a cleared input, or a nullable
            // service price/tax snapshot) or other non-numeric text. Coerce to a
            // safe number here so the live preview never throws; the authoritative
            // amounts are still recomputed and validated on save.
            $line = $this->line(
                $this->numeric($row['quantity'] ?? 0),
                $this->numeric($row['unit_price_usd'] ?? 0),
                $type,
                $this->numericOrNull($row['discount_value'] ?? null),
                $this->numeric($row['tax_rate'] ?? 0),
            );

            $computed[] = array_merge($row, $line);

            $subtotal = $subtotal->plus($line['line_subtotal_usd']);
            $discount = $discount->plus($line['discount_usd']);
            $tax = $tax->plus($line['tax_usd']);
            $total = $total->plus($line['line_total_usd']);
        }

        return [
            'lines' => $computed,
            'subtotal_usd' => Money::money($subtotal),
            'discount_usd' => Money::money($discount),
            'tax_usd' => Money::money($tax),
            'total_usd' => Money::money($total),
        ];
    }

    private function resolveType(mixed $type): ?DiscountType
    {
        if ($type instanceof DiscountType) {
            return $type;
        }

        return $type ? DiscountType::tryFrom((string) $type) : null;
    }

    /** A numeric line value, or 0 when blank/absent/non-numeric. */
    private function numeric(mixed $value): string
    {
        return is_numeric($value) ? (string) $value : '0';
    }

    /** A numeric discount value, or null (no discount) when blank/non-numeric. */
    private function numericOrNull(mixed $value): ?string
    {
        return is_numeric($value) ? (string) $value : null;
    }
}
