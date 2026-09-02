<?php

namespace App\Services;

use App\Contracts\WhatsAppProvider;
use App\Enums\WhatsAppMessageStatus;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Support\Money;
use App\Support\PhoneNormalizer;
use App\Support\TemplateRenderer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Orchestrates outbound WhatsApp. Financial code calls the notify* helpers only
 * AFTER its own transaction has committed; every send is persisted as a
 * whatsapp_messages row and handed to a queued job, so a WhatsApp failure can
 * never roll back an invoice issue or a payment posting.
 */
class WhatsAppService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ExchangeRateService $rates,
        private readonly CustomerBalanceService $balances,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('whatsapp', 'enabled', false);
    }

    public function defaultCountryCode(): ?string
    {
        $cc = $this->settings->get('whatsapp', 'default_country_code');

        return $cc !== null && $cc !== '' ? (string) $cc : null;
    }

    /** Prefer the dedicated WhatsApp number, fall back to the main phone. */
    public function resolvePhone(Customer $customer): ?string
    {
        $raw = $customer->whatsapp_number ?: $customer->phone;

        return PhoneNormalizer::normalize($raw, $this->defaultCountryCode());
    }

    /**
     * Persist a message row and dispatch it. Automatic callers pass
     * $throwOnError=false so a bad number or a disabled channel is logged and
     * skipped rather than bubbling into financial code.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function queue(array $attributes, bool $throwOnError = true): ?WhatsAppMessage
    {
        if (! $this->enabled()) {
            if ($throwOnError) {
                throw new RuntimeException('خدمة واتساب غير مفعّلة. فعّلها من الإعدادات.');
            }
            Log::info('WhatsApp disabled — message not queued', ['invoice' => $attributes['invoice_id'] ?? null]);

            return null;
        }

        $phone = $attributes['phone'] ?? null;
        if (! $phone) {
            if ($throwOnError) {
                throw new RuntimeException('رقم واتساب غير صالح للعميل.');
            }
            Log::warning('WhatsApp not queued — invalid phone', ['customer' => $attributes['customer_id'] ?? null]);

            return null;
        }

        $message = WhatsAppMessage::create([
            'customer_id' => $attributes['customer_id'] ?? null,
            'invoice_id' => $attributes['invoice_id'] ?? null,
            'payment_id' => $attributes['payment_id'] ?? null,
            'subscription_id' => $attributes['subscription_id'] ?? null,
            'template_id' => $attributes['template_id'] ?? null,
            'phone' => $phone,
            'message_body' => $attributes['message_body'],
            'provider' => (string) $this->settings->get('whatsapp', 'provider', 'null'),
            'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
            'status' => WhatsAppMessageStatus::Pending,
            'created_by' => Auth::id(),
        ]);

        SendWhatsAppMessageJob::dispatch($message->id);

        return $message;
    }

    /**
     * Manual free-text (or template-rendered) send from the UI. Throws on any
     * problem so the operator sees a clear error.
     */
    public function sendManual(Customer $customer, string $body, array $links = [], ?int $templateId = null): WhatsAppMessage
    {
        $phone = $this->resolvePhone($customer);

        return $this->queue([
            'customer_id' => $customer->id,
            'phone' => $phone,
            'message_body' => $body,
            'template_id' => $templateId,
            'invoice_id' => $links['invoice_id'] ?? null,
            'payment_id' => $links['payment_id'] ?? null,
            'subscription_id' => $links['subscription_id'] ?? null,
        ], throwOnError: true);
    }

    /**
     * Send an ISSUED invoice to its customer as a PDF document over WhatsApp.
     * This is a synchronous operator action (from the invoices list confirmation
     * modal), so it returns the outcome immediately for a success/failure toast
     * and never runs inside a financial transaction. Drafts and cancelled
     * invoices are refused. The PDF is generated on demand to a private temp
     * file and deleted right after the attempt — it is never stored publicly.
     */
    public function sendInvoice(Invoice $invoice): WhatsAppMessage
    {
        if (! $this->enabled()) {
            throw new RuntimeException('خدمة واتساب غير مفعّلة. فعّلها من الإعدادات.');
        }
        if ($invoice->isDraft()) {
            throw new RuntimeException('لا يمكن إرسال مسودة عبر واتساب. أصدر الفاتورة أولاً.');
        }
        if ($invoice->isCancelled()) {
            throw new RuntimeException('لا يمكن إرسال فاتورة ملغاة عبر واتساب.');
        }

        $invoice->loadMissing('customer');
        $customer = $invoice->customer;
        if (! $customer) {
            throw new RuntimeException('لا يوجد عميل مرتبط بهذه الفاتورة.');
        }

        $phone = $this->resolvePhone($customer);
        if (! $phone) {
            throw new RuntimeException('رقم واتساب غير صالح للعميل.');
        }

        // An approved provider-side template reaches the customer reliably as a
        // first (business-initiated) message; a free PDF document only lands
        // inside the 24h customer-service window. When an invoice template is
        // configured we send it (text only, one {{1}} parameter); otherwise we
        // keep the historical behaviour of attaching the invoice PDF.
        $template = $this->invoiceTemplateName();

        return $template !== null
            ? $this->sendInvoiceTemplate($invoice, $customer, $phone, $template)
            : $this->sendInvoiceDocument($invoice, $customer, $phone);
    }

    /** Send the invoice as an approved provider-side template (text only). */
    private function sendInvoiceTemplate(Invoice $invoice, Customer $customer, string $phone, string $templateName): WhatsAppMessage
    {
        $variable = $this->invoiceTemplateVariable($invoice);

        $message = WhatsAppMessage::create([
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'phone' => $phone,
            'message_body' => $variable,
            'provider' => (string) $this->settings->get('whatsapp', 'provider', 'null'),
            'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
            'status' => WhatsAppMessageStatus::Pending,
            'created_by' => Auth::id(),
        ]);

        try {
            $provider = app(WhatsAppProvider::class);
            $result = $provider->sendTemplate($phone, $templateName, [$variable], $variable);

            if (! $result->ok) {
                throw new RuntimeException($result->error ?? 'WhatsApp template send failed');
            }

            $message->update([
                'status' => WhatsAppMessageStatus::Sent,
                'provider' => $provider->key(),
                'provider_message_id' => $result->providerMessageId,
                'sent_at' => now(),
                'failure_reason' => null,
            ]);

            app(AuditLogger::class)->log('invoice_whatsapp_sent', $invoice, 'Invoices',
                new: ['phone' => $phone, 'template' => $templateName],
                description: "إرسال الفاتورة {$invoice->invoice_number} عبر واتساب");
        } catch (\Throwable $e) {
            Log::warning('WhatsApp invoice template send failed', ['invoice' => $invoice->id, 'error' => $e->getMessage()]);
            $this->markFailed($message, $e->getMessage());

            throw new RuntimeException('تعذّر إرسال الفاتورة عبر واتساب. حاول مرة أخرى.');
        }

        return $message;
    }

    /** Send the invoice PDF as a document attachment (used inside the 24h window). */
    private function sendInvoiceDocument(Invoice $invoice, Customer $customer, string $phone): WhatsAppMessage
    {
        $pdfService = app(InvoicePdfService::class);
        $filename = $pdfService->filename($invoice);
        $body = $this->invoiceMessageBody($invoice);

        $message = WhatsAppMessage::create([
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'phone' => $phone,
            'message_body' => $body,
            'document_name' => $filename,
            'provider' => (string) $this->settings->get('whatsapp', 'provider', 'null'),
            'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
            'status' => WhatsAppMessageStatus::Pending,
            'created_by' => Auth::id(),
        ]);

        $path = null;
        try {
            $path = $pdfService->storeTemp($invoice);
            $provider = app(WhatsAppProvider::class);
            $result = $provider->sendDocument($phone, $body, $path, $filename);

            if (! $result->ok) {
                throw new RuntimeException($result->error ?? 'WhatsApp document send failed');
            }

            $message->update([
                'status' => WhatsAppMessageStatus::Sent,
                'provider' => $provider->key(),
                'provider_message_id' => $result->providerMessageId,
                'sent_at' => now(),
                'failure_reason' => null,
            ]);

            app(AuditLogger::class)->log('invoice_whatsapp_sent', $invoice, 'Invoices',
                new: ['phone' => $phone, 'document_name' => $filename],
                description: "إرسال الفاتورة {$invoice->invoice_number} عبر واتساب");
        } catch (\Throwable $e) {
            // Log internally but never surface provider secrets/stack traces.
            Log::warning('WhatsApp invoice send failed', ['invoice' => $invoice->id, 'error' => $e->getMessage()]);
            $this->markFailed($message, $e->getMessage());

            throw new RuntimeException('تعذّر إرسال الفاتورة عبر واتساب. حاول مرة أخرى.');
        } finally {
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
        }

        return $message;
    }

    /**
     * The customer-facing WhatsApp body for an invoice: total in USD and its ILS
     * equivalent AT THE INVOICE'S OWN exchange rate (never a global/latest rate).
     */
    public function invoiceMessageBody(Invoice $invoice): string
    {
        $invoice->loadMissing('customer');
        $totalUsd = Money::money($invoice->total_usd);
        $rate = $invoice->exchange_rate;
        $totalIls = $rate ? Money::convertUsdToIls($invoice->total_usd, $rate) : null;
        $company = (string) $this->settings->get('company', 'name', config('app.name'));

        $lines = [
            "مرحباً {$invoice->customer?->name}،",
            "مرفق لكم فاتورة رقم {$invoice->invoice_number} بقيمة {$totalUsd} USD.",
        ];
        if ($totalIls !== null) {
            $lines[] = "القيمة بالشيكل حسب سعر صرف الفاتورة: {$totalIls} ILS";
        }
        $lines[] = 'تاريخ الاستحقاق: '.(optional($invoice->due_date)->toDateString() ?? '—');
        $lines[] = 'شكراً لتعاملكم معنا.';
        $lines[] = $company;

        return implode("\n", $lines);
    }

    /** The configured Meta-side invoice-notification template name, or null. */
    public function invoiceTemplateName(): ?string
    {
        $name = trim((string) ($this->settings->get('whatsapp', 'meta_invoice_template')
            ?: config('services.whatsapp.meta_invoice_template') ?? ''));

        return $name !== '' ? $name : null;
    }

    /**
     * The single {{1}} body parameter for the invoice-notification template:
     * a one-line invoice summary. Kept to a single line because Meta rejects
     * template parameters containing newlines, tabs, or 4+ consecutive spaces.
     */
    public function invoiceTemplateVariable(Invoice $invoice): string
    {
        $invoice->loadMissing('customer');
        $total = Money::money($invoice->total_usd);
        $due = optional($invoice->due_date)->toDateString();

        $text = "عزيزنا {$invoice->customer?->name}، صدرت فاتورتكم رقم {$invoice->invoice_number} بقيمة {$total} USD";
        if ($due) {
            $text .= "، تاريخ الاستحقاق {$due}";
        }
        $text .= '.';

        return $this->sanitizeTemplateParam($text);
    }

    /** Collapse every whitespace run to a single space (Meta template rule). */
    private function sanitizeTemplateParam(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /** The single send attempt performed by the job (throws on failure). */
    public function deliver(WhatsAppMessage $message): void
    {
        if ($message->status->isTerminal()) {
            return;
        }

        $provider = app(WhatsAppProvider::class);
        $result = $provider->sendText($message->phone, $message->message_body);

        if ($result->ok) {
            $message->update([
                'status' => WhatsAppMessageStatus::Sent,
                'provider' => $provider->key(),
                'provider_message_id' => $result->providerMessageId,
                'sent_at' => now(),
                'failure_reason' => null,
            ]);

            return;
        }

        throw new RuntimeException($result->error ?? 'WhatsApp send failed');
    }

    public function markFailed(WhatsAppMessage $message, string $reason): void
    {
        $message->update([
            'status' => WhatsAppMessageStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => mb_substr($reason, 0, 240),
        ]);
    }

    public function markQueuedForRetry(WhatsAppMessage $message, string $reason): void
    {
        $message->update([
            'status' => WhatsAppMessageStatus::Queued,
            'failure_reason' => mb_substr($reason, 0, 240),
        ]);
    }

    /** Re-queue a failed message (an operator action, needs whatsapp.retry). */
    public function retry(WhatsAppMessage $message): WhatsAppMessage
    {
        if (! $message->isRetryable()) {
            throw new RuntimeException('يمكن إعادة إرسال الرسائل الفاشلة فقط.');
        }
        $message->update(['status' => WhatsAppMessageStatus::Pending, 'failure_reason' => null, 'failed_at' => null]);
        SendWhatsAppMessageJob::dispatch($message->id);

        return $message;
    }

    // ---- High-level notifications (best-effort, never throw upstream) ----

    public function notifyInvoiceIssued(Invoice $invoice): ?WhatsAppMessage
    {
        return $this->notifyForInvoice($invoice, WhatsAppTemplate::KEY_INVOICE_ISSUED);
    }

    public function notifySubscriptionInvoice(Invoice $invoice): ?WhatsAppMessage
    {
        return $this->notifyForInvoice($invoice, WhatsAppTemplate::KEY_SUBSCRIPTION_INVOICE);
    }

    private function notifyForInvoice(Invoice $invoice, string $templateKey): ?WhatsAppMessage
    {
        if (! $this->enabled()) {
            return null;
        }
        $template = WhatsAppTemplate::active()->where('key', $templateKey)->first();
        if (! $template) {
            return null;
        }
        $invoice->loadMissing('customer', 'subscription');
        $customer = $invoice->customer;
        if (! $customer) {
            return null;
        }

        try {
            $body = TemplateRenderer::render($template->body, $this->invoiceVariables($invoice));
        } catch (\Throwable $e) {
            Log::warning('Invoice WhatsApp render failed', ['invoice' => $invoice->id, 'error' => $e->getMessage()]);

            return null;
        }

        return $this->queue([
            'customer_id' => $customer->id,
            'phone' => $this->resolvePhone($customer),
            'message_body' => $body,
            'template_id' => $template->id,
            'invoice_id' => $invoice->id,
            'subscription_id' => $invoice->subscription_id,
        ], throwOnError: false);
    }

    public function notifyPaymentReceived(Payment $payment): ?WhatsAppMessage
    {
        if (! $this->enabled()) {
            return null;
        }
        $template = WhatsAppTemplate::active()->where('key', WhatsAppTemplate::KEY_PAYMENT_RECEIVED)->first();
        if (! $template) {
            return null;
        }
        $payment->loadMissing('customer');
        $customer = $payment->customer;
        if (! $customer) {
            return null;
        }

        try {
            $body = TemplateRenderer::render($template->body, [
                'customer_name' => $customer->name,
                'payment_amount' => Money::money($payment->payment_amount),
                'payment_currency' => $payment->payment_currency->value ?? (string) $payment->payment_currency,
                'balance_usd' => $this->balances->netBalanceUsd($customer),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Payment WhatsApp render failed', ['payment' => $payment->id, 'error' => $e->getMessage()]);

            return null;
        }

        return $this->queue([
            'customer_id' => $customer->id,
            'phone' => $this->resolvePhone($customer),
            'message_body' => $body,
            'template_id' => $template->id,
            'payment_id' => $payment->id,
        ], throwOnError: false);
    }

    /**
     * Standard template variables for an invoice.
     *
     * @return array<string,string|null>
     */
    public function invoiceVariables(Invoice $invoice): array
    {
        $invoice->loadMissing('customer', 'subscription');

        return [
            'customer_name' => $invoice->customer?->name,
            'invoice_number' => $invoice->invoice_number,
            'invoice_total_usd' => Money::money($invoice->total_usd),
            'invoice_remaining_usd' => Money::money($invoice->remaining_usd),
            'due_date' => optional($invoice->due_date)->toDateString(),
            'subscription_name' => $invoice->subscription?->name,
        ];
    }
}
