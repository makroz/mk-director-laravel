<?php

declare(strict_types=1);

namespace Mk\Director\Traits;

use Illuminate\Support\Facades\DB;
use Exception;

/**
 * Trait HasProgressiveCode
 * 
 * Genera códigos progresivos secuenciales y seguros contra concurrencia.
 * Útil para recibos, encuestas, asambleas, transacciones, etc.
 * 
 * Requiere tabla 'mk_progressive_codes' (id, scope, type, last_number, updated_at).
 * Configurable en config/mk_director.php.
 */
trait HasProgressiveCode
{
    /**
     * Boot the trait to generate the code on creating.
     */
    public static function bootHasProgressiveCode()
    {
        static::creating(function ($model) {
            // Permitir asignar un código manual si ya viene seteado
            if (!empty($model->code)) {
                return;
            }

            $scopeField = $model->getProgressiveCodeScopeField();
            $scopeValue = $scopeField ? $model->getAttribute($scopeField) : 'global';
            
            if ($scopeField && empty($scopeValue)) {
                throw new Exception("El campo scope '{$scopeField}' es requerido para generar el código progresivo en " . static::class);
            }

            $type = $model->getProgressiveCodeType();
            
            $model->code = self::generateNextCode($scopeValue, $type);
        });
    }

    /**
     * Define el campo usado para dar scope al código (ej: 'client_id', 'company_id').
     * Retorna null para usar un contador global para todo el tipo.
     */
    protected function getProgressiveCodeScopeField(): ?string
    {
        return property_exists($this, 'progressiveCodeScopeField') 
            ? $this->progressiveCodeScopeField 
            : null;
    }

    /**
     * Define el prefijo/tipo para el código progresivo.
     * Ej: 'SVY' para Survey, 'ASM' para Assembly.
     */
    protected function getProgressiveCodeType(): string
    {
        return property_exists($this, 'progressiveCodeType') 
            ? $this->progressiveCodeType 
            : strtoupper(substr(class_basename(static::class), 0, 3));
    }

    /**
     * Genera el siguiente código usando bloqueo de tabla para evitar race conditions.
     */
    public static function generateNextCode($scope, $type): string
    {
        return DB::transaction(function () use ($scope, $type) {
            $counterTable = config('mk_director.progressive_codes.table', 'mk_progressive_codes');
            $year = date('Y');
            
            // Intentar obtener el registro con bloqueo
            $counter = DB::table($counterTable)
                ->where('scope', (string)$scope)
                ->where('type', $type)
                ->lockForUpdate()
                ->first();

            if (!$counter) {
                // Insertar si no existe. Ignoramos colisiones si 2 transacciones insertan al mismo tiempo.
                DB::table($counterTable)->insertOrIgnore([
                    'scope' => (string)$scope,
                    'type' => $type,
                    'last_number' => 0,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                
                // Volver a obtener con bloqueo
                $counter = DB::table($counterTable)
                    ->where('scope', (string)$scope)
                    ->where('type', $type)
                    ->lockForUpdate()
                    ->first();
            }

            $nextNumber = $counter->last_number + 1;
            
            DB::table($counterTable)
                ->where('id', $counter->id)
                ->update([
                    'last_number' => $nextNumber,
                    'updated_at' => now()
                ]);
                
            $paddedNumber = str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
            return "{$type}-{$year}-{$paddedNumber}";
        });
    }
}
