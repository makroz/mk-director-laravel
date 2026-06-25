<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Attributes;

use Attribute;

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
 * @see \Mk\Director\Console\Commands\DiscoverAbilitiesCommand consumer.
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
    ) {}
}