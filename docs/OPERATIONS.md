# Sprint 2 — Operational Core (Customers, Services, Projects, Tasks)

The daily operational heart of the company. Operational and financial data stay strictly separated: employees see the work (customer name, project, tasks, files, comments, team) but never money (service prices/costs, contract value, invoices, balances, profit).

## Customers (CRM)
- `customers`: `customer_number` (CUS-#####), name, contact_person, phone, `whatsapp_number`, city, address, tax_number, `customer_category_id`, `status`, `source`, notes, is_active, created/updated_by. **No email field anywhere** — phone/WhatsApp are the channels.
- Enums: `CustomerStatus` (Lead/Active/Inactive/OnHold/Archived), `CustomerSource`. `customer_categories` are CRUD-managed and never hard-deleted while in use.
- `CustomerService`: auto number, create/update, and **archive** (status→Archived, is_active=false) instead of hard delete — projects/tasks history is preserved.
- Profile tabs: overview / projects / tasks / attachments / activity. Financial tabs (quotations, invoices, payments, subscriptions, whatsapp) are deferred to later sprints and gated behind finance permissions; no fake data is shown.

## Services (catalog)
- `services`: `service_code` (SRV-####), name, category, description, `service_type`, `default_price_usd`, `estimated_cost_ils`, `tax_rate`, is_active, notes.
- Enum `ServiceType` (OneTime/Monthly/Yearly/Custom).
- **Financial-field protection is enforced in the backend**, not just the UI: `Service::toArray()` strips `default_price_usd`, `estimated_cost_ils`, `tax_rate` for anyone lacking `services.view_financial` (`Service::FINANCIAL_FIELDS`). The catalog page hides those columns/inputs and refuses to read/write them for unauthorised users. `services.view` alone shows only name/code/type. Price/cost changes emit explicit `service_price_changed` / `service_cost_changed` audit entries with old/new values.

## Projects
- `projects`: `project_number` (PRJ-YYYY-####), customer, name, description, project_type, `project_manager_id`, department, `priority`, `status`, dates, completed_at, notes.
- Enums: `ProjectStatus` (Draft/Active/OnHold/UnderReview/Completed/Cancelled), shared `Priority` (Low/Normal/High/Urgent).
- `project_members` (unique project_id+employee_id) — duplicate membership is rejected by `ProjectService`.
- **Progress** is derived, never stored: `completed / (all non-cancelled tasks)`, 0 when there are no tasks.
- `ProjectService`: auto number, create/update (keeps completed_at in step with status), add/remove member (notifies the added member), and **cancel** instead of hard delete.
- Financial project accounting is intentionally deferred; the relations are ready for quotations/invoices/expenses in later sprints. No fake financial numbers.

## Tasks + workflow
- `tasks`: `task_number` (TSK-######), title, description, customer, project, department, `primary_assignee_id`, `priority`, `status`, dates, completed_at, estimated_minutes, notes.
- `task_assignees` (unique), `task_comments` (threaded via parent_id), `task_checklist_items`, `task_status_history` (dedicated workflow log), `tags`/`task_tag`, and unified polymorphic `attachments`.
- **Customer/project consistency**: a task's customer always matches its project's customer — `TaskService` derives it from the project and rejects a conflicting customer.
- Enum `TaskStatus` (New/InProgress/WaitingReview/ChangesRequested/Completed/Cancelled).

### Workflow (`TaskWorkflowService`)
State graph and who may drive each edge:

| From | To | Who |
|------|----|-----|
| New | In Progress | assignee (or `tasks.manage`) |
| In Progress | Waiting Review | assignee (or `tasks.manage`) |
| Waiting Review | Completed | `tasks.review` |
| Waiting Review | Changes Requested | `tasks.review` (reason required) |
| Changes Requested | In Progress | assignee (or `tasks.manage`) |
| Completed | In Progress (reopen) | `tasks.review`/`tasks.manage` (reason required) |
| any open | Cancelled | `tasks.manage` |

- An employee can start/submit their own task (they are the assignee) but **cannot approve it** — approval needs `tasks.review`. Invalid edges are rejected. Every transition writes a `task_status_history` row (from/to/changed_by/reason) **and** an audit entry, and fires notifications (reviewers on submit; assignee on decision/reopen).
- `Task::late()` scope = overdue (`due_date < today`) and still open; never stored.

## Access rules (real Backend authorization)
- Routes carry `can:` middleware; the admin area is additionally behind `admin.area`. Employees hitting an admin URL are redirected out; users with admin experience but without a specific permission get 403.
- **Query scoping** (not UI hiding): `Customer::visibleTo`, `Project::visibleTo`, `Task::visibleTo`. With the all-access permission (`customers.view` / `projects.view` / `tasks.view`) the user sees everything; otherwise an employee sees only customers/projects tied to their membership or assigned tasks, and only their own tasks (`tasks.view_own`). Task/Project detail pages abort 403 unless the record is visible to the user. Employees cannot enumerate all customers/projects/tasks through the components — the base query is already scoped.

## Permissions added
`customers.manage|archive|attachments`; `services.create|edit|view_financial`; `projects.view_assigned|create|edit|members|attachments`; `tasks.view_own|edit|manage|comment|attachments|checklist`. Distribution follows least privilege: PM gets full project/task management and `services.view` **without** `services.view_financial` (cannot see prices); Accountant keeps finance + service pricing but **no** project/task admin; HR keeps HR only; Employee/Team Leader get `view_assigned`/`view_own` + comment/checklist/attachments and drive only their own tasks' workflow.

## Data safety
Customers → archive; Projects → cancel; Tasks → cancel. No hard delete of operational records with history. Trivial unused items (checklist entries) may be deleted with the right permission.
