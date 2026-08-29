# Sprint 1 — HR (Departments, Employees, Attendance, Leaves)

Operational HR module. **No salary or financial data** lives here — that belongs to the future Payroll module and is gated behind `payroll.view`.

## Departments
- `departments`: name, unique `code`, description, `manager_id` (→ employees, app-level link), `is_active`, `sort_order`, created/updated_by.
- `DepartmentService`: create/update; `canDelete()` is false while the department has employees; `delete()` refuses in that case (deactivate instead — never a hard delete of a linked department).

## Employees
- `employees`: unique `employee_number`, optional `user_id` (login account link), `full_name`, phone, `job_title`, `department_id`, self-referencing `direct_manager_id`, `hire_date`, `employment_status`, `employment_type`, `working_hours_per_day`, notes, `is_active`, created/updated_by.
- Enums: `EmploymentStatus` (Active/Suspended/Resigned/Terminated), `EmploymentType` (FullTime/PartTime/Freelance/Contract).
- `EmployeeService`: auto employee number, and `assertValidManager()` blocks self-management and management **cycles** (walks up the proposed manager chain).
- Employee ≠ User: a `User` is a login; an `Employee` is the HR profile. They link both ways (`users.employee_id` ↔ `employees.user_id`).
- Profile page has tabs: overview / attendance / leaves / tasks (Sprint 2) / projects (Sprint 2) / documents / activity. **No payroll tab.**
- `employee_documents`: title, type, stored file (local disk), notes, uploaded_by — managed under `employees.documents`.

## Attendance
- `attendance_records`: one row per `employee_id + attendance_date` (unique index). Holds check-in/out timestamps, `worked_minutes`, `late_minutes`, `overtime_minutes`, `status`, sources, notes, `meta` (GPS/device-ready), approval + audit columns.
- Enum `AttendanceStatus`: Present/Late/Absent/Leave/RemoteWork/ExternalMission.

### Calculation (all in `AttendanceService`, never in components)
- **Timestamps are the server clock** — the employee cannot set or edit them.
- **Grace / late:** threshold = `work_start + grace_minutes`. Check-in at or before the threshold = 0 late minutes; after it, `late = check_in − threshold`.
  - e.g. start 09:00, grace 15 → threshold 09:15. Check-in 09:22 → **7** late minutes; 09:10 → 0.
- **Worked minutes** = `check_out − check_in`.
- **Overtime** = `max(0, worked − working_hours_per_day × 60)` (falls back to the company `default_working_hours`).
- Computed values are **stored** so past months stay stable.
- Rules: no double check-in, no check-out before check-in, no double check-out.
- Adjustments (`attendance.adjust`) recompute derived fields, stamp approver, write an explicit audit entry, and notify the employee.

### Settings (reused from Sprint 0, group `attendance`)
`work_start`, `work_end`, `grace_minutes`, `work_days`, `weekend`, `default_working_hours`.

## Leaves
- `leave_types`: name, unique `code`, `is_paid`, `requires_attachment`, `is_active`.
- `leave_requests`: `reference_no` (LV-YYYY-#####), employee, type, dates, `total_days`, reason, attachment, `status`, reviewer + notes.
- Enum `LeaveStatus`: Pending/Approved/Rejected/Cancelled.

### Rules (all in `LeaveService`)
- **Day counting** excludes the weekly day off and any non-working day (`calculateDays` iterates the range against the working-days setting). A range must contain ≥ 1 working day.
- **No two approved leaves overlap** for the same employee (checked on submit and on approve).
- **Approve** → each working day in range is marked `Leave` in attendance (never overwrites a real check-in); the employee is notified.
- **Reject** → no attendance effect; the employee is notified.
- **Cancel (pending)** → employee self-cancels a still-pending request.
- **Reverse (approved)** → authorised (`leaves.manage`) reversal: removes the system-synced Leave days and sets the request to Cancelled — **never a hard delete**; kept in the audit log.

## Notifications
Database channel: `LeaveRequestSubmitted` (to `leaves.approve` holders), `LeaveStatusChanged` (to the employee on approve/reject), `AttendanceAdjusted` (to the employee).

## Permissions added
`departments.view|create|edit|manage`, `employees.create|edit|documents` (view/manage from Sprint 0),
`attendance.view|view_own|check_in|check_out|manage|adjust|reports`,
`leaves.view|view_own|create|approve|reject|manage`.
Every staff role gets the self-service bundle (`attendance.view_own|check_in|check_out`, `leaves.view_own|create|request`). HR gets the full HR management set; **no role is granted financial permissions here**. Semantics: `attendance.view` = view everyone's attendance (managers/HR); `attendance.view_own` = own only (employees).
