<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Export;

use Carbon\Carbon;
use Mk\Director\Export\Csv\CsvGenerator;
use Mk\Director\Export\Xlsx\XlsxGenerator;
use Mk\Director\Tests\MkLaravelTestCase;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * La línea "Generado: …" del CSV y del XLSX va en `export.display_timezone`,
 * igual que las fechas de las filas y que el encabezado del PDF.
 *
 * 🔴 Se armaba con `date()`, que usa la zona del proceso (UTC en la app): un
 * reporte pedido a las 20:30 en Bolivia decía "Generado: 00:30" del día
 * siguiente, mientras sus filas ya salían en hora local. Medido en RETO.
 */
uses(MkLaravelTestCase::class);

beforeEach(function (): void {
    config(['mk_director.export.display_timezone' => 'America/La_Paz']);
    // 2026-09-27 00:30 UTC = 2026-09-26 20:30 en La Paz (UTC-4): cambia el DÍA.
    Carbon::setTestNow(Carbon::parse('2026-09-27 00:30:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('CSV: "Generado" uses the display timezone', function (): void {
    $out = (new CsvGenerator([['name' => 'Ana']], ['name' => 'Nombre'], title: 'Reporte'))->generate();

    expect($out['csv'])->toContain('Generado: 2026-09-26 20:30')
        ->not->toContain('2026-09-27');
});

test('XLSX: "Generado" uses the display timezone', function (): void {
    $out = (new XlsxGenerator([['name' => 'Ana']], ['name' => 'Nombre'], title: 'Reporte'))->generate();

    $tmp = tempnam(sys_get_temp_dir(), 'mk-xlsx-');
    file_put_contents($tmp, $out['xlsx']);
    $cell = (string) IOFactory::load($tmp)->getActiveSheet()->getCell('A2')->getValue();
    @unlink($tmp);

    expect($cell)->toBe('Generado: 2026-09-26 20:30');
});
