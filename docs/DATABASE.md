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

## Sprint 5 (expenses/suppliers/accounting)
- **expense_categories**: id, name.
- **expenses**: id, reference_no, category_id, description, amount, currency, exchange_rate, amount_ils, expense_date, payment_method, account_id, supplier_id(nullable), project_id(nullable), customer_id(nullable), employee_id(nullable), department_id(nullable), notes, created_by.
- **suppliers**: id, name, phone, whatsapp, address, tax_number, type, notes.
- **supplier_transactions**: id, supplier_id, type(charge/payment), amount, currency, date, reference.
- **accounts**: id, name, type(Cash/Bank/CreditCard/Other), currency, opening_balance, is_active. Balance = sum of transactions (never edited directly).
- **account_transactions**: id, account_id, direction(debit/credit), amount, currency, date, source_type, source_id, description.
- **chart_of_accounts**: id, code, name, type(Asset/Liability/Equity/Revenue/Expense), parent_id, is_system.
- **journal_entries**: id, reference_no, entry_date, memo, source_type, source_id, status(Posted/Reversed), reversed_by(nullable), created_by.
- **journal_entry_lines**: id, journal_entry_id, account_id→chart_of_accounts, debit, credit, currency, memo. (sum debit == sum credit)

## Sprint 6 (payroll)
- **payroll_runs**: id, reference_no, period_year, period_month, status(Draft/Approved/Paid), total_net, approved_by, approved_at, paid_at, created_by.
- **payroll_items**: id, payroll_run_id, employee_id, base_salary, bonuses, commission, overtime, allowances, deductions, absence_deduction, late_deduction, loans, other_adjustments, net_salary.
- **salary_adjustments**: id, employee_id, payroll_run_id(nullable), type, amount, reason, effective_month, created_by.

## Sprint 7 (subscriptions/whatsapp)
- **subscriptions**: id, customer_id, service_id, start_date, billing_cycle(Monthly/Yearly), amount_usd, next_invoice_date, status(Active/Paused/Cancelled/Expired), auto_generate_invoice.
- **whatsapp_templates**: id, name, key, body, is_active.
- **whatsapp_messages**: id, customer_id, phone, template_id, message, status, sent_at, delivered_at, read_at, failed_at, provider_message_id, created_by.
- **payment_reminder_rules**: id, offset_days(signed), enabled, template_id.

## Cross-cutting
- **attachments**: id, attachable_type, attachable_id, disk, path, original_name, mime, size, uploaded_by.
- **notifications**: Laravel notifications table (id uuid, type, notifiable, data, read_at).
