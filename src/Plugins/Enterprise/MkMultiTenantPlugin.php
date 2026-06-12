<?php

namespace Mk\Director\Plugins\Enterprise;

use Mk\Director\Contracts\MkPluginInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * MkMultiTenantPlugin
 * 
 * Garantiza que todos los CRUDS ejecutados en SmartControllers estén 
 * absolutamente blindados al `tenant_id` del token JWT/Session sin 
 * depender en Global Scopes nativos (que los devs pueden olvidar aplicar).
 */
class MkMultiTenantPlugin implements MkPluginInterface
{
    protected string $tenantColumn;
    
    public function __construct(array $config = [])
    {
        $this->tenantColumn = $config['column'] ?? 'client_id';
    }

    public function boot(): void
    {
        //
    }

    public function getRequirements(): array
    {
        return [
            $this->tenantColumn => 'Required for Multi-Tenant routing isolation'
        ];
    }

    public function beforeQuery(Builder $query, Request $request): void
    {
        $tenantId = $this->resolveTenantId($request);
        
        if (!$tenantId && $this->isStrict()) {
            throw new UnauthorizedHttpException('Tenant Isolation Error. No context found.');
        }

        if ($tenantId) {
            // Fuerza aislamiento en WHERE
            $query->where($query->getModel()->getTable() . '.' . $this->tenantColumn, $tenantId);
        }
    }

    public function beforeSave(Request $request, array &$data, string $mode): void
    {
        // Fuerza el ID del tenant en el payload de creación
        $tenantId = $this->resolveTenantId($request);
        
        if ($tenantId) {
            $data[$this->tenantColumn] = $tenantId;
        }
    }

    public function afterSave($model, Request $request, string $mode): void
    {
        //
    }

    public function beforeDelete($model, Request $request): void
    {
        // Validar que el recurso a eliminar pertenezca al Tenant
        $tenantId = $this->resolveTenantId($request);
        
        if ($tenantId && $model->{$this->tenantColumn} != $tenantId) {
            throw new UnauthorizedHttpException('Tenant Isolation Breach Detected during DELETE.');
        }
    }

    public function afterDelete($model, Request $request): void
    {
        //
    }

    public function afterResponse(&$responseData): void
    {
        //
    }

    protected function resolveTenantId(Request $request): mixed
    {
        // Soporta JWT Auth o Session Auth asumiendo convención 'client_id' 
        return $request->user()?->{$this->tenantColumn} ?? null;
    }

    protected function isStrict(): bool
    {
        return config('mk_director.plugins.multitenant.strict_mode', true);
    }
}
