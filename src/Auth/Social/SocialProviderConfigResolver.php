<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Social;

/**
 * De dónde salen, AL MOMENTO DEL LOGIN, los client ids que un scope acepta
 * para un proveedor.
 *
 * Es un contrato y no sólo config porque el consumer puede guardar las
 * credenciales en SU base y prender o apagar cada método desde una consola:
 * leerlas de `config()` al bootear dejaría un proveedor apagado aceptando
 * tokens hasta el próximo deploy.
 *
 * El default ({@see ConfigSocialProviderConfigResolver}) lee
 * `mk_director.auth.social.providers.*`. Para reemplazarlo, bindeá tu
 * implementación en un provider del consumer:
 *
 *     $this->app->bind(SocialProviderConfigResolver::class, DbSocialProviderConfigResolver::class);
 */
interface SocialProviderConfigResolver
{
    /**
     * Las audiencias (`aud`) aceptadas para `$provider` en `$scope`.
     *
     *  - Google: los client ids OAuth (web, iOS, Android) que pueden pedir el token.
     *  - Apple: el Services ID (web) y los bundle ids de las apps.
     *
     * 🔴 Lista vacía = el proveedor está APAGADO para ese scope: el login
     * responde 403 sin mirar el token. Nunca devuelvas un comodín: la audiencia
     * es lo que impide que un token emitido para la app de OTRO (cualquier app
     * con «Iniciar sesión con Google») sirva para entrar a la tuya.
     *
     * @return list<string>
     */
    public function clientIds(string $scope, string $provider): array;
}
