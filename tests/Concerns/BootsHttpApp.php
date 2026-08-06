<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Concerns;

use Illuminate\Auth\AuthServiceProvider;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Cookie\CookieServiceProvider;
use Illuminate\Database\ConnectionResolverInterface as Resolver;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler as FoundationExceptionHandler;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Hashing\HashServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\SessionServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\TranslationServiceProvider;
use Illuminate\Validation\ValidationServiceProvider;
use Illuminate\View\ViewServiceProvider;
use Laravel\Sanctum\SanctumServiceProvider;
use Mk\Director\MkServiceProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * BootsHttpApp — una app Laravel de VERDAD para los tests que necesitan la
 * CADENA de middleware, no una pieza suelta.
 *
 * 🔴 POR QUÉ EXISTE, Y POR QUÉ NO ALCANZABA CON LO QUE YA HABÍA.
 *
 * `MkLaravelTestCase` bootea un Container pelado: sirve para config, facades
 * y clases sueltas. Con eso se puede instanciar un middleware y llamarle
 * `handle($request, $next)` a mano — y eso es exactamente lo que hacían los
 * tests de tenancy.
 *
 * Un middleware llamado a mano PASA EN VERDE con la fuga de aislamiento
 * puesta, porque el test le entrega el `$request` ya con el usuario resuelto.
 * El bug no estaba en la validación: estaba en el ORDEN en que Laravel corre
 * los middlewares. `TenantResolver` se registra en el grupo `api`, y el
 * middleware de GRUPO corre ANTES que el de RUTA (`mk.auth:{scope}`), así que
 * cuando el resolver preguntaba `$request->user()` Sanctum todavía no había
 * resuelto nada y la respuesta era `null`. La validación entera vivía adentro
 * de un `if ($user !== null)` que nunca se cumplía.
 *
 * Un bug de ORDEN sólo se ve con la cadena entera armada. De ahí este harness:
 * Application real + Router real + Kernel real + Sanctum real. La ruta se
 * registra con el MISMO cableado que usa un consumer (`['api', 'mk.auth:X']`)
 * y la request entra por `Kernel::handle()`.
 *
 * DÓNDE SE APARTA DE UNA APP REAL (y por qué es honesto):
 *  - `$bootstrappers = []`: los bootstrappers de Laravel leen `.env`,
 *    `config/*.php` y `bootstrap/providers.php`, que un PAQUETE no tiene. La
 *    config y los providers se pinean a mano acá. No toca el pipeline de
 *    middleware, que es lo que se está midiendo.
 *  - El Kernel se construye ANTES de registrar los providers del paquete, que
 *    es el orden real: `bootstrap/app.php` arma el Kernel (y con él los grupos
 *    de middleware) y los providers bootean después. Si se invirtiera, el
 *    `syncMiddlewareToRouter()` del Kernel borraría el `pushMiddlewareToGroup`
 *    del provider y el test mediría un grupo vacío.
 */
trait BootsHttpApp
{
    public ?Application $httpApp = null;

    protected ?FoundationHttpKernel $httpKernel = null;

    /**
     * Resolver/dispatcher globales de Eloquent tal como estaban ANTES de
     * bootear esta app.
     *
     * 🔴 No se los pone en null al terminar: se RESTAURAN. `phpunit.xml`
     * documenta que la suite tiene un test order-dependiente
     * (`BugNew31MkBelongsToManyTest`) que sólo pasa porque otro archivo dejó un
     * connection resolver puesto. Blanquear el estático lo hace explotar con
     * "Call to a member function connection() on null" — un rojo que no tiene
     * nada que ver con lo que ese test prueba. Restaurar deja el mundo como se
     * lo encontró, sin tapar la deuda ni agregarle una nueva.
     */
    protected ?Resolver $resolverPrevio = null;

    protected ?Dispatcher $dispatcherPrevio = null;

    /**
     * @param  array<string,mixed>  $mkDirectorConfig  overrides para `mk_director`
     * @param  class-string  $userModel  modelo del provider `admins`
     */
    public function bootHttpApp(string $userModel, array $mkDirectorConfig = []): Application
    {
        $app = new Application(sys_get_temp_dir().'/mk-director-http-'.getmypid());

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $app->detectEnvironment(fn () => 'testing');
        $app->instance('config', new ConfigRepository($this->httpAppConfig($userModel, $mkDirectorConfig)));
        $app->singleton(ExceptionHandlerContract::class, FoundationExceptionHandler::class);

        foreach ($this->frameworkProviders() as $provider) {
            $app->register($provider);
        }

        // El Kernel PRIMERO (mismo orden que `bootstrap/app.php`): define los
        // grupos de middleware que después el provider del paquete extiende.
        $kernel = new class($app, $app['router']) extends FoundationHttpKernel
        {
            /** Ver el docblock del trait: los bootstrappers leen archivos que un paquete no tiene. */
            protected $bootstrappers = [];

            protected $middleware = [];

            /** El grupo `api` por defecto de Laravel 11+. */
            protected $middlewareGroups = [
                'api' => [SubstituteBindings::class],
            ];
        };

        $app->instance(HttpKernelContract::class, $kernel);

        $app->register(SanctumServiceProvider::class);
        $app->register(MkServiceProvider::class);

        $app->boot();

        $this->resolverPrevio = EloquentModel::getConnectionResolver();
        $this->dispatcherPrevio = EloquentModel::getEventDispatcher();

        EloquentModel::clearBootedModels();
        EloquentModel::setConnectionResolver($app['db']);
        EloquentModel::setEventDispatcher($app['events']);

        $this->httpApp = $app;
        $this->httpKernel = $kernel;

        return $app;
    }

    /**
     * Manda una request por el Kernel real. `$headers` en formato HTTP crudo
     * (`HTTP_X_TENANT_ID`), igual que `Request::create()`.
     *
     * @param  array<string,string>  $headers
     */
    public function httpGet(string $uri, array $headers = []): Response
    {
        // 🔴 EL SEGUNDO REQUEST DE UN TEST SE AUTENTICABA COMO EL PRIMERO.
        //
        // Los guards viven en el container y `RequestGuard::user()` MEMOIZA el
        // usuario resuelto. Entre dos requests de un mismo test el container no
        // se reconstruye, asi que el segundo trae otro `Authorization`, se
        // rutea bien, y `$request->user()` devuelve igual el objeto del
        // primero.
        //
        // No es que falle un test: es que PASA sin medir nada. Un test de "el
        // usuario A no ve lo del usuario B" corre las dos veces como A —
        // verde, y sin haber probado el aislamiento jamas. Es exactamente la
        // lente del bug: el instrumento comparte el defecto que tendria que
        // detectar.
        //
        // Va aca y no en cada test porque la regla la tendria que recordar
        // quien escriba el test siguiente, y olvidarla no da error: da un
        // verde. Un guard olvidado no le cuesta nada al test — `mk.auth` lo
        // vuelve a resolver del token, que es justo lo que se quiere medir.
        $this->httpApp?->make('auth')->forgetGuards();

        $request = Request::create($uri, 'GET', server: array_merge(
            ['HTTP_ACCEPT' => 'application/json'],
            $headers,
        ));

        return $this->httpKernel->handle($request);
    }

    /**
     * Desarma TODO el estado global que dejó la app. Sin esto, el archivo de
     * test siguiente hereda un connection resolver muerto y falla por algo que
     * no tiene nada que ver con lo que estaba probando.
     */
    public function tearDownHttpApp(): void
    {
        EloquentModel::clearBootedModels();

        if ($this->resolverPrevio !== null) {
            EloquentModel::setConnectionResolver($this->resolverPrevio);
        } else {
            EloquentModel::unsetConnectionResolver();
        }

        if ($this->dispatcherPrevio !== null) {
            EloquentModel::setEventDispatcher($this->dispatcherPrevio);
        } else {
            EloquentModel::unsetEventDispatcher();
        }

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        $this->httpKernel = null;
        $this->httpApp = null;
    }

    /** @return array<int,class-string> */
    protected function frameworkProviders(): array
    {
        return [
            DatabaseServiceProvider::class,
            FilesystemServiceProvider::class,
            CacheServiceProvider::class,
            CookieServiceProvider::class,
            SessionServiceProvider::class,
            EncryptionServiceProvider::class,
            AuthServiceProvider::class,
            HashServiceProvider::class,
            ViewServiceProvider::class,
            TranslationServiceProvider::class,
            ValidationServiceProvider::class,
        ];
    }

    /**
     * @param  class-string  $userModel
     * @param  array<string,mixed>  $mkDirectorConfig
     * @return array<string,mixed>
     */
    protected function httpAppConfig(string $userModel, array $mkDirectorConfig): array
    {
        $tmp = sys_get_temp_dir().'/mk-director-http-'.getmypid();

        return [
            'app' => [
                'name' => 'mk-director-http-test',
                'env' => 'testing',
                'key' => 'base64:'.base64_encode(random_bytes(32)),
                'cipher' => 'AES-256-CBC',
                'debug' => true,
                'providers' => [],
            ],
            'database' => [
                'default' => 'testing',
                'connections' => [
                    'testing' => [
                        'driver' => 'sqlite',
                        'database' => ':memory:',
                        'prefix' => '',
                        'foreign_key_constraints' => false,
                    ],
                ],
                'migrations' => 'migrations',
            ],
            // 🔴 El guard por DEFECTO es `web`, como en cualquier app Laravel.
            // Es la mitad del bug: `$request->user()` sin argumento resuelve
            // por acá y devuelve null, porque el token del scope vive en el
            // guard `admin` (sanctum). Poner `sanctum` de default acá haría
            // pasar el test en verde con el bug vivo.
            'auth' => [
                'defaults' => ['guard' => 'web'],
                'guards' => [
                    'web' => ['driver' => 'session', 'provider' => 'admins'],
                    'admin' => ['driver' => 'sanctum', 'provider' => 'admins'],
                ],
                'providers' => [
                    'admins' => ['driver' => 'eloquent', 'model' => $userModel],
                ],
            ],
            'session' => [
                'driver' => 'array',
                'lifetime' => 120,
                'expire_on_close' => false,
                'encrypt' => false,
                'files' => $tmp.'/sessions',
                'connection' => null,
                'table' => 'sessions',
                'store' => null,
                'lottery' => [2, 100],
                'cookie' => 'mk_director_session',
                'path' => '/',
                'domain' => null,
                'secure' => false,
                'http_only' => true,
                'same_site' => 'lax',
            ],
            'view' => [
                'paths' => [$tmp.'/views'],
                'compiled' => $tmp.'/compiled',
            ],
            'cache' => [
                'default' => 'array',
                'stores' => ['array' => ['driver' => 'array']],
                'prefix' => 'mk_director_test',
            ],
            'sanctum' => [
                'guard' => ['web'],
                'expiration' => null,
                'prefix' => '',
                'middleware' => [],
            ],
            'mk_director' => array_replace_recursive([
                'tenant' => [
                    'enabled' => true,
                    'resolver' => 'header',
                    'header_name' => 'X-Tenant-ID',
                    'strict' => true,
                    'allowlist_routes' => [],
                    'fail_closed' => false,
                ],
                'features' => ['auto_cache' => false, 'file_storage_plugin' => false],
                'plugins' => [],
                'openapi' => ['enabled' => false],
            ], $mkDirectorConfig),
        ];
    }
}
