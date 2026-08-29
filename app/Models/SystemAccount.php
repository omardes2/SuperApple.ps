<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps a stable business key (SystemAccountKey) to a chart account, so posting
 * logic resolves accounts by key rather than by a hard-coded code.
 */
class SystemAccount extends Model
{
    protected $fillable = ['key', 'account_id'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
