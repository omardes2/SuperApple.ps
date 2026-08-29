<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Models\Service;
use App\Models\Subscription;
use App\Support\DocumentCalculator;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Manages the subscription lifecycle (draft → active → paused/resumed →
 * cancelled/expired). It never posts accounting and never issues invoices —
 * that is the billing service's job, always through InvoiceService.
 *
 * Item prices are snapshotted at agreement time: editing the service catalog
 * later never changes an existing subscription or any invoice already produced.
 */
class SubscriptionService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     * @param  iterable<array<string,mixed>>  $items
     */
    public function create(array $data, iterable $items = []): Subscription
    {
        return DB::transaction(function () use ($data, $items) {
            $rows = $this->prepareItems($items);
            $totals = $this->totalsFor($rows);

            $subscription = Subscription::create([
                'subscription_number' => $data['subscription_number'] ?? $this->numbers->next('subscription'),
                'customer_id' => $data['customer_id'],
                'project_id' => $data['project_id'] ?? null,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'billing_cycle' => $data['billing_cycle'] ?? 'monthly',
                'billing_interval' => max(1, (int) ($data['billing_interval'] ?? 1)),
                'start_date' => $data['start_date'] ?? now()->toDateString(),
                'end_date' => $data['end_date'] ?? null,
                'next_billing_date' => null, // set on activation
                'payment_terms_days' => $data['payment_terms_days'] ?? null,
                'currency' => 'USD',
                'auto_generate_invoice' => $data['auto_generate_invoice'] ?? true,
                'auto_issue_invoice' => $data['auto_issue_invoice'] ?? false,
                'status' => SubscriptionStatus::Draft,
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
                ...$totals,
            ]);

            $this->writeItems($subscription, $rows);
            $this->audit->log('subscription_created', $subscription, 'Subscriptions', description: 'إنشاء اشتراك (مسودة)');

            return $subscription;
        });
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  iterable<array<string,mixed>>  $items
     */
    public function update(Subscription $subscription, array $data, iterable $items): Subscription
    {
        if ($subscription->isCancelled()) {
            throw new RuntimeException('لا يمكن تعديل اشتراك ملغى.');
        }

        return DB::transaction(function () use ($subscription, $data, $items) {
            $rows = $this->prepareItems($items);
            $totals = $this->totalsFor($rows);

            $subscription->update([
                'customer_id' => $data['customer_id'] ?? $subscription->customer_id,
                'project_id' => $data['project_id'] ?? null,
                'name' => $data['name'] ?? $subscription->name,
                'description' => $data['description'] ?? null,
                'billing_cycle' => $data['billing_cycle'] ?? $subscription->billing_cycle,
                'billing_interval' => max(1, (int) ($data['billing_interval'] ?? $subscription->billing_interval)),
                'start_date' => $data['start_date'] ?? $subscription->start_date,
                'end_date' => $data['end_date'] ?? null,
                'payment_terms_days' => $data['payment_terms_days'] ?? null,
                'auto_generate_invoice' => $data['auto_generate_invoice'] ?? $subscription->auto_generate_invoice,
                'auto_issue_invoice' => $data['auto_issue_invoice'] ?? $subscription->auto_issue_invoice,
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
                'updated_by' => Auth::id(),
                ...$totals,
            ]);

            $subscription->items()->delete();
            $this->writeItems($subscription, $rows);

            $this->audit->log('subscription_updated', $subscription, 'Subscriptions', description: 'تعديل اشتراك');

            return $subscription;
        });
    }

    /** Activate the subscription and seed its next billing date. */
    public function activate(Subscription $subscription): Subscription
    {
        if (! in_array($subscription->status, [SubscriptionStatus::Draft, SubscriptionStatus::Paused], true)) {
            throw new RuntimeException('يمكن تفعيل المسودات أو الاشتراكات الموقوفة فقط.');
        }
        if ($subscription->items()->count() === 0) {
            throw new RuntimeException('لا يمكن تفعيل اشتراك بدون بنود.');
        }

        // First billing is a full cycle from the start date (no proration).
        $next = $subscription->next_billing_date
            ? Carbon::parse($subscription->next_billing_date)
            : Carbon::parse($subscription->start_date);

        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'next_billing_date' => $next->toDateString(),
            'activated_at' => now(),
            'paused_at' => null,
            'updated_by' => Auth::id(),
        ]);

        $this->audit->log('subscription_activated', $subscription, 'Subscriptions', description: 'تفعيل الاشتراك');

        return $subscription;
    }

    public function pause(Subscription $subscription): Subscription
    {
        if (! $subscription->isActive()) {
            throw new RuntimeException('يمكن إيقاف الاشتراكات النشطة فقط.');
        }
        $subscription->update([
            'status' => SubscriptionStatus::Paused,
            'paused_at' => now(),
            'updated_by' => Auth::id(),
        ]);
        $this->audit->log('subscription_paused', $subscription, 'Subscriptions', description: 'إيقاف مؤقت للاشتراك');

        return $subscription;
    }

    /**
     * Resume a paused subscription. The user chooses the resume/next-billing
     * date; no backdated invoices are ever generated automatically.
     */
    public function resume(Subscription $subscription, ?string $nextBillingDate = null): Subscription
    {
        if (! $subscription->isPaused()) {
            throw new RuntimeException('يمكن استئناف الاشتراكات الموقوفة فقط.');
        }

        $next = $nextBillingDate
            ? Carbon::parse($nextBillingDate)
            : Carbon::parse($subscription->next_billing_date ?? now());
        // Never resume into the past — that would mint backdated invoices.
        if ($next->lt(now()->startOfDay())) {
            $next = now()->startOfDay();
        }

        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'next_billing_date' => $next->toDateString(),
            'paused_at' => null,
            'updated_by' => Auth::id(),
        ]);
        $this->audit->log('subscription_resumed', $subscription, 'Subscriptions', description: 'استئناف الاشتراك');

        return $subscription;
    }

    public function cancel(Subscription $subscription, string $reason): Subscription
    {
        if ($subscription->isCancelled()) {
            throw new RuntimeException('الاشتراك ملغى بالفعل.');
        }
        if (blank($reason)) {
            throw new RuntimeException('يجب إدخال سبب الإلغاء.');
        }

        // Past invoices are kept untouched — cancelling only stops future billing.
        $subscription->update([
            'status' => SubscriptionStatus::Cancelled,
            'next_billing_date' => null,
            'cancelled_at' => now(),
            'cancelled_by' => Auth::id(),
            'cancellation_reason' => $reason,
            'updated_by' => Auth::id(),
        ]);
        $this->audit->log('subscription_cancelled', $subscription, 'Subscriptions',
            new: ['reason' => $reason], description: 'إلغاء الاشتراك');

        return $subscription;
    }

    /**
     * Recompute + persist header totals from the current items (used by the UI).
     */
    public function recomputeTotals(Subscription $subscription): Subscription
    {
        $rows = $subscription->items->map(fn ($i) => $i->toLineArray())->all();
        $subscription->update($this->totalsFor($this->prepareItems($rows)));

        return $subscription;
    }

    /**
     * Normalise raw item inputs into persistable subscription-item rows.
     *
     * @param  iterable<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    private function prepareItems(iterable $items): array
    {
        $rows = [];
        $sort = 0;
        foreach ($items as $item) {
            $sort++;
            $name = $item['item_name'] ?? null;
            $service = ! empty($item['service_id']) ? Service::find($item['service_id']) : null;
            if (($name === null || $name === '') && $service) {
                $name = $service->name;
            }
            $price = $item['unit_price_usd'] ?? null;
            if (($price === null || $price === '') && $service) {
                $price = $service->default_price_usd;
            }
            $rows[] = [
                'service_id' => $item['service_id'] ?? null,
                'item_name' => $name ?: 'بند',
                'description' => $item['description'] ?? null,
                'quantity' => $item['quantity'] ?? 1,
                'unit_price_usd' => $price ?: 0,
                'discount_type' => $item['discount_type'] ?? null,
                'discount_value' => $item['discount_value'] ?? null,
                'tax_rate' => $item['tax_rate'] ?? 0,
                'sort_order' => $sort,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array{subtotal_usd:string,discount_usd:string,tax_usd:string,total_usd:string}
     */
    private function totalsFor(array $rows): array
    {
        $calc = app(DocumentCalculator::class)->document($rows);

        return [
            'subtotal_usd' => $calc['subtotal_usd'],
            'discount_usd' => $calc['discount_usd'],
            'tax_usd' => $calc['tax_usd'],
            'total_usd' => $calc['total_usd'],
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    private function writeItems(Subscription $subscription, array $rows): void
    {
        foreach ($rows as $row) {
            $subscription->items()->create([
                'service_id' => $row['service_id'],
                'item_name' => $row['item_name'],
                'description' => $row['description'],
                'quantity' => Money::money($row['quantity']),
                'unit_price_usd' => (string) $row['unit_price_usd'],
                'discount_type' => $row['discount_type'] ?: null,
                'discount_value' => ($row['discount_value'] ?? null) !== null && $row['discount_value'] !== '' ? (string) $row['discount_value'] : null,
                'tax_rate' => (string) ($row['tax_rate'] ?? 0),
                'sort_order' => $row['sort_order'],
            ]);
        }
    }
}
