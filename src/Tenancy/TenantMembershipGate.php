<?php

declare(strict_types=1);

namespace Mk\Director\Tenancy;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mk\Director\Auth\Middleware\MkAuthenticate;

/**
 * TenantMembershipGate — la única regla de "¿este usuario pertenece al tenant
 * que dice el request?", extraída para poder invocarla desde el ÚNICO lugar
 * donde el usuario ya existe: el middleware de autenticación.
 *
 * 🔴 POR QUÉ NO ALCANZABA CON DEJARLA ADENTRO DE `TenantResolver`.
 *
 * `MkServiceProvider` registra `TenantResolver` en el grupo `api`, y en Laravel
 * el middleware de GRUPO corre ANTES que el de RUTA. La autenticación
 * (`mk.auth:{scope}`) es de ruta. Así que cuando el resolver preguntaba
 * `$request->user()`, Sanctum todavía no había mirado el token: ese `user()`
 * sin argumento sale por el guard DEFAULT (`web`) y devuelve `null`. La
 * validación entera estaba adentro de `if ($user !== null)` y no se ejecutaba
 * jamás. Un admin del tenant A mandaba `X-Tenant-ID: <B>` y recibía 200 con
 * los datos de B.
 *
 * El detalle que importa para no repetir el error: durante meses se ENDURECIÓ
 * esa validación (se agregó la rama `ERR_TENANT_MEMBERSHIP_REQUIRED` "para
 * cerrar el agujero del consumer que olvidó el trait") sin notar que el camino
 * no llegaba hasta ahí. Mejorar un chequeo que no corre no cambia nada. Por eso
 * el arreglo no es una validación mejor: es garantizar el MOMENTO en que corre.
 *
 * De ahí que la regla viva acá y la llamen DOS lugares:
 *  1. {@see MkAuthenticate} — inmediatamente
 *     después de resolver el usuario y ANTES de `$next($request)`. Es la
 *     garantía ESTRUCTURAL: corre en la misma función que produce el `$user`,
 *     así que no hay orden de middleware que pueda saltearla, y corre antes del
 *     controller (un 403 emitido después de `$next()` llegaría tarde: un POST
 *     ya habría escrito en el tenant ajeno).
 *  2. {@see TenantResolver} — de forma oportunista, por si el consumer cableó
 *     el resolver como middleware de RUTA después de `mk.auth`, o por si el
 *     usuario ya viene resuelto por otro medio.
 *
 * Cuando `mk_director.tenant.enabled` es false, el gate es un no-op — las apps
 * single-tenant no ven ningún cambio.
 */
class TenantMembershipGate
{
    public function __construct(
        protected TenantContext $context,
        protected Config $config,
    ) {}

    /**
     * Devuelve la respuesta 403 cuando el usuario NO pertenece al tenant
     * activo, o `null` cuando el request puede seguir.
     *
     * @param  string|int|null  $tenantId  tenant a validar; si es null se toma
     *                                     del {@see TenantContext}.
     */
    public function check(Request $request, ?Authenticatable $user, string|int|null $tenantId = null): ?JsonResponse
    {
        if (! $this->enabled()) {
            return null;
        }

        $tenantId ??= $this->context->current();

        // Sin tenant no hay nada que comparar. El caso "falta el tenant y
        // estamos en strict" lo resuelve TenantResolver con un 400 antes de
        // llegar acá; duplicarlo desde el gate rompería las rutas públicas.
        if ($tenantId === null) {
            return null;
        }

        // Sin usuario autenticado no hay membresía que verificar (ruta pública).
        if ($user === null) {
            return null;
        }

        // R2-004: el token del tenant A no puede operar sobre el tenant B.
        if (method_exists($user, 'getTenantId')) {
            $userTenantId = $user->getTenantId();

            if ($userTenantId !== null && (string) $userTenantId !== (string) $tenantId) {
                return $this->errorResponse(
                    403,
                    'ERR_TENANT_MISMATCH',
                    'Tenant context does not match the authenticated user.',
                );
            }

            return null;
        }

        // LAR-05: un usuario autenticado SIN el trait HasTenantMembership no
        // puede probar membresía. En strict mode eso se rechaza, salvo que la
        // ruta esté en `tenant.allowlist_routes` (login, refresh, etc: el que
        // se está autenticando todavía no probó nada, es el punto del flujo).
        if ($this->strict() && ! $this->isAllowlisted($request)) {
            return $this->errorResponse(
                403,
                'ERR_TENANT_MEMBERSHIP_REQUIRED',
                'Authenticated user has no tenant membership. Add HasTenantMembership to your User model or add this route to tenant.allowlist_routes.',
            );
        }

        return null;
    }

    public function enabled(): bool
    {
        return filter_var(
            $this->config->get('mk_director.tenant.enabled', false),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * LAR-05: `strict` se lee con filter_var y no con `(bool)`. `(bool) 'false'`
     * es TRUE en PHP, así que un consumer que pineó `MK_TENANT_STRICT=false` en
     * `.env` obtenía lo contrario de lo que pidió.
     */
    public function strict(): bool
    {
        return filter_var(
            $this->config->get('mk_director.tenant.strict', true),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Envelope canónico de error (R-PKG-024 + LAR-09 R-PKG-044), consistente
     * con `BaseController::sendError()`, `MkAbility::errorResponse()` y
     * `MkAuthenticate`. El `__extraData.code` es el identificador legible por
     * máquina sobre el que ramifica el frontend.
     */
    public function errorResponse(int $status, string $code, string $message): JsonResponse
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
     * ¿El path del request está exento del gate estricto de membresía?
     *
     * Los patrones default son los endpoints de auth del paquete, porque un
     * usuario que se está autenticando todavía no probó pertenecer a ningún
     * tenant. El consumer puede sumar los suyos (webhooks, health checks) en
     * `config/mk_director.php`.
     */
    public function isAllowlisted(Request $request): bool
    {
        $patterns = (array) $this->config->get('mk_director.tenant.allowlist_routes', [
            'api/*/auth/login',
            'api/*/auth/refresh',
            'api/*/auth/forgot',
            'api/*/auth/reset',
        ]);

        $path = trim($request->path(), '/');

        foreach ($patterns as $pattern) {
            $regex = '#^'.str_replace('\*', '[^/]+', preg_quote((string) $pattern, '#')).'$#';

            if (preg_match($regex, $path) === 1) {
                return true;
            }
        }

        return false;
    }
}
