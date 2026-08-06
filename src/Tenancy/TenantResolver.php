<?php

declare(strict_types=1);

namespace Mk\Director\Tenancy;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mk\Director\Auth\Middleware\MkAuthenticate;
use Mk\Director\MkServiceProvider;

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
 * 🔴 DÓNDE VIVE LA VALIDACIÓN DE MEMBRESÍA (y por qué NO acá).
 *
 * Este middleware va en el grupo `api`, y en Laravel el middleware de GRUPO
 * corre ANTES que el de RUTA — o sea, antes de `mk.auth:{scope}`. En este
 * punto Sanctum todavía no resolvió el token: `$request->user()` sale por el
 * guard default (`web`) y devuelve `null`.
 *
 * Durante mucho tiempo la validación de membresía vivió acá, entera adentro de
 * un `if ($user !== null)`. Como ese `if` nunca se cumplía en el cableado por
 * defecto, el tenant se tomaba del header SIN VERIFICAR: un admin del tenant A
 * mandaba `X-Tenant-ID: <B>` y recibía 200 con los datos de B.
 *
 * La regla se mudó a {@see TenantMembershipGate} y la invoca
 * {@see MkAuthenticate} justo después de resolver
 * el usuario. Acá se sigue llamando de forma OPORTUNISTA para el cableado
 * alternativo (resolver registrado como middleware de ruta, después de
 * `mk.auth`), pero la garantía real es la otra.
 *
 * Usage in a project:
 *  - Set `mk_director.tenant.enabled = true` in `config/mk_director.php`.
 *  - The {@see MkServiceProvider} auto-registers this
 *    middleware on the `api` group.
 *  - Add `tenant_id` (indexed, FK to tenants) to the tables you
 *    want scoped, and `use HasTenantScope` on the models.
 */
class TenantResolver
{
    protected TenantMembershipGate $gate;

    public function __construct(
        protected TenantContext $context,
        protected Config $config,
        ?TenantMembershipGate $gate = null,
    ) {
        // El gate se puede inyectar, pero por default se arma con las MISMAS
        // dependencias que ya tiene el resolver. Así `new TenantResolver($ctx,
        // $config)` (la firma histórica, usada por consumers y tests) sigue
        // funcionando sin que el gate quede en null y se saltee en silencio.
        $this->gate = $gate ?? new TenantMembershipGate($context, $config);
    }

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

        // R2-004: validar que el usuario autenticado pertenezca a este tenant.
        //
        // 🔴 ACÁ ESTO ES OPORTUNISTA, NO LA GARANTÍA. En el cableado por
        // defecto (`pushMiddlewareToGroup('api', ...)`) este middleware corre
        // ANTES de `mk.auth:{scope}`, así que `$request->user()` devuelve null
        // y el gate no tiene a quién validar. La garantía la da
        // {@see \Mk\Director\Auth\Middleware\MkAuthenticate}, que llama al
        // MISMO gate apenas resuelve el usuario. Se conserva la llamada acá
        // para el cableado alternativo: resolver registrado como middleware de
        // RUTA, después de `mk.auth`.
        if ($denied = $this->gate->check($request, $request->user(), $tenantId)) {
            return $denied;
        }

        $this->context->set($tenantId);

        return $next($request);
    }

    /**
     * Envelope canónico de error (R-PKG-024 + LAR-09 R-PKG-044), consistente
     * con `BaseController::sendError()`, `MkAbility::errorResponse()` y
     * `MkAuthenticate`. El `__extraData.code` es el identificador legible por
     * máquina sobre el que ramifica el frontend.
     *
     * Delega en {@see TenantMembershipGate} para que exista UNA sola definición
     * del envelope de tenancy: dos copias divergen, y cuando divergen el
     * frontend ramifica sobre un `code` que sólo aparece en la mitad de los
     * caminos.
     */
    protected function canonicalErrorResponse(int $status, string $code, string $message): JsonResponse
    {
        return $this->gate->errorResponse($status, $code, $message);
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
