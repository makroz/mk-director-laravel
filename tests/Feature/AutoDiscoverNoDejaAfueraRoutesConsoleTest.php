<?php

declare(strict_types=1);

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Exceptions\Handler as FoundationExceptionHandler;
use Illuminate\Log\LogServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Mk\Director\MkServiceProvider;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Con `auto_discover_abilities` prendido, `routes/console.php` se sigue leyendo.
 *
 * 🔴 El auto-discover hacía `Artisan::call()` durante el boot del provider.
 * Eso arranca el kernel de consola y marca sus comandos como cargados ANTES de
 * que Laravel le agregue `routes/console.php`, que se agrega en un callback
 * `booted` (`ApplicationBuilder::withCommands`). El archivo no se leía nunca y
 * el scheduler quedaba vacío, sin error. Medido en RETO: `schedule:list` decía
 * "No scheduled tasks" y `mk:reports-clean` no estaba programado.
 *
 * El test arma la app en el MISMO orden que `artisan`: kernel resuelto, su
 * callback `booted` registrado, y después el boot de los providers.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

afterEach(function () {
    unset($GLOBALS['mk_console_php_leido']);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
});

test('🔴 con auto_discover_abilities prendido, routes/console.php se lee', function () {
    $consolePhp = tempnam(sys_get_temp_dir(), 'mk-console-').'.php';
    file_put_contents($consolePhp, "<?php\n\$GLOBALS['mk_console_php_leido'] = true;\n");

    $app = new Application(sys_get_temp_dir().'/mk-director-console-'.getmypid());
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($app);
    $app->detectEnvironment(fn () => 'testing');

    $config = $this->httpAppConfig(stdClass::class, []);
    $config['mk_director']['features']['auto_discover_abilities'] = true;
    $app->instance('config', new ConfigRepository($config));
    $app->singleton(ExceptionHandlerContract::class, FoundationExceptionHandler::class);

    foreach ([...$this->frameworkProviders(), LogServiceProvider::class] as $provider) {
        $app->register($provider);
    }

    // La tabla existe: sin ella el auto-discover se saltea y el bug no aparece.
    Schema::create('abilities', function ($t) {
        $t->id();
        $t->string('name');
    });

    // Lo que hace `artisan`: resolver el kernel y, como `withCommands`,
    // agregarle las rutas de consola en `booted`.
    $app->singleton(ConsoleKernelContract::class, fn ($app) => new class($app, $app['events']) extends ConsoleKernel
    {
        protected $bootstrappers = [];

        // El kernel base sólo lee las rutas de consola si es EXACTAMENTE su
        // clase; una subclase lo apaga.
        protected function shouldDiscoverCommands()
        {
            return true;
        }
    });
    $kernel = $app->make(ConsoleKernelContract::class);
    $app->booted(fn () => $kernel->addCommandRoutePaths([$consolePhp]));

    $app->register(MkServiceProvider::class);
    $app->boot();

    $kernel->bootstrap();

    expect($GLOBALS['mk_console_php_leido'] ?? false)->toBeTrue();

    @unlink($consolePhp);
});
