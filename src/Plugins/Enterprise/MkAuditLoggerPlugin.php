<?php

namespace Mk\Director\Plugins\Enterprise;

use Mk\Director\Contracts\MkPluginInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MkAuditLoggerPlugin
 * 
 * Intercepta y registra todas las mutaciones realizadas a través de SmartController
 * para generar un rastro de auditoría B2B Inmutable.
 */
class MkAuditLoggerPlugin implements MkPluginInterface
{
    protected array $config = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function boot(): void
    {
        // Initialization if needed
    }

    public function getRequirements(): array
    {
        return [];
    }

    public function beforeQuery(Builder $query, Request $request): void
    {
        // No auditamos lecturas por temas de performance (configurable)
    }

    public function beforeSave(Request $request, array &$data, string $mode): void
    {
        // Capturamos el intent
        $this->config['intent_payload'] = $data;
    }

    public function afterSave($model, Request $request, string $mode): void
    {
        $this->logAction($mode, $model, $request);
    }

    public function beforeDelete($model, Request $request): void
    {
        // Capturar estado antes de morir
        $this->config['dead_payload'] = $model->toArray();
    }

    public function afterDelete($model, Request $request): void
    {
        $this->logAction('delete', $model, $request, $this->config['dead_payload'] ?? []);
    }

    public function afterResponse(&$responseData): void
    {
        //
    }

    protected function logAction(string $action, $model, Request $request, array $extraContext = [])
    {
        // En una app real esto insertaría en DB `audit_logs`. Por ahora, simula inserción
        $logData = [
            'tenant_id' => $request->user()?->client_id ?? null,
            'user_id' => $request->user()?->id ?? 'system',
            'action' => $action,
            'resource_type' => get_class($model),
            'resource_id' => $model->id,
            'ip_address' => $request->ip(),
            'payload' => $this->config['intent_payload'] ?? $extraContext,
            'created_at' => now(),
        ];

        // Guardado persistente (ej: config option para DB connect o Log file)
        Log::channel('audit')->info("MK Audit [{$action}]: " . get_class($model), $logData);
    }
}
