# WhatsApp Go-Live Checklist (optional)

The app runs fully **without** WhatsApp: it ships with the **Null provider** and
`whatsapp.enabled = false`, so no real messages are ever sent until an operator
turns it on. Do NOT enable a real provider until the steps below are done, and
never commit real credentials.

## 1. Choose a provider
- **Meta WhatsApp Cloud API** (official), or
- **360dialog** (BSP). The code is provider-agnostic via `App\Contracts\WhatsAppProvider`.

## 2. Collect the external requirements (from the provider, not from us)
- Provider name (driver key).
- API token / permanent access token.
- Phone Number ID.
- WhatsApp Business Account ID (if the provider requires it).
- Approved message templates (Cloud API pre-approves templates; their names +
  languages must match the app's template keys: `invoice_issued`,
  `payment_reminder_before_due` / `_due_today` / `_overdue`, `payment_received`,
  `subscription_invoice_created`, `payment_reminder_manual`).
- A test destination phone number you control.

## 3. Configure (no secrets in code or the DB dump)
- Put tokens in the server environment / secure config only, e.g.:
  ```dotenv
  WHATSAPP_PHONE_NUMBER_ID=<your-phone-number-id>
  WHATSAPP_TOKEN=<your-permanent-access-token>
  ```
- In the app Settings (WhatsApp): set the provider and the default country code.

## 4. Verify the queue
- WhatsApp sends go through `SendWhatsAppMessageJob` on the **database** queue —
  the worker (Supervisor/systemd) must be running, or messages stay Pending.

## 5. Test before trusting automation
- Enable the channel, send a **manual** message from the WhatsApp screen to your
  test number, and confirm it arrives and the message row moves to Sent.
- Only then rely on automatic invoice notifications and payment reminders.

## 6. Message status / webhooks
- Inbound messages and delivery/read webhooks are a **future enhancement** —
  not implemented yet. Status currently reflects send + retry outcomes only.

> A WhatsApp failure never affects invoices or payments — sends happen after the
> financial transaction commits, on the queue, with bounded retries.
