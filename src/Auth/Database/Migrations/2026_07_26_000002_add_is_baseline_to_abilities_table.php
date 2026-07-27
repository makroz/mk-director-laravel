<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `is_baseline` a la tabla de abilities.
 *
 * Una ability BASELINE la tiene cualquier usuario autenticado del scope por el
 * solo hecho de existir: ver el propio perfil, ver el muro, cambiarse la
 * contraseña. Se declara con `#[Ability('...', baseline: true)]` y la persiste
 * `mk:discover-abilities`.
 *
 * 🔴 POR QUÉ HACE FALTA UNA COLUMNA Y NO ALCANZA CON EL ROL BASE.
 *
 * Sin ella, la única marca de "esto es baseline" sería estar dentro del rol
 * base. Pero a ese rol también le puede agregar cosas un admin desde la UI, y
 * entonces el discovery no tendría forma de distinguir lo que él mismo puso de
 * lo que puso una persona. Le quedan dos salidas y las dos son malas: sincronizar
 * a lo bruto —y borrarle el trabajo al admin sin avisar— o no borrar nunca —y
 * que sacar `baseline: true` del código NO revoque el permiso—.
 *
 * La columna corta el nudo: el discovery sólo toca lo que él marcó.
 *
 * 🔴 POR QUÉ `boolean` Y NO `unsignedTinyInteger` COMO SU VECINA `is_fixed`.
 *
 * Es una desviación consciente del patrón de al lado, así que va explicada.
 * `is_fixed` es un enum (`FixedStatus`) porque tiene etiquetas que la UI muestra
 * — "Fijo" / "Editable" — y podría crecer a un tercer estado. `is_baseline` no:
 * es un predicado binario que no admite un tercer valor con sentido. Tiparlo
 * como enum sería ceremonia sin nada que la justifique.
 *
 * 🔴 LA TABLA NO SIEMPRE SE LLAMA `abilities`.
 *
 * El scaffolder tiene dos caminos con schema distinto: `mk:module X --with-rbac`
 * crea `{scope}_abilities` per-scope, y `mk:make:auth-user X --with-crud` usa la
 * `abilities` global del paquete. Esta migración es del PAQUETE, así que sólo
 * puede hacerse cargo de la global — las per-scope las creó el consumidor y el
 * paquete no sabe ni cuáles son ni cuántas hay.
 *
 * Por eso la columna se agrega si la tabla existe, y el discovery pregunta si la
 * columna está antes de escribirla: en una per-scope vieja, baseline no se
 * persiste y el comando lo AVISA, en vez de reventar con un error de SQL que no
 * nombra el problema.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('abilities') || Schema::hasColumn('abilities', 'is_baseline')) {
            return;
        }

        Schema::table('abilities', function (Blueprint $table): void {
            $table->boolean('is_baseline')->default(false)->after('description');

            // El discovery pregunta "¿cuáles son las baseline de este scope?" en
            // cada corrida, y el rol base se arma con esa respuesta. Sin índice
            // es un scan de toda la tabla de permisos.
            $table->index('is_baseline');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('abilities') || ! Schema::hasColumn('abilities', 'is_baseline')) {
            return;
        }

        Schema::table('abilities', function (Blueprint $table): void {
            $table->dropIndex(['is_baseline']);
            $table->dropColumn('is_baseline');
        });
    }
};
