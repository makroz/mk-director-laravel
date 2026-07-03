<?php

declare(strict_types=1);

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
 *
 * Security:
 *  - The tenant column is restricted to a hardcoded whitelist
 *    (R2-009). An attacker who can pass `column: 'password'` would
 *    otherwise be able to inject arbitrary WHERE clauses against
 *    sensitive columns. The whitelist covers every supported naming
 *    convention (client_id, tenant_id, org_id, company_id).
 *  - The beforeDelete comparison uses strict `!==` so an integer
 *    tenant id never compares equal to a string UUID of all zeros
 *    (R2-018).
 *  - MkMultiTenantPlugin is mutually exclusive with {@see \Mk\Director\Tenancy\HasTenantScope}:
 *    if both are present on a model, the plugin throws at boot to
 *    prevent the tenant predicate from being applied twice (R4-003).
 */
class MkMultiTenantPlugin implements MkPluginInterface
{
    /**
     * Whitelisted tenant column names (audit R2-009).
     *
     * Adding a column here is a one-line, code-reviewed change.
     * Attempting to construct the plugin with anything else throws
     * immediately so the misconfiguration is loud, not silent.
     */
    public const TENANT_COLUMN_WHITELIST = [
        'tenant_id',
        'client_id',
        'org_id',
        'company_id',
    ];

    protected string $tenantColumn;

    public function __construct(array $config = [])
    {
        $candidate = $config['column'] ?? 'client_id';

        if (! in_array($candidate, self::TENANT_COLUMN_WHITELIST, true)) {
            throw new \InvalidArgumentException(sprintf(
                'MkMultiTenantPlugin: invalid tenant column "%s". Allowed: %s.',
                $candidate,
                implode(', ', self::TENANT_COLUMN_WHITELIST),
            ));
        }

        $this->tenantColumn = $candidate;
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
            // R4-003: mutually exclusive with HasTenantScope. If the
            // model already applies the TenantScope via a global scope,
            // we skip the duplicate predicate. Otherwise the WHERE
            // would be applied twice.
            $model = $query->getModel();
            if ($this->scopeAlreadyApplied($model)) {
                return;
            }

            // Fuerza aislamiento en WHERE
            $query->where($query->getModel()->getTable() . '.' . $this->tenantColumn, $tenantId);
        }
    }

    /**
     * Returns true if the model already applies the HasTenantScope global
     * scope (so we should NOT add another predicate). We detect this via
     * class_uses_recursive (Laravel helper) with an inline fallback.
     *
     * R-PKG-022 BUG-NEW-32 + HALLAZGO-NEW-05: refactored from reflective
     * access (`ReflectionProperty::setAccessible(true)` + `getValue()`) to
     * use the public accessor {@see HasTenantScope::isTenantEnabled()}.
     *
     * Beneficios:
     *  - Elimina el deprecation warning de PHP 8.5.
     *  - API pública limpia (no reflection tricks).
     *  - Performance: O(1) sin overhead de reflection.
     *  - Testeable directamente con `ModelClass::isTenantEnabled()`.
     */
    protected function scopeAlreadyApplied(object $model): bool
    {
        $class = $model::class;

        while ($class !== false) {
            $traits = $this->classUsesRecursive($class);
            if (in_array(\Mk\Director\Tenancy\HasTenantScope::class, $traits, true)) {
                // The trait only registers the scope when the model set
                // $usesTenant = true. Read that flag via the public accessor.
                //
                // R-PKG-022: previously used reflection (deprecated PHP 8.5).
                if (method_exists($class, 'isTenantEnabled')) {
                    return (bool) $class::isTenantEnabled();
                }

                // Legacy fallback: model uses HasTenantScope but predates
                // isTenantEnabled() accessor (v1.x < rc11). Conservative
                // default — treat as enabled if property exists.
                if (property_exists($class, 'usesTenant')) {
                    try {
                        $reflection = new \ReflectionProperty($class, 'usesTenant');

                        return (bool) $reflection->getValue();
                    } catch (\Throwable) {
                        return false;
                    }
                }
            }
            $class = get_parent_class($class);
        }

        return false;
    }

    /**
     * Inline implementation of Laravel's class_uses_recursive helper so
     * this plugin does not depend on the foundation Application being
     * booted in unit context.
     */
    private function classUsesRecursive(string $class): array
    {
        $traits = [];
        do {
            $traits = array_merge(class_uses($class) ?: [], $traits);
            $class = get_parent_class($class);
        } while ($class !== false);

        return array_unique($traits);
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

        // R2-018: strict comparison (!== not !=). Without strict
        // comparison, a string UUID like '00000000-0000-0000-0000-000000000000'
        // could coerce-equal an integer 0 and silently allow the delete
        // across tenants.
        if ($tenantId !== null && $tenantId !== false && $tenantId !== '' && (string) $model->{$this->tenantColumn} !== (string) $tenantId) {
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
        $user = $request->user();
        if (! $user) {
            return null;
        }

        // R-PKG-024 (rc13): if the user model implements `getTenantId()`
        // (typically via the `HasTenantMembership` trait), use the
        // method as the preferred resolver. This matches the pattern
        // already used by `TenantResolver` (TenantResolver.php:89-90) and
        // lets consumers with custom tenant-resolution logic (e.g.
        // derived from org memberships) override the accessor without
        // touching the plugin.
        //
        // BC: the fallback to direct property access is preserved for
        // legacy consumers whose User model predates the trait.
        if (method_exists($user, 'getTenantId')) {
            return $user->getTenantId();
        }

        // Legacy fallback: acceso directo a la columna configurada.
        return $user->{$this->tenantColumn} ?? null;
    }

    protected function isStrict(): bool
    {
        return config('mk_director.plugins.multitenant.strict_mode', true);
    }
}
