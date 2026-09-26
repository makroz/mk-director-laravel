<?php

declare(strict_types=1);

use Illuminate\Bus\BusServiceProvider;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Export\AsyncExportManager;
use Mk\Director\Export\Contracts\CustomReportInterface;
use Mk\Director\Export\CustomReportRegistry;
use Mk\Director\Export\Jobs\GenerateCustomReportJob;
use Mk\Director\Export\Support\ExportConfigDefaults;
use Mk\Director\Models\MkReport;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Un reporte custom se pide sólo con SU ability.
 *
 * 🔴 La ruta `POST {prefix}/{type}/export` tiene un middleware para todos los
 * reportes. Sin chequeo propio, quien podía pedir uno pedía cualquier custom,
 * y el custom corre su consulta sin pasar por la Policy del listado.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class CrAdmin extends AuthUser
{
    protected $table = 'cr_admins';

    protected $guarded = [];

    public function getAuthScope(): string
    {
        return 'admin';
    }
}

/** Un usuario con un set fijo de abilities. */
final class CrUsuario extends User
{
    /** @param  string[]  $abilities */
    public function __construct(private array $abilities = [])
    {
        parent::__construct();
        $this->id = 7;
    }

    public function canMk(string $ability): bool
    {
        return in_array($ability, $this->abilities, true);
    }
}

final class CrFinanzasReport implements CustomReportInterface
{
    use ExportConfigDefaults;

    public function __construct(private ?string $ability) {}

    public function key(): string
    {
        return 'finanzas-ingresos';
    }

    public function module(): string
    {
        return 'finanzas';
    }

    public function title(): string
    {
        return 'Ingresos';
    }

    public function supportedFormats(): array
    {
        return ['csv'];
    }

    public function ability(): ?string
    {
        return $this->ability;
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
    $this->bootHttpApp(CrAdmin::class, [
        'tenant' => ['enabled' => false],
        // La respuesta 202 arma la URL de status con `route()`.
        'export' => ['register_routes' => true],
    ]);

    (require __DIR__.'/../../src/Database/Migrations/2026_08_11_000001_create_mk_reports_table.php')->up();

    app('router')->getRoutes()->refreshNameLookups();
    app()->register(BusServiceProvider::class);
    Bus::fake();
});

afterEach(function () {
    $this->tearDownHttpApp();
});

function pedirElCustom(?string $abilityDelReporte, object $usuario): int
{
    $registry = new CustomReportRegistry;
    $registry->register(new CrFinanzasReport($abilityDelReporte));
    app()->instance(CustomReportRegistry::class, $registry);

    $request = Request::create('/x', 'POST', ['_export' => 'csv']);
    $request->setUserResolver(fn () => $usuario);
    app()->instance('request', $request);

    return app(AsyncExportManager::class)
        ->export($request, [], 'finanzas-ingresos')
        ->getStatusCode();
}

test('🔴 sin la ability del reporte: 403 y NO se crea ni se encola nada', function () {
    expect(pedirElCustom('finanzas.viewAny', new CrUsuario(['members.viewAny'])))->toBe(403);

    expect(MkReport::count())->toBe(0);
    Bus::assertNotDispatched(GenerateCustomReportJob::class);
});

test('con la ability del reporte: 202 y se encola', function () {
    expect(pedirElCustom('finanzas.viewAny', new CrUsuario(['finanzas.viewAny'])))->toBe(202);

    expect(MkReport::count())->toBe(1);
    Bus::assertDispatched(GenerateCustomReportJob::class);
});

test('un usuario sin canMk no pasa un reporte con ability', function () {
    expect(pedirElCustom('finanzas.viewAny', new User))->toBe(403);
});

test('un reporte con ability null queda abierto a quien llega a la ruta', function () {
    expect(pedirElCustom(null, new CrUsuario))->toBe(202);
});
