<?php

declare(strict_types=1);

namespace Mk\Director\Console\Concerns;

use Illuminate\Support\Str;

/**
 * Resuelve el FQCN del modelo de un scope de auth, por el mismo camino que usa
 * `mk.auth:{scope}` en cada request: `auth.guards.{scope}.provider` →
 * `auth.providers.{provider}.model`, que es exactamente lo que el scaffolder
 * cablea en `config/auth.php`.
 *
 * Vive en un trait porque tres comandos de consola necesitan la misma
 * respuesta —`mk:auth:create-super-admin`, `mk:auth:two-factor-reset` y
 * `mk:auth:grant`— y las dos primeras ya tenían el método copiado palabra por
 * palabra. Una tercera copia era el momento de dejar de copiar: si el día de
 * mañana cambia el camino por el que se resuelve un scope, tiene que cambiar
 * en un solo lugar o los comandos empiezan a contestar distinto entre ellos.
 */
trait ResolvesScopeModel
{
    /**
     * FQCN del modelo del scope. Cae a la convención del scaffolder
     * (`App\Modules\{Scope}\Models\{Scope}`) si el guard no está cableado en
     * `config/auth.php`: quien llama verifica que la clase exista y falla con
     * un mensaje que dice cómo generar el scope.
     */
    protected function resolveScopeModel(string $scope): string
    {
        try {
            $provider = function_exists('config') ? config("auth.guards.{$scope}.provider") : null;
            $model = is_string($provider) ? config("auth.providers.{$provider}.model") : null;
        } catch (\Throwable) {
            $model = null;
        }

        if (is_string($model) && $model !== '') {
            return $model;
        }

        $studly = Str::studly($scope);

        return "App\\Modules\\{$studly}\\Models\\{$studly}";
    }

    /**
     * Modelos de todos los guards cableados, más el Admin por convención (BC).
     *
     * @return array<int, string>
     */
    protected function candidateScopeModels(): array
    {
        $models = ['App\\Modules\\Admin\\Models\\Admin'];

        try {
            $guards = function_exists('config') ? (array) config('auth.guards', []) : [];
        } catch (\Throwable) {
            $guards = [];
        }

        foreach (array_keys($guards) as $guard) {
            $models[] = $this->resolveScopeModel((string) $guard);
        }

        return array_values(array_unique($models));
    }
}
