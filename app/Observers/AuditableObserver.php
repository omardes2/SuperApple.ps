<?php

namespace App\Observers;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Generic observer that pushes model lifecycle events into the audit log.
 * Attached to any model using the Auditable trait.
 */
class AuditableObserver
{
    public function __construct(protected AuditLogger $logger) {}

    public function created(Model $model): void
    {
        $this->logger->logModelEvent($model, 'created');
    }

    public function updated(Model $model): void
    {
        $this->logger->logModelEvent($model, 'updated');
    }

    public function deleted(Model $model): void
    {
        $this->logger->logModelEvent($model, 'deleted');
    }
}
