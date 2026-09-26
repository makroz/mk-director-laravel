<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Models;

use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Mk\Director\Auth\Concerns\HasAbilities;
use Mk\Director\Auth\Concerns\HasRoles;
use Mk\Director\Auth\Services\TokenIssuer;
use Mk\Director\Tenancy\Concerns\HasTenantMembership;

/**
 * AuthUser — modelo abstracto base para todos los usuarios
 * autenticables de mk-director (Admin, Member, etc.).
 *
 * Spec: MK-LAR-1.0.2 / MK-LAR-1.0.6.
 * R-PKG-009: agnóstico al campo de login (default `email`, BC).
 *
 * Cada subclase concreta debe:
 *  - extender esta clase
 *  - declarar `$table` y `$fillable` propios (o reusar `auth_users`)
 *  - opcionalmente sobreescribir `getAuthScope()` si necesita lógica custom
 *  - opcionalmente sobreescribir `$loginField` si usa un campo no-email
 *    (RETO: `ci`, genéricos: `phone`, `username`, `documento`)
 *
 * Tabla por defecto: `auth_users` (snake_case del nombre de clase).
 * El consumer puede sobreescribirla desde una subclase concreta
 * si necesita una tabla específica del módulo.
 *
 * @property string $id
 * @property string $name
 * @property string $password
 * @property string $auth_scope
 * @property string|null $client_id
 * @property string|null $remember_token
 */
abstract class AuthUser extends Authenticatable implements AuthenticatableContract, MustVerifyEmail
{
    use HasAbilities;
    use HasApiTokens;
    use HasRoles;
    use HasTenantMembership;
    use HasUuids;
    use Notifiable;

    /**
     * R-PKG-035 DB defensive boot (v1.8.3-rc0).
     *
     * HALLAZGO-NEW-FASE15 (Mario DB question 2026-06-30): la tabla
     * `auth_users` existe porque `AuthUser::$table = 'auth_users'` (línea
     * abajo). Las subclases concretas (Admin, Member, etc.) generadas
     * por `mk:make:auth-user` DEBEN sobreescribir `protected $table`
     * con su tabla del scope (e.g. `protected $table = 'admins'`).
     *
     * Drift footgun: si el scaffolder falla en pine `protected $table`
     * (e.g. stub regeneration que borra la línea por error), la
     * subclase cae al default `'auth_users'` — tabla vacía que el
     * paquete publica pero NUNCA popula. Resultado: queries de login,
     * me, refresh retornan `null` o 404 silenciosamente, sin error
     * claro para el developer.
     *
     * Este boot listener pinea un error explícito en runtime si la
     * subclase NO sobreescribió `$table`. Defense-in-depth (per Mario
     * feedback: "soluciones de raíz, no parches" — elimina la clase de
     * bugs).
     *
     * BC-safe:
     * - NO se dispara en la base class `AuthUser` directa (no es
     *   instanciable — es abstract).
     * - NO se dispara en subclases que pinean `protected $table`
     *   correctamente (caso normal post-`mk:make:auth-user --with-crud`).
     * - SOLO se dispara si la subclase NO pine `protected $table` —
     *   drift footgun case.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::preventTableDriftFootgun();

        static::deleted(static function (self $user): void {
            $user->forgetAccessOnDelete();
        });
    }

    /**
     * Al borrar la cuenta se van sus tokens, sus roles y sus permisos directos.
     *
     * 🔴 `role_user` Y `ability_user` SON POLIMÓRFICAS: la base no tiene una FK
     * que las limpie. Medido en NetPizza: `DELETE /api/admins/{id}` (CRUDSmart,
     * que borra el modelo directo) dejaba las tres cosas colgando, y el
     * Repository que sí las limpiaba no está en ese camino. Acá corre venga de
     * donde venga el borrado.
     *
     * Va en `deleted` y no en `deleting`: si el borrado falla —una FK que lo
     * bloquea—, la cuenta sigue viva y no puede quedarse sin sus permisos. Con
     * soft delete no hace nada, para que `restore()` la devuelva entera.
     */
    protected function forgetAccessOnDelete(): void
    {
        if (method_exists($this, 'isForceDeleting') && ! $this->isForceDeleting()) {
            return;
        }

        $this->tokens()->delete();
        $this->roles()->detach();
        $this->directAbilities()->detach();
    }

    /**
     * Pinear error explícito si una subclase no override `$table`.
     * Helper separado para mantener `boot()` limpio y testeable.
     */
    protected static function preventTableDriftFootgun(): void
    {
        // No check para la base class (no instanciable directo).
        if (static::class === self::class) {
            return;
        }

        $reflection = new \ReflectionClass(static::class);
        if (! $reflection->hasProperty('table')) {
            return; // No pine $table en ningún ancestor.
        }

        $property = $reflection->getProperty('table');

        // Si la propiedad `$table` está declarada en la SUBCLASE
        // (no en ancestor), asumimos que es override consciente.
        // Si está heredada de `AuthUser` (default `'auth_users'`),
        // es drift footgun → error explícito.
        if ($property->class !== static::class) {
            // El plural inglés es sólo el DEFAULT del scaffolder: un scope
            // generado con `--plural=` (`Operador` → `operadores`) tiene otra
            // tabla, y desde acá no hay forma de saberla. El mensaje no puede
            // afirmar "tu tabla es X" — mandaría a pinear `operadors`.
            $defaultTable = Str::snake(Str::pluralStudly(class_basename(static::class)));

            throw new \LogicException(sprintf(
                '%s extends AuthUser pero NO override protected $table. '.
                'Si no se sobreescribe, AuthUser usaría la tabla "auth_users" (vacía, no usada). '.
                'Pineá en tu modelo del scope la tabla que crea su migración: protected $table = "<tabla del scope>"; '.
                '(el scaffolder usa "%s" salvo que se haya generado con --plural=).',
                static::class,
                $defaultTable,
            ));
        }
    }

    /**
     * Tabla por defecto del modelo base. Subclases concretas pueden
     * sobreescribirla con su propia tabla.
     */
    protected $table = 'auth_users';

    /**
     * Campo usado para login. Default BC: `email`. Subclases generadas con
     * `mk:make:auth-user --login-field=<campo>` lo sobreescriben (RETO: `ci`).
     *
     * Para queries agnósticas al campo, usar `scopeWhereLoginField($value)`
     * que consume este property automáticamente.
     */
    protected string $loginField = 'email';

    /**
     * Columnas asignables en masa. El `auth_scope` por defecto es
     * `user`; subclases (Admin, Member, etc.) deben setear su scope
     * propio al crear.
     *
     * NOTA: `email` queda en el fillable base por BC. Subclases con
     * `--login-field != email` (generadas por el scaffolder) sobreescriben
     * `$fillable` completamente sin `email`. Ver auth-user.model.stub.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'auth_scope',
        'client_id',
    ];

    /**
     * Las cuatro columnas del segundo factor, en un solo lugar.
     *
     * Las emite `mk:make:auth-user --two-factor` en la tabla del scope (el
     * paquete no puede migrarlas: no conoce el nombre de la tabla). Un scope sin
     * ellas se comporta como si el 2FA estuviera apagado — `getAttribute()`
     * devuelve null y {@see hasConfirmedTwoFactor()} da `false`.
     *
     * Existe como constante porque la leen tres lugares que tienen que decir lo
     * mismo: `$hidden`, {@see forgetTwoFactor()} y el comando
     * `mk:auth:two-factor-reset`. Escrita tres veces, un rename deja una
     * columna adentro.
     *
     * @var array<int, string>
     */
    public const TWO_FACTOR_COLUMNS = [
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'two_factor_last_step',
    ];

    /**
     * Columnas ocultas al serializar.
     *
     * 🔴 Las cuatro del segundo factor van acá, no sólo el secreto. El secreto y
     * los códigos de recuperación son credenciales; `two_factor_last_step` y
     * `two_factor_confirmed_at` no lo son, pero un `toArray()` del modelo sale
     * en el `data` de `/me` y de cada CRUD de usuarios, y ahí no significan
     * nada: `/me` expone `two_factor_enabled` y `two_factor_confirmed_at`
     * explícitamente, con el nombre que el front consume.
     *
     * Ocultar una columna que no existe es un no-op: un scope sin `--two-factor`
     * no cambia en nada.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'two_factor_last_step',
    ];

    /**
     * Casts de atributos.
     *
     * `email_verified_at` queda en casts base por BC (MustVerifyEmail interface).
     * Subclases con `--login-field != email` (generadas por el scaffolder) lo
     * eliminan via stub. Si la subclase override `$casts` completamente, puede
     * incluir o no este cast según necesidad.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * Setter explícito para el password del usuario, complemento de
     * `getAuthPassword()` (contrato `Authenticatable`, solo lectura).
     *
     * BUG FIX (2026-07-15-profile-edit-password-otp Phase 5): `resetPassword()`,
     * `changePassword()` y `confirmPasswordCode()` en `BaseAuthController`
     * llaman `$user->setAuthPassword($plain)` desde siempre (`resetPassword`/
     * `changePassword` son pre-existentes, `confirmPasswordCode` es Phase 2 de
     * este spec) pero el método NUNCA existió en `AuthUser` — cualquier
     * consumer que ejecutara esos 3 endpoints contra un modelo real explotaba
     * con `BadMethodCallException`. Descubierto vía el feature test de scope
     * parity de RETO (`PasswordOtpScopeParityTest`), que fue el primer test
     * end-to-end del repo en golpear `confirmPasswordCode()` con un modelo
     * Eloquent real (los tests previos de `EmailOtpService`/`AuthUserCompleteFlowE2ETest`
     * corren contra fixtures/mocks que no ejercitan este call site, o
     * cubren solo hasta el veredicto sin llegar al `DB::transaction` que
     * persiste el nuevo password).
     *
     * Asigna el valor CRUDO (sin `Hash::make()` acá) porque `$casts['password']
     * = 'hashed'` (línea de arriba) ya hashea automáticamente al asignar —
     * llamar `Hash::make()` acá causaría doble-hash y rompería el login
     * subsecuente. `save()` persiste inmediatamente (los 3 call sites ya
     * corren dentro de `DB::transaction`).
     */
    public function setAuthPassword(string $password): void
    {
        $this->setAttribute($this->getAuthPasswordName(), $password);
        $this->save();
    }

    /**
     * ¿Este usuario terminó de enrolar su segundo factor?
     *
     * Las dos condiciones juntas: hay secreto Y está confirmado. Un secreto sin
     * confirmar es un enrolamiento que el usuario abandonó a mitad de camino —
     * si eso trabara el login, quedaría afuera de su propia cuenta.
     *
     * Tolera la ausencia de las columnas (scope generado sin `--two-factor`) y
     * un secreto vacío o en blanco.
     */
    public function hasConfirmedTwoFactor(): bool
    {
        $secret = $this->getAttribute('two_factor_secret');

        return is_string($secret)
            && trim($secret) !== ''
            && $this->getAttribute('two_factor_confirmed_at') !== null;
    }

    /**
     * Borra el enrolamiento del segundo factor (dispositivo perdido, o baja
     * voluntaria). Deja el modelo listo para enrolarse de nuevo.
     *
     * Escribe SÓLO las columnas que la tabla tiene: un scope sin `--two-factor`
     * reventaría con «Unknown column». Se mide con los atributos ya cargados y
     * no con `Schema::hasColumn()`, que sería una query por columna.
     */
    public function forgetTwoFactor(): void
    {
        $attributes = $this->getAttributes();
        $touched = false;

        foreach (self::TWO_FACTOR_COLUMNS as $column) {
            if (! array_key_exists($column, $attributes)) {
                continue;
            }
            $this->setAttribute($column, null);
            $touched = true;
        }

        if ($touched) {
            $this->save();
        }
    }

    /**
     * Devuelve el scope del usuario. Null significa que el user fue
     * mal creado y el login debe rechazarse (Capa 1 de la spec).
     */
    public function getAuthScope(): ?string
    {
        $scope = $this->getAttribute('auth_scope');

        return is_string($scope) && $scope !== '' ? $scope : null;
    }

    /**
     * Setter explícito para auth_scope.
     */
    public function setAuthScope(string $scope): void
    {
        $this->setAttribute('auth_scope', $scope);
    }

    /**
     * Devuelve el nombre del campo usado para login (`email`, `ci`, etc.).
     *
     * Útil para queries dinámicas en consumers que no quieren hardcodear
     * el nombre del campo:
     *
     *     $user = User::whereLoginField($request->input('login'))->first();
     *
     * Spec: R-PKG-009 D6.
     */
    public function getLoginField(): string
    {
        return $this->loginField;
    }

    /**
     * Local scope agnóstico al campo de login.
     *
     * Equivalente a `where($this->loginField, $value)` pero consume el
     * property `$loginField` de la subclase concreta. Esto permite a los
     * consumers usar el mismo query string independientemente del campo:
     *
     *     // Default email: WHERE email = ?
     *     Admin::query()->whereLoginField('admin@example.com')->first();
     *
     *     // Override ci: WHERE ci = ?
     *     AdminReto::query()->whereLoginField('1234567')->first();
     *
     * Spec: R-PKG-009 D6.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWhereLoginField(Builder $query, string $value): Builder
    {
        return $query->where($this->loginField, $value);
    }

    /**
     * Revoca el access token actual —y el refresh token de su sesión— con
     * null-safety.
     *
     * R-PKG-027 PKG-NEW-08 helper: el patrón naive
     *
     *     $token = $user->currentAccessToken();
     *     $token->delete();
     *
     * revienta con `Call to a member function delete() on null` cuando
     * `currentAccessToken()` retorna null (autenticación via Sanctum
     * stateful SPA con cookies, o token ya revocado por otra request).
     *
     * Este helper encapsula la null-safety para que consumers scaffoldeados
     * no tengan que recordar el patrón. Defense-in-depth.
     *
     * HALLAZGO-NEW-FASE14-03 fix (v1.8.1+): después de `$token->delete()`,
     * invalida el cache del AuthManager via `\Auth::forgetGuards()`.
     *
     * En testing (Pest/PHPUnit), todas las requests dentro del mismo test
     * comparten el container PHP. Sanctum cachea el user resuelto en
     * `Auth::guard($scope)` durante el lifecycle del container. Sin el
     * `forgetGuards()`, el siguiente request con el Bearer token revocado
     * sigue resolviendo el user cacheado → `GET /me` post-`POST /logout`
     * retorna 200 en vez del 401 esperado.
     *
     * En producción (cada HTTP request = PHP process fresco), `\Auth::forgetGuards()`
     * es no-op porque no hay guards cacheados en un process que arranca de
     * cero. El fix es transparente para consumidores production.
     *
     * Spec: R-PKG-027 PKG-NEW-08 + HALLAZGO-NEW-FASE14-03 (feedback RETO fase 14, 2026-06-29).
     *
     * @return bool `true` si había un token que se pudo revocar, `false`
     *              si no había token (cookie-based auth o token ya revocado).
     */
    public function safeLogoutCurrentToken(): bool
    {
        $token = $this->currentAccessToken();
        if ($token === null) {
            return false;
        }

        // Logout cierra la SESIÓN: también el refresh token al que apunta este
        // access (`TokenIssuer::issueTokenPair()`). Si no, el refresh seguía
        // vivo 7 días después del logout. Access tokens emitidos sin sesión
        // (anteriores a este cambio) no traen el vínculo: sólo se revoca el access.
        $refreshId = $token instanceof PersonalAccessToken
            ? TokenIssuer::linkedRefreshTokenId($token->abilities ?? [])
            : null;
        if ($refreshId !== null) {
            $this->tokens()->whereKey($refreshId)->delete();
        }

        $token->delete();

        // HALLAZGO-NEW-FASE14-03: invalidate cached auth state so subsequent
        // requests in the same process don't see the now-revoked token via
        // the cached user on the AuthManager guard. See method docblock.
        Auth::forgetGuards();

        return true;
    }
}
