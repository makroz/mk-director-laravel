<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Export\Contracts\ExportConfigInterface;
use Mk\Director\Export\ExportConfigRegistry;
use Mk\Director\Export\Jobs\GenerateListExportJob;
use Mk\Director\Export\Support\ColumnDefinition;
use Mk\Director\Export\Support\ExportConfigDefaults;
use Mk\Director\Models\MkReport;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Cuando el controller no deja filas, el archivo sale en el formato PEDIDO.
 *
 * 🔴 `archivoSinFilas()` escribía siempre un PDF: un reporte pedido en CSV o
 * XLSX se guardaba como `<uuid>.csv` con bytes de PDF adentro, y la descarga
 * no abría. Medido leyendo el job; ningún test lo cubría.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class SfAdmin extends AuthUser
{
    protected $table = 'sf_admins';

    protected $guarded = [];

    public function getAuthScope(): string
    {
        return 'admin';
    }
}

/** Un controller que NO pasa por `ExportaListados`: no deja filas. */
final class SfSinTraitController
{
    public function index(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }
}

final class SfExportConfig implements ExportConfigInterface
{
    use ExportConfigDefaults;

    public function module(): string
    {
        return 'sf_widgets';
    }

    public function title(): string
    {
        return 'Widgets';
    }

    public function columns(): array
    {
        return [ColumnDefinition::make('name')->label('Nombre')];
    }
}

beforeEach(function () {
    $this->bootHttpApp(SfAdmin::class, [
        'tenant' => ['enabled' => false],
        'export' => ['disk' => 'local', 'directory' => 'reports'],
    ]);

    (require __DIR__.'/../../src/Database/Migrations/2026_08_11_000001_create_mk_reports_table.php')->up();

    Storage::fake('local');
    // Como en un worker: el guard necesita un request en el container.
    app()->instance('request', Request::create('/'));

    $registry = app(ExportConfigRegistry::class);
    $registry->register(new SfExportConfig);
    app()->instance(ExportConfigRegistry::class, $registry);
});

afterEach(function () {
    $this->tearDownHttpApp();
});

test('🔴 un export sin filas sale en el formato pedido, no en PDF', function (string $format) {
    $report = MkReport::create([
        'uuid' => (string) Str::uuid(),
        'user_id' => 1,
        'type' => 'sf_widgets',
        'format' => $format,
        'params' => [],
        'status' => MkReport::STATUS_PENDING,
    ]);

    app()->call([new GenerateListExportJob($report->id, SfSinTraitController::class), 'handle']);

    $report->refresh();
    $contenido = Storage::disk('local')->get($report->file_path);

    expect($report->status)->toBe(MkReport::STATUS_COMPLETED)
        ->and($report->file_path)->toEndWith(".{$format}")
        ->and(str_starts_with($contenido, '%PDF'))->toBeFalse();

    if ($format === 'xlsx') {
        // Un XLSX es un ZIP.
        expect(substr($contenido, 0, 2))->toBe('PK');
    }
})->with(['csv', 'xlsx']);
