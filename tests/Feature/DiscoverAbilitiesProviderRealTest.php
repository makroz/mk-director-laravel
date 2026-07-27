<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Feature;

use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Mk\Director\Console\Commands\DiscoverAbilitiesCommand;
use Mk\Director\Tests\MkLaravelTestCase;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `discoverAbilities()` sobre un provider DE VERDAD, uno que extiende
 * `Illuminate\Support\ServiceProvider`.
 *
 * ## Por qué hacía falta este archivo
 *
 * El camino del provider es, según la documentación del comando, la FUENTE
 * AUTORITATIVA: si el módulo implementa `discoverAbilities()`, ese array es el
 * único source y los atributos se ignoran.
 *
 * No funcionaba para nadie. El comando instanciaba con `app($providerClass)`, y
 * el constructor de `ServiceProvider` pide `$app` — un parámetro que el
 * contenedor no puede autowirear:
 *
 *     Unresolvable dependency resolving [Parameter #0 [ <required> $app ]]
 *
 * Y no reventaba: el `catch` lo bajaba a un warning y seguía por el fallback,
 * escribiendo OTRAS abilities. En RETO eso daba `communications.posts.*`, que
 * no valida ninguna ruta, en vez de las `admin.posts.*` declaradas.
 *
 * ## Por qué los tests que ya existían no lo veían
 *
 * Instancian un provider `eval`-uado que NO extiende `ServiceProvider` y no
 * tiene constructor — el contenedor lo resuelve sin chistar. Un doble que no
 * comparte con el original justamente lo único que podía fallar. Por eso este
 * archivo usa una clase real, con la herencia real.
 */
uses(MkLaravelTestCase::class);

afterEach(function () {
    Container::setInstance(null);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
});

function providerRealCommand(): DiscoverAbilitiesCommand
{
    $cmd = new DiscoverAbilitiesCommand;
    $cmd->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

    return $cmd;
}

/**
 * Declara un provider que EXTIENDE `ServiceProvider`, como los de verdad.
 *
 * Namespace único por corrida para que dos tests en el mismo proceso no
 * choquen con "Cannot redeclare class".
 *
 * @return string FQCN del provider declarado.
 */
function declararProviderReal(string $cuerpoDiscover): string
{
    $ns = 'TestNs\\ProviderReal'.str_replace('.', '', uniqid('', true));

    eval(<<<PHP
        namespace {$ns};
        class BillingModuleServiceProvider extends \\Illuminate\\Support\\ServiceProvider {
            public function discoverAbilities(): array {
                {$cuerpoDiscover}
            }
        }
        PHP);

    return $ns.'\\BillingModuleServiceProvider';
}

function descubrirDesdeProvider(string $providerFqn): array
{
    $cmd = providerRealCommand();
    $m = (new ReflectionClass($cmd))->getMethod('discoverAbilitiesFromProvider');
    $m->setAccessible(true);

    return $m->invokeArgs($cmd, ['Billing', ['path' => '/tmp', 'classes' => [$providerFqn]]]);
}

it('🔴 un provider que extiende ServiceProvider SÍ se puede instanciar', function () {
    // El bug exacto: antes esto caía en el `catch`, avisaba, y devolvía
    // `source = fallback` con la lista vacía.
    Container::setInstance(new Container);
    Facade::setFacadeApplication(Container::getInstance());

    $fqn = declararProviderReal("return ['billing.invoices.view'];");

    $out = descubrirDesdeProvider($fqn);

    expect($out['source'])->toBe('provider')
        ->and($out['abilities'])->toBe([
            ['name' => 'billing.invoices.view', 'description' => null, 'baseline' => false],
        ]);
});

it('🔴 y la forma extendida con baseline llega entera', function () {
    // Un módulo con provider IGNORA los atributos, así que ésta es su única vía
    // para declarar una baseline. Si la instanciación falla, el flag no llega
    // nunca y el rol base queda vacío sin que nada lo diga.
    Container::setInstance(new Container);
    Facade::setFacadeApplication(Container::getInstance());

    $fqn = declararProviderReal(<<<'PHP'
        return [
            'billing.invoices.create',
            ['name' => 'billing.profile.view', 'description' => 'Ver su perfil', 'baseline' => true],
        ];
        PHP);

    $out = descubrirDesdeProvider($fqn);

    expect($out['source'])->toBe('provider')
        ->and($out['abilities'])->toBe([
            ['name' => 'billing.invoices.create', 'description' => null, 'baseline' => false],
            ['name' => 'billing.profile.view', 'description' => 'Ver su perfil', 'baseline' => true],
        ]);
});

it('un provider que revienta de verdad sigue cayendo al fallback', function () {
    // El `catch` tiene que seguir estando: un `discoverAbilities()` con un bug
    // adentro no puede tumbar la corrida entera del comando. Lo que cambió es
    // que ya no se traga el error de instanciación, que era del PAQUETE.
    Container::setInstance(new Container);
    Facade::setFacadeApplication(Container::getInstance());

    $fqn = declararProviderReal("throw new \\RuntimeException('boom');");

    $out = descubrirDesdeProvider($fqn);

    expect($out['source'])->toBe('fallback')
        ->and($out['abilities'])->toBe([]);

    // PRUEBA DE VIDA: el mismo camino, con un provider sano, sí descubre. Sin
    // esto, la aserción de arriba la cumpliría igual una instanciación rota —
    // que es LITERALMENTE el bug que este archivo existe para cerrar.
    $sano = declararProviderReal("return ['billing.invoices.view'];");

    expect(descubrirDesdeProvider($sano)['source'])->toBe('provider');
});
