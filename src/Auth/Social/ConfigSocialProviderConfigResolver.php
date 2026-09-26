<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Social;

/**
 * Resolver por defecto: `mk_director.auth.social.providers.{provider}`.
 *
 * `client_ids` acepta un array o un string separado por comas (lo que sale de
 * un `.env`). `enabled = false` apaga el proveedor aunque tenga client ids.
 * Es el mismo valor para todos los scopes: un scope sin la ruta de login
 * social no expone nada, así que el corte por scope es la ruta.
 */
final class ConfigSocialProviderConfigResolver implements SocialProviderConfigResolver
{
    public function clientIds(string $scope, string $provider): array
    {
        $config = config("mk_director.auth.social.providers.{$provider}");

        if (! is_array($config) || ! filter_var($config['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return [];
        }

        $ids = $config['client_ids'] ?? [];
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }

        return array_values(array_filter(array_map('trim', (array) $ids), fn ($id) => $id !== ''));
    }
}
