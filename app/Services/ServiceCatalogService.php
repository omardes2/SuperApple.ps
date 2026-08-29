<?php

namespace App\Services;

use App\Models\Service;
use Illuminate\Support\Facades\Auth;

/**
 * Service-catalog operations. Price/cost changes are explicitly audited with
 * old/new values (in addition to the generic Auditable trail).
 */
class ServiceCatalogService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public function create(array $data): Service
    {
        $data['service_code'] = $data['service_code'] ?? $this->numbers->next('service');
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        return Service::create($data);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function update(Service $service, array $data): Service
    {
        $priceBefore = $service->default_price_usd;
        $costBefore = $service->estimated_cost_ils;

        $data['updated_by'] = Auth::id();
        $service->update($data);

        $this->auditFinancialChange($service, 'default_price_usd', $priceBefore, 'service_price_changed', 'تغيير سعر الخدمة');
        $this->auditFinancialChange($service, 'estimated_cost_ils', $costBefore, 'service_cost_changed', 'تغيير تكلفة الخدمة');

        return $service;
    }

    private function auditFinancialChange(Service $service, string $field, mixed $before, string $action, string $desc): void
    {
        $after = $service->getAttribute($field);

        if ((string) $before !== (string) $after) {
            $this->audit->log(
                action: $action,
                subject: $service,
                module: 'Services',
                old: [$field => $before],
                new: [$field => $after],
                description: $desc,
            );
        }
    }
}
