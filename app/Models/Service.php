<?php

namespace App\Models;

use App\Enums\ServiceType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Service extends Model
{
    use Auditable;

    /** Financial columns that require services.view_financial to be seen. */
    public const FINANCIAL_FIELDS = ['default_price_usd', 'estimated_cost_ils', 'tax_rate'];

    protected $fillable = [
        'service_code', 'name', 'category', 'description', 'service_type',
        'requires_ad_budget', 'default_price_usd', 'estimated_cost_ils',
        'tax_rate', 'is_active', 'notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'requires_ad_budget' => 'boolean',
        'service_type' => ServiceType::class,
        'default_price_usd' => 'decimal:2',
        'estimated_cost_ils' => 'decimal:2',
        'tax_rate' => 'decimal:2',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function canViewFinancials(?User $user = null): bool
    {
        $user ??= Auth::user();

        return (bool) $user?->can('services.view_financial');
    }

    /**
     * Strip financial fields from array output for users without
     * services.view_financial. This is a backend guard — the money fields never
     * reach an unauthorised serialization, not just a hidden UI column.
     */
    public function toArray(): array
    {
        $array = parent::toArray();

        if (! $this->canViewFinancials()) {
            foreach (self::FINANCIAL_FIELDS as $field) {
                unset($array[$field]);
            }
        }

        return $array;
    }

    /**
     * Safe columns for pickers used in operational (non-financial) contexts.
     *
     * @return list<string>
     */
    public static function pickerColumns(): array
    {
        return ['id', 'service_code', 'name', 'category', 'service_type', 'requires_ad_budget'];
    }
}
