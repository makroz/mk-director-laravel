<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Social;

/**
 * De dónde sale, al momento de usarla, la clave de Apple de un scope.
 *
 * Un contrato aparte de {@see SocialProviderConfigResolver} para no romper a
 * quien ya lo implementa. El default ({@see ConfigAppleSigningKeyResolver}) lee
 * `mk_director.auth.social.providers.apple.{team_id,key_id,private_key}`; un
 * consumer que la guarda en su base bindea el suyo.
 *
 * `null` = sin clave: el login con Apple no canjea el código y no hay token
 * que revocar. Es el estado por defecto.
 */
interface AppleSigningKeyResolver
{
    public function forScope(string $scope): ?AppleSigningKey;
}
