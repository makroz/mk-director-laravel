<?php

declare(strict_types=1);

namespace Mk\Director\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Mk\Director\Auth\Enums\FixedStatus;
use Mk\Director\Auth\Models\Ability;

/**
 * Serialización de una {@see Ability}.
 *
 * POR QUÉ VIVE EN EL PAQUETE
 * --------------------------
 * `Ability` es un modelo del paquete. `mk:make:auth-user X --with-crud`
 * (R-PKG-014) generaba una copia de este resource DENTRO de cada módulo
 * scaffolded, así que una app con dos scopes terminaba con dos archivos
 * idénticos salvo el namespace — dos lugares donde arreglar el mismo bug.
 * El shape de un modelo del paquete lo define el paquete.
 *
 * CONTRATO
 * --------
 * 🔴 EL SHAPE DE ESTE PAYLOAD ES CONTRATO PÚBLICO. Agregar claves es aditivo;
 * renombrar o sacar una es breaking y se evalúa contra los consumers.
 *
 * @mixin Ability
 */
class MkAbilityResource extends JsonResource
{
    /**
     * `is_fixed` acepta las DOS formas a propósito: `Ability` no castea la
     * columna a {@see FixedStatus}, así que en lectura normal llega como int
     * crudo desde la base. El `instanceof` cubre al consumer que SÍ castea en
     * su propio modelo (o que arma el resource desde un objeto en memoria).
     * En ambos casos sale un int — nunca un string, nunca un objeto.
     *
     * `module` NO tiene columna en la tabla `abilities` del paquete: hoy
     * serializa null para todo consumer que no la agregue por su cuenta. Se
     * mantiene porque los fronts ya la reciben y sacarla sería breaking.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_fixed' => $this->is_fixed instanceof FixedStatus ? $this->is_fixed->value : (int) ($this->is_fixed ?? 0),
            'module' => $this->module,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
