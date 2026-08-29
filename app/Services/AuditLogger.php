<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Central writer for the audit trail. Every important action — model
 * create/update/delete and explicit financial events — is recorded here.
 */
class AuditLogger
{
    /**
     * Record an arbitrary action.
     *
     * @param  array<string,mixed>|null  $old
     * @param  array<string,mixed>|null  $new
     */
    public function log(
        string $action,
        ?Model $subject = null,
        ?string $module = null,
        ?array $old = null,
        ?array $new = null,
        ?string $description = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'module' => $module ?? ($subject ? class_basename($subject) : null),
            'auditable_type' => $subject ? $subject->getMorphClass() : null,
            'auditable_id' => $subject?->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'description' => $description,
            'ip_address' => Request::ip(),
            'user_agent' => (string) Request::userAgent(),
            'url' => Request::fullUrl(),
            'created_at' => now(),
        ]);
    }

    /**
     * Convenience for model lifecycle events driven by the observer.
     */
    public function logModelEvent(Model $model, string $event): void
    {
        [$old, $new] = match ($event) {
            'created' => [null, $this->clean($model, $model->getAttributes())],
            'updated' => [
                $this->clean($model, array_intersect_key($model->getRawOriginal(), $model->getChanges())),
                $this->clean($model, $model->getChanges()),
            ],
            'deleted' => [$this->clean($model, $model->getAttributes()), null],
            default => [null, null],
        };

        // Skip pure timestamp-only updates.
        if ($event === 'updated' && empty($new)) {
            return;
        }

        $this->log(
            action: $event,
            subject: $model,
            old: $old,
            new: $new,
        );
    }

    /**
     * Remove hidden/sensitive attributes (e.g. password) before storing.
     *
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    protected function clean(Model $model, array $attributes): array
    {
        $hidden = array_merge($model->getHidden(), ['password', 'remember_token']);

        return collect($attributes)
            ->except($hidden)
            ->all();
    }
}
