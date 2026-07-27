<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Concerns;

use Mk\Director\Auth\Models\Role;

/**
 * Le da a cada usuario NUEVO el rol base de su scope, apenas nace.
 *
 * El rol base junta las abilities declaradas con
 * `#[Ability(..., baseline: true)]`: las que tiene cualquier usuario autenticado
 * del scope por el solo hecho de existir. Lo arma y lo mantiene
 * `php artisan mk:discover-abilities`; este trait se encarga de que a un usuario
 * recién creado le llegue solo.
 *
 * ## Cómo se usa
 *
 * ```php
 * class Member extends AuthUser
 * {
 *     use AssignsBaseRole;
 * }
 * ```
 *
 * `mk:make:auth-user` lo agrega solo en los scopes nuevos.
 *
 * ## Qué reemplaza
 *
 * El patrón que cada consumidor terminaba inventando. En RETO fue
 * `Member::grantWallAccess()` colgado de un hook `created`, que le pega
 * abilities DIRECTAS a cada member: el mismo permiso repetido en `ability_user`
 * una vez por usuario, y sin forma de revocarlo salvo recorrer la tabla.
 * Con un rol, el permiso vive en un solo lugar y sacarlo del rol lo saca de
 * todos.
 *
 * 🔴 ES OPT-IN, Y TIENE QUE SERLO. El trait se agrega a mano (o lo agrega el
 * scaffolder). Colgarlo automático de `AuthUser` le cambiaría el comportamiento
 * a todos los consumidores que ya existen sin que nadie lo pidiera — y encima
 * en el camino de creación de usuarios, que es lo último que uno quiere que
 * cambie solo.
 */
trait AssignsBaseRole
{
    public static function bootAssignsBaseRole(): void
    {
        static::created(function ($user): void {
            $user->asignarRolBase();
        });
    }

    /**
     * Engancha el rol base del scope del usuario, si existe.
     *
     * 🔴 NO USA `assignRole('base')` NI POR CASUALIDAD, Y ACÁ ESTÁ EL PORQUÉ.
     *
     * `assignRole(string)` resuelve el rol con `firstOrCreate(['name', 'guard'])`
     * — o sea que si el rol base NO existe, LO CREA. Vacío. Y eso rompería de
     * frente la invariante del discovery, que se cuida de no crear roles vacíos
     * justamente porque un rol sin permisos colgando en la UI invita a que
     * alguien le cuelgue cosas creyendo que hace algo. Peor: lo crearía el
     * primer usuario que se registre, o sea en producción y sin que nadie mire.
     *
     * Por eso busca primero y sólo asigna si encontró. La sobrecarga que recibe
     * un `Role` ya resuelto no crea nada.
     *
     * 🔴 QUE NO HAYA ROL BASE ES NORMAL, NO UN ERROR. Un scope que no declaró
     * ninguna baseline no tiene rol base, y no debería: sus usuarios no tienen
     * línea de base. Este método es un no-op ahí y no avisa nada — avisar en
     * cada alta de usuario de cada scope que no usa la feature sería ruido puro.
     *
     * 🔴 LO QUE SÍ SE PROPAGA SON LOS ERRORES DE VERDAD. No hay try/catch: si la
     * base contestó lo suficiente como para crear el usuario, contesta para
     * engancharle un rol — es la misma conexión y la misma transacción. Tragarse
     * una excepción acá dejaría un usuario a medio aprovisionar, sin sus
     * permisos, y sin ningún rastro de por qué. Un alta que falla fuerte se ve y
     * se arregla; un usuario que existe pero no puede entrar a nada es un ticket
     * de soporte que nadie sabe leer.
     */
    public function asignarRolBase(): void
    {
        $scope = $this->getAuthScope();

        if ($scope === null) {
            return;
        }

        $rol = Role::query()
            ->where('name', (string) config('mk_director.auth.base_role', 'base'))
            ->where('guard', $scope)
            ->first();

        if ($rol === null) {
            return;
        }

        $this->assignRole($rol);
    }
}
