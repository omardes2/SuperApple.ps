<?php

namespace App\Models\Concerns;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Opt-in a model into automatic audit logging of create/update/delete.
 * Add `use Auditable;` to any model whose changes must be tracked.
 *
 * Events are registered directly (rather than via Model::observe(), which
 * news up the model and would re-enter the boot process from inside
 * boot<Trait>). Sensitive attributes are stripped by the AuditLogger.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => app(AuditLogger::class)->logModelEvent($model, 'created'));
        static::updated(fn (Model $model) => app(AuditLogger::class)->logModelEvent($model, 'updated'));
        static::deleted(fn (Model $model) => app(AuditLogger::class)->logModelEvent($model, 'deleted'));
    }
}
