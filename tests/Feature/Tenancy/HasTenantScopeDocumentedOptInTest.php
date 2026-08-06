<?php

declare(strict_types=1);

use Mk\Director\Tenancy\HasTenantScope;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * EL OPT-IN QUE DOCUMENTA `HasTenantScope` TIENE QUE COMPILAR.
 *
 * 🔴 EL BUG. El docblock del trait dice, textual:
 *
 *     class Survey extends Model
 *     {
 *         use HasTenantScope;
 *         protected static bool $usesTenant = true;
 *     }
 *
 * y eso es un FATAL de PHP, porque el trait declaraba la misma propiedad con
 * otro valor inicial:
 *
 *     Survey and Mk\Director\Tenancy\HasTenantScope define the same property
 *     ($usesTenant) in the composition of Survey. However, the definition
 *     differs and is considered incompatible.
 *
 * O sea: el único camino documentado para prender el aislamiento por modelo era
 * literalmente imposible de seguir. Y `mk:update` imprimía el MISMO consejo
 * ("agrega: protected static bool $usesTenant = true;"), así que la herramienta
 * del paquete mandaba a los consumers derecho contra el fatal.
 *
 * 🔴 POR QUÉ NADIE LO VIO EN LA SUITE. Los tests del paquete ya conocían el
 * fatal y lo ESQUIVABAN: `HasTenantScopeAccessorTest` tiene un comentario que
 * dice "avoids the fatal ... when you try to redeclare it via `protected static
 * bool $usesTenant = true`" y usa `setTenantEnabled()` en su lugar. Se
 * documentó el síntoma como si fuera una limitación del lenguaje y se siguió de
 * largo, con el camino de la documentación roto. Un workaround en los tests no
 * arregla la doc: la tapa.
 *
 * 🔴 CÓMO SE MIDE. Un fatal de composición de traits ocurre al COMPILAR la
 * clase: no es una excepción, no se puede `try/catch`, y mata el proceso. Un
 * test que declarara la clase acá se llevaría puesta la suite entera en vez de
 * fallar. Por eso se corre en un PROCESO APARTE y se mira el exit code.
 *
 * Y la línea del modelo no está hardcodeada: se EXTRAE DEL DOCBLOCK del trait.
 * Si mañana alguien cambia el ejemplo de la doc, este test prueba el ejemplo
 * nuevo. Pinear una copia sería volver a tener dos verdades que pueden
 * divergir — que es exactamente cómo la doc se quedó mintiendo tanto tiempo.
 */
uses(MkLaravelTestCase::class);

/** Saca del docblock del trait la línea de opt-in que se le pide al consumer. */
function lineaDeOptInDocumentada(): string
{
    $reflection = new ReflectionClass(HasTenantScope::class);
    $doc = (string) $reflection->getDocComment();

    expect($doc)->not->toBe('', 'HasTenantScope debe tener docblock con el ejemplo de opt-in');

    preg_match('/^\s*\*\s*(protected static .*\$usesTenant\s*=\s*true;)/m', $doc, $m);

    expect($m[1] ?? null)->not->toBeNull(
        'El docblock de HasTenantScope debe mostrar cómo prender el opt-in por modelo'
    );

    return trim($m[1]);
}

/**
 * Corre un snippet en un proceso PHP aparte.
 *
 * @return array{0:int,1:string} exit code + stdout/stderr combinados
 */
function correrEnProcesoAparte(string $php): array
{
    $raiz = dirname(__DIR__, 3);
    $archivo = tempnam(sys_get_temp_dir(), 'mk-optin-').'.php';

    file_put_contents($archivo, "<?php\nrequire '{$raiz}/vendor/autoload.php';\n".$php);

    $salida = [];
    $codigo = 0;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($archivo).' 2>&1', $salida, $codigo);

    @unlink($archivo);

    return [$codigo, implode("\n", $salida)];
}

test('🔴 el modelo del docblock COMPILA (hoy es un fatal de composición de traits)', function () {
    $linea = lineaDeOptInDocumentada();

    [$codigo, $salida] = correrEnProcesoAparte(<<<PHP
        class ModeloDelDocblock extends Illuminate\\Database\\Eloquent\\Model
        {
            use Mk\\Director\\Tenancy\\HasTenantScope;

            {$linea}
        }

        echo 'COMPILO';
        PHP);

    expect($codigo)->toBe(0, "El ejemplo de la documentación no compila:\n".$salida);
    expect($salida)->toContain('COMPILO');
    expect($salida)->not->toContain('define the same property');
});

test('🔴 y además OPTA POR EL TENANT: compilar sin prender el scope no serviría de nada', function () {
    // El fatal se podría "arreglar" borrando la propiedad del trait y dejando
    // que nadie la lea — el ejemplo compilaría y el aislamiento seguiría
    // apagado. Esta aserción cierra esa salida falsa.
    $linea = lineaDeOptInDocumentada();

    [$codigo, $salida] = correrEnProcesoAparte(<<<PHP
        class ModeloOptIn extends Illuminate\\Database\\Eloquent\\Model
        {
            use Mk\\Director\\Tenancy\\HasTenantScope;

            {$linea}
        }

        echo ModeloOptIn::isTenantEnabled() ? 'PRENDIDO' : 'APAGADO';
        PHP);

    expect($codigo)->toBe(0, $salida);
    expect($salida)->toContain('PRENDIDO');
});

test('EL CASO INVERSO: sin la línea del docblock, el trait sigue siendo un no-op (default OFF)', function () {
    // Sin esto el test de arriba se pondría verde con un `isTenantEnabled()`
    // que devuelve true siempre — y el opt-in dejaría de ser opt-in.
    [$codigo, $salida] = correrEnProcesoAparte(<<<'PHP'
        class ModeloSinOptIn extends Illuminate\Database\Eloquent\Model
        {
            use Mk\Director\Tenancy\HasTenantScope;
        }

        echo ModeloSinOptIn::isTenantEnabled() ? 'PRENDIDO' : 'APAGADO';
        PHP);

    expect($codigo)->toBe(0, $salida);
    expect($salida)->toContain('APAGADO');
});

test('el consejo que imprime `mk:update` es el MISMO que documenta el trait', function () {
    // `MkUpdateCommand` le dice al consumer que agregue la línea. Si la doc y el
    // comando divergen, la mitad de los consumers copia el consejo roto.
    $comando = (string) file_get_contents(
        dirname(__DIR__, 3).'/src/Console/Commands/MkUpdateCommand.php'
    );

    expect($comando)->toContain('protected static bool $usesTenant = true;');
    expect(lineaDeOptInDocumentada())->toBe('protected static bool $usesTenant = true;');
});
