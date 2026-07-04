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

        // LAR-05 (2026-07-03 audit): read `strict` via filter_var. The
        // previous `(bool) $config->get(...)` was a footgun — `(bool) 'false'`
        // is true in PHP, so a consumer that pinned `MK_TENANT_STRICT=false`
        // in `.env` got the OPPOSITE of what they asked for (still strict,
        // because the default is true and `'false'` is truthy as a string).
        // filter_var with FILTER_VALIDATE_BOOLEAN handles 'false'/'0'/'no'
        // correctly. Same pattern as auth.refresh.rotate_on_refresh (F1.2)
        // and openapi.enabled (F1.4).
        $strict = filter_var(
            $this->config->get('mk_director.tenant.strict', true),
            FILTER_VALIDATE_BOOLEAN
        );

        $tenantId = match ($resolver) {
            'path' => $this->resolveFromPath($request),
            'subdomain' => $this->resolveFromSubdomain($request),
            default => $this->resolveFromHeader($request),
        };

        if ($tenantId === null) {
            if ($strict) {
                return $this->canonicalErrorResponse(
                    400,
                    'ERR_TENANT_MISSING',
                    'Missing tenant context. Provide X-Tenant-ID header.',
                );
            }
            // Non-strict: leave context null, scope is a no-op.
            return $next($request);
        }

        // R2-004: validate that the request's authenticated user actually
        // belongs to this tenant. Without this check, a token issued for
        // tenant A could access tenant B's data just by sending
        // X-Tenant-ID: <B> on the next request.
        //
        // LAR-05: in strict mode, a user without the HasTenantMembership
        // trait (no `getTenantId()` method) used to silently pass the
        // membership gate. Now, in strict mode, that case is rejected
        // unless the request path is in `tenant.allowlist_routes` (auth
        // endpoints, public routes the consumer declared). This closes
        // the loophole where a consumer forgot to add the trait to their
        // User model and accidentally bypassed tenant isolation.
        $user = $request->user();
        if ($user !== null) {
            if (method_exists($user, 'getTenantId')) {
                $userTenantId = $user->getTenantId();
                if ($userTenantId !== null && (string) $userTenantId !== (string) $tenantId) {
                    return $this->canonicalErrorResponse(
                        403,
                        'ERR_TENANT_MISMATCH',
                        'Tenant context does not match the authenticated user.',
                    );
                }
            } elseif ($strict && ! $this->isAllowlisted($request)) {
                return $this->canonicalErrorResponse(
                    403,
                    'ERR_TENANT_MEMBERSHIP_REQUIRED',
                    'Authenticated user has no tenant membership. Add HasTenantMembership to your User model or add this route to tenant.allowlist_routes.',
                );
            }
        }

        $this->context->set($tenantId);

        return $next($request);
    }

    /**
     * Build a canonical single-level-envelope error response (R-PKG-024 +
     * LAR-09 R-PKG-044) consistent with `BaseController::sendError()`,
     * `MkAbility::errorResponse()` and `MkAuthenticate`. The `__extraData.code`
     * is the machine-readable identifier the frontend branches on.
     */
    protected function canonicalErrorResponse(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $message,
            'data' => null,
            '__extraData' => [
                'code' => $code,
            ],
            'debugMsg' => [],
        ], $status);
    }

    /**
     * LAR-05: is the current request path exempt from the strict
     * tenant-membership gate? Returns true when the request matches
     * any of the patterns in `tenant.allowlist_routes`.
     *
     * Default patterns are the package's auth endpoints (login/refresh/
     * forgot/reset) because a user authenticating has not yet proven
     * tenant membership — that's the whole point of the flow. Consumers
     * can add additional public routes (e.g. /api/webhooks/*) in
     * `config/mk_director.php` after publishing.
     */
    protected function isAllowlisted(Request $request): bool
    {
        $patterns = (array) $this->config->get('mk_director.tenant.allowlist_routes', [
            'api/*/auth/login',
            'api/*/auth/refresh',
            'api/*/auth/forgot',
            'api/*/auth/reset',
        ]);

        $path = trim($request->path(), '/');

        foreach ($patterns as $pattern) {
            $regex = '#^' . str_replace('\*', '[^/]+', preg_quote((string) $pattern, '#')) . '$#';

            if (preg_match($regex, $path) === 1) {
                return true;
            }
        }

        return false;
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
