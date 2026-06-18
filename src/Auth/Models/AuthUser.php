<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Models;

use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
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
 *
 * Cada subclase concreta debe:
 *  - extender esta clase
 *  - declarar `$table` y `$fillable` propios (o reusar `auth_users`)
 *  - opcionalmente sobreescribir `getAuthScope()` si necesita lógica custom
 *
 * Tabla por defecto: `auth_users` (snake_case del nombre de clase).
 * El consumer puede sobreescribirla desde una subclase concreta
 * si necesita una tabla específica del módulo.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $auth_scope
 * @property string|null $client_id
 * @property \Illuminate\Support\Carbon|null $email_verified_at
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
     * Columnas asignables en masa. El `auth_scope` por defecto es
     * `user`; subclases (Admin, Member, etc.) deben setear su scope
     * propio al crear.
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
}
