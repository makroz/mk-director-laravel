<?php

declare(strict_types=1);

namespace Mk\Director\Auth;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Mk\Director\Auth\Middleware\MkAbility;
use Mk\Director\Auth\Middleware\MkAuthenticate;
use Mk\Director\Auth\Services\AbilityResolver;
use Mk\Director\Auth\Services\AuthScopeResolver;
use Mk\Director\Auth\Services\EmailOtpService;
use Mk\Director\Auth\Services\TokenIssuer;
use Mk\Director\Auth\Services\TotpService;

/**
 * Auth subsystem service provider.
 *
 * Registers:
 *  - TokenIssuer (singleton) — issues and revokes Sanctum tokens.
 *  - EmailOtpService (singleton) — generates/verifies email-OTP codes
 *    (2026-07-15-profile-edit-password-otp, ADR-2).
 *  - TotpService (singleton) — TOTP maths for the two-factor flow
 *    (DEVELOPER_GUIDE § 3.20). Stateless: the replay guard lives in the
 *    scope table (`two_factor_last_step`), not in the service.
 *  - AuthScopeResolver — validates that the current token's scope matches
 *    the expected one.
 *  - AbilityResolver (scoped) — cachea el set de abilities por usuario.
 *  - `mk.auth` and `mk.ability` middleware aliases.
 */
class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TokenIssuer::class);
        $this->app->singleton(EmailOtpService::class);
        $this->app->singleton(TotpService::class);
        $this->app->bind(AuthScopeResolver::class, function ($app) {
            return new AuthScopeResolver($app['request']);
        });

        $this->registerAbilityResolver();
    }

    /**
     * 🔴 NADIE REGISTRABA ESTE SERVICIO, Y POR ESO LA CACHÉ DE PERMISOS NO
     * CORRÍA NUNCA (hallazgo 13 del piloto 2).
     *
     * `HasAbilities::abilityResolver()` arranca con
     * `if (! $app->bound(AbilityResolver::class)) return null;`. El guard está
     * puesto para el unit test que no bootea container — pero sin un `bind()` en
     * ningún provider, ese `null` era la respuesta de SIEMPRE, también en
     * producción: `canMk()` caía siempre a `canMkLegacy()`, que es justo el
     * camino con N+1 que el resolver vino a reemplazar (auditoría R4-001), y
     * `invalidateAbilityCache()` era un no-op porque no había nada cacheado.
     *
     * Un fallback silencioso sobre un servicio que nadie registró es
     * indistinguible de un fallback que nunca se usa: no falla, no loguea, y no
     * tiene forma de notarse.
     *
     * ── 🔴 POR QUÉ `scoped()` Y UN `ArrayStore`, NO LA CACHÉ DE LA APP ───────
     *
     * Lo que la auditoría R4-001 midió es un N+1 DENTRO de un request: las
     * policies y la cadena de middleware llaman `canMk()` muchas veces por
     * request y cada llamada volvía a la base. Una caché por request cubre el
     * 100 % de ese problema.
     *
     * Usar la caché compartida de la app cubriría lo mismo y agregaría un
     * problema nuevo: `AbilityResolver::invalidate()` es POR USUARIO, así que
     * cambiarle las abilities a un ROL —lo que hace `PUT /roles/{id}/abilities`,
     * y también cualquier `$role->abilities()->sync()`— no invalida a ninguno de
     * sus usuarios. Con un TTL de 300 s eso son cinco minutos de permisos
     * viejos, sin error y sin rastro. Con un store por request no existe: el
     * request siguiente arranca con la caché vacía.
     *
     * `scoped()` en vez de `singleton()` porque es lo que Octane reinicia entre
     * requests; con `singleton()` un worker de larga vida se quedaría con la
     * caché del primer usuario que atendió.
     *
     * NO se le pasa `setLoader()`: `AbilityResolver::loadFromSource()` ya
     * delega en `getEffectiveAbilities()`, que reusa las relaciones que
     * `MkAuthenticate` deja cargadas. Un loader acá sería una SEGUNDA
     * implementación de la misma regla de autorización, que es exactamente la
     * divergencia que el docblock de ese método documenta.
     */
    private function registerAbilityResolver(): void
    {
        $this->app->scoped(AbilityResolver::class, function (): AbilityResolver {
            return new AbilityResolver(new CacheRepository(new ArrayStore));
        });
    }

    public function boot(): void
    {
        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('mk.auth', MkAuthenticate::class);
        $router->aliasMiddleware('mk.ability', MkAbility::class);
    }
}
