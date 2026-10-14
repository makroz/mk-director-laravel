<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Controllers\SmartController;
use Mk\Director\Export\Contracts\ExportConfigInterface;
use Mk\Director\Export\ExportConfigRegistry;
use Mk\Director\Export\Jobs\GenerateListExportJob;
use Mk\Director\Export\Support\ColumnDefinition;
use Mk\Director\Export\Support\ExportConfigDefaults;
use Mk\Director\Models\MkReport;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * `mk_director.export.max_rows` corta el export ANTES de traer las filas.
 *
 * 🔴 Sin tope, el job trae el filtro entero y el formato arma el archivo en
 * memoria: un filtro demasiado ancho agota el worker y el reporte muere como
 * "Job failed" sin decir por qué. Con el tope, el reporte queda `failed` con un
 * mensaje que pide acotar el filtro, y el job no se reintenta.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class TpAdmin extends AuthUser
{
    protected $table = 'tp_admins';

    protected $guarded = [];

    public function getAuthScope(): string
    {
        return 'admin';
    }
}

final class TpWidget extends Model
{
    protected $table = 'tp_widgets';

    protected $fillable = ['name'];

    public $timestamps = false;
}

final class TpWidgetController extends SmartController
{
    protected array $mkConfig = [
        'model' => TpWidget::class,
        'searchable' => ['name'],
        'features' => ['authorize_with_policy' => false],
    ];
}

final class TpWidgetExportConfig implements ExportConfigInterface
{
    use ExportConfigDefaults;

    public function module(): string
    {
        return 'tp_widgets';
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
    $this->bootHttpApp(TpAdmin::class, [
        'tenant' => ['enabled' => false],
        'features' => ['auto_cache' => false, 'authorize_with_policy' => false],
        'export' => ['disk' => 'local', 'directory' => 'reports'],
    ]);

    (require __DIR__.'/../../src/Database/Migrations/2026_08_11_000001_create_mk_reports_table.php')->up();

    Schema::create('tp_widgets', function ($t) {
        $t->id();
        $t->string('name');
    });

    foreach (range(1, 30) as $i) {
        TpWidget::create(['name' => "w{$i}"]);
    }

    Storage::fake('local');
    app()->instance('request', Request::create('/'));

    $registry = app(ExportConfigRegistry::class);
    $registry->register(new TpWidgetExportConfig);
    app()->instance(ExportConfigRegistry::class, $registry);
});

afterEach(function () {
    $this->tearDownHttpApp();
});

function correrElExport(array $params = []): MkReport
{
    $report = MkReport::create([
        'uuid' => (string) Str::uuid(),
        'user_id' => 1,
        'type' => 'tp_widgets',
        'format' => 'csv',
        'params' => $params,
        'status' => MkReport::STATUS_PENDING,
    ]);

    app()->call([new GenerateListExportJob($report->id, TpWidgetController::class), 'handle']);

    return $report->refresh();
}

test('🔴 pasarse del tope deja el reporte failed con el motivo, sin relanzar', function () {
    Config::set('mk_director.export.max_rows', 29);

    $report = correrElExport();

    expect($report->status)->toBe(MkReport::STATUS_FAILED)
        ->and($report->error_message)->toBe('El filtro trae 30 filas y el máximo es 29. Acote el filtro para exportar.')
        ->and($report->file_path)->toBeNull();
});

test('el tope se mide sobre el filtro, no sobre la tabla', function () {
    Config::set('mk_director.export.max_rows', 29);

    // Sólo `w12`. La búsqueda pide 3 caracteres (`search.min_chars`).
    expect(correrElExport(['search' => 'w12'])->status)->toBe(MkReport::STATUS_COMPLETED);
});

test('justo en el tope, exporta', function () {
    Config::set('mk_director.export.max_rows', 30);

    expect(correrElExport()->status)->toBe(MkReport::STATUS_COMPLETED);
});

test('0 es sin tope', function () {
    Config::set('mk_director.export.max_rows', 0);

    expect(correrElExport()->status)->toBe(MkReport::STATUS_COMPLETED);
});
