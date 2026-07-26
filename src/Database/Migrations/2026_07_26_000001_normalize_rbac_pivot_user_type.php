<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normaliza `role_user.user_type` y `ability_user.user_type` al alias del
 * morph map.
 *
 * POR QUÉ HACE FALTA
 * ------------------
 * Las pivots del RBAC se escribían en dos vocabularios según el camino:
 * `assignRole()` guardaba el FQCN (`App\Modules\Admin\Models\Admin`) y un
 * `attach()` pelado guardaba el alias (`admin`). Desde el fix las dos
 * escriben el alias, y la lectura acepta los dos para que el deploy no le
 * saque los roles a nadie mientras tanto.
 *
 * Esto cierra la transición: pasa las filas viejas al alias, para que la
 * columna vuelva a tener un solo idioma.
 *
 * 🔴 NO ES DESTRUCTIVA Y NO PUEDE SERLO. Sólo toca filas cuyo `user_type` es
 * EXACTAMENTE un FQCN que está en el morph map. Un valor que no reconoce
 * —otro paquete, otra convención, una fila a mano— se deja intacto, porque el
 * costo de equivocarse acá es que alguien pierda sus permisos.
 *
 * `down()` revierte al FQCN por simetría, aunque el estado mezclado del que
 * venimos no sea reproducible: lo que sí garantiza es que un rollback no deje
 * filas que la versión anterior no entienda.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tablas = ['role_user', 'ability_user'];

    public function up(): void
    {
        $this->traducir(fn (array $mapa) => $mapa);
    }

    public function down(): void
    {
        $this->traducir(fn (array $mapa) => array_flip($mapa));
    }

    /**
     * @param  callable(array<string, string>): array<string, string>  $direccion
     *                                                                             Recibe alias=>FQCN y devuelve el mapa de traducción a aplicar.
     */
    private function traducir(callable $direccion): void
    {
        $morphMap = Relation::morphMap() ?? [];

        if ($morphMap === []) {
            // Sin morph map no hay alias que aplicar. No es un error: un
            // consumidor puede no usarlo, y en ese caso el FQCN YA es el
            // valor canónico que devuelve `getMorphClass()`.
            return;
        }

        // alias => FQCN, invertido a FQCN => alias para el `up()`.
        $traduccion = $direccion(array_flip($morphMap));

        foreach ($this->tablas as $tabla) {
            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, 'user_type')) {
                continue;
            }

            foreach ($traduccion as $desde => $hacia) {
                // Igualdad exacta, nunca `LIKE`: un `App\Modules\Admin\Models\Admin`
                // y un `App\Modules\Admin\Models\AdminLog` comparten prefijo.
                DB::table($tabla)
                    ->where('user_type', $desde)
                    ->update(['user_type' => $hacia]);
            }
        }
    }
};
