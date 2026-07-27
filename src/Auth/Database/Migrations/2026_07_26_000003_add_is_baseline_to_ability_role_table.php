<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca en `ability_role` qué vinculaciones las puso el discovery.
 *
 * 🔴 POR QUÉ NO ALCANZA CON LA COLUMNA DE `abilities`.
 *
 * `abilities.is_baseline` dice "esta ability es de línea de base". Suena
 * suficiente para reconciliar el rol base, y no lo es. El caso que lo rompe:
 *
 *   1. El código declara `member.wall.viewAny` como baseline. El discovery la
 *      mete en el rol base.
 *   2. Un admin, desde la UI, le agrega al rol base `member.reports.view`, que
 *      NO es baseline. Decisión suya, perfectamente válida.
 *   3. Alguien saca `baseline: true` de `member.wall.viewAny`.
 *
 * Ahora las dos abilities están en el rol base y las dos tienen
 * `is_baseline = false`. Son INDISTINGUIBLES. El discovery tiene que sacar la
 * primera —el código dejó de pedirla— y respetar la segunda, y mirando esa
 * columna no puede decidir cuál es cuál. Le quedan dos salidas y las dos son
 * malas: borrar las dos (le vuela el trabajo al admin, sin avisar) o no borrar
 * ninguna (sacar el flag no revoca nunca).
 *
 * La respuesta es que el dato no vive en la ability, vive en la VINCULACIÓN:
 * no es "esta ability es baseline" sino "esta ability está en este rol PORQUE
 * es baseline". Eso es una propiedad de la fila del pivot, y por eso la columna
 * va acá.
 *
 * Con esto la regla del discovery es de una línea: toca sólo las filas con
 * `is_baseline = 1`. Lo que puso una persona tiene 0 y no se mira nunca.
 *
 * 🔴 EL DEFAULT `false` ES LO QUE HACE ESTO SEGURO PARA LO QUE YA EXISTE.
 * Todas las vinculaciones actuales —las que sembró `{Scope}RolesSeeder`, las
 * que agregó un admin— quedan en 0, o sea "no las puso el discovery". Que es
 * exactamente la verdad: cuando esta migración corre, el discovery todavía no
 * puso ninguna.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ability_role') || Schema::hasColumn('ability_role', 'is_baseline')) {
            return;
        }

        Schema::table('ability_role', function (Blueprint $table): void {
            $table->boolean('is_baseline')->default(false)->after('role_id');

            // El discovery pregunta "¿qué le puse yo a este rol?" en cada
            // corrida, siempre acotado por `role_id`.
            $table->index(['role_id', 'is_baseline']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ability_role') || ! Schema::hasColumn('ability_role', 'is_baseline')) {
            return;
        }

        Schema::table('ability_role', function (Blueprint $table): void {
            $table->dropIndex(['role_id', 'is_baseline']);
            $table->dropColumn('is_baseline');
        });
    }
};
