<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Identidad polimórfica de un usuario en las pivots del RBAC (`role_user`,
 * `ability_user`).
 *
 * 🔴 POR QUÉ ESTO EXISTE: LA COLUMNA `user_type` SE ESCRIBÍA EN DOS IDIOMAS Y
 * NO SE LEÍA NUNCA.
 *
 * Había dos caminos de escritura y cada uno usaba un vocabulario distinto:
 *
 *   $admin->assignRole('editor');   →  user_type = "App\Modules\Admin\Models\Admin"
 *   $admin->roles()->attach($id);   →  user_type = "admin"
 *
 * El primero venía de `pivotExtras()`, que usaba `static::class`; el segundo de
 * `MkBelongsToMany`, que usa `getMorphClass()` y respeta el morph map. La misma
 * columna, la misma tabla, dos idiomas según por dónde entraste.
 *
 * Y lo grave no era la inconsistencia sino que NADIE FILTRABA POR ESA COLUMNA.
 * `belongsToMany(Role::class, 'role_user')` sólo empareja por `user_id`. Con
 * dos scopes que comparten el espacio de ids, medido:
 *
 *   admin #1  →  ['editor', 'otro']
 *   member #1 →  ['editor', 'otro']   ← los del admin
 *
 * Lo mismo con las abilities directas. Es escalación de privilegios entre
 * scopes: la columna que existía para impedirlo estaba de adorno.
 *
 * En RETO no llegó a dispararse porque los ids son UUID y el de un member
 * nunca coincide con el de un admin. Eso es suerte del esquema, no una
 * defensa: cualquier consumidor con ids autoincrementales —el default de
 * Laravel, y lo que scaffoldea el propio paquete— lo tiene activo.
 *
 * CÓMO SE CIERRA, Y POR QUÉ SE ACEPTAN LOS DOS IDIOMAS AL LEER.
 * Se escribe SIEMPRE `getMorphClass()` (el alias, si hay morph map), pero al
 * leer se aceptan el alias Y el FQCN. Si el filtro exigiera sólo el alias, en
 * el instante del deploy toda fila vieja escrita con FQCN dejaría de
 * matchear y TODO EL MUNDO PERDERÍA SUS ROLES hasta que corra la migración —
 * un arreglo de seguridad que provoca una caída total es peor que el agujero.
 *
 * Aceptar los dos no debilita nada: dos modelos distintos tienen alias
 * distinto Y FQCN distinto, así que el filtro sigue separando scopes. La
 * migración `..._normalize_rbac_pivot_user_type` pasa las filas viejas al
 * alias; cuando no queden FQCN en la base, el segundo valor se puede sacar.
 */
final class MorphPivot
{
    /**
     * Cache de "¿esta tabla tiene columna `user_type`?" por nombre de tabla.
     * `null` = indeterminado (no hay container ni conexión).
     *
     * Sin cache, cada `roles()` haría un `hasColumn()`, o sea una consulta al
     * information_schema por cada chequeo de autorización: el arreglo costaría
     * más que el problema.
     *
     * @var array<string, bool|null>
     */
    private static array $tieneUserType = [];

    /**
     * @return bool|null `null` si no se pudo determinar.
     *
     * 🔴 USA EL SCHEMA BUILDER DIRECTO Y NO EL FACADE `Schema`, y eso importa:
     * el facade necesita `db.schema` bindeado en el container, y hay setups
     * —el harness de este mismo paquete, sin ir más lejos— que no lo bindean.
     * Ahí `Schema::hasColumn()` falla en silencio y se cachea un `false`, o
     * sea "la columna no existe" cuando en realidad es "no pude mirar".
     *
     * Esta lógica estaba DUPLICADA en `MkBelongsToMany`, con esta versión
     * robusta de un lado y la del facade del otro. Dos caches independientes
     * de la misma pregunta que podían contestar distinto — y contestaron: un
     * test que dropeaba la columna veía la respuesta nueva por un lado y la
     * vieja por el otro, y el INSERT reventaba.
     */
    private static function detectar(string $tabla): ?bool
    {
        if (array_key_exists($tabla, self::$tieneUserType)) {
            return self::$tieneUserType[$tabla];
        }

        try {
            if (! function_exists('app') || ! app()->bound('db')) {
                return self::$tieneUserType[$tabla] = null;
            }

            $schema = app('db')->connection()->getSchemaBuilder();

            return self::$tieneUserType[$tabla] = $schema->hasColumn($tabla, 'user_type');
        } catch (\Throwable) {
            return self::$tieneUserType[$tabla] = null;
        }
    }

    /**
     * ¿Se puede FILTRAR por `user_type` en esta tabla?
     *
     * Sólo con certeza. Con indeterminado no se filtra: agregar un `WHERE`
     * sobre una columna que quizá no existe es un error de SQL en CADA
     * request, y eso es peor que el agujero que el filtro venía a tapar.
     */
    public static function hasUserTypeColumn(string $tabla): bool
    {
        return self::detectar($tabla) === true;
    }

    /**
     * ¿Se debe ESCRIBIR `user_type` al insertar en esta tabla?
     *
     * Acá sí se actúa con indeterminado, al revés que en la lectura: si la
     * columna existe y no la escribimos, la fila queda con un `user_type`
     * nulo que después no matchea nada. Si no existe, el INSERT falla fuerte
     * y se ve — mejor que fallar callado.
     */
    public static function shouldWriteUserType(string $tabla): bool
    {
        return self::detectar($tabla) !== false;
    }

    /** Valor a ESCRIBIR en `user_type`. Uno solo, y es el del morph map. */
    public static function canonical(Model $user): string
    {
        return $user->getMorphClass();
    }

    /**
     * Valores que se ACEPTAN al leer: el canónico más el FQCN, para no dejar
     * afuera las filas escritas antes de la normalización.
     *
     * @return list<string>
     */
    public static function identities(Model $user): array
    {
        return array_values(array_unique([$user->getMorphClass(), $user::class]));
    }

    /** Olvida la detección cacheada. Sólo para tests que crean tablas al vuelo. */
    public static function flushSchemaCache(): void
    {
        self::$tieneUserType = [];
    }
}
