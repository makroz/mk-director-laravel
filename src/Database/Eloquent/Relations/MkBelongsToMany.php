<?php

declare(strict_types=1);

namespace Mk\Director\Database\Eloquent\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Concerns\HasAbilities;
use Mk\Director\Auth\Concerns\HasRoles;
use Mk\Director\Auth\Pivots\MkPivot;

/**
 * MkBelongsToMany — BelongsToMany del paquete que auto-inyecta `user_type`
 * en las mutaciones nativas cuando la pivot lo requiere.
 *
 * R-PKG-021 BUG-NEW-31 (CRITICAL, RC9 regression of HALLAZGO-NEW-01):
 *
 * En v1.6.0-rc9 se introdujo {@see MkPivot} con un
 * listener `creating` que debería setear `user_type` automáticamente. PERO
 * el listener NO se dispara en runtime para mutations nativas de Eloquent.
 *
 * Causa raíz (verificada contra vendor Laravel 13 `Concerns/InteractsWithPivotTable.php`):
 *  - `BelongsToMany::attach()` cuando `$this->using` está seteado delega a
 *    `attachUsingCustomClass($ids, $attributes)` (línea 377).
 *  - `attachUsingCustomClass()` itera los records y para cada uno hace:
 *      `$this->newPivot($record, false)->save();`
 *  - `newPivot()` instancia la pivot class (`MkRoleUserPivot`/`MkAbilityUserPivot`)
 *    con los attributes mergeados, retorna la instance, pero NO llama
 *    `setPivotParent($this->parent)` ANTES del `save()`.
 *  - Cuando se dispara el `creating` event, `$pivot->pivotParent` es `null`,
 *    así que el listener del `MkPivot::boot()` no puede setear `user_type`.
 *
 * Solución de raíz R-PKG-021: override `newPivot()` en esta subclass para:
 *   1. Inyectar `user_type = $this->parent->getMorphClass()` en `$attributes`
 *      ANTES de instanciar la pivot, cuando la pivot tiene la columna.
 *   2. Llamar `setPivotParent($this->parent)` en la pivot retornada, para que
 *      cualquier `creating`/`updating` listener adicional (incluido el de
 *      `MkPivot::boot()`) tenga contexto completo.
 *
 * Esto cubre TODAS las mutations nativas:
 *  - `attach()`: usa `attachUsingCustomClass()` cuando hay `using(...)` →
 *    override `newPivot()` aplica.
 *  - `attachNew()` (protected): llama `attach()` → cubre `sync()`,
 *    `syncWithoutDetaching()`, `attachNew()` directo.
 *  - `toggle()`: llama `attach()` + `detach()` → cubre attach.
 *  - `syncWithPivotValues()`: pasa attributes via el array key → nuestro
 *    `buildPivotPayload()` mergea correctamente.
 *
 * Defense-in-depth:
 *  - `MkPivot::boot()` listener sigue activo como segunda capa (cubre cualquier
 *    edge case donde pivotParent SÍ está seteado).
 *  - Opt-out via override de `roles()`/`directAbilities()` en el modelo concreto
 *    del consumer sin retornar esta clase.
 *  - BC-safe: si la pivot NO tiene columna `user_type` (consumer legacy),
 *    la inyección es no-op via `Schema::hasColumn()` cacheado.
 *
 * Cómo se usa (interno al paquete):
 *  El trait {@see HasRoles} usa `MkBelongsToMany::from()`
 *  para promover una `BelongsToMany` instance (creada por `belongsToMany()`)
 *  a `MkBelongsToMany` via reflection-based state copy. Esto preserva todo
 *  el setup interno de Laravel (query builder, related model, keys) y
 *  solo cambia el tipo de la instance para que `newPivot()` overridee.
 *
 * Custom pivots: extender {@see MkPivot} y declarar
 * `protected $table = '<nombre_custom>'`. La relation custom se aplica
 * automáticamente al extender las traits del paquete.
 *
 * @see MkPivot (defense-in-depth via listener)
 * @see HasRoles::roles()
 * @see HasAbilities::directAbilities()
 */
class MkBelongsToMany extends BelongsToMany
{
    /**
     * Cache de detección de `user_type` column por tabla.
     *
     * El cache guarda TRES estados:
     *  - `true`:  la pivot TIENE columna `user_type` → merge aplica.
     *  - `false`: la pivot NO TIENE columna `user_type` (consumer legacy) → merge no-op.
     *  - `null`:  no se pudo detectar (DB no disponible / facade falla) → tratar como
     *             "no se puede decidir", comportamiento conservador.
     *
     * Se cachea por tabla para evitar query a information_schema por cada attach.
     *
     * @var array<string, bool|null>
     */
    private static array $userTypeColumnCache = [];

    /**
     * Crea una MkBelongsToMany a partir de una BelongsToMany,
     * copiando el estado interno via reflection.
     *
     * Por qué reflection-copy y no `new MkBelongsToMany(...)`:
     *  - El constructor de `BelongsToMany` toma 8 argumentos (query, parent,
     *    table, foreignPivotKey, relatedPivotKey, parentKey, relatedKey,
     *    relationName). Calcular todos manualmente es frágil y duplica
     *    lógica interna de Laravel.
     *  - `belongsToMany()` (en Model) ya hace ese setup correctamente.
     *    Reflection-copy preserva ese setup y solo cambia el tipo de la
     *    instance, así las llamadas dinámicas a métodos nativos
     *    (`sync()`, `syncWithoutDetaching()`, etc.) terminan llamando a
     *    NUESTRO `newPivot()` override vía dynamic dispatch de PHP.
     *
     * Si Laravel agrega nuevas properties internas a `BelongsToMany`, este
     * método las copia automáticamente via reflection (no hay lista hardcoded).
     * Trade-off: depende de que las properties nuevas sean compatibles con
     * `MkBelongsToMany` (que no agrega ninguna property nueva conflictiva).
     *
     * IMPORTANTE: usa `static::class` (late static binding) en vez de
     * `self::class` para que subclases de `MkBelongsToMany` (e.g. para tests)
     * se instancien correctamente. Sin esto, una llamada a
     * `DebugMkBelongsToMany::from($source)` retornaría una instance de
     * `MkBelongsToMany` (clase padre), perdiendo los overrides de la subclase.
     */
    public static function from(BelongsToMany $source): self
    {
        $instance = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();

        $sourceReflection = new \ReflectionObject($source);
        $targetReflection = new \ReflectionObject($instance);

        foreach ($sourceReflection->getProperties() as $sourceProp) {
            if (! $targetReflection->hasProperty($sourceProp->getName())) {
                continue;
            }

            $targetProp = $targetReflection->getProperty($sourceProp->getName());
            $sourceProp->setAccessible(true);
            $targetProp->setAccessible(true);

            if (! $sourceProp->isInitialized($source)) {
                continue;
            }

            $value = $sourceProp->getValue($source);

            // Skip typed properties that wouldn't accept the value type.
            // Esto cubre el caso donde Laravel agrega una property typed que
            // no podemos asignar sin error. Para las properties standard de
            // BelongsToMany, esto no aplica.
            if ($targetProp->hasType()) {
                $type = $targetProp->getType();
                if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin()) {
                    $typeName = $type->getName();
                    if ($value !== null && ! ($value instanceof $typeName)) {
                        continue;
                    }
                }
            }

            $targetProp->setValue($instance, $value);
        }

        return $instance;
    }

    /**
     * Override del hook público `newPivot()` para inyectar `user_type`
     * y setear `pivotParent` antes del save.
     *
     * Laravel llama a este método desde `attachUsingCustomClass()` y desde
     * otros paths. Al overridearlo aquí, garantizamos que TODA pivot creada
     * vía esta relation tenga `user_type` correcto desde el inicio.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function newPivot(array $attributes = [], $exists = false)
    {
        $attributes = $this->mergeUserTypeIntoAttributes($attributes);

        $pivot = parent::newPivot($attributes, $exists);

        // CRÍTICO: setear pivotParent ANTES de save para que cualquier
        // listener `creating` (incluido el de MkPivot::boot()) tenga contexto.
        // Sin esto, $pivot->pivotParent es null en runtime y el listener
        // no puede setear user_type aunque quisieramos delegar a él.
        if ($this->parent !== null && method_exists($pivot, 'setPivotParent')) {
            $pivot->setPivotParent($this->parent);
        }

        return $pivot;
    }

    /**
     * Override de `attach()` para inyectar `user_type` cuando se llama con
     * array de IDs (caso típico del feedback de RETO).
     *
     * Laravel's `attach([1, 2, 3])` con `using(...)` delega a
     * `attachUsingCustomClass([1,2,3], [])` que llama `newPivot($record, false)`
     * por cada ID. Como nuestro `newPivot()` override ya inyecta `user_type`
     * cuando aplica, el caso array-id queda cubierto transitivamente.
     *
     * PERO si el consumer pasa `attach([1, 2, 3], ['extra' => 'value'])`,
     * Laravel itera con los attributes correctos — y nuestro `newPivot()`
     * mergea `user_type` con esos attributes sin pisar.
     *
     * Este override explícito es defense-in-depth: si Laravel cambia el
     * flow de attach en el futuro, todavía inyectamos `user_type` antes.
     *
     * @param  mixed  $ids  int|string|array<int, int|string|Model>|Model
     * @param  array<string, mixed>  $attributes
     */
    public function attach($ids, array $attributes = [], $touch = true)
    {
        $merged = $this->mergeUserTypeIntoAttributes($attributes);

        // Si $using está seteado, replicar el flow de attachUsingCustomClass()
        // manualmente. Esto evita depender del path "if ($this->using)" del
        // parent::attach(), que en runtime con reflection-promoted instances
        // puede no rutear correctamente al path correcto.
        $using = $this->readUsingProperty();

        if ($using !== null && $using !== '') {
            // Replicar attachUsingCustomClass() (protected en BelongsToMany).
            $records = $this->formatAttachRecords($this->parseIds($ids), $merged);

            foreach ($records as $record) {
                $this->newPivot($record, false)->save();
            }

            if ($touch) {
                $this->touchIfTouching();
            }

            return;
        }

        // Path legacy: delegar a parent::attach() (INSERT directo sin using).
        parent::attach($ids, $merged, $touch);
    }

    /**
     * Lee la property `using` via reflection (es protected en BelongsToMany).
     *
     * @return string|null
     */
    private function readUsingProperty(): ?string
    {
        $reflection = new \ReflectionClass(\Illuminate\Database\Eloquent\Relations\BelongsToMany::class);
        if (! $reflection->hasProperty('using')) {
            return null;
        }

        $prop = $reflection->getProperty('using');
        $prop->setAccessible(true);

        return $prop->getValue($this);
    }

    /**
     * Merge `user_type` en los attributes de la pivot cuando aplica.
     *
     * Reglas:
     *  1. Si el consumer ya pasó `user_type` explícito, respetar (no pisar).
     *  2. Si la pivot NO tiene columna `user_type` (consumer legacy),
     *     retornar attributes sin cambios.
     *  3. Si hay parent, setear `user_type = $parent->getMorphClass()`.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function mergeUserTypeIntoAttributes(array $attributes): array
    {
        // 1. Respetar override explícito.
        if (isset($attributes['user_type']) && $attributes['user_type'] !== null) {
            return $attributes;
        }

        // 2. Detectar si la pivot tiene columna user_type (cacheado).
        $table = $this->getTable();

        if (! isset(self::$userTypeColumnCache[$table])) {
            // Usar DB::connection()->getSchemaBuilder() directamente — más
            // robusto que el facade Schema::hasColumn() que requiere
            // `db.schema` bindeado en el container (algunos setups como
            // Capsule-based testing no lo bindean, lo cual hace fallar
            // silenciosamente Schema::hasColumn y cachear `false`).
            try {
                if (! function_exists('app')) {
                    self::$userTypeColumnCache[$table] = null;
                } else {
                    $app = app();
                    if (! $app->bound('db')) {
                        self::$userTypeColumnCache[$table] = null;
                    } else {
                        $schema = $app->make('db')->connection()->getSchemaBuilder();
                        self::$userTypeColumnCache[$table] = $schema->hasColumn($table, 'user_type') ? true : false;
                    }
                }
            } catch (\Throwable) {
                // DB no disponible / tabla no existe / facade falla.
                // Marcar como "indeterminado" (null) — el merge intentará con
                // verificación adicional más abajo.
                self::$userTypeColumnCache[$table] = null;
            }
        }

        // 3. Si el cache dice "no tiene columna" explícitamente, no forzar.
        if (self::$userTypeColumnCache[$table] === false) {
            return $attributes;
        }

        // 4. Si el cache es null (indeterminado) o true, intentar setear user_type.
        //    Defense-in-depth: si la columna no existe, el INSERT fallará
        //    con SQLSTATE — eso es OK, mejor que pinear silenciosamente.
        if ($this->parent !== null) {
            $attributes['user_type'] = $this->parent->getMorphClass();
        }

        return $attributes;
    }

    /**
     * Helper para tests que necesitan limpiar el cache entre casos.
     * No es parte del API público; usar solo desde test code.
     *
     * @internal
     */
    public static function clearUserTypeCache(): void
    {
        self::$userTypeColumnCache = [];
    }
}
