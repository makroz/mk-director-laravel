<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Models;

use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Mk\Director\Auth\Concerns\HasAbilities;
use Mk\Director\Auth\Concerns\HasRoles;
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
            $expectedTable = Str::snake(Str::pluralStudly(class_basename(static::class)));

            throw new \LogicException(sprintf(
                '%s extends AuthUser pero NO override protected $table. '.
                'Si no se sobreescribe, AuthUser usaría la tabla "auth_users" (vacía, no usada). '.
                'Pineá en tu modelo del scope: protected $table = "%s"; '.
                'Para regenerar el scaffold completo: php artisan mk:make:auth-user %s --with-crud --force',
                static::class,
                $expectedTable,
                class_basename(static::class),
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
     * Columnas ocultas al serializar.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
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
     * Revoca el access token actual con null-safety.
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

        $token->delete();

        // HALLAZGO-NEW-FASE14-03: invalidate cached auth state so subsequent
        // requests in the same process don't see the now-revoked token via
        // the cached user on the AuthManager guard. See method docblock.
        \Auth::forgetGuards();

        return true;
    }
}
