<?php

declare(strict_types=1);

namespace Mk\Director\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Interface for Module Services with Hooks
 *
 * Todo service de módulo puede implementar esta interfaz.
 * Los métodos son opcionales - si no existen, el controller usa默认值.
 */
interface MkModuleServiceInterface
{
    /**
     * Hook before create - modificar input antes de crear
     */
    public function beforeCreate(Request $request, array $input): array;

    /**
     * Hook after create - lógica después de crear
     */
    public function afterCreate(Request $request, Model $model, array $input): mixed;

    /**
     * Hook before update - modificar input antes de actualizar.
     *
     * R-PKG-034 BUG-NEW-33: `$id` ahora acepta `string|int` para
     * compatibilidad con modelos que usan `HasUuids` (UUIDs v7 tipo
     * `01HXYZ...`). Razón histórica: la firma pineaba `int $id` desde v1.0,
     * pero `CRUDSmart::show()` y `CRUDSmart::update()` (R-PKG-016 BUG-NEW-20)
     * ya aceptaban `string|int $id` desde v1.6.x. Cualquier consumer MME
     * que implementaba esta interface con UUIDs pineaba TypeError en runtime
     * al primer `update()` con ID string. Esta firma alinea la interface
     * con el call-site real. BC-safe: una impl con `int $id` no rompe
     * (PHP permite narrowing en la implementación).
     */
    public function beforeUpdate(Request $request, string|int $id, array $input): array;

    /**
     * Hook after update - lógica después de actualizar.
     *
     * R-PKG-034 BUG-NEW-33: ver `beforeUpdate()` — `$id` ahora es `string|int`.
     */
    public function afterUpdate(Request $request, Model $model, array $input, string|int $id): mixed;

    /**
     * Hook before delete - retornar false para bloquear.
     *
     * R-PKG-034 BUG-NEW-33: ver `beforeUpdate()` — `$id` ahora es `string|int`.
     */
    public function beforeDelete(Request $request, Model $model, string|int $id): bool;

    /**
     * Hook after delete - lógica después de eliminar.
     *
     * R-PKG-034 BUG-NEW-33: ver `beforeUpdate()` — `$id` ahora es `string|int`.
     */
    public function afterDelete(Request $request, Model $model, string|int $id): mixed;

    /**
     * Hook before list - modificar query
     */
    public function beforeList(Request $request, $query);

    /**
     * Hook after list - agregar datos extra
     */
    public function afterList(Request $request, $data, int $total): array;

    /**
     * Hook before search - agregar condiciones de búsqueda
     */
    public function beforeSearch(Request $request, $query);

    /**
     * Hook for detail view (show)
     */
    public function beforeShow(Request $request, Model $model): Model;
}
