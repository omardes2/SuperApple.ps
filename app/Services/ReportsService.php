<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\PaymentReminderLog;
use App\Models\Project;
use App\Models\WhatsAppMessage;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Read-side analytics for the executive dashboard and the reports centre. Money
 * figures follow the system rules: customer/receivable amounts are USD (the
 * official currency); accounting revenue/expense/cash come from the GL in ILS;
 * any ILS estimate on a USD balance is clearly marked and uses the latest rate.
 */
class ReportsService
{
    public function __construct(
        private readonly AccountingReportService $accounting,
    ) {}

    // ---------------------------------------------------------------- Executive

    /** Accounting revenue for the month from the GL (ILS), not raw invoice totals. */
    public function revenueThisMonthIls(): string
    {
        $pl = $this->accounting->profitAndLoss(now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString());

        return $pl['total_revenue'];
    }

    public function expensesThisMonthIls(): string
    {
        $pl = $this->accounting->profitAndLoss(now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString());

        return $pl['total_expense'];
    }

    public function netProfitThisMonthIls(): string
    {
        $pl = $this->accounting->profitAndLoss(now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString());

        return $pl['net_profit'];
    }

    public function collectedThisMonthUsd(): string
    {
        return Money::money(
            Payment::posted()
                ->whereMonth('payment_date', now()->month)
                ->whereYear('payment_date', now()->year)
                ->sum('usd_equivalent')
        );
    }

    /** Outstanding receivables in USD (the official figure). */
    public function outstandingReceivablesUsd(): string
    {
        return Money::money(Invoice::issued()->sum('remaining_usd'));
    }

    /**
     * Accounting-correct ILS of outstanding receivables: each invoice's remaining
     * × ITS OWN locked rate, summed — never total USD × one latest rate. Display
     * only; the official figure stays USD.
     */
    public function receivablesIlsByDocument(): string
    {
        $total = '0.00';
        Invoice::issued()->where('remaining_usd', '>', 0)
            ->select('remaining_usd', 'exchange_rate')
            ->chunk(500, function ($rows) use (&$total) {
                foreach ($rows as $inv) {
                    if ($inv->exchange_rate !== null && Money::isPositive($inv->exchange_rate)) {
                        $total = Money::add($total, Money::convertUsdToIls($inv->remaining_usd, $inv->exchange_rate));
                    }
                }
            });

        return $total;
    }

    /**
     * ILS actually collected this month, per payment: an ILS payment contributes
     * its own amount; a USD payment its USD × its own payment rate. Never one rate
     * across all. Display only.
     */
    public function collectedThisMonthIls(): string
    {
        $total = '0.00';
        Payment::posted()
            ->whereMonth('payment_date', now()->month)
            ->whereYear('payment_date', now()->year)
            ->select('payment_currency', 'payment_amount', 'usd_equivalent', 'exchange_rate')
            ->chunk(500, function ($rows) use (&$total) {
                foreach ($rows as $p) {
                    $ils = $p->payment_currency->value === 'ILS'
                        ? Money::money($p->payment_amount)
                        : ($p->exchange_rate !== null && Money::isPositive($p->exchange_rate)
                            ? Money::convertUsdToIls($p->usd_equivalent, $p->exchange_rate)
                            : '0.00');
                    $total = Money::add($total, $ils);
                }
            });

        return $total;
    }

    // ------------------------------------------------------------------- Charts

    /**
     * Revenue vs expenses per month from the GL (ILS), oldest first.
     *
     * @return list<array{month:string,label:string,revenue:string,expense:string,net:string}>
     */
    public function revenueVsExpenses(int $months = 6): array
    {
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $start = now()->startOfMonth()->subMonthsNoOverflow($i);
            $end = $start->copy()->endOfMonth();
            $pl = $this->accounting->profitAndLoss($start->toDateString(), $end->toDateString());
            $out[] = [
                'month' => $start->format('Y-m'),
                'label' => $start->translatedFormat('M Y'),
                'revenue' => $pl['total_revenue'],
                'expense' => $pl['total_expense'],
                'net' => $pl['net_profit'],
            ];
        }

        return $out;
    }

    /**
     * Cash collection per month. USD equivalent is the accounting figure; the
     * original-currency split is shown separately and never conflated with it.
     *
     * @return list<array{month:string,label:string,usd_equivalent:string,original_ils:string,original_usd:string}>
     */
    public function cashCollectionByMonth(int $months = 6): array
    {
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $start = now()->startOfMonth()->subMonthsNoOverflow($i);
            $end = $start->copy()->endOfMonth();
            $base = Payment::posted()->whereBetween('payment_date', [$start->toDateString(), $end->toDateString()]);

            $out[] = [
                'month' => $start->format('Y-m'),
                'label' => $start->translatedFormat('M Y'),
                'usd_equivalent' => Money::money((clone $base)->sum('usd_equivalent')),
                'original_ils' => Money::money((clone $base)->where('payment_currency', 'ILS')->sum('payment_amount')),
                'original_usd' => Money::money((clone $base)->where('payment_currency', 'USD')->sum('payment_amount')),
            ];
        }

        return $out;
    }

    // -------------------------------------------------------------- AR Aging

    /**
     * Receivables aging in USD. Buckets by days overdue relative to $asOf.
     *
     * @return array{buckets:array<string,string>, rows:list<array<string,mixed>>, total:string}
     */
    public function arAging(?string $asOf = null): array
    {
        $asOf = Carbon::parse($asOf ?? now()->toDateString())->startOfDay();
        $buckets = ['current' => '0.00', '1_30' => '0.00', '31_60' => '0.00', '61_90' => '0.00', '90_plus' => '0.00'];
        $bucketsIls = $buckets;

        $invoices = Invoice::query()
            ->whereIn('status', [
                InvoiceStatus::Issued->value, InvoiceStatus::Sent->value,
                InvoiceStatus::PartiallyPaid->value, InvoiceStatus::Overdue->value,
            ])
            ->where('remaining_usd', '>', 0)
            ->with('customer')
            ->get();

        $byCustomer = [];
        $total = '0.00';
        $totalIls = '0.00';

        foreach ($invoices as $inv) {
            $remaining = Money::money($inv->remaining_usd);
            // ILS at THIS invoice's own locked rate — never a single blended rate.
            $remainingIls = $inv->exchange_rate !== null && Money::isPositive($inv->exchange_rate)
                ? Money::convertUsdToIls($remaining, $inv->exchange_rate)
                : '0.00';
            $total = Money::add($total, $remaining);
            $totalIls = Money::add($totalIls, $remainingIls);
            $due = $inv->due_date ? Carbon::parse($inv->due_date)->startOfDay() : null;
            $daysOverdue = $due && $due->lt($asOf) ? $due->diffInDays($asOf) : 0;
            $bucket = $this->bucketFor($daysOverdue);
            $buckets[$bucket] = Money::add($buckets[$bucket], $remaining);
            $bucketsIls[$bucket] = Money::add($bucketsIls[$bucket], $remainingIls);

            $cid = $inv->customer_id;
            if (! isset($byCustomer[$cid])) {
                $byCustomer[$cid] = [
                    'customer' => $inv->customer,
                    'invoices' => 0,
                    'remaining_usd' => '0.00',
                    'remaining_ils' => '0.00',
                    'oldest_due' => $due?->toDateString(),
                    'max_days_overdue' => 0,
                ];
            }
            $byCustomer[$cid]['invoices']++;
            $byCustomer[$cid]['remaining_usd'] = Money::add($byCustomer[$cid]['remaining_usd'], $remaining);
            $byCustomer[$cid]['remaining_ils'] = Money::add($byCustomer[$cid]['remaining_ils'], $remainingIls);
            if ($due && ($byCustomer[$cid]['oldest_due'] === null || $due->toDateString() < $byCustomer[$cid]['oldest_due'])) {
                $byCustomer[$cid]['oldest_due'] = $due->toDateString();
            }
            $byCustomer[$cid]['max_days_overdue'] = max($byCustomer[$cid]['max_days_overdue'], $daysOverdue);
        }

        usort($byCustomer, fn ($a, $b) => (float) $b['remaining_usd'] <=> (float) $a['remaining_usd']);

        return ['buckets' => $buckets, 'buckets_ils' => $bucketsIls, 'rows' => array_values($byCustomer), 'total' => $total, 'total_ils' => $totalIls];
    }

    private function bucketFor(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 0 => 'current',
            $daysOverdue <= 30 => '1_30',
            $daysOverdue <= 60 => '31_60',
            $daysOverdue <= 90 => '61_90',
            default => '90_plus',
        };
    }

    // --------------------------------------------------------- Top customers

    /**
     * Revenue by customer from the GL (revenue lines carry customer_id), in ILS.
     *
     * @return list<array{customer:?Customer,amount:string}>
     */
    public function topCustomersByRevenue(int $limit = 10): array
    {
        $rows = JournalEntryLine::query()
            ->whereHas('account', fn ($q) => $q->where('account_type', 'revenue'))
            ->whereHas('journalEntry', fn ($q) => $q->whereIn('status', ['posted', 'reversed']))
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->selectRaw('customer_id as cid, COALESCE(SUM(credit_ils)-SUM(debit_ils),0) as amount')
            ->orderByDesc('amount')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($r) => [
            'customer' => Customer::find($r->cid),
            'amount' => Money::money($r->amount),
        ])->all();
    }

    /** @return list<array{customer:Customer,amount:string,amount_ils:string}> */
    public function topCustomersByOutstanding(int $limit = 10): array
    {
        $statuses = [InvoiceStatus::Issued->value, InvoiceStatus::Sent->value, InvoiceStatus::PartiallyPaid->value, InvoiceStatus::Overdue->value];

        $rows = Invoice::query()
            ->whereIn('status', $statuses)
            ->where('remaining_usd', '>', 0)
            ->groupBy('customer_id')
            ->selectRaw('customer_id, COALESCE(SUM(remaining_usd),0) as amount')
            ->orderByDesc('amount')
            ->limit($limit)
            ->get();

        // For the few top customers, sum each one's ILS at their invoices' own
        // rates (per-document) — a bounded, non-N+1 follow-up over $limit rows.
        return $rows->map(function ($r) use ($statuses) {
            $customer = Customer::find($r->customer_id);
            if ($customer === null) {
                return null;
            }
            $ils = '0.00';
            Invoice::where('customer_id', $r->customer_id)->whereIn('status', $statuses)
                ->where('remaining_usd', '>', 0)->select('remaining_usd', 'exchange_rate')
                ->each(function ($inv) use (&$ils) {
                    if ($inv->exchange_rate !== null && Money::isPositive($inv->exchange_rate)) {
                        $ils = Money::add($ils, Money::convertUsdToIls($inv->remaining_usd, $inv->exchange_rate));
                    }
                });

            return ['customer' => $customer, 'amount' => Money::money($r->amount), 'amount_ils' => $ils];
        })->filter()->values()->all();
    }

    /** @return list<array{customer:Customer,amount:string,amount_ils:string}> */
    public function topCustomersByPayments(int $limit = 10): array
    {
        $rows = Payment::posted()
            ->groupBy('customer_id')
            ->selectRaw('customer_id, COALESCE(SUM(usd_equivalent),0) as amount')
            ->orderByDesc('amount')
            ->limit($limit)
            ->get();

        return $rows->map(function ($r) {
            $customer = Customer::find($r->customer_id);
            if ($customer === null) {
                return null;
            }
            // Each customer's collected ILS at each payment's own rate (per-document).
            $ils = '0.00';
            Payment::posted()->where('customer_id', $r->customer_id)
                ->select('payment_currency', 'payment_amount', 'usd_equivalent', 'exchange_rate')
                ->each(function ($p) use (&$ils) {
                    $ils = Money::add($ils, $p->payment_currency->value === 'ILS'
                        ? Money::money($p->payment_amount)
                        : ($p->exchange_rate !== null && Money::isPositive($p->exchange_rate)
                            ? Money::convertUsdToIls($p->usd_equivalent, $p->exchange_rate) : '0.00'));
                });

            return ['customer' => $customer, 'amount' => Money::money($r->amount), 'amount_ils' => $ils];
        })->filter()->values()->all();
    }

    /** @return list<array{customer:Customer,count:int}> */
    public function topCustomersByActiveProjects(int $limit = 10): array
    {
        $rows = Project::query()
            ->where('status', 'active')
            ->groupBy('customer_id')
            ->selectRaw('customer_id, COUNT(*) as cnt')
            ->orderByDesc('cnt')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($r) => ['customer' => Customer::find($r->customer_id), 'count' => (int) $r->cnt])
            ->filter(fn ($r) => $r['customer'] !== null)->values()->all();
    }

    // ------------------------------------------------------------- WhatsApp

    /** @return array<string,int> */
    public function whatsappAnalytics(): array
    {
        $counts = WhatsAppMessage::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        return [
            'sent' => (int) ($counts['sent'] ?? 0),
            'delivered' => (int) ($counts['delivered'] ?? 0),
            'read' => (int) ($counts['read'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
            'reminders_sent' => PaymentReminderLog::where('status', 'sent')->count(),
        ];
    }
}
