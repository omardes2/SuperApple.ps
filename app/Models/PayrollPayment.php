<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollPayment extends Model
{
    use Auditable;

    protected $fillable = [
        'payment_number', 'payroll_run_id', 'payroll_item_id', 'employee_id', 'amount_ils',
        'financial_account_id', 'payment_date', 'reference', 'status',
        'reversed_at', 'reversed_by', 'created_by',
    ];

    protected $casts = [
        'amount_ils' => 'decimal:2',
        'payment_date' => 'date',
        'reversed_at' => 'datetime',
    ];

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PayrollItem::class, 'payroll_item_id');
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', 'posted');
    }
}
