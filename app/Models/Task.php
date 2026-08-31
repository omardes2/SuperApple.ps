<?php

namespace App\Models;

use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use Auditable, HasAttachments;

    protected $fillable = [
        'task_number', 'title', 'description', 'customer_id', 'project_id',
        'department_id', 'primary_assignee_id', 'priority', 'status',
        'start_date', 'due_date', 'completed_at', 'estimated_minutes', 'notes',
        'ad_budget_amount', 'ad_budget_currency', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'estimated_minutes' => 'integer',
        'ad_budget_amount' => 'decimal:2',
        'priority' => Priority::class,
        'status' => TaskStatus::class,
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function primaryAssignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'primary_assignee_id');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'task_assignees')
            ->withPivot('role', 'assigned_at', 'status', 'started_at', 'completed_at', 'added_by', 'is_active')
            ->withTimestamps();
    }

    /**
     * The active task team: primary assignee + participants who have not been
     * removed. This drives collaborative completion.
     */
    public function activeMembers(): BelongsToMany
    {
        return $this->assignees()->wherePivot('is_active', true);
    }

    /** Services attached to this task (operational — no pricing stored here). */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'task_service')->withTimestamps();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->whereNull('parent_id')->latest();
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class)->orderBy('sort_order');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(TaskStatusHistory::class)->latest('created_at');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'task_tag')->withTimestamps();
    }

    public function isLate(): bool
    {
        return $this->due_date && $this->due_date->isPast() && $this->status->isOpen();
    }

    /**
     * All employee ids that count as "assigned" to this task.
     *
     * @return array<int>
     */
    public function assignedEmployeeIds(): array
    {
        $ids = $this->assignees()->pluck('employees.id')->all();

        if ($this->primary_assignee_id) {
            $ids[] = $this->primary_assignee_id;
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    public function isAssignedTo(?Employee $employee): bool
    {
        return $employee !== null && in_array((int) $employee->id, $this->assignedEmployeeIds(), true);
    }

    /** The active team-member pivot row for an employee, or null. */
    public function memberFor(?Employee $employee): ?Employee
    {
        if ($employee === null) {
            return null;
        }

        return $this->activeMembers->firstWhere('id', $employee->id);
    }

    /** Whether an employee is an active member of this task's team. */
    public function isActiveMember(?Employee $employee): bool
    {
        return $this->memberFor($employee) !== null;
    }

    /** Whether any attached service carries a funded-ads campaign budget. */
    public function hasAdBudgetService(): bool
    {
        return $this->services->contains(fn (Service $s) => (bool) $s->requires_ad_budget);
    }

    // ---- Scopes ----

    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value]);
    }

    public function scopeLate($query)
    {
        return $query->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->whereNotIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value]);
    }

    /**
     * Tasks a user may see: everyone with tasks.view; otherwise (tasks.view_own)
     * only tasks where the employee is primary assignee or a co-assignee.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user && $user->can('tasks.view')) {
            return $query;
        }

        $employeeId = $user?->employee?->id;

        if (! $employeeId || ! $user->can('tasks.view_own')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($employeeId) {
            $q->where('primary_assignee_id', $employeeId)
                ->orWhereHas('assignees', fn (Builder $a) => $a->where('employee_id', $employeeId));
        });
    }

    public function isVisibleTo(?User $user): bool
    {
        return static::query()->whereKey($this->getKey())->visibleTo($user)->exists();
    }
}
