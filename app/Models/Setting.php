<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['group', 'key', 'value', 'type'];

    /**
     * Cast the stored string value to its declared type.
     */
    public function typedValue(): mixed
    {
        return match ($this->type) {
            'int' => (int) $this->value,
            'decimal' => (float) $this->value,
            'bool' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode((string) $this->value, true),
            default => $this->value,
        };
    }
}
