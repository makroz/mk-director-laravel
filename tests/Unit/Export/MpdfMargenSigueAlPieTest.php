<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Export;

use Mk\Director\Export\Pdf\MpdfGenerator;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * El margen inferior tiene que SEGUIR al pie, no ser un número fijo.
 *
 * 🔴 En el original era un `25` escrito en el código, medido a mano contra UN
 * pie: logo de 22 px más dos líneas legales. El modo de fallar era
 * intermitente —el contenido pisaba el pie sólo en las páginas donde la última
 * fila caía justo—, que es la peor forma de fallar que hay.
 *
 * Estos tests miden lo que mPDF calculó, no lo que el docblock promete.
 */
uses(MkLaravelTestCase::class);

/**
 * Un pie con `$lineas` renglones. Cada uno del mismo alto, para que la
 * relación entre líneas y milímetros sea comprobable.
 */
function pieDe(int $lineas): string
{
    $html = '<div style="font-size: 8px; text-align: center;">';

    for ($i = 1; $i <= $lineas; $i++) {
        $html .= '<div style="margin-bottom: 2px;">Línea legal número '.$i.' del pie de página</div>';
    }

    return $html.'<div>Página {PAGENO} de {nb}</div></div>';
}

function margenesDePrueba(array $pisa = []): void
{
    config()->set('mk_director.export', array_replace_recursive([
        'job_memory_limit' => '512M',
        'pdf_font_dir' => null,
        'pdf_font_family' => 'sans-serif',
        'pdf_orientation' => 'P',
        'log_margins' => false,
        'margins' => [
            'top' => 8,
            'bottom' => 10,
            'left' => 10,
            'right' => 10,
            'header' => 9,
            'footer' => 9,
            'auto_top' => 'stretch',
            'auto_bottom' => 'stretch',
            'auto_padding' => 2,
        ],
    ], $pisa));
}

/** Renderiza una página mínima y devuelve las medidas del marco. */
function medirCon(string $pie, array $pisa = []): array
{
    margenesDePrueba($pisa);

    $gen = new MpdfGenerator(footerHtml: $pie);
    $gen->render('<p>contenido</p>');

    return $gen->medidasDelMarco();
}

test('un pie MÁS ALTO empuja el margen inferior — no se queda en el piso', function () {
    $corto = medirCon(pieDe(2));
    $largo = medirCon(pieDe(8));

    // Lo primero: mPDF efectivamente midió los pies, y el largo es más alto.
    expect($corto['footer_height'])->toBeGreaterThan(0.0);
    expect($largo['footer_height'])->toBeGreaterThan($corto['footer_height']);

    // 🔴 Y esto es lo que el número fijo no podía hacer: el margen SIGUE al pie.
    expect($largo['bottom_margin'])->toBeGreaterThan($corto['bottom_margin']);
});

test('el margen nunca deja al contenido pisando el pie', function () {
    foreach ([1, 3, 6, 10] as $lineas) {
        $m = medirCon(pieDe($lineas));

        // ⚠️ Esta guarda NO es decorativa. Sin ella el test pasa VACUAMENTE
        // cuando el margen automático está apagado: ahí mPDF no mide el pie,
        // `footer_height` sale 0, y la comparación de abajo se vuelve trivial.
        // Se descubrió reinyectando el bug y viendo este test en VERDE
        // mientras los otros cuatro se ponían rojos.
        expect($m['footer_height'])->toBeGreaterThan(
            0.0,
            "Con {$lineas} líneas el pie no se midió: el test no está midiendo nada"
        );

        // El contenido corta en `page_height - bottom_margin`. El pie arranca
        // en `page_height - margin_footer - footer_height`. El primero tiene
        // que estar POR ENCIMA del segundo, siempre.
        $cortaElContenido = $m['page_height'] - $m['bottom_margin'];
        $arrancaElPie = $m['page_height'] - 9.0 - $m['footer_height'];

        expect($cortaElContenido)->toBeLessThanOrEqual(
            $arrancaElPie,
            "Con {$lineas} líneas el contenido invade el pie"
        );
    }
});

test('auto_padding es la perilla: subirlo agranda el aire, con el mismo pie', function () {
    $pie = pieDe(3);

    $apretado = medirCon($pie, ['margins' => ['auto_padding' => 2]]);
    $holgado = medirCon($pie, ['margins' => ['auto_padding' => 15]]);

    expect($apretado['footer_height'])->toBe($holgado['footer_height']);
    expect($holgado['bottom_margin'])->toBeGreaterThan($apretado['bottom_margin']);
    expect($holgado['bottom_margin'] - $apretado['bottom_margin'])->toBeGreaterThan(10.0);
});

test('con auto_bottom en false el margen queda FIJO — para quien ya midió', function () {
    $corto = medirCon(pieDe(2), ['margins' => ['auto_bottom' => false, 'bottom' => 25]]);
    $largo = medirCon(pieDe(8), ['margins' => ['auto_bottom' => false, 'bottom' => 25]]);

    expect($corto['bottom_margin'])->toBe(25.0);
    expect($largo['bottom_margin'])->toBe(25.0);

    // ⚠️ Y EN MODO FIJO mPDF NO MIDE EL PIE — no lo necesita, así que ni lo
    // calcula. `footer_height` sale 0. O sea que el diagnóstico de
    // `medidasDelMarco()` sólo sirve en modo automático: quien fija el margen
    // se queda sin la única herramienta que le diría si le alcanza.
    expect($largo['footer_height'])->toBe(0.0);
});

test('🔴 el número fijo del original NO alcanza para un pie de 8 líneas', function () {
    // El alto real hay que pedírselo al modo automático, que es el único que
    // lo mide. Con eso se ve de una si un margen fijo sirve o no.
    $medido = medirCon(pieDe(8));

    // El margen que ese pie NECESITA.
    $necesario = 9.0 + $medido['footer_height'] + 2.0;

    // El `MARGIN_BOTTOM = 25` que el motor original tenía escrito en el
    // código, medido a mano contra un pie de dos líneas. Con ocho, el
    // contenido se le mete adentro.
    expect($necesario)->toBeGreaterThan(25.0);
    expect($medido['bottom_margin'])->toBe($necesario);
});

test('el piso se respeta: un pie chiquito no encoge el margen por debajo de bottom', function () {
    $m = medirCon('<div style="font-size:6px;">.</div>', ['margins' => ['bottom' => 30]]);

    expect($m['bottom_margin'])->toBeGreaterThanOrEqual(30.0);
});

test('sin pie, el margen se queda en el piso configurado', function () {
    margenesDePrueba(['margins' => ['bottom' => 18]]);

    $gen = new MpdfGenerator;
    $gen->render('<p>sin pie</p>');

    expect($gen->medidasDelMarco()['bottom_margin'])->toBe(18.0);
});

test('lineasQueEntranEnElPie responde con el alto MEDIDO, no con una fórmula', function () {
    margenesDePrueba();

    $gen = new MpdfGenerator(footerHtml: pieDe(4));
    $gen->render('<p>contenido</p>');

    // Con 40 mm de tope y un pie de 4 líneas medido, dice cuántas entran.
    $entran = $gen->lineasQueEntranEnElPie(topeDeMargen: 40.0, lineasActuales: 4);

    expect($entran)->toBeInt();
    expect($entran)->toBeGreaterThan(4);

    // Con un tope apretado no entra ninguna: la respuesta es 0, no una
    // excepción ni un número optimista.
    expect($gen->lineasQueEntranEnElPie(topeDeMargen: 9.0, lineasActuales: 4))->toBe(0);
});
