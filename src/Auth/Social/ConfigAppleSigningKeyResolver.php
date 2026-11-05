<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Social;

/** La clave de Apple desde `config()`; sin las tres partes, `null`. */
final class ConfigAppleSigningKeyResolver implements AppleSigningKeyResolver
{
    public function forScope(string $scope): ?AppleSigningKey
    {
        $apple = (array) config('mk_director.auth.social.providers.apple', []);
        $teamId = trim((string) ($apple['team_id'] ?? ''));
        $keyId = trim((string) ($apple['key_id'] ?? ''));
        $privateKey = trim((string) ($apple['private_key'] ?? ''));

        return $teamId === '' || $keyId === '' || $privateKey === ''
            ? null
            : new AppleSigningKey($teamId, $keyId, $privateKey);
    }
}
