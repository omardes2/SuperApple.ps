<?php

namespace App\Models;

use App\Enums\Priority;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use Auditable, HasAttachments;

    protected $fillable = [
        'project_number', 'customer_id', 'name', 'description', 'project_type',
        'project_manager_id', 'department_id', 'priority', 'status',
        'start_date', 'due_date', 'completed_at', 'notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'priority' => Priority::class,
        'status' => ProjectStatus::class,
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function projectManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'project_manager_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'project_members')
            ->withPivot('role', 'joined_at')
            ->withTimestamps();
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Progress derived from tasks: completed / (all non-cancelled). Never stored.
     */
    public function progress(): int
    {
        $tasks = $this->relationLoaded('tasks') ? $this->tasks : $this->tasks()->get();
        $counted = $tasks->where('status', '!=', TaskStatus::Cancelled);

        if ($counted->isEmpty()) {
            return 0;
        }

        $completed = $counted->where('status', TaskStatus::Completed)->count();

        return (int) round($completed / $counted->count() * 100);
    }

    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', [ProjectStatus::Completed->value, ProjectStatus::Cancelled->value]);
    }

    public function isLate(): bool
    {
        return $this->due_date
            && $this->due_date->isPast()
            && $this->status->isOpen();
    }

    /**
     * Projects a user may see: everyone with projects.view; otherwise only
     * projects the employee is a member of or has a task in.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user && $user->can('projects.view')) {
            return $query;
        }

        $employeeId = $user?->employee?->id;

        if (! $employeeId || ! $user->can('projects.view_assigned')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($employeeId) {
            $q->whereHas('memberships', fn (Builder $m) => $m->where('employee_id', $employeeId))
                ->orWhereHas('tasks', fn (Builder $t) => $t->where('primary_assignee_id', $employeeId)
                    ->orWhereHas('assignees', fn (Builder $a) => $a->where('employee_id', $employeeId)));
        });
    }

    public function isVisibleTo(?User $user): bool
    {
        return static::query()->whereKey($this->getKey())->visibleTo($user)->exists();
    }
}
