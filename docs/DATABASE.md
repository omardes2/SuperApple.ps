# Database Schema (target)

DB-agnostic migrations (MySQL prod / SQLite test). Money = `decimal(15,2)`, rates = `decimal(12,6)`. All important tables carry `created_by`/`updated_by` where useful and timestamps. Financial tables use soft state (void/cancel/reverse) not hard delete.

Legend: PK = id (bigint), FK noted, `→` references.

## Sprint 0 tables
- **users**: id, name, phone, email(nullable, staff login only), password, employee_id→employees(nullable), is_active, locale, timestamps.
- **roles / permissions / model_has_roles / model_has_permissions / role_has_permissions**: spatie standard.
- **settings**: id, group, key (unique with group), value(text), type(enum: string,int,decimal,bool,json), timestamps.
- **audit_logs**: id, user_id→users(nullable), action, module, auditable_type, auditable_id, old_values(json), new_values(json), ip_address, user_agent, url, created_at.

## Sprint 1 (HR)
- **departments**: id, name, description, manager_id→employees(nullable).
- **employees**: id, reference_no, user_id→users(nullable), name, phone, job_title, department_id→departments, manager_id→employees(nullable), hire_date, status(enum), work_days(json), work_hours(json), hr_notes(text). *No salary here.*
- **employee_documents**: id, employee_id, title, file path via attachments.
- **attendance_records**: id, employee_id, work_date, check_in, check_out, worked_minutes, late_minutes, overtime_minutes, status(enum Present/Late/Absent/Leave/Remote/External), notes, meta(json for future GPS/device).
- **leave_requests**: id, employee_id, type, start_date, end_date, days, reason, status(Pending/Approved/Rejected/Cancelled), approved_by, decided_at, attachment.

## Sprint 2 (CRM/Ops)
- **customer_categories**: id, name.
- **customers**: id, reference_no, company_name, contact_person, phone, whatsapp, address, city, tax_number, status(enum), category_id, notes. **NO email field.**
- **services**: id, name, category, description, default_price, currency, estimated_cost, tax_rate, type(OneTime/Monthly/Yearly/Custom), is_active.
- **projects**: id, reference_no, name, customer_id, description, type, manager_id→employees, start_date, delivery_date, status(enum), priority(enum), notes, quotation_id(nullable), invoice_id(nullable).
- **project_members**: project_id, employee_id, role.
- **tasks**: id, reference_no, title, description, customer_id(nullable), project_id(nullable), assignee_id→employees, department_id, priority, start_date, due_date, status(enum), tags(json).
- **task_assignees**: task_id, employee_id.
- **task_comments**: id, task_id, user_id, body.
- **task_checklist_items**: id, task_id, label, is_done.
- **task_status_history**: id, task_id, from_status, to_status, changed_by, note, created_at.

## Sprint 3 (billing docs)
- **exchange_rates**: id, rate_date(unique), usd_to_ils.
- **quotations**: id, reference_no, customer_id, issue_date, valid_until, subtotal, discount, tax, total, currency, notes, terms, status(enum), created_by.
- **quotation_items**: id, quotation_id, service_id(nullable), description, quantity, unit_price, discount, tax_rate, line_total.
- **invoices**: id, reference_no, customer_id, quotation_id(nullable), project_id(nullable), issue_date, due_date, currency('USD'), subtotal_usd, discount_usd, tax_usd, total_usd, exchange_rate, total_ils_at_issue, paid_usd_equivalent, remaining_usd, status(enum), notes, issued_at, created_by.
- **invoice_items**: id, invoice_id, service_id(nullable), description, quantity, unit_price_usd, discount_usd, tax_rate, line_total_usd.

## Sprint 4 (payments) — implemented
- **payments**: id, `payment_number`(PAY-YYYY-####, unique), customer_id, payment_date, payment_currency(USD|ILS), payment_amount(15,2), exchange_rate(12,6 nullable), usd_equivalent(15,2), payment_method, **account_id (unsigned, nullable, indexed, NO FK — reserved for Sprint 5 cash/bank)**, reference_number, notes, status(draft|posted|cancelled), received_by→users, posted_at, cancelled_at, cancelled_by→users, cancellation_reason, created_by, updated_by. Indexes: customer_id, payment_date, status. `allocated`/`unallocated` are **derived** (not stored) from active allocations.
- **payment_allocations**: id, payment_id→payments(cascade), invoice_id→invoices, allocated_usd(15,2), and an accounting snapshot per allocation: invoice_exchange_rate(12,6), payment_exchange_rate(12,6), invoice_accounting_value_ils(15,2), payment_accounting_value_ils(15,2), exchange_difference_ils(15,2), status(active|reversed), reversed_at, reversed_by→users, reversal_reason. Reversed rows are kept (never hard-deleted).

## Sprint 5 (accounting/expenses/suppliers/cash & banks) — implemented

Base ledger currency = ILS. Every posted journal balances (Σ debit_ils = Σ credit_ils).

- **chart_of_accounts**: id, code(unique), name, parent_id→self(nullable), account_type(asset|liability|equity|revenue|expense), normal_balance(debit|credit), is_system, is_active, allow_manual_posting, description, created_by, updated_by. Postings hit active leaf accounts only.
- **system_accounts**: id, key(unique), account_id→chart_of_accounts. Maps `SystemAccountKey` → account so logic never hard-codes a code.
- **financial_accounts**: id, name, type(cash|bank|credit_card|other), currency(ILS|USD), gl_account_id→chart_of_accounts, bank_name, account_number, iban, opening_balance, opening_balance_date, is_active, notes. Balance is DERIVED from journal lines.
- **journal_entries**: id, journal_number(JRN-YYYY-######, unique), entry_date, source_type, source_id, posting_type, description, status(draft|posted|reversed), is_reversal, posted_at, reversed_at, reversal_entry_id→self, created_by, posted_by. Unique(source_type, source_id, posting_type) for idempotency.
- **journal_entry_lines**: id, journal_entry_id→journal_entries(cascade), account_id→chart_of_accounts, description, debit_ils, credit_ils, original_currency, original_amount, exchange_rate, + dimensions (customer_id, supplier_id, project_id, invoice_id, payment_id, expense_id, supplier_bill_id, supplier_payment_id, financial_account_id).
- **account_transfers**: id, transfer_number, transfer_date, from_account_id/to_account_id→financial_accounts, currency, amount, amount_ils, notes, status, posted_at, created_by. Same-currency only.

- **expense_categories**: id, name, default_expense_account_id→chart_of_accounts(nullable), is_active.
- **expenses**: id, expense_number(EXP-YYYY-####), expense_date, category_id, supplier_id?, project_id?, employee_id?, description, currency, amount, exchange_rate?, amount_ils, payment_method, financial_account_id?, reference_number?, tax_amount?, status(draft|approved|posted|cancelled), approved_by?, posted_at?, cancelled_*, created_by, updated_by.
- **suppliers**: id, supplier_number(SUP-#####), name, contact_person?, phone?, whatsapp_number?, address?, tax_number?, supplier_type?, notes?, is_active, created_by, updated_by. No email.
- **supplier_bills**: id, bill_number(BILL-YYYY-####), supplier_id, project_id?, bill_date, due_date?, currency, subtotal, tax, total(original), exchange_rate?, total_ils, paid_original, remaining_original, status(draft|posted|partially_paid|paid|cancelled), reference_number?, notes?, posted_at?, cancelled_*, created_by, updated_by.
- **supplier_bill_items**: id, supplier_bill_id(cascade), expense_account_id?→chart_of_accounts, project_id?, description, quantity, unit_price, tax, total, sort_order.
- **supplier_payments**: id, payment_number(SPAY-YYYY-####), supplier_id, payment_date, currency, amount, exchange_rate?, amount_ils, financial_account_id?, reference_number?, notes?, status(draft|posted|cancelled), posted_at?, cancelled_*, created_by, updated_by.
- **supplier_payment_allocations**: id, supplier_payment_id(cascade), supplier_bill_id, allocated_original, bill_accounting_value_ils, payment_accounting_value_ils, exchange_difference_ils, status(active|reversed), reversed_*.

### Sprint 5 (original target — superseded by the implemented list above)
- **expense_categories**: id, name.
- **expenses**: id, reference_no, category_id, description, amount, currency, exchange_rate, amount_ils, expense_date, payment_method, account_id, supplier_id(nullable), project_id(nullable), customer_id(nullable), employee_id(nullable), department_id(nullable), notes, created_by.
- **suppliers**: id, name, phone, whatsapp, address, tax_number, type, notes.
- **supplier_transactions**: id, supplier_id, type(charge/payment), amount, currency, date, reference.
- **accounts**: id, name, type(Cash/Bank/CreditCard/Other), currency, opening_balance, is_active. Balance = sum of transactions (never edited directly).
- **account_transactions**: id, account_id, direction(debit/credit), amount, currency, date, source_type, source_id, description.
- **chart_of_accounts**: id, code, name, type(Asset/Liability/Equity/Revenue/Expense), parent_id, is_system.
- **journal_entries**: id, reference_no, entry_date, memo, source_type, source_id, status(Posted/Reversed), reversed_by(nullable), created_by.
- **journal_entry_lines**: id, journal_entry_id, account_id→chart_of_accounts, debit, credit, currency, memo. (sum debit == sum credit)

## Sprint 6 (payroll) — implemented
Base currency ILS. Salary is kept OFF the employees table (confidentiality).
- **employee_salary_profiles**: id, employee_id, effective_from, effective_to?, base_salary_ils, salary_type(monthly|daily|hourly), working_days_basis?, daily_rate?, hourly_rate?, overtime_rate?(absolute ILS/hour), status(active|archived), approved_by?, created_by. Effective-dated history; a raise creates a new row.
- **salary_adjustments**: id, employee_id, payroll_run_id?, adjustment_type(earning|deduction), category, amount_ils, effective_date, recurring_end_date?, description?, is_recurring, gl_account_id?→chart_of_accounts, status(active|cancelled), approved_by?, created_by.
- **employee_advances**: id, advance_number(ADV-YYYY-####), employee_id, type(advance|loan), request_date, approved_date?, amount_ils, remaining_ils, installment_ils?, installments?, status(draft|approved|paid|partially_recovered|recovered|cancelled), financial_account_id?, paid_at?, approved_by?, cancelled_*, created_by, updated_by.
- **employee_advance_recoveries**: id, employee_advance_id, payroll_run_id, payroll_item_id, amount_ils, status(active|reversed).
- **payroll_runs**: id, payroll_number(PAYROLL-YYYY-MM), year, month (unique together), period_start, period_end, status(draft|calculated|approved|posted|paid|cancelled), total_gross_ils, total_deductions_ils, total_advances_ils, total_net_ils, calculated_at, approved_at/by, posted_at/by, paid_at, cancelled_*, created_by.
- **payroll_items**: id, payroll_run_id, employee_id (unique together), *_snapshot (name/department/job_title), base_salary_ils, working/attended/paid_leave/unpaid_leave/absent days, late_minutes, overtime_minutes, overtime_amount_ils, allowances/bonuses/commissions_ils, absence/late/unpaid_leave/other/advances deduction_ils, gross_salary_ils, total_deductions_ils, net_salary_ils, paid_amount_ils, remaining_payable_ils, calculation_snapshot(json).
- **payroll_payments**: id, payment_number(SALP-YYYY-#####), payroll_run_id, payroll_item_id, employee_id, amount_ils, financial_account_id, payment_date, reference?, status(posted|reversed), reversed_*, created_by.

## Sprint 7 (subscriptions/whatsapp)
- **subscriptions**: id, customer_id, service_id, start_date, billing_cycle(Monthly/Yearly), amount_usd, next_invoice_date, status(Active/Paused/Cancelled/Expired), auto_generate_invoice.
- **whatsapp_templates**: id, name, key, body, is_active.
- **whatsapp_messages**: id, customer_id, phone, template_id, message, status, sent_at, delivered_at, read_at, failed_at, provider_message_id, created_by.
- **payment_reminder_rules**: id, offset_days(signed), enabled, template_id.

## Cross-cutting
- **attachments**: id, attachable_type, attachable_id, disk, path, original_name, mime, size, uploaded_by.
- **notifications**: Laravel notifications table (id uuid, type, notifiable, data, read_at).

## Sprint 7 — Subscriptions & WhatsApp
- **subscriptions**: id, subscription_number (SUB-YYYY-####), customer_id, project_id?, name, description?, billing_cycle, billing_interval, start_date, end_date?, next_billing_date, last_billed_at?, payment_terms_days?, currency (USD), subtotal/discount/tax/total_usd (snapshot), auto_generate_invoice, auto_issue_invoice, status, notes?, terms?, activated_at/paused_at/cancelled_at/cancelled_by/cancellation_reason, created_by/updated_by.
- **subscription_items**: id, subscription_id, service_id?, item_name, description?, quantity, unit_price_usd (snapshot), discount_type?, discount_value?, tax_rate, sort_order.
- **subscription_billings**: id, subscription_id, invoice_id?, period_start, period_end, billing_date, status (generated|issued|skipped|failed), error_message?. **unique(subscription_id, period_start, period_end)** — duplicate-billing guard.
- **invoices.subscription_id** (nullable FK) — links a recurring invoice back to its subscription (informational only).
- **whatsapp_templates**: id, name, key (unique), category, language, body, is_active, variables_schema?, created_by/updated_by.
- **whatsapp_messages**: id, customer_id?, invoice_id?, payment_id?, subscription_id?, template_id?, phone, message_body, provider, provider_message_id?, direction, status, scheduled_for?/sent_at?/delivered_at?/read_at?/failed_at?, failure_reason?, created_by?.
- **payment_reminder_rules**: id, name, offset_days, timing_type (before_due|due_date|after_due), template_id?, is_active, send_time?, created_by/updated_by.
- **payment_reminder_logs**: id, invoice_id, reminder_rule_id?, whatsapp_message_id?, due_date, sent_on, status, note?. **unique(invoice_id, reminder_rule_id, due_date)** — duplicate-reminder guard.

## Sprint 8 — Users profile columns
- **users.last_login_at** (nullable timestamp) — recorded on each successful login.
- **users.notification_preferences** (nullable json) — per-user toggles for notification categories (tasks/hr/finance/subscriptions/whatsapp).
No new business tables in Sprint 8 — it is an integration/stabilisation sprint. Settings gained `system.version`, `subscriptions.expiry_warning_days`, and a `dashboard.*` threshold group.
