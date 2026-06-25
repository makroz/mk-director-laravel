<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Models;

use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
}
