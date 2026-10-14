<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Mk\Director\Auth\Controllers\BaseAuthController;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\Concerns\RunsAuthUserScaffolder;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Lo que `mk:make:auth-user` IMPRIME tiene que coincidir con lo que GENERA.
 *
 * 1. El resumen listaba `/auth/{login,refresh,logout,me,forgot,reset}`: las
 *    rutas reales son `password/forgot` y `password/reset`, y faltaban
 *    `logout-all`, `password/change`, los del PIN, `register`, 2FA… Ahora la
 *    lista se lee del `api.php` generado.
 * 2. Los "pasos siguientes" mandaban a "implementar los TODOs del
 *    AuthController": el controller generado extiende BaseAuthController y no
 *    tiene ningún TODO.
 * 3. LoginRequest y MeRequest se generaban sin que nada los usara:
 *    `BaseAuthController::login()`/`me()` reciben `Request` y validan inline, y
 *    una subclase no puede angostar el tipo del parámetro. Ya no se generan.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class, RunsAuthUserScaffolder::class);

afterEach(function () {
    $this->tearDownHttpApp();
    $this->cleanScaffolderTempDirs();
});

/** @return list<string> `VERBO path` de las rutas de auth del archivo, vía el router real */
function generatedAuthRoutes(string $routesFile, string $scopeLower): array
{
    Route::getRoutes();
    require $routesFile;

    $prefix = "api/{$scopeLower}/auth/";
    $out = [];
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        if (str_starts_with($route->uri(), $prefix)) {
            $out[] = $route->methods()[0].' '.substr($route->uri(), strlen($prefix));
        }
    }

    return $out;
}

function summaryRoutesLine(string $output): string
{
    preg_match('/• Routes:.*$/m', $output, $m);

    return $m[0] ?? '';
}

test('el resumen lista las rutas que el stub generó, no la lista fija vieja', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir(['scope' => 'Operador', '--plural' => 'operadores']);
    expect($exit)->toBe(0, $output);

    $line = summaryRoutesLine($output);
    expect($line)->not->toContain('{login,refresh,logout,me,forgot,reset}');

    $routes = generatedAuthRoutes($base.'/app/Modules/Operador/Http/Routes/api.php', 'operador');
    expect($routes)->toContain('POST password/forgot', 'POST password/reset', 'POST logout-all', 'GET me');
    foreach ($routes as $route) {
        expect($line)->toContain($route);
    }
    expect(substr_count($line, ', ') + 1)->toBe(count($routes));
});

test('el resumen sigue a los flags: --verify-email agrega sus rutas', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir([
        'scope' => 'Operador',
        '--plural' => 'operadores',
        '--verify-email' => true,
    ]);
    expect($exit)->toBe(0, $output);

    expect(summaryRoutesLine($output))
        ->toContain('GET email/verify/{id}/{hash}')
        ->toContain('POST email/resend');
});

test('los pasos siguientes ya no mandan a implementar TODOs del AuthController', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir([
        'scope' => 'Operador',
        '--plural' => 'operadores',
        '--skip-auth-wire' => true, // fuerza printAuthConfigSnippets()
    ]);
    expect($exit)->toBe(0, $output);

    expect($output)->toContain('Snippets para config/auth.php');
    expect($output)->not->toContain('TODOs');
    expect($output)->toContain('extiende BaseAuthController');

    $controller = (string) file_get_contents($base.'/app/Modules/Operador/Http/Controllers/AuthController.php');
    expect($controller)->toContain('extends BaseAuthController')->not->toContain('TODO');
});

test('LoginRequest y MeRequest ya no se generan: nada podía usarlos', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir(['scope' => 'Operador', '--plural' => 'operadores']);
    expect($exit)->toBe(0, $output);

    $requests = $base.'/app/Modules/Operador/Http/Requests';
    expect(file_exists("{$requests}/LoginRequest.php"))->toBeFalse();
    expect(file_exists("{$requests}/MeRequest.php"))->toBeFalse();
    expect($this->allGeneratedContent($base))->not->toContain('LoginRequest')->not->toContain('MeRequest');
});

test('el porqué: login() y me() del controller base reciben Request, no un FormRequest', function () {
    // Si esto cambia (el base resuelve un FormRequest por scope), volver a
    // generar LoginRequest/MeRequest tiene sentido. Mientras tanto, un override
    // `login(LoginRequest $r)` en la subclase es un fatal de PHP.
    foreach (['login', 'me'] as $method) {
        $type = (new ReflectionMethod(BaseAuthController::class, $method))->getParameters()[0]->getType();
        expect($type?->getName())->toBe(Request::class);
    }
});
