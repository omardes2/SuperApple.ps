<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentReminderLog;
use App\Models\PaymentReminderRule;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Support\Money;
use App\Support\TemplateRenderer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Payment reminders over WhatsApp, both manual (from a customer profile) and
 * automatic (rule-driven, run daily). The message amount is always USD; any ILS
 * figure shown is an estimate at the LATEST exchange rate — never an invoice's
 * frozen issue rate. Duplicate reminders are prevented by the unique
 * (invoice_id, reminder_rule_id, due_date) index on payment_reminder_logs.
 */
class PaymentReminderService
{
    public function __construct(
        private readonly CustomerBalanceService $balances,
        private readonly ExchangeRateService $rates,
        private readonly WhatsAppService $whatsapp,
    ) {}

    /**
     * The data behind the manual "Send payment reminder" modal on a customer.
     *
     * @return array<string,mixed>
     */
    public function manualContext(Customer $customer): array
    {
        $invoices = $this->outstandingInvoices($customer);
        $netUsd = $this->balances->netBalanceUsd($customer);

        // The standalone exchange-rate module was retired: there is no central /
        // latest rate to estimate an ILS figure from, so reminders quote USD only.
        return [
            'outstanding_invoices' => $invoices,
            'outstanding_usd' => $this->balances->outstandingUsd($customer),
            'unallocated_credit_usd' => $this->balances->unallocatedCreditUsd($customer),
            'net_balance_usd' => $netUsd,
            'estimated_ils' => null,
            'latest_rate' => null,
            'invoice_list' => $this->invoiceListText($invoices),
        ];
    }

    /**
     * Balance-oriented template variables for a manual reminder. The official
     * figure is USD; no ILS estimate is produced since the central rate is gone.
     *
     * @return array<string,string>
     */
    public function balanceVariables(Customer $customer): array
    {
        $ctx = $this->manualContext($customer);

        return [
            'customer_name' => (string) $customer->name,
            'balance_usd' => (string) $ctx['net_balance_usd'],
            'balance_ils' => (string) ($ctx['estimated_ils'] ?? '—'),
            'invoice_list' => (string) $ctx['invoice_list'],
        ];
    }

    /** The editable default template for manual reminders. */
    public function defaultManualTemplate(): ?WhatsAppTemplate
    {
        return WhatsAppTemplate::active()->where('key', WhatsAppTemplate::KEY_REMINDER_MANUAL)->first();
    }

    /**
     * Send a manual reminder. If $customBody is given it is used (still rendered
     * against the balance variables); otherwise the default template is used.
     */
    public function sendManualReminder(Customer $customer, ?string $customBody = null, ?int $templateId = null): WhatsAppMessage
    {
        $vars = $this->balanceVariables($customer);
        $template = $templateId ? WhatsAppTemplate::find($templateId) : $this->defaultManualTemplate();

        $body = $customBody ?? $template?->body;
        if ($body === null) {
            throw new \RuntimeException('لا يوجد نص أو قالب للرسالة.');
        }

        $rendered = TemplateRenderer::render($body, $vars);

        return $this->whatsapp->sendManual($customer, $rendered, [], $template?->id);
    }

    /**
     * Run all active reminder rules for a date. Each (invoice, rule) pair fires
     * at most once per due date.
     *
     * @return array<string,mixed>
     */
    public function runRules(?string $onDate = null, bool $dryRun = false): array
    {
        $onDate = Carbon::parse($onDate ?? now()->toDateString())->startOfDay();
        $rules = PaymentReminderRule::active()->whereNotNull('template_id')->get();

        $sent = 0;
        $skipped = 0;
        $failed = 0;
        $details = [];

        foreach ($rules as $rule) {
            $template = $rule->template;
            if (! $template || ! $template->is_active) {
                continue;
            }

            foreach ($this->remindableInvoices() as $invoice) {
                if (! $invoice->due_date) {
                    continue;
                }
                $target = $rule->sendDateFor(Carbon::parse($invoice->due_date));
                if (! $target->isSameDay($onDate)) {
                    continue;
                }

                $dueDate = Carbon::parse($invoice->due_date)->toDateString();

                // Dedupe: already logged for this invoice+rule+due date?
                $already = PaymentReminderLog::where('invoice_id', $invoice->id)
                    ->where('reminder_rule_id', $rule->id)
                    ->whereDate('due_date', $dueDate)
                    ->exists();
                if ($already) {
                    $skipped++;

                    continue;
                }

                if ($dryRun) {
                    $sent++;
                    $details[] = ['invoice' => $invoice->invoice_number, 'rule' => $rule->name, 'outcome' => 'would_send'];

                    continue;
                }

                $result = $this->fireReminder($invoice, $rule, $template, $dueDate, $onDate->toDateString());
                $result === PaymentReminderLog::STATUS_SENT ? $sent++ : ($result === PaymentReminderLog::STATUS_SKIPPED ? $skipped++ : $failed++);
                $details[] = ['invoice' => $invoice->invoice_number, 'rule' => $rule->name, 'outcome' => $result];
            }
        }

        return [
            'date' => $onDate->toDateString(),
            'dry_run' => $dryRun,
            'sent' => $sent,
            'skipped' => $skipped,
            'failed' => $failed,
            'details' => $details,
        ];
    }

    private function fireReminder(Invoice $invoice, PaymentReminderRule $rule, WhatsAppTemplate $template, string $dueDate, string $onDate): string
    {
        $invoice->loadMissing('customer');
        $customer = $invoice->customer;
        if (! $customer) {
            return PaymentReminderLog::STATUS_SKIPPED;
        }

        try {
            $vars = array_merge(
                $this->whatsapp->invoiceVariables($invoice),
                $this->balanceVariables($customer),
            );
            $body = TemplateRenderer::render($template->body, $vars);
        } catch (\Throwable $e) {
            Log::warning('Reminder render failed', ['invoice' => $invoice->id, 'error' => $e->getMessage()]);
            $this->log($invoice, $rule, null, $dueDate, $onDate, PaymentReminderLog::STATUS_FAILED, $e->getMessage());

            return PaymentReminderLog::STATUS_FAILED;
        }

        $message = $this->whatsapp->queue([
            'customer_id' => $customer->id,
            'phone' => $this->whatsapp->resolvePhone($customer),
            'message_body' => $body,
            'template_id' => $template->id,
            'invoice_id' => $invoice->id,
        ], throwOnError: false);

        $status = $message ? PaymentReminderLog::STATUS_SENT : PaymentReminderLog::STATUS_SKIPPED;
        $this->log($invoice, $rule, $message?->id, $dueDate, $onDate, $status, $message ? null : 'تعذّر الإرسال (رقم غير صالح أو الخدمة متوقفة).');

        return $status;
    }

    private function log(Invoice $invoice, PaymentReminderRule $rule, ?int $messageId, string $dueDate, string $onDate, string $status, ?string $note): void
    {
        try {
            PaymentReminderLog::create([
                'invoice_id' => $invoice->id,
                'reminder_rule_id' => $rule->id,
                'whatsapp_message_id' => $messageId,
                'due_date' => $dueDate,
                'sent_on' => $onDate,
                'status' => $status,
                'note' => $note,
            ]);
        } catch (QueryException $e) {
            // Unique index race — another run logged it first. Fine.
            Log::info('Reminder log dedupe race', ['invoice' => $invoice->id, 'rule' => $rule->id]);
        }
    }

    /** Invoices eligible for a reminder: issued/sent/partially-paid with balance. */
    private function remindableInvoices()
    {
        return Invoice::query()
            ->whereIn('status', [
                InvoiceStatus::Issued->value,
                InvoiceStatus::Sent->value,
                InvoiceStatus::PartiallyPaid->value,
                InvoiceStatus::Overdue->value,
            ])
            ->where('remaining_usd', '>', 0)
            ->whereNotNull('due_date')
            ->with('customer')
            ->cursor();
    }

    /** @return Collection<int,Invoice> */
    private function outstandingInvoices(Customer $customer)
    {
        return $customer->invoices()
            ->whereIn('status', [
                InvoiceStatus::Issued->value,
                InvoiceStatus::Sent->value,
                InvoiceStatus::PartiallyPaid->value,
                InvoiceStatus::Overdue->value,
            ])
            ->where('remaining_usd', '>', 0)
            ->orderBy('due_date')
            ->get();
    }

    /** @param Collection<int,Invoice> $invoices */
    private function invoiceListText($invoices): string
    {
        if ($invoices->isEmpty()) {
            return '—';
        }

        return $invoices->map(function (Invoice $i) {
            $due = optional($i->due_date)->toDateString();

            return "• {$i->invoice_number} — ".Money::money($i->remaining_usd)." USD (استحقاق {$due})";
        })->implode("\n");
    }
}
