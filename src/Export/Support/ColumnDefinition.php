<?php

declare(strict_types=1);

namespace Mk\Director\Export\Support;

use BackedEnum;
use Closure;

/**
 * La definición de UNA columna del reporte.
 *
 * Reemplaza los arrays `EXPORT_COLUMNS` que vivían inline en cada reporte.
 * Sabe cuatro cosas: de dónde sacar el valor, cómo formatearlo para leer,
 * cómo devolverlo para calcular, y si entra en el renglón de totales.
 *
 * ```php
 * // Ancho automático: el motor reparte 100 / cantidad de columnas.
 * ColumnDefinition::make('amount')->label('Monto')->format('currency')->sumarize(),
 *
 * // Ancho explícito y enum resuelto por su clase.
 * ColumnDefinition::make('status')
 *     ->label('Estado')
 *     ->width('15%')
 *     ->phpEnum(ExpenseStatus::class),
 *
 * // Renderer propio para lo que no entra en un access path.
 * ColumnDefinition::make('category_path')
 *     ->label('Categoría')
 *     ->renderer(fn ($row) => RowValue::at($row, 'category.parent.name')
 *         ? RowValue::at($row, 'category.parent.name').' > '.RowValue::at($row, 'category.name')
 *         : RowValue::at($row, 'category.name')),
 * ```
 *
 * Es inmutable: cada método devuelve una instancia nueva. Así una columna
 * declarada en una constante no se puede mutar sin querer desde un `foreach`.
 */
final class ColumnDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly string $label = '',
        public readonly ?string $width = null,
        public readonly string $align = 'left',
        public readonly ?string $format = null,
        public readonly ?array $enumMap = null,
        public readonly ?string $phpEnum = null,
        public readonly ?Closure $renderer = null,
        /**
         * Si esta columna se suma en el renglón de totales del pie.
         *
         * 🔴 Va acá, junto al ancho y la alineación, porque es una propiedad
         * de la COLUMNA: quien la declara ya sabe si es plata o si es texto.
         *
         * ⚠️ El TEXTO del renglón NO vive acá: nombra a la fila, no a una
         * columna. Si viviera en la columna, dos columnas sumadas podrían
         * declarar dos etiquetas distintas para el mismo renglón. Lo declara
         * la config, en `etiquetaDeTotales()`.
         */
        public readonly bool $sumarize = false,
    ) {}

    public static function make(string $key): self
    {
        return new self(key: $key);
    }

    public function label(string $label): self
    {
        return $this->con(label: $label);
    }

    public function width(string $width): self
    {
        return $this->con(width: $width);
    }

    public function align(string $align): self
    {
        return $this->con(align: $align);
    }

    public function format(string $format): self
    {
        return $this->con(format: $format);
    }

    public function enumMap(array $enumMap): self
    {
        return $this->con(enumMap: $enumMap);
    }

    public function phpEnum(string $phpEnum): self
    {
        return $this->con(phpEnum: $phpEnum);
    }

    public function renderer(Closure $renderer): self
    {
        return $this->con(renderer: $renderer);
    }

    /**
     * Marca la columna para el renglón de totales. Default `false`: la mayoría
     * de las columnas no son plata.
     */
    public function sumarize(bool $sumarize = true): self
    {
        return $this->con(sumarize: $sumarize);
    }

    /**
     * La copia con un campo cambiado.
     *
     * 🔴 Existe porque el original repetía la lista COMPLETA de los nueve
     * argumentos en cada `with*`. Agregar una propiedad obligaba a tocar los
     * nueve métodos, y olvidarse de uno hace que ese método la pierda en
     * silencio: la columna sale sin el dato y nada falla.
     *
     * El sentinela es necesario porque `null` es un valor legítimo para casi
     * todos estos campos y no se puede usar para decir "no lo toques".
     */
    private function con(mixed ...$cambios): self
    {
        $actual = [
            'key' => $this->key,
            'label' => $this->label,
            'width' => $this->width,
            'align' => $this->align,
            'format' => $this->format,
            'enumMap' => $this->enumMap,
            'phpEnum' => $this->phpEnum,
            'renderer' => $this->renderer,
            'sumarize' => $this->sumarize,
        ];

        return new self(...array_merge($actual, $cambios));
    }

    /**
     * `true` si el ancho lo tiene que calcular el motor en runtime.
     */
    public function isAutoWidth(): bool
    {
        return $this->width === null || $this->width === 'auto' || $this->width === '';
    }

    /**
     * El ancho final en CSS. Automático reparte `100 / $totalColumns`; un
     * ancho numérico suelto (e.g. `'15'`) se interpreta como porcentaje.
     */
    public function resolvedWidth(int $totalColumns): string
    {
        if (! $this->isAutoWidth()) {
            return is_numeric($this->width) ? $this->width.'%' : (string) $this->width;
        }

        $auto = $totalColumns > 0 ? 100 / $totalColumns : 100;

        return number_format($auto, 2, '.', '').'%';
    }

    /**
     * Extrae el valor de la fila. El renderer, si existe, tiene prioridad
     * sobre el access path.
     */
    public function extractValue(mixed $row): mixed
    {
        if ($this->renderer !== null) {
            return ($this->renderer)($row);
        }

        return RowValue::at($row, $this->key);
    }

    /**
     * El valor tal como se LEE: formateado, y con la celda vacía marcada.
     *
     * Prioridad: enum por clase → enum ya casteado → mapa explícito → formato
     * predefinido → el valor tal cual.
     *
     * 🔴 La celda vacía se MARCA acá, no en cada reporte. Antes cada config lo
     * decidía columna por columna y por eso no había regla: un módulo marcaba
     * tres columnas, otro una, otro ninguna. El mismo dato faltante se veía
     * distinto según el reporte. Acá no hay que acordarse: una columna nueva
     * ya nace marcando.
     *
     * ⚠️ Sólo para el PDF. El XLSX y el CSV salen por {@see rawValue} y ahí la
     * celda vacía se queda VACÍA a propósito: son formatos para calcular, y un
     * "-/-" metido en una columna de importes rompe cualquier `SUM`.
     */
    public function formatValue(mixed $value): mixed
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return TextFormat::PLACEHOLDER;
        }

        if ($this->phpEnum !== null && enum_exists($this->phpEnum)) {
            return EnumResolver::resolve($value, $this->phpEnum);
        }

        if ($this->format === 'enum' && $value instanceof BackedEnum) {
            return EnumResolver::resolveGeneric($value);
        }

        if ($this->enumMap !== null && $this->mapeable($value) && array_key_exists($value, $this->enumMap)) {
            return $this->enumMap[$value];
        }

        return match ($this->format) {
            'currency' => number_format((float) $value, 2, '.', ','),
            'integer' => (int) $value,
            'decimal:2' => number_format((float) $value, 2, '.', ','),
            'decimal:4' => number_format((float) $value, 4, '.', ','),
            'percentage' => number_format((float) $value * 100, 2, '.', ',').'%',
            // 🔴 Acá vivía una SEGUNDA tabla de formatos de fecha, escrita a
            // mano, y se había separado de la de `DateFormat`. Encima ésta no
            // convertía la zona horaria, así que el mismo registro salía con
            // horas distintas —y a veces con DÍAS distintos— según por qué
            // reporte entraras. Ahora hay un solo lugar que sabe esto.
            default => $this->esFecha()
                ? DateFormat::format($value, (string) $this->format)
                : $value,
        };
    }

    /**
     * El valor tal como se CALCULA: sin formato visual, sólo el cast.
     *
     * Es lo que necesita el XLSX para que el usuario pueda hacer `SUM` sobre
     * la columna. `formatValue('currency')` da `"1,800.00"` —texto, no
     * computable—; `rawValue('currency')` da `1800.0`, y el generador le pone
     * a la celda el formato nativo `#,##0.00` para que igual se lea bien.
     *
     * ⚠️ Los enums y las fechas SÍ salen como texto: Excel no tiene un tipo
     * para "estado de una expensa", y una fecha como número crudo es un
     * entero que nadie reconoce.
     */
    public function rawValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($this->phpEnum !== null && enum_exists($this->phpEnum)) {
            return EnumResolver::resolve($value, $this->phpEnum);
        }

        if ($this->format === 'enum' && $value instanceof BackedEnum) {
            return EnumResolver::resolveGeneric($value);
        }

        if ($this->enumMap !== null && $this->mapeable($value) && array_key_exists($value, $this->enumMap)) {
            return $this->enumMap[$value];
        }

        return match ($this->format) {
            'currency', 'decimal:2', 'decimal:4' => (float) $value,
            'integer' => (int) $value,
            'percentage' => (float) $value * 100,
            default => $this->esFecha()
                ? $this->formatValue($value)
                : $value,
        };
    }

    /**
     * Si el formato declarado es uno de fecha. La lista sale de la config: si
     * estuviera escrita a mano habría que acordarse de sumar cada formato
     * nuevo en los dos lados, o el PDF sale con la fecha bien y el XLSX con el
     * timestamp crudo.
     */
    public function esFecha(): bool
    {
        return $this->format !== null
            && in_array($this->format, DateFormat::nombresValidos(), true);
    }

    /**
     * ⚠️ `array_key_exists` con una clave que no es int|string tira
     * `TypeError` en PHP 8. Un valor de columna puede ser cualquier cosa —un
     * float, un objeto, un array de una relación mal declarada—, así que la
     * clave se verifica ANTES de tocar el mapa.
     */
    private function mapeable(mixed $value): bool
    {
        return is_int($value) || is_string($value);
    }

    /**
     * Los encabezados, para el `thead` del PDF, la fila 0 del XLSX y la
     * cabecera del CSV.
     *
     * @param  ColumnDefinition[]  $columns
     * @return string[]
     */
    public static function headersFor(array $columns): array
    {
        return array_map(fn (self $c) => $c->label, $columns);
    }
}
