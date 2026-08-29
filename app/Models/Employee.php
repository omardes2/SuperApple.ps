<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use App\Enums\EmploymentType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use Auditable;

    protected $fillable = [
        'employee_number', 'user_id', 'full_name', 'phone', 'job_title',
        'department_id', 'direct_manager_id', 'hire_date', 'employment_status',
        'employment_type', 'working_hours_per_day', 'notes', 'is_active',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'is_active' => 'boolean',
        'working_hours_per_day' => 'decimal:2',
        'employment_status' => EmploymentStatus::class,
        'employment_type' => EmploymentType::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function directManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'direct_manager_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'direct_manager_id');
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function salaryProfiles(): HasMany
    {
        return $this->hasMany(EmployeeSalaryProfile::class);
    }

    public function salaryAdjustments(): HasMany
    {
        return $this->hasMany(SalaryAdjustment::class);
    }

    public function advances(): HasMany
    {
        return $this->hasMany(EmployeeAdvance::class);
    }

    public function payrollItems(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    /** The salary profile in effect on a given date, if any. */
    public function salaryProfileOn(string $date): ?EmployeeSalaryProfile
    {
        return $this->salaryProfiles()->effectiveOn($date)->first();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
