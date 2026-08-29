# Implementation Plan

Status legend: ✅ done · 🚧 in progress · ⬜ pending

## Sprint 0 — Foundation ✅ (30 tests green)
- ✅ Laravel 13, PHP 8.4, Livewire 4, spatie/laravel-permission, Tailwind 4 RTL
- ✅ Architecture / DB / currency / permissions docs
- ✅ Enums (Currency, RoleName)
- ✅ `settings`, `audit_logs`, `document_sequences` migrations + models
- ✅ Extend `users` (phone, employee_id, is_active, locale)
- ✅ Permissions + roles seeder (full matrix in `App\Support\Permissions`)
- ✅ `Auditable` trait + `AuditLogger` service (strips secrets)
- ✅ `Settings` service (cached key/value, typed)
- ✅ `DocumentNumberService` (atomic auto numbering)
- ✅ Gate::before super-admin bypass
- ✅ Auth (login/logout via Livewire), role-based redirect
- ✅ Admin RTL layout + Employee RTL layout, permission-aware sidebar (`AdminNavigation`)
- ✅ `admin.area` middleware keeps employees out of back-office
- ✅ Settings Livewire page, Audit Log Livewire page
- ✅ Seeders: 7 role users + settings + roles/permissions
- ✅ Tests: auth, employee/PM cannot reach financial data, audit writes (no secret leak), settings save+audit, numbering, page rendering
- ✅ migrate + test green + pint clean

## Sprint 1 — HR ✅ (39 tests green; 69 total)
- ✅ Departments (CRUD, manager, active toggle, delete guard when employees exist) + `DepartmentService`
- ✅ Employees module separate from User auth, bi-directional link, profile with tabs, documents; `EmployeeService` (auto number, circular-manager prevention). No salary/financial fields on the employee record.
- ✅ Attendance: check-in/out (server clock, no double in/out), grace/late/worked/overtime maths, admin dashboard + adjustments, employee self-service; `AttendanceService`
- ✅ Leaves: types, request workflow (submit/approve/reject/cancel/reverse), working-day counting excluding weekend, overlap protection, attendance sync on approval; `LeaveService`
- ✅ Enums: EmploymentStatus, EmploymentType, AttendanceStatus, AttendanceSource, LeaveStatus
- ✅ Granular permissions + self-service bundle; employee/admin dashboards updated with HR cards
- ✅ Database notifications (leave submitted/approved/rejected, attendance adjusted)
- ✅ Seeders: 8 departments, 5 leave types, 10 employees linked to demo users, ~2 weeks attendance, sample leaves
- ✅ Docs: `docs/HR.md`
- ✅ Full suite green + pint clean; no Sprint 0 regression

## Sprint 2 — Operational core ✅ (48 tests green; 117 total)
- ✅ Customers (CRM) with categories, sources, statuses, profile tabs, attachments, archive-not-delete; `CustomerService`. No email field anywhere.
- ✅ Services catalog with **backend financial-field protection** (`services.view_financial`); price/cost change auditing; `ServiceCatalogService`.
- ✅ Projects with members (dedup), derived progress, profile (overview/tasks/team/files/activity), cancel-not-delete; `ProjectService`.
- ✅ Tasks: full workflow (`TaskWorkflowService`), assignees, comments, checklist, status history, tags, unified attachments; customer/project consistency; `TaskService`.
- ✅ Enums: Priority, CustomerStatus, CustomerSource, ServiceType, ProjectStatus, TaskStatus.
- ✅ Real query-level visibility scoping (`visibleTo`) for customers/projects/tasks; employees can't enumerate others' data or open unrelated records (403).
- ✅ Employee experience: My Tasks (filters), My Projects, task detail with workflow actions; admin experience: full index + detail pages.
- ✅ Dashboards: operational cards (admin) + real task/project data (employee, placeholders removed).
- ✅ Notifications: task assigned/submitted/status-changed, project member added.
- ✅ Seeders: 10 customers, 16 services, 6 projects (+members), 34 tasks (+comments/checklists/status history).
- ✅ Docs: `docs/OPERATIONS.md`. Full suite green + pint clean + build; no Sprint 0/1 regression.

## Sprint 3–8
See ARCHITECTURE.md §8 and DATABASE.md. Executed only after the prior sprint's tests pass.

## Definition of Done per sprint
1. Migrations run clean on fresh DB.
2. Seeders produce demo data.
3. `php artisan test` green.
4. `npm run build` succeeds (asset pipeline).
5. Git commit with clear message, pushed to `claude/creative-agency-erp-crm-2j7oz8`.
