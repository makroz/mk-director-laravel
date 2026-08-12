<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Export;

use Mk\Director\Export\Contracts\ExportConfigInterface;
use Mk\Director\Export\Support\ColumnDefinition;
use Mk\Director\Export\Support\ExportConfigDefaults;
use Mk\Director\Models\MkReport;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Un `ExportConfig` mínimo son TRES métodos, no trece.
 *
 * 🔴 Entre `ExportConfigInterface` y su padre `ReportChromeAware` hay trece
 * métodos, y en un config real sólo tres dicen algo del módulo. Sin el trait
 * de defaults, los otros diez son copiar y pegar en cada módulo — y agregar un
 * método declarativo nuevo al contrato rompería TODOS los configs existentes.
 *
 * Este test es el que garantiza que eso siga siendo cierto: si alguien suma un
 * método a la interface sin darle default en el trait, el config de acá abajo
 * deja de compilar.
 */
uses(MkLaravelTestCase::class);

/** El config más chico que puede existir. */
final class ConfigMinimo implements ExportConfigInterface
{
    use ExportConfigDefaults;

    public function module(): string
    {
        return 'minimo';
    }

    public function title(): string
    {
        return 'Reporte Mínimo';
    }

    public function columns(): array
    {
        return [ColumnDefinition::make('id')->label('ID')];
    }
}

beforeEach(function () {
    config()->set('mk_director.export.chunk_size', ['pdf' => 20, 'xlsx' => 500, 'csv' => 1000]);
    config()->set('mk_director.export.csv_separator', ',');
});

test('🔴 un config con TRES métodos satisface el contrato entero', function () {
    // Si esto no instancia, es que alguien sumó un método a la interface sin
    // darle default en el trait — y con eso rompió todos los módulos del
    // consumer, no sólo este test.
    expect(new ConfigMinimo)->toBeInstanceOf(ExportConfigInterface::class);
});

test('los defaults son los que el motor espera', function () {
    $config = new ConfigMinimo;

    expect($config->supportedFormats())->toBe(['pdf', 'xlsx', 'csv']);
    expect($config->requiredRelations())->toBe([]);
    expect($config->useExtraData())->toBeFalse();
    expect($config->headerHtml(new MkReport))->toBeNull();
    expect($config->footerHtml())->toBeNull();
    expect($config->etiquetaDeTotales())->toBe('Total');
});

test('🔴 el PDF se parte MUCHO más chico que los otros dos', function () {
    $config = new ConfigMinimo;

    // No es un descuido de la config: mPDF mantiene el documento entero en
    // memoria mientras lo arma, así que un segmento grande no acelera —
    // revienta. XLSX y CSV escriben en streaming.
    expect($config->chunkSize('pdf'))->toBeLessThan($config->chunkSize('xlsx'));
    expect($config->chunkSize('xlsx'))->toBeLessThan($config->chunkSize('csv'));
});

test('el chunk sale de la CONFIG, no de números escritos en el trait', function () {
    config()->set('mk_director.export.chunk_size', ['pdf' => 7, 'xlsx' => 77, 'csv' => 777]);

    $config = new ConfigMinimo;

    // Así un consumer los cambia para todos sus módulos en un solo lugar.
    expect($config->chunkSize('pdf'))->toBe(7);
    expect($config->chunkSize('xlsx'))->toBe(77);
    expect($config->chunkSize('csv'))->toBe(777);
});

test('un formato desconocido cae en pdf y nunca en cero', function () {
    $config = new ConfigMinimo;

    // ⚠️ Un `chunkSize` de 0 haría un bucle infinito de segmentos vacíos.
    expect($config->chunkSize('vaya-uno-a-saber'))->toBe(20);
    expect($config->chunkSize('excel'))->toBe(500);

    config()->set('mk_director.export.chunk_size', ['pdf' => 0]);
    expect($config->chunkSize('pdf'))->toBe(1);
});

test('beforeExport por defecto no toca la data', function () {
    $filas = [['id' => 1], ['id' => 2]];

    expect((new ConfigMinimo)->beforeExport($filas, new MkReport))->toBe($filas);
});
