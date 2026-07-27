<?php

declare(strict_types=1);

namespace Mk\Director\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Mk\Director\Auth\Enums\FixedStatus;
use Mk\Director\Auth\Models\Role;

/**
 * Serialización de un {@see Role}.
 *
 * POR QUÉ VIVE EN EL PAQUETE
 * --------------------------
 * Misma razón que {@see MkAbilityResource}: `Role` es un modelo del paquete y
 * `mk:make:auth-user X --with-crud` (R-PKG-014) dejaba una copia idéntica en
 * cada módulo scaffolded.
 *
 * CONTRATO
 * --------
 * 🔴 EL SHAPE DE ESTE PAYLOAD ES CONTRATO PÚBLICO. Agregar claves es aditivo;
 * renombrar o sacar una es breaking y se evalúa contra los consumers.
 *
 * @mixin Role
 */
class MkRoleResource extends JsonResource
{
    /**
     * `abilities` es CONDICIONAL, no opcional-por-conveniencia: sale sólo con
     * la relación ya cargada (`whenLoaded`). Si se resolviera siempre, el
     * índice de roles dispararía un N+1 — una query de abilities por rol
     * listado. El controller decide con `->with('abilities')`, y el cliente
     * distingue "sin abilities" (array vacío) de "no pediste abilities"
     * (clave ausente).
     *
     * Se serializan sólo los NOMBRES, no el resource completo: es lo que el
     * front necesita para pintar los checkboxes del rol.
     *
     * Ver {@see MkAbilityResource} por la doble forma de `is_fixed`.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'guard' => $this->guard,
            'description' => $this->description,
            'is_fixed' => $this->is_fixed instanceof FixedStatus ? $this->is_fixed->value : (int) ($this->is_fixed ?? 0),
            'abilities' => $this->whenLoaded('abilities', fn () => $this->abilities->pluck('name')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
