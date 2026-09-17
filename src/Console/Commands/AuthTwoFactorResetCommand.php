<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Mk\Director\Auth\Events\AuthEvent;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Console\Concerns\ResolvesScopeModel;

/**
 * `php artisan mk:auth:two-factor-reset {scope} {login}` — le saca el segundo
 * factor a un usuario.
 *
 * 🔴 POR QUÉ TIENE QUE EXISTIR ESTE COMANDO.
 *
 * Con la política `required`, un usuario que perdió el teléfono y se quedó sin
 * códigos de recuperación NO tiene salida por la API: el login le devuelve un
 * desafío que no puede contestar, y `two-factor/disable` responde 403 porque el
 * scope exige el segundo factor. Sin este comando la única salida sería un
 * `UPDATE` a mano en producción — que es exactamente la clase de operación que
 * termina borrando la columna equivocada.
 *
 * El modelo se resuelve por el mismo camino que `mk.auth:{scope}`:
 * `auth.guards.{scope}.provider` → `auth.providers.{provider}.model`, con la
 * convención `App\Modules\{Scope}\Models\{Scope}` como respaldo.
 *
 * Es IDEMPOTENTE: un usuario que ya no tenía segundo factor sale por
 * `SUCCESS` diciendo que no había nada que sacar. Y pide confirmación salvo que
 * se pase `--force` (scripts, CI).
 *
 * Además de limpiar las cuatro columnas, CIERRA TODAS LAS SESIONES del usuario:
 * si alguien entró con el dispositivo perdido, sacarle el enrolamiento sin
 * cortarle el token lo dejaría adentro.
 */
class AuthTwoFactorResetCommand extends Command
{
    use ResolvesScopeModel;

    protected $signature = 'mk:auth:two-factor-reset
        {scope : Scope de auth del usuario, en snake_case (ej: operator, admin)}
        {login : Valor del campo de login del usuario (email, ci, username… según el scope)}
        {--force : No preguntar. Para scripts y CI.}
        {--keep-sessions : No cerrar las sesiones vivas del usuario. Sólo si sabés que el dispositivo perdido no tiene ninguna.}';

    protected $description = 'Le saca el segundo factor (TOTP) a un usuario que perdió su dispositivo: limpia las cuatro columnas y cierra sus sesiones. Idempotente.';

    public function handle(): int
    {
        $scope = strtolower(trim((string) $this->argument('scope')));
        $login = trim((string) $this->argument('login'));

        if (! preg_match('/^[a-z][a-z0-9_]*$/', $scope)) {
            $this->error("El scope debe ser snake_case (ej: operator). Recibido: '{$scope}'.");

            return self::FAILURE;
        }

        if ($login === '') {
            $this->error('Falta el valor del campo de login del usuario.');

            return self::FAILURE;
        }

        $modelClass = $this->resolveScopeModel($scope);
        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, AuthUser::class)) {
            $this->error("No se encontró el modelo del scope '{$scope}' ({$modelClass}).");
            $this->line('Generá el scope con `php artisan mk:make:auth-user '.Str::studly($scope).'` o cableá su guard en config/auth.php.');

            return self::FAILURE;
        }

        $loginField = (new $modelClass)->getLoginField();

        // Sin global scopes: en consola no hay tenant ni usuario, y con
        // `tenant.fail_closed` el scope agrega `where 1 = 0` — el usuario existe
        // y la búsqueda no lo ve.
        /** @var AuthUser|null $user */
        $user = $modelClass::withoutGlobalScopes()->where($loginField, $login)->first();

        if (! $user) {
            $this->error("No hay ningún {$scope} con {$loginField} = {$login}.");

            return self::FAILURE;
        }

        if (! $user->hasConfirmedTwoFactor() && $this->twoFactorAttributes($user) === []) {
            $this->info('El usuario no tiene verificación en dos pasos: no hay nada que sacar.');

            return self::SUCCESS;
        }

        $this->table(['Campo', 'Valor'], [
            ['scope', $scope],
            [$loginField, (string) $user->getAttribute($loginField)],
            ['id', (string) $user->getKey()],
            ['2FA confirmado', $user->hasConfirmedTwoFactor() ? 'sí' : 'no (enrolamiento a medias)'],
        ]);

        if (! $this->option('force') && ! $this->confirm('¿Sacarle la verificación en dos pasos a este usuario?', false)) {
            $this->warn('Cancelado. No se tocó nada.');

            return self::SUCCESS;
        }

        $user->forgetTwoFactor();

        $closedSessions = 0;
        if (! $this->option('keep-sessions') && method_exists($user, 'tokens')) {
            $closedSessions = $user->tokens()->delete();
        }

        AuthEvent::dispatch('auth.two_factor.reset', [
            'scope' => $scope,
            'user_id' => (string) $user->getKey(),
            'actor' => 'console',
        ]);

        $this->info('✅ Verificación en dos pasos eliminada.');
        if ($closedSessions > 0) {
            $this->line("   → {$closedSessions} sesión(es) cerradas.");
        }
        $this->line('   El próximo login le va a ofrecer el enrolamiento de nuevo (política `required`), o lo va a dejar entrar derecho (`optional`).');

        return self::SUCCESS;
    }

    /**
     * Las columnas del segundo factor que la fila tiene CON valor. Un
     * enrolamiento a medias (secreto sin confirmar) también cuenta: hay que
     * poder limpiarlo.
     *
     * @return array<int, string>
     */
    private function twoFactorAttributes(AuthUser $user): array
    {
        $attributes = $user->getAttributes();

        return array_values(array_filter(
            AuthUser::TWO_FACTOR_COLUMNS,
            static fn (string $column) => ($attributes[$column] ?? null) !== null,
        ));
    }
}
