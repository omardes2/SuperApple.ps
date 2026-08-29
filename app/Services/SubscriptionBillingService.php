<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\SubscriptionBilling;
use App\Models\User;
use App\Notifications\SubscriptionAutoIssueFailed;
use App\Notifications\SubscriptionInvoiceFailed;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Turns due subscriptions into invoices. This is NOT a new accounting path:
 * every invoice is created and issued through InvoiceService, so it obeys all
 * standard rules (USD, exchange-rate snapshot at issue, customer/item snapshots,
 * GL posting at issue, immutability). This service only decides *when* and
 * *what* to bill, prevents duplicates, and advances the schedule.
 *
 * Duplicate prevention is layered: a row lock on the subscription, an existence
 * check on subscription_billings, and finally the unique
 * (subscription_id, period_start, period_end) index as the hard guarantee.
 */
class SubscriptionBillingService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly ExchangeRateService $rates,
        private readonly Settings $settings,
        private readonly WhatsAppService $whatsapp,
    ) {}

    /**
     * Bill every active subscription that is due on or before $onDate.
     * Each subscription is processed in its own transaction so one failure
     * never blocks the rest.
     *
     * @return array<string,mixed>
     */
    public function runDue(?string $onDate = null, bool $dryRun = false, ?int $onlySubscriptionId = null): array
    {
        $onDate ??= now()->toDateString();

        $query = Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->whereNotNull('next_billing_date')
            ->whereDate('next_billing_date', '<=', $onDate);

        if ($onlySubscriptionId !== null) {
            $query->whereKey($onlySubscriptionId);
        } else {
            // The scheduled run only touches auto-generating subscriptions.
            $query->where('auto_generate_invoice', true);
        }

        $results = [];
        foreach ($query->orderBy('id')->pluck('id') as $id) {
            $results[] = $this->billOne((int) $id, $onDate, $dryRun);
        }

        return [
            'date' => $onDate,
            'dry_run' => $dryRun,
            'processed' => count($results),
            'generated' => collect($results)->where('outcome', 'generated')->count(),
            'issued' => collect($results)->where('outcome', 'issued')->count(),
            'skipped' => collect($results)->where('outcome', 'skipped')->count(),
            'failed' => collect($results)->where('outcome', 'failed')->count(),
            'details' => $results,
        ];
    }

    /**
     * Bill a single subscription for its current due period.
     *
     * @return array<string,mixed>
     */
    public function billOne(int $subscriptionId, string $onDate, bool $dryRun = false): array
    {
        $committedInvoice = null;
        $committedIssued = false;

        try {
            return DB::transaction(function () use ($subscriptionId, $onDate, $dryRun, &$committedInvoice, &$committedIssued) {
                /** @var Subscription $sub */
                $sub = Subscription::whereKey($subscriptionId)->lockForUpdate()->firstOrFail();

                if ($sub->status !== SubscriptionStatus::Active) {
                    return $this->result($sub, 'skipped', 'الاشتراك غير نشط.');
                }
                if ($sub->next_billing_date === null) {
                    return $this->result($sub, 'skipped', 'لا يوجد تاريخ فوترة قادم.');
                }

                $periodStart = Carbon::parse($sub->next_billing_date)->startOfDay();
                if ($periodStart->gt(Carbon::parse($onDate)->endOfDay())) {
                    return $this->result($sub, 'skipped', 'الفوترة غير مستحقة بعد.');
                }

                // Past the contract end date → expire, never bill.
                if ($sub->end_date && $periodStart->gt(Carbon::parse($sub->end_date)->endOfDay())) {
                    if (! $dryRun) {
                        $sub->update(['status' => SubscriptionStatus::Expired, 'next_billing_date' => null]);
                    }

                    return $this->result($sub, 'skipped', 'انتهى الاشتراك.');
                }

                $nextBilling = $sub->billing_cycle->advance($periodStart->copy(), (int) $sub->billing_interval);
                $periodEnd = $nextBilling->copy()->subDay();

                // Idempotency: never bill the same period twice.
                $existing = SubscriptionBilling::where('subscription_id', $sub->id)
                    ->whereDate('period_start', $periodStart->toDateString())
                    ->whereDate('period_end', $periodEnd->toDateString())
                    ->first();
                if ($existing) {
                    return $this->result($sub, 'skipped', 'تمت فوترة هذه الفترة مسبقاً.', $existing);
                }

                if ($dryRun) {
                    return array_merge(
                        $this->result($sub, 'generated', 'محاكاة: سيتم إنشاء فاتورة.'),
                        [
                            'period_start' => $periodStart->toDateString(),
                            'period_end' => $periodEnd->toDateString(),
                            'total_usd' => $sub->total_usd,
                        ],
                    );
                }

                // ---- Create the invoice draft through InvoiceService ----
                $dueDays = $sub->payment_terms_days ?? (int) $this->settings->get('finance', 'default_invoice_due_days', 30);
                $invoiceDate = Carbon::parse($onDate)->toDateString();
                $dueDate = Carbon::parse($invoiceDate)->addDays($dueDays)->toDateString();

                $invoice = $this->invoices->createDraft(
                    [
                        'customer_id' => $sub->customer_id,
                        'project_id' => $sub->project_id,
                        'subscription_id' => $sub->id,
                        'invoice_date' => $invoiceDate,
                        'due_date' => $dueDate,
                        'notes' => $sub->notes,
                        'terms' => $sub->terms,
                    ],
                    $sub->items->map(fn ($i) => $i->toLineArray())->all(),
                );

                $billing = SubscriptionBilling::create([
                    'subscription_id' => $sub->id,
                    'invoice_id' => $invoice->id,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'billing_date' => $invoiceDate,
                    'status' => SubscriptionBilling::STATUS_GENERATED,
                ]);

                // next_billing_date advances only after a successful generation.
                $sub->update([
                    'next_billing_date' => $nextBilling->toDateString(),
                    'last_billed_at' => now(),
                ]);
                // If the new next-billing runs past the contract end, expire it.
                if ($sub->end_date && $nextBilling->gt(Carbon::parse($sub->end_date)->endOfDay())) {
                    $sub->update(['status' => SubscriptionStatus::Expired, 'next_billing_date' => null]);
                }

                $outcome = 'generated';
                $issued = false;

                // ---- Optional auto-issue (independent of auto-generate) ----
                if ($sub->auto_issue_invoice) {
                    $rate = $this->rates->suggestedRate($invoiceDate);
                    if ($rate === null) {
                        // No rate → do NOT guess. Leave the draft, flag it, notify.
                        $billing->update(['error_message' => 'لا يوجد سعر صرف لإصدار الفاتورة تلقائياً.']);
                        $this->notifyAutoIssueFailed($sub, $invoice);
                    } else {
                        $invoice->update(['exchange_rate' => Money::rate($rate)]);
                        $this->invoices->issue($invoice->refresh());
                        $billing->update(['status' => SubscriptionBilling::STATUS_ISSUED]);
                        $outcome = 'issued';
                        $issued = true;
                    }
                }

                $committedInvoice = $invoice;
                $committedIssued = $issued;

                return array_merge(
                    $this->result($sub, $outcome, $issued ? 'تم إنشاء الفاتورة وإصدارها.' : 'تم إنشاء مسودة الفاتورة.', $billing),
                    ['invoice_id' => $invoice->id, 'issued' => $issued],
                );
            });
        } catch (QueryException $e) {
            // A concurrent run already billed this period (unique index hit).
            if ($this->isUniqueViolation($e)) {
                return ['subscription_id' => $subscriptionId, 'outcome' => 'skipped', 'message' => 'فوترة متزامنة لنفس الفترة.'];
            }

            return $this->handleFailure($subscriptionId, $onDate, $e);
        } catch (Throwable $e) {
            return $this->handleFailure($subscriptionId, $onDate, $e);
        } finally {
            // After-commit WhatsApp notification for freshly issued invoices.
            if ($committedIssued && $committedInvoice !== null && ! $dryRun) {
                $this->safelyNotifyWhatsApp($committedInvoice->refresh());
            }
        }
    }

    private function safelyNotifyWhatsApp(Invoice $invoice): void
    {
        try {
            $this->whatsapp->notifySubscriptionInvoice($invoice);
        } catch (Throwable $e) {
            Log::warning('Subscription invoice WhatsApp notify failed', ['invoice' => $invoice->id, 'error' => $e->getMessage()]);
        }
    }

    /** @return array<string,mixed> */
    private function handleFailure(int $subscriptionId, string $onDate, Throwable $e): array
    {
        Log::error('Subscription billing failed', ['subscription' => $subscriptionId, 'error' => $e->getMessage()]);

        // Record the failure against the subscription for visibility. Best effort.
        try {
            $sub = Subscription::find($subscriptionId);
            if ($sub) {
                SubscriptionBilling::create([
                    'subscription_id' => $sub->id,
                    'invoice_id' => null,
                    'period_start' => Carbon::parse($sub->next_billing_date ?? $onDate)->toDateString(),
                    'period_end' => Carbon::parse($sub->next_billing_date ?? $onDate)->toDateString(),
                    'billing_date' => $onDate,
                    'status' => SubscriptionBilling::STATUS_FAILED,
                    'error_message' => mb_substr($e->getMessage(), 0, 240),
                ]);
                Notification::send($this->recipients(), new SubscriptionInvoiceFailed($sub, $e->getMessage()));
            }
        } catch (Throwable $inner) {
            Log::error('Recording subscription billing failure failed', ['error' => $inner->getMessage()]);
        }

        return ['subscription_id' => $subscriptionId, 'outcome' => 'failed', 'message' => $e->getMessage()];
    }

    private function notifyAutoIssueFailed(Subscription $sub, Invoice $invoice): void
    {
        try {
            Notification::send($this->recipients(), new SubscriptionAutoIssueFailed($sub, $invoice));
        } catch (Throwable $e) {
            Log::warning('Auto-issue failure notification failed', ['error' => $e->getMessage()]);
        }
    }

    /** Users who bill subscriptions receive billing-failure notifications. */
    private function recipients()
    {
        return User::permission('subscriptions.bill')->get();
    }

    /** @return array<string,mixed> */
    private function result(Subscription $sub, string $outcome, string $message, ?SubscriptionBilling $billing = null): array
    {
        return [
            'subscription_id' => $sub->id,
            'subscription_number' => $sub->subscription_number,
            'outcome' => $outcome,
            'message' => $message,
            'billing_id' => $billing?->id,
        ];
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $code = $e->errorInfo[1] ?? null;

        // 19 = SQLite constraint, 1062 = MySQL duplicate, 23505 = Postgres unique.
        return in_array((int) $code, [19, 1062, 23505], true)
            || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
