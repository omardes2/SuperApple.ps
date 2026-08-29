<?php

namespace App\Models;

use App\Enums\CustomerSource;
use App\Enums\CustomerStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use Auditable, HasAttachments;

    protected $fillable = [
        'customer_number', 'name', 'contact_person', 'phone', 'whatsapp_number',
        'city', 'address', 'tax_number', 'customer_category_id', 'status',
        'source', 'notes', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'status' => CustomerStatus::class,
        'source' => CustomerSource::class,
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(CustomerCategory::class, 'customer_category_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Restrict a query to customers a user is allowed to see. Users with
     * customers.view see everyone; otherwise only customers linked to a project
     * the employee is a member of, or a task assigned to them.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user && $user->can('customers.view')) {
            return $query;
        }

        $employeeId = $user?->employee?->id;

        if (! $employeeId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($employeeId) {
            $q->whereHas('projects', fn (Builder $p) => $p->whereHas('members', fn (Builder $m) => $m->where('employee_id', $employeeId)))
                ->orWhereHas('tasks', fn (Builder $t) => $t->where('primary_assignee_id', $employeeId)
                    ->orWhereHas('assignees', fn (Builder $a) => $a->where('employee_id', $employeeId)));
        });
    }
}
