<?php

declare(strict_types=1);

namespace Mk\Director\Tenancy;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TenantResolver — HTTP middleware that resolves the current
 * tenant from the request and writes it into the {@see TenantContext}
 * singleton.
 *
 * Spec: MK-LAR-1.0.6 (Capa 4) + proposal M-1.
 *
 * Resolution strategies (selected via `mk_director.tenant.resolver`):
 *  - `header` (default): reads the `X-Tenant-ID` request header
 *    (name configurable via `mk_director.tenant.header_name`).
 *  - `path`: reads the first URI segment, e.g. `/acme/api/...`
 *    treats `acme` as the tenant slug and resolves it to an id
 *    by querying the configured tenant model.
 *  - `subdomain`: takes the leftmost subdomain of the host,
 *    e.g. `acme.example.com` → slug `acme`, resolved to id.
 *
 * Strict mode (default ON): if the resolver is configured and the
 * tenant is missing, the request is rejected with 400. This is
 * safer than silently applying a global scope to "no rows".
 *
 * Usage in a project:
 *  - Set `mk_director.tenant.enabled = true` in `config/mk_director.php`.
 *  - The {@see \Mk\Director\MkServiceProvider} auto-registers this
 *    middleware on the `api` group.
 *  - Add `tenant_id` (indexed, FK to tenants) to the tables you
 *    want scoped, and `use HasTenantScope` on the models.
 */
class TenantResolver
{
    public function __construct(
        protected TenantContext $context,
        protected Config $config,
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // Opt-in: when the feature is disabled the middleware is
        // a pass-through. The provider always registers us on the
        // `api` group so flipping the config at runtime (e.g. in
        // a test) does not require a re-boot.
        if (! $this->config->get('mk_director.tenant.enabled', false)) {
            return $next($request);
        }

        $resolver = (string) $this->config->get('mk_director.tenant.resolver', 'header');
        $strict = (bool) $this->config->get('mk_director.tenant.strict', true);

        $tenantId = match ($resolver) {
            'path' => $this->resolveFromPath($request),
            'subdomain' => $this->resolveFromSubdomain($request),
            default => $this->resolveFromHeader($request),
        };

        if ($tenantId === null) {
            if ($strict) {
                return new JsonResponse([
                    'error' => 'ERR_TENANT_MISSING',
                    'message' => 'Missing tenant context. Provide X-Tenant-ID header.',
                ], 400);
            }
            // Non-strict: leave context null, scope is a no-op.
            return $next($request);
        }

        // R2-004: validate that the request's authenticated user actually
        // belongs to this tenant. Without this check, a token issued for
        // tenant A could access tenant B's data just by sending
        // X-Tenant-ID: <B> on the next request.
        //
        // We use the HasTenantMembership trait (when the user model uses
        // it) to read the tenant id via a single source of truth. Models
        // without the trait are treated as tenant-agnostic — we log a
        // debug message when the trait is missing so consumers notice.
        $user = $request->user();
        if ($user !== null && method_exists($user, 'getTenantId')) {
            $userTenantId = $user->getTenantId();
            if ($userTenantId !== null && (string) $userTenantId !== (string) $tenantId) {
                return new JsonResponse([
                    'error' => 'ERR_TENANT_MISMATCH',
                    'message' => 'Tenant context does not match the authenticated user.',
                ], 403);
            }
        }

        $this->context->set($tenantId);

        return $next($request);
    }

    /**
     * Resolve the tenant id from the configured header.
     */
    protected function resolveFromHeader(Request $request): string|int|null
    {
        $name = (string) $this->config->get('mk_director.tenant.header_name', 'X-Tenant-ID');

        $value = $request->header($name);

        if ($value === null || $value === '') {
            return null;
        }

        return $this->normalize($value);
    }

    /**
     * Resolve the tenant id from the first path segment, looking
     * it up by slug on the configured tenant model.
     */
    protected function resolveFromPath(Request $request): string|int|null
    {
        $segment = $request->segment(1);

        if ($segment === null || $segment === '') {
            return null;
        }

        return $this->resolveSlugToId($segment);
    }

    /**
     * Resolve the tenant id from the leftmost subdomain.
     */
    protected function resolveFromSubdomain(Request $request): string|int|null
    {
        $host = $request->getHost();
        $parts = explode('.', $host);

        // Need at least 2 parts (subdomain.domain.tld). Skip "www".
        if (count($parts) < 3) {
            return null;
        }

        $slug = $parts[0];

        if ($slug === '' || $slug === 'www') {
            return null;
        }

        return $this->resolveSlugToId($slug);
    }

    /**
     * Resolve a tenant slug to its id via the configured tenant
     * model class. Returns null if the model class is not set
     * (caller has not configured the path/subdomain resolver).
     */
    protected function resolveSlugToId(string $slug): string|int|null
    {
        $modelClass = $this->config->get('mk_director.tenant.model');

        if (! is_string($modelClass) || ! class_exists($modelClass)) {
            return null;
        }

        $row = $modelClass::query()->where('slug', $slug)->first();

        return $row?->getKey();
    }

    /**
     * Cast the raw header value to int when it is numeric, otherwise
     * keep it as string (UUIDs, slugs).
     */
    protected function normalize(string $value): string|int
    {
        return ctype_digit($value) ? (int) $value : $value;
    }
}
