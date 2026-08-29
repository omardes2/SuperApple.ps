<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryLine extends Model
{
    protected $fillable = [
        'journal_entry_id', 'account_id', 'description', 'debit_ils', 'credit_ils',
        'original_currency', 'original_amount', 'exchange_rate',
        'customer_id', 'supplier_id', 'project_id', 'invoice_id', 'payment_id',
        'expense_id', 'supplier_bill_id', 'supplier_payment_id', 'financial_account_id',
    ];

    protected $casts = [
        'debit_ils' => 'decimal:2',
        'credit_ils' => 'decimal:2',
        'original_amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
    ];

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }
}
