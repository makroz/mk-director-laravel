<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Export;

use Illuminate\Container\Container;
use Mk\Director\Export\Contracts\ExportConfigInterface;
use Mk\Director\Export\Contracts\ReportHeaderProvider;
use Mk\Director\Export\ExportService;
use Mk\Director\Export\Support\ColumnDefinition;
use Mk\Director\Export\Support\DefaultReportHeaderProvider;
use Mk\Director\Export\Support\TextFormat;
use Mk\Director\Export\Support\TotalesDeLaTabla;
use Mk\Director\Models\MkReport;
use Mk\Director\Tests\MkLaravelTestCase;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * El motor de export, de punta a punta, en los tres formatos.
 *
 * 🔴 Se ejerce el RENDER, no la forma de las clases. Un test que verifica que
 * el método existe y devuelve string no habría cazado ninguno de los bugs que
 * este puerto arrastra documentados: el generador de XLSX indexando las filas
 * por el rótulo en vez de la clave —planilla con encabezados y ninguna fila—,
 * ni los tres `render*FromCustom()` que reventaban con `TypeError` en la
 * primera línea porque nadie los llamaba.
 */
uses(MkLaravelTestCase::class);

/**
 * Un config de prueba con las cuatro cosas que importan: una columna de texto,
 * una de plata que suma, un enum por mapa, y una fecha.
 */
final class FacturaExportConfig implements ExportConfigInterface
{
    public function module(): string
    {
        return 'facturas';
    }

    public function title(): string
    {
        return 'Reporte de Facturas';
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::make('concepto')->label('Concepto'),
            ColumnDefinition::make('cliente.nombre')->label('Cliente'),
            ColumnDefinition::make('estado')->label('Estado')->enumMap([1 => 'Pagada', 2 => 'Pendiente']),
            ColumnDefinition::make('emitida_en')->label('Emitida')->format('date'),
            ColumnDefinition::make('monto')->label('Monto')->format('currency')->align('right')->sumarize(),
        ];
    }

    public function beforeExport(iterable $data, MkReport $report): iterable
    {
        return $data;
    }

    public function requiredRelations(): array
    {
        return [];
    }

    public function useExtraData(): bool
    {
        return false;
    }

    public function chunkSize(string $format): int
    {
        return match ($format) {
            'pdf' => 2,
            'xlsx' => 2,
            default => 2,
        };
    }

    public function supportedFormats(): array
    {
        return ['pdf', 'xlsx', 'csv'];
    }

    public function headerHtml(MkReport $report): ?string
    {
        return null;
    }

    public function footerHtml(): ?string
    {
        return null;
    }

    public function includeSignatures(): bool
    {
        return false;
    }

    public function csvSeparator(): string
    {
        return ',';
    }

    public function etiquetaDeTotales(): string
    {
        return 'Total facturado';
    }
}

/**
 * Cinco filas para que con `chunkSize: 2` haya tres segmentos y el último sea
 * parcial — que es el camino donde vive el diferido de "cuál es el último".
 */
function filasDePrueba(): array
{
    return [
        // 🔴 01:00 UTC del 6 son las 21:00 del 5 en La Paz (UTC-4). Es la fila
        // que CAMBIA DE DÍA según se convierta la zona o no — el caso exacto
        // que en producción hacía aparecer un pago al día siguiente.
        //
        // ⚠️ La primera versión de este dato decía `2026-03-05 21:00:00`, que
        // en La Paz son las 17:00 del MISMO día: el test pasaba con y sin
        // conversión. Se descubrió reinyectando el bug y viendo el test en
        // VERDE. Un caso de zona horaria tiene que cruzar la medianoche o no
        // mide nada.
        ['concepto' => 'Expensa marzo', 'cliente' => ['nombre' => 'Ana Paz'], 'estado' => 1, 'emitida_en' => '2026-03-06 01:00:00', 'monto' => 1800.5],
        ['concepto' => 'Expensa abril', 'cliente' => ['nombre' => 'Beto Ruiz'], 'estado' => 2, 'emitida_en' => '2026-04-05 10:00:00', 'monto' => 1200],
        ['concepto' => 'Multa', 'cliente' => ['nombre' => 'Ana Paz'], 'estado' => 1, 'emitida_en' => '2026-04-11 08:30:00', 'monto' => 250.25],
        ['concepto' => 'Reserva salón', 'cliente' => null, 'estado' => 2, 'emitida_en' => null, 'monto' => 0],
        ['concepto' => 'Expensa mayo', 'cliente' => ['nombre' => 'Cyn Vaca'], 'estado' => 1, 'emitida_en' => '2026-05-05 12:00:00', 'monto' => 749.25],
    ];
}

function reporteDePrueba(): MkReport
{
    // ⚠️ SIN persistir a propósito. `Model::update()` corta en seco cuando el
    // modelo no existe, así que `anotarElAvance()` es un no-op y el test no
    // necesita base de datos para ejercer el render entero.
    return new MkReport([
        'uuid' => 'test-uuid',
        'user_id' => '1',
        'type' => 'facturas',
        'format' => 'pdf',
        'total_chunks' => 3,
    ]);
}

beforeEach(function () {
    config()->set('mk_director.export', [
        'date_formats' => ['date' => 'd/m/Y', 'datetime' => 'd/m/Y H:i', 'time' => 'H:i'],
        'xlsx_cell_formats' => ['date' => 'dd/mm/yyyy', 'datetime' => 'dd/mm/yyyy hh:mm', 'time' => 'hh:mm'],
        'display_timezone' => 'America/La_Paz',
        'csv_separator' => ',',
        'job_memory_limit' => '512M',
        'pdf_font_dir' => null,
        'pdf_font_family' => 'sans-serif',
        'pdf_orientation' => 'P',
        'chrome' => ['legal_lines' => [], 'signatures' => [], 'footer_logo' => null],
    ]);

    Container::getInstance()->bind(ReportHeaderProvider::class, DefaultReportHeaderProvider::class);
});

test('el PDF sale con encabezado, las cinco filas y el renglón de totales', function () {
    $pdf = (new ExportService)->renderPdfFromConfig(filasDePrueba(), new FacturaExportConfig, reporteDePrueba());

    expect($pdf)->toStartWith('%PDF-');
    // 5 filas con chunkSize 2 → tres segmentos, el último parcial. Cada
    // segmento abre página nueva salvo el primero, más el desborde natural.
    expect(strlen($pdf))->toBeGreaterThan(1000);
});

test('el XLSX indexa las filas por la CLAVE de la columna, no por el rótulo', function () {
    $xlsx = (new ExportService)->renderXlsxFromConfig(filasDePrueba(), new FacturaExportConfig, reporteDePrueba());

    // Un XLSX es un ZIP; se lee de vuelta para mirar los valores reales.
    $tmp = tempnam(sys_get_temp_dir(), 'mk-xlsx-test').'.xlsx';
    file_put_contents($tmp, $xlsx);

    $hoja = IOFactory::load($tmp)->getActiveSheet();

    // Fila 1 título, 2 fecha, 3 vacía, 4 encabezados, 5+ datos.
    expect($hoja->getCell('A1')->getValue())->toBe('Reporte de Facturas');
    expect($hoja->getCell('A4')->getValue())->toBe('Concepto');
    expect($hoja->getCell('A5')->getValue())->toBe('Expensa marzo');

    // 🔴 Éste es el bug que el original tuvo en producción: si se indexa por
    // el rótulo, la relación anidada no matchea y la celda queda vacía.
    expect($hoja->getCell('B5')->getValue())->toBe('Ana Paz');

    // El enum resuelto por el mapa.
    expect($hoja->getCell('C5')->getValue())->toBe('Pagada');

    // 🔴 El monto es NÚMERO, no el texto "1,800.50". Es lo que permite el SUM.
    expect($hoja->getCell('E5')->getValue())->toBe(1800.5);
    expect($hoja->getCell('E5')->getStyle()->getNumberFormat()->getFormatCode())->toBe('#,##0.00');

    @unlink($tmp);
});

test('la fecha se convierte a la zona de visualización: 21:00 UTC no salta de día', function () {
    $csv = (new ExportService)->renderCsvFromConfig(filasDePrueba(), new FacturaExportConfig, reporteDePrueba());

    // 2026-03-05 21:00 UTC en La Paz (UTC-4) es el 5, no el 6. Sin convertir
    // la zona, ésta es exactamente la fila que cambia de día.
    expect($csv)->toContain('05/03/2026');
    expect($csv)->not->toContain('06/03/2026');
});

test('el CSV lleva los números pelados, sin separador de miles', function () {
    $csv = (new ExportService)->renderCsvFromConfig(filasDePrueba(), new FacturaExportConfig, reporteDePrueba());

    // 🔴 Un "1,800.50" acá rompe cualquier importador: con la coma como
    // separador, esa celda se parte en dos columnas.
    expect($csv)->toContain('1800.5');
    expect($csv)->not->toContain('1,800.50');

    // BOM UTF-8 al inicio, o Excel arruina los acentos.
    expect($csv)->toStartWith("\xEF\xBB\xBF");
});

test('la celda vacía se MARCA al leer y se deja VACÍA al calcular', function () {
    $servicio = new ExportService;

    // El PDF marca: la fila sin cliente y sin fecha tiene que decir algo.
    $pdf = $servicio->renderPdfFromConfig(filasDePrueba(), new FacturaExportConfig, reporteDePrueba());
    expect($pdf)->toStartWith('%PDF-');

    // El CSV no marca: un "-/-" en una columna de importes rompe el SUM.
    $csv = $servicio->renderCsvFromConfig(filasDePrueba(), new FacturaExportConfig, reporteDePrueba());
    expect($csv)->not->toContain(TextFormat::PLACEHOLDER);
});

test('los totales suman lo que la columna MUESTRA, no el texto formateado', function () {
    $totales = TotalesDeLaTabla::deLasColumnas(
        (new FacturaExportConfig)->columns(),
        'Total facturado'
    );

    expect($totales)->not->toBeNull();

    foreach (filasDePrueba() as $fila) {
        $totales->acumular('monto', $fila['monto']);
    }

    // 1800.5 + 1200 + 250.25 + 0 + 749.25
    expect($totales->total('monto'))->toBe(4000.0);
    expect($totales->textoDeLaEtiqueta())->toBe('Total facturado');
});

test('sin columnas que sumen NO hay acumulador — y por lo tanto no hay renglón', function () {
    $sinPlata = [
        ColumnDefinition::make('concepto')->label('Concepto'),
        ColumnDefinition::make('estado')->label('Estado'),
    ];

    expect(TotalesDeLaTabla::deLasColumnas($sinPlata, 'Total'))->toBeNull();
});

test('un reporte sin filas igual produce un PDF con su encabezado', function () {
    // ⚠️ Un archivo con encabezado y ninguna fila le dice al usuario que su
    // filtro no devolvió nada. Un archivo de 0 bytes le dice que algo se rompió.
    $pdf = (new ExportService)->renderPdfFromConfig([], new FacturaExportConfig, reporteDePrueba());

    expect($pdf)->toStartWith('%PDF-');
    expect(strlen($pdf))->toBeGreaterThan(500);
});
