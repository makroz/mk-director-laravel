<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Attributes;

use Attribute;
use Mk\Director\Console\Commands\DiscoverAbilitiesCommand;

/**
 * Atributo PHP para declarar abilities en métodos de controllers.
 *
 * Consumido por `php artisan mk:discover-abilities` (R-PKG-007).
 *
 * Forma de uso:
 *
 * ```php
 * use Mk\Director\Auth\Attributes\Ability;
 *
 * class PostController
 * {
 *     #[Ability('posts.viewAny', 'Listar posts')]
 *     public function index(Request $request) { ... }
 *
 *     #[Ability('posts.create')]
 *     public function store(StorePostRequest $request) { ... }
 * }
 * ```
 *
 * ## Precedencia (Q1 = hybrid)
 *
 * - Si el módulo expone un ServiceProvider con `discoverAbilities(): array`,
 *   ese array es el ÚNICO source-of-truth. Los atributos PHP se IGNORAN.
 * - Si el provider NO implementa `discoverAbilities()`, este atributo es
 *   la fuente primaria (sobre docblock).
 * - Docblock `@mk-ability name|description` se escanea junto con los
 *   atributos solo cuando el provider no existe (fallback combinado).
 *
 * ## Repeatable
 *
 * El atributo es IS_REPEATABLE: si un método expone múltiples abilities
 * (por ejemplo, un endpoint que delega a varios sub-actions), podés
 * apilar varios `#[Ability(...)]`:
 *
 * ```php
 * #[Ability('posts.view')]
 * #[Ability('posts.viewAny')]
 * public function index() { ... }
 * ```
 *
 * Spec: R-PKG-007 — design.md D1 (hybrid source-of-truth) + D2 (attribute primary).
 *
 * @see DiscoverAbilitiesCommand consumer.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Ability
{
    public function __construct(
        /**
         * Nombre de la ability (convención: `{scope}.{resource}.{action}`).
         *
         * Ejemplos válidos: `posts.viewAny`, `admin.users.list`,
         * `billing.invoices.refund`, `posts.*` (wildcard).
         */
        public string $name,

        /**
         * Descripción human-readable. Opcional — si es null, el comando
         * de discover usa el docblock del método (si tiene) o un fallback
         * genérico como "Auto-descubierta desde {Controller}::{method}".
         */
        public ?string $description = null,

        /**
         * ¿Es una ability BASELINE, o sea que la tiene CUALQUIER usuario
         * autenticado de este scope por el solo hecho de existir?
         *
         * El default es `false`: una ability es role-gated salvo que se diga
         * lo contrario. Esa asimetría es deliberada — olvidarse el flag deja a
         * alguien SIN un permiso (se ve, se reporta, se arregla), mientras que
         * el default inverso se lo daría a todo el mundo en silencio.
         *
         * ## Qué problema resuelve
         *
         * Hay permisos que no son de un rol: son la línea de base del scope.
         * Ver el propio perfil. Ver el muro. Cambiarse la contraseña. Sin una
         * forma de declararlos, cada consumidor termina inventando el suyo —
         * en RETO fue un `Member::grantWallAccess()` colgado de un hook
         * `created`, que le pega abilities DIRECTAS a cada member que nace.
         * Funciona, y deja el permiso repetido en `ability_user` una vez por
         * usuario en vez de una sola vez en un rol.
         *
         * ## Cómo se declara
         *
         * ```php
         * #[Ability('member.wall.viewAny', 'Ver el muro', baseline: true)]
         * public function index() { ... }
         * ```
         *
         * 🔴 EL FLAG VIVE PEGADO AL ENDPOINT QUE PROTEGE, Y ESO ES EL PUNTO.
         * La alternativa era una lista en el config, y es justo la forma que
         * más nos mordió: un contrato en un lugar distinto del código que
         * describe, sin guard-rail, se desincroniza y ningún test lo nota. Acá
         * no puede pasar — si el método se mueve o se borra, el marcador se va
         * con él.
         *
         * ## Qué NO hace por sí solo
         *
         * Marcar `baseline: true` no concede nada hasta que corre
         * `php artisan mk:discover-abilities`, que es quien lo persiste y quien
         * arma el rol base.
         */
        public bool $baseline = false,
    ) {}
}
