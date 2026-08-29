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

## Sprint 1–8
See ARCHITECTURE.md §8 and DATABASE.md. Executed only after the prior sprint's tests pass.

## Definition of Done per sprint
1. Migrations run clean on fresh DB.
2. Seeders produce demo data.
3. `php artisan test` green.
4. `npm run build` succeeds (asset pipeline).
5. Git commit with clear message, pushed to `claude/creative-agency-erp-crm-2j7oz8`.
