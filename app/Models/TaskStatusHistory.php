<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskStatusHistory extends Model
{
    protected $table = 'task_status_history';

    public const UPDATED_AT = null;

    protected $fillable = ['task_id', 'from_status', 'to_status', 'changed_by', 'reason', 'created_at'];

    protected $casts = [
        'created_at' => 'datetime',
        'from_status' => TaskStatus::class,
        'to_status' => TaskStatus::class,
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
