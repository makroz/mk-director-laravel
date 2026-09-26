<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Controllers\SmartController;
use Mk\Director\Export\AsyncExportManager;
use Mk\Director\Export\Contracts\ExportConfigInterface;
use Mk\Director\Export\ExportConfigRegistry;
use Mk\Director\Export\FilasDelExport;
use Mk\Director\Export\Support\ColumnDefinition;
use Mk\Director\Export\Support\ExportConfigDefaults;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * El export de un listado lleva TODAS las filas del filtro, no la página que
 * estaba en pantalla.
 *
 * 🔴 `CRUDSmart::index()` le entregaba al export `$paginator->items()`: una
 * página. Sin `per_page` eran 15 filas; el admin manda `per_page=100` y el tope
 * del paquete es 100, así que por encima de eso el archivo salía recortado,
 * `completed`, sin un solo aviso. Medido en RETO con el reporte de miembros.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class ExAdmin extends AuthUser
{
    protected $table = 'ex_admins';

    protected $guarded = [];

    public function getAuthScope(): string
    {
        return 'admin';
    }
}

final class ExWidget extends Model
{
    protected $table = 'ex_widgets';

    protected $fillable = ['name'];

    public $timestamps = false;
}

final class ExWidgetController extends SmartController
{
    protected array $mkConfig = [
        'model' => ExWidget::class,
        'searchable' => ['name'],
        'features' => ['authorize_with_policy' => false],
    ];
}

final class ExWidgetExportConfig implements ExportConfigInterface
{
    use ExportConfigDefaults;

    public function module(): string
    {
        return 'ex_widgets';
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
    $this->bootHttpApp(ExAdmin::class, [
        'tenant' => ['enabled' => false],
        'features' => ['auto_cache' => false, 'authorize_with_policy' => false],
        'list' => ['default_per_page' => 15, 'max_per_page' => 100],
    ]);

    Schema::create('ex_widgets', function ($t) {
        $t->id();
        $t->string('name');
    });

    foreach (range(1, 130) as $i) {
        ExWidget::create(['name' => "w{$i}"]);
    }

    // El registry no es singleton: se fija una instancia para que el
    // controller vea el config registrado acá.
    $registry = app(ExportConfigRegistry::class);
    $registry->register(new ExWidgetExportConfig);
    app()->instance(ExportConfigRegistry::class, $registry);
});

afterEach(function () {
    $this->tearDownHttpApp();
});

/** Lo que hace el job: re-ejecutar el index con la marca de re-entrada. */
function filasQueRecibeElJob(array $params): array
{
    $buzon = app(FilasDelExport::class);
    $buzon->vaciar();

    $request = Request::create('/x', 'GET', $params + [
        '_export' => 'csv',
        AsyncExportManager::MARCA_DE_REENTRADA => 1,
    ]);
    app()->instance('request', $request);

    app(ExWidgetController::class)->index($request);

    expect($buzon->hayFilas())->toBeTrue();

    return collect($buzon->filas())->all();
}

test('🔴 sin per_page el export trae las 130 filas, no las 15 de la primera página', function () {
    expect(filasQueRecibeElJob([]))->toHaveCount(130);
});

test('🔴 con per_page=100 y page=2 el export sigue trayendo las 130', function () {
    expect(filasQueRecibeElJob(['per_page' => 100, 'page' => 2]))->toHaveCount(130);
});

test('el export respeta el filtro del listado', function () {
    expect(filasQueRecibeElJob(['search' => 'w12']))
        ->toHaveCount(collect(range(1, 130))->filter(fn ($i) => str_contains("w{$i}", 'w12'))->count());
});

test('el listado normal sigue paginando', function () {
    $request = Request::create('/x', 'GET', ['per_page' => 100]);
    app()->instance('request', $request);

    $data = app(ExWidgetController::class)->index($request)->getData(true);

    expect($data['data'])->toHaveCount(100);
});
