<?php

declare(strict_types=1);

namespace Mk\Director\DTOs;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * DTO Factory - Crea DTOs automáticamente desde configuración
 * 
 * Soporta:
 * - DTO explícito definido por el dev
 * - Fallback automático si no hay DTO
 * - Validación de enums automática
 * - Detección de tipos desde el modelo
 */
class DTOFactory
{
    /**
     * Crear DTO desde request - detecta si hay DTO definido o usa fallback
     */
    public static function makeFromRequest(
        \Illuminate\Http\Request $request,
        string $modelClass,
        ?string $dtoClass = null,
        ?array $enumMap = null
    ): array {
        $data = $request->all();

        return self::makeFromArray($data, $modelClass, $dtoClass, $enumMap);
    }

    /**
     * Crear DTO desde array
     */
    public static function makeFromArray(
        array $data,
        string $modelClass,
        ?string $dtoClass = null,
        ?array $enumMap = null
    ): array {
        // Si hay DTO explícito, usarlo
        if ($dtoClass && class_exists($dtoClass)) {
            return self::makeFromDTO($data, $dtoClass);
        }

        // Fallback automático con validación
        return self::makeAuto($data, $modelClass, $enumMap ?? []);
    }

    /**
     * Usar DTO explícito
     */
    protected static function makeFromDTO(array $data, string $dtoClass): array
    {
        $dto = $dtoClass::fromArray($data);
        return $dto->toArray();
    }

    /**
     * Fallback automático - detecta tipos del modelo y valida
     */
    protected static function makeAuto(array $data, string $modelClass, array $enumMap): array
    {
        // Obtener fillable del modelo
        $model = new $modelClass;
        $fillable = $model->getFillable();

        // Filtrar solo campos del modelo
        $filtered = array_intersect_key($data, array_flip($fillable));

        // Validar enums
        foreach ($enumMap as $field => $enumClass) {
            if (isset($filtered[$field])) {
                $filtered[$field] = self::validateEnum($filtered[$field], $enumClass);
            }
        }

        // Convertir tipos automáticamente
        foreach ($filtered as $key => $value) {
            $filtered[$key] = self::castToModelType($model, $key, $value);
        }

        return $filtered;
    }

    /**
     * Validar valor de enum
     */
    protected static function validateEnum(mixed $value, string $enumClass): string|int|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Si ya es un enum, retornar su valor
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        // Intentar crear el enum
        try {
            return $enumClass::from($value)->value;
        } catch (\Throwable $e) {
            if (method_exists($enumClass, 'cases')) {
                $validValues = array_column($enumClass::cases(), 'value');
                throw new InvalidArgumentException(
                    "Valor inválido para el campo (Enum {$enumClass}): '{$value}'. "
                    . "Valores válidos: " . implode(', ', $validValues)
                );
            }
            throw new InvalidArgumentException("Valor inválido: '{$value}'");
        }
    }

    /**
     * Convertir al tipo del campo del modelo con estrictez PHP 8.2+
     */
    protected static function castToModelType(Model $model, string $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        // Obtener casting del modelo si existe
        $casts = $model->getCasts();

        if (isset($casts[$field])) {
            $castType = $casts[$field];

            // Soporte nativo para PHP 8.1+ Enums en la propiedad $casts del Model
            if (is_string($castType) && enum_exists($castType)) {
                return self::validateEnum($value, $castType);
            }
            $coreType = explode(':', is_string($castType) ? $castType : '')[0];
            $subType = explode(':', is_string($castType) ? $castType : ':')[1] ?? '';

            $isJson = in_array($coreType, ['array', 'json', 'jsonb', 'collection', 'object']) ||
                      ($coreType === 'encrypted' && in_array($subType, ['array', 'collection', 'object', 'json']));

            if ($isJson) {
                return (is_array($value) || is_object($value)) ? $value : (is_string($value) ? self::safeJsonDecode($value) : $value);
            }

            return match ($coreType) {
                'boolean', 'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value,
                'integer', 'int' => (int) $value,
                'float', 'double', 'real', 'decimal' => (float) $value,
                'datetime', 'date', 'timestamp' => $value instanceof \DateTime ? $value : ($value ? \Carbon\Carbon::parse($value) : null),
                default => $value,
            };
        }

        return $value;
    }

    /**
     * Decodificación JSON segura con soporte PHP 8.3+ json_validate
     */
    protected static function safeJsonDecode(string $value): array|object
    {
        if (function_exists('json_validate')) {
            if (!json_validate($value)) {
                throw new InvalidArgumentException("Payload JSON inválido detectado por DTOFactory.");
            }
        }
        
        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException("Payload JSON inválido: " . json_last_error_msg());
        }
        
        return $decoded;
    }

    public static function detectEnums(string $modelClass): array
    {
        $enums = [];
        
        $reflection = new \ReflectionClass($modelClass);
        $modelDir = dirname($reflection->getFileName());
        $modelNamespace = $reflection->getNamespaceName();

        // Resolve the enum namespace from the model's own namespace, not hardcoded App\Modules\.
        // e.g. App\Modules\Survey\Models\SurveyModel → App\Modules\Survey\Enums\
        $enumNamespace  = preg_replace('/\\\\Models$/', '\\Enums', $modelNamespace)
            ?: $modelNamespace . '\\Enums';

        if (function_exists('config')) {
            try {
                if ($resolver = config('mk_director.enum_namespace_resolver')) {
                    $enumNamespace = $resolver($modelClass, $modelNamespace);
                }
            } catch (\Throwable $e) {
                // Ignore container/binding resolution errors in bare unit tests
            }
        }

        $enumDir = dirname($modelDir) . '/Enums';
        $enumFiles = glob($enumDir . '/*Enum.php') ?: [];
        
        foreach ($enumFiles as $file) {
            $className = basename($file, '.php');
            
            // Mapear: SurveyStatusEnum -> status
            $fieldName = ltrim(
                strtolower(preg_replace('/([A-Z])/', '_$1', str_replace('Enum', '', $className))),
                '_'
            );

            $fqcn = $enumNamespace . '\\' . $className;

            if (class_exists($fqcn) || interface_exists($fqcn)) {
                $enums[$fieldName] = $fqcn;
            }
        }

        return $enums;
    }
}
