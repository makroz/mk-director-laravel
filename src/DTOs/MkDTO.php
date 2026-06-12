<?php

namespace Mk\Director\DTOs;

use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * MkDTO - Base class for Data Transfer Objects
 * 
 * Proporciona:
 * - Type safety con propiedades tipadas
 * - Validación automática de enums
 * - Conversión desde Request o Array
 * - Métodos de validación
 */
abstract class MkDTO
{
    /**
     * Create DTO from Request
     */
    public static function fromRequest(Request $request): static
    {
        return static::fromArray($request->all());
    }

    /**
     * Create DTO from array
     */
    public static function fromArray(array $data): static
    {
        $dto = new static();
        
        foreach ($data as $key => $value) {
            if (!property_exists($dto, $key)) {
                continue;
            }

            $casted = $dto->validateAndCast($key, $value);

            // PHP 8.2+: readonly properties cannot be assigned outside the constructor
            // after initialization. We detect this early and provide a clear error.
            $prop = new \ReflectionProperty($dto, $key);
            if ($prop->isReadOnly() && $prop->isInitialized($dto)) {
                throw new \LogicException(
                    sprintf(
                        'Cannot hydrate readonly property %s::$%s after construction. '
                        . 'Override fromArray() in your DTO to inject values via the constructor.',
                        static::class,
                        $key
                    )
                );
            }

            $dto->{$key} = $casted;
        }

        return $dto;
    }

    /**
     * Convertir a array para crear modelo
     */
    public function toArray(): array
    {
        $data = [];
        $reflection = new \ReflectionClass($this);
        
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED) as $property) {
            $key = $property->getName();
            
            // PHP 8+ Uninitialized check
            if ($property->isInitialized($this)) {
                $value = $this->{$key};
                if ($value !== null) {
                    $data[$key] = $value;
                }
            }
        }

        return $data;
    }

    /**
     * Detectar enums en el modelo
     */
    public static function detectEnums(string $modelClass): array
    {
        $enums = [];

        $reflection = new \ReflectionClass($modelClass);
        $modelDir  = dirname($reflection->getFileName());
        $modelName = $reflection->getShortName();

        // Resolve the enum namespace from the model's own namespace, not hardcoded App\Modules\.
        // e.g. App\Modules\Survey\Models\SurveyModel → App\Modules\Survey\Enums\
        $modelNamespace = $reflection->getNamespaceName();
        $enumNamespace  = preg_replace('/\\\\Models$/', '\\Enums', $modelNamespace)
            ?: $modelNamespace . '\\Enums';

        // Allow override via config (e.g. config('mk_director.enum_namespace_resolver'))
        if (function_exists('config')) {
            try {
                if ($resolver = config('mk_director.enum_namespace_resolver')) {
                    $enumNamespace = $resolver($modelClass, $modelNamespace);
                }
            } catch (\Throwable $e) {
                // Ignore container/binding resolution errors in bare unit tests
            }
        }

        $enumFiles = glob($modelDir . '/*Enum.php') ?: [];

        foreach ($enumFiles as $file) {
            $className = basename($file, '.php');

            // Mapear: SurveyStatusEnum → status_status (snake, sin sufijo Enum)
            $fieldName = ltrim(
                strtolower(preg_replace('/([A-Z])/', '_$1', str_replace('Enum', '', $className))),
                '_'
            );

            $fqcn = $enumNamespace . '\\' . $className;

            // Solo registrar si la clase realmente existe (evita referencias rotas)
            if (class_exists($fqcn) || interface_exists($fqcn)) {
                $enums[$fieldName] = $fqcn;
            }
        }

        return $enums;
    }

    /**
     * Validar y convertir valor al tipo de la propiedad
     */
    protected function validateAndCast(string $key, mixed $value): mixed
    {
        $types = (array) $this->getPropertyType($key);
        
        if ($value === null || in_array('mixed', $types)) {
            return $value;
        }

        // Validar enum si existe
        if ($enumType = $this->isEnumProperty($key)) {
            return $this->validateEnum($key, $enumType, $value);
        }

        // Si es una union, seleccionar el primer tipo representativo para casting
        $type = current(array_filter($types, fn($t) => $t !== 'null' && $t !== 'mixed')) ?: 'mixed';

        return match ($type) {
            'string' => $this->castToString($value),
            'int', 'integer' => $this->castToInt($value),
            'float', 'double' => $this->castToFloat($value),
            'bool', 'boolean' => $this->castToBool($value),
            'array' => $this->castToArray($value),
            'DateTime', 'datetime' => $this->castToDateTime($value),
            default => $value,
        };
    }

    /**
     * Obtener tipo de propiedad usando reflection (Soporta PHP 8 DNF / Unions)
     */
    protected function getPropertyType(string $property): string|array
    {
        $reflection = new \ReflectionProperty($this, $property);
        $type = $reflection->getType();
        
        if ($type instanceof \ReflectionNamedType) {
            return $type->getName();
        }
        
        if ($type instanceof \ReflectionUnionType) {
            $out = [];
            foreach ($type->getTypes() as $t) {
                if ($t instanceof \ReflectionNamedType) {
                    $out[] = $t->getName();
                }
            }
            return !empty($out) ? $out : 'mixed';
        }
        
        return 'mixed';
    }

    /**
     * Verificar si la propiedad es un enum en una definición singular o Union
     */
    protected function isEnumProperty(string $property): string|bool
    {
        $types = (array) $this->getPropertyType($property);
        
        foreach ($types as $type) {
            if (enum_exists($type)) {
                return $type;
            }

            if (class_exists($type)) {
                $reflection = new \ReflectionClass($type);
                if ($reflection->isEnum()) {
                    return $type;
                }
            }
        }

        return false;
    }

    /**
     * Validar valor de enum
     */
    protected function validateEnum(string $property, string $enumClass, mixed $value): \BackedEnum|string|int
    {
        // Si el valor ya es el enum, retornarlo
        if ($value instanceof \BackedEnum) {
            return $value;
        }

        // Si es un string o int, intentar crear el enum
        try {
            return $enumClass::from($value);
        } catch (\Throwable $e) {
            $validValues = array_column($enumClass::cases(), 'value');
            throw new InvalidArgumentException(
                "Valor inválido para {$property}: '{$value}'. "
                . "Valores válidos: " . implode(', ', $validValues)
            );
        }
    }

    // --- Cast Methods ---

    protected function castToString(mixed $value): string
    {
        return match (gettype($value)) {
            'string' => $value,
            'integer', 'double', 'boolean' => (string) $value,
            'array' => json_encode($value),
            default => (string) $value,
        };
    }

    protected function castToInt(mixed $value): int
    {
        return match (gettype($value)) {
            'integer' => $value,
            'string' => (int) filter_var($value, FILTER_SANITIZE_NUMBER_INT),
            'double' => (int) $value,
            'boolean' => $value ? 1 : 0,
            default => (int) $value,
        };
    }

    protected function castToFloat(mixed $value): float
    {
        return match (gettype($value)) {
            'double' => $value,
            'integer' => (float) $value,
            'string' => (float) filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_THOUSAND),
            'boolean' => $value ? 1.0 : 0.0,
            default => (float) $value,
        };
    }

    protected function castToBool(mixed $value): bool
    {
        return match (gettype($value)) {
            'boolean' => $value,
            'integer' => $value !== 0,
            'string' => in_array(strtolower($value), ['true', '1', 'yes', 'on']),
            default => (bool) $value,
        };
    }

    protected function castToArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            // Soporte estricto para JSON validación PHP 8.3+
            if (function_exists('json_validate')) {
                if (!json_validate($value)) {
                    throw new InvalidArgumentException("Formato JSON inválido detectado en DTO.");
                }
            }
            
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException("Formato JSON inválido: " . json_last_error_msg());
            }
            
            return is_array($decoded) ? $decoded : [$value];
        }

        return (array) $value;
    }

    protected function castToDateTime(mixed $value): ?\DateTime
    {
        if ($value instanceof \DateTime) {
            return $value;
        }

        if (empty($value)) {
            return null;
        }

        try {
            return new \DateTime($value);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException(
                "Formato de fecha inválido: '{$value}'. Use formato Y-m-d H:i:s"
            );
        }
    }

    /**
     * Obtener errores de validación
     * Override en child class para reglas personalizadas
     */
    public static function validate(array $data): array
    {
        return [];
    }
}
