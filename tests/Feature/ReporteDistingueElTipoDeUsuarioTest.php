<?php

declare(strict_types=1);

use Illuminate\Bus\BusServiceProvider;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Export\AsyncExportManager;
use Mk\Director\Export\Contracts\CustomReportInterface;
use Mk\Director\Export\Controllers\MkReportController;
use Mk\Director\Export\CustomReportRegistry;
use Mk\Director\Export\Support\ExportConfigDefaults;
use Mk\Director\Models\MkReport;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * El dueño de un reporte es su id Y su tipo.
 *
 * 🔴 Con `user_id` solo, en un consumer con dos scopes de ids enteros el
 * member 5 veía y bajaba los reportes del admin 5, y el job corría con el
 * modelo fijo de `export.user_model`: un reporte del member 5 se generaba como
 * el admin 5.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class TuBootAdmin extends AuthUser
{
    protected $table = 'tu_boot_admins';

    protected $guarded = [];

    public function getAuthScope(): string
    {
        return 'admin';
    }
}

final class TuAdmin extends User
{
    protected $table = 'tu_admins';

    protected $guarded = [];

    public $timestamps = false;
}

final class TuMember extends User
{
    protected $table = 'tu_members';

    protected $guarded = [];

    public $timestamps = false;
}

final class TuReporteAbierto implements CustomReportInterface
{
    use ExportConfigDefaults;

    public function key(): string
    {
        return 'tu-abierto';
    }

    public function module(): string
    {
        return 'tu';
    }

    public function title(): string
    {
        return 'Abierto';
    }

    public function supportedFormats(): array
    {
        return ['csv'];
    }

    public function ability(): ?string
    {
        return null;
    }

    public function render(MkReport $report, string $format): string
    {
        return "a\n";
    }

    public function columns(): array
    {
        return [];
    }
}

beforeEach(function () {
    $this->bootHttpApp(TuBootAdmin::class, [
        'tenant' => ['enabled' => false],
        'export' => ['register_routes' => true, 'user_model' => TuAdmin::class],
    ]);

    foreach (['2026_08_11_000001_create_mk_reports_table', '2026_09_26_000001_add_user_type_to_mk_reports_table'] as $m) {
        (require __DIR__."/../../src/Database/Migrations/{$m}.php")->up();
    }

    foreach (['tu_admins', 'tu_members'] as $tabla) {
        Schema::create($tabla, function ($t) {
            $t->id();
            $t->string('name');
        });
    }

    // El mismo id en las dos tablas: es el choque que se mide.
    $this->admin = TuAdmin::create(['id' => 5, 'name' => 'Admin cinco']);
    $this->member = TuMember::create(['id' => 5, 'name' => 'Member cinco']);

    app('router')->getRoutes()->refreshNameLookups();
    app()->register(BusServiceProvider::class);
    Bus::fake();

    $registry = new CustomReportRegistry;
    $registry->register(new TuReporteAbierto);
    app()->instance(CustomReportRegistry::class, $registry);
});

afterEach(function () {
    $this->tearDownHttpApp();
});

function pedirElReporteComo(object $usuario): MkReport
{
    $request = Request::create('/x', 'POST', ['_export' => 'csv']);
    // Primero el binding: el rebinding de Auth pisa el user resolver.
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $usuario);

    $respuesta = app(AsyncExportManager::class)->export($request, [], 'tu-abierto');

    return MkReport::where('uuid', $respuesta->getData(true)['job_id'])->firstOrFail();
}

test('🔴 el reporte guarda el tipo de quien lo pidió', function () {
    expect(pedirElReporteComo($this->member)->user_type)->toBe($this->member->getMorphClass());
});

test('🔴 el member 5 no ve ni baja el reporte del admin 5', function () {
    $reporte = pedirElReporteComo($this->admin);

    expect(MkReport::query()->de($this->member)->count())->toBe(0)
        ->and(MkReport::query()->de($this->admin)->count())->toBe(1);

    $member = $this->member;
    $request = Request::create('/x', 'GET');
    // Primero el binding: el rebinding de Auth pisa el user resolver.
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $member);

    expect(app(MkReportController::class)->status($request, $reporte->uuid)->getStatusCode())->toBe(404);
});

test('🔴 el job resuelve al dueño con SU modelo, no con export.user_model', function () {
    $solicitante = pedirElReporteComo($this->member)->solicitante();

    expect($solicitante)->toBeInstanceOf(TuMember::class)
        ->and($solicitante->name)->toBe('Member cinco');
});

test('una fila sin user_type (anterior a la columna) sigue siendo del id, con el modelo de la config', function () {
    $reporte = pedirElReporteComo($this->admin);
    $reporte->update(['user_type' => null]);

    expect(MkReport::query()->de($this->admin)->count())->toBe(1)
        ->and($reporte->fresh()->solicitante())->toBeInstanceOf(TuAdmin::class);
});
