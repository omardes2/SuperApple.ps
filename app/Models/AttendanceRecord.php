<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    protected $fillable = [
        'employee_id', 'attendance_date', 'check_in_at', 'check_out_at',
        'worked_minutes', 'late_minutes', 'overtime_minutes', 'status',
        'check_in_source', 'check_out_source', 'notes', 'meta',
        'approved_by', 'approved_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
        'approved_at' => 'datetime',
        'worked_minutes' => 'integer',
        'late_minutes' => 'integer',
        'overtime_minutes' => 'integer',
        'meta' => 'array',
        'status' => AttendanceStatus::class,
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function workedHoursLabel(): string
    {
        $h = intdiv($this->worked_minutes, 60);
        $m = $this->worked_minutes % 60;

        return sprintf('%02d:%02d', $h, $m);
    }
}
