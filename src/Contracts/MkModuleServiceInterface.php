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
     * Hook before update - modificar input antes de actualizar
     */
    public function beforeUpdate(Request $request, int $id, array $input): array;

    /**
     * Hook after update - lógica después de actualizar
     */
    public function afterUpdate(Request $request, Model $model, array $input, int $id): mixed;

    /**
     * Hook before delete - retornar false para bloquear
     */
    public function beforeDelete(Request $request, Model $model, int $id): bool;

    /**
     * Hook after delete - lógica después de eliminar
     */
    public function afterDelete(Request $request, Model $model, int $id): mixed;

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
