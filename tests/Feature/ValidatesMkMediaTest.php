<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\Validation\Validator;
use Mk\Director\Http\Requests\Concerns\ValidatesMkMedia;

/**
 * 🔴 SIN `uses(MkLaravelTestCase)` Y SIN LA FACADE `Validator`, A PROPÓSITO.
 *
 * El TestCase del paquete arma un contenedor MÍNIMO a mano (ver
 * `tests/MkLaravelTestCase.php`) y no registra el binding `validator`: la
 * facade explota con "Target class [validator] does not exist". La salida
 * fácil sería sumarle el `ValidationServiceProvider` a ese bootstrap, pero lo
 * comparten otros treinta tests y no hay motivo para moverlo por esto.
 *
 * Armar el `Factory` acá cuesta tres líneas, deja el test independiente del
 * bootstrap compartido, y no depende de ningún estado global.
 */
function validador(array $datos, array $reglas): Validator
{
    $factory = new ValidationFactory(new Translator(new ArrayLoader, 'es'));

    return $factory->make($datos, $reglas);
}

/**
 * Reglas de entrada de `media[]`.
 *
 * 🔴 LO QUE ESTOS TESTS PROTEGEN SON LÍMITES DE SEGURIDAD, no formato: qué
 * mimes entran, cuánto pesa cada uno y cuántos archivos acepta una request.
 *
 * El que carga el peso es el del LÍMITE POR TIPO: probado en rojo bajando el
 * tope de video al de imagen, caen 3. Ver más abajo la corrección de una
 * afirmación falsa que venía heredada sobre `mimes` vs `mimetypes`.
 */

/**
 * Un host de juguete que sólo expone las reglas del trait.
 *
 * 🔴 NO EXTIENDE `FormRequest` A PROPÓSITO. Instanciar uno a mano arrastra el
 * contenedor y sus dependencias, y no aporta nada: el trait no toca una sola
 * cosa de `FormRequest` —sólo usa `$this` para llamar a sus propios métodos
 * sobrescribibles—. Probarlo sobre un host mínimo deja claro cuál es su
 * superficie real, y de paso documenta que el trait sirve en cualquier clase,
 * no sólo en un Request.
 */
function reglasDeMedia(bool $conEmbed = false): array
{
    $request = new class
    {
        use ValidatesMkMedia;

        public function reglas(bool $conEmbed): array
        {
            return $conEmbed
                ? $this->mkMediaRules() + $this->mkEmbedUrlRules()
                : $this->mkMediaRules();
        }

        public function reglasDeBorrado(): array
        {
            return $this->mkRemoveMediaRules();
        }
    };

    return $request->reglas($conEmbed);
}

function validarMedia(array $datos, bool $conEmbed = false): Validator
{
    return validador($datos, reglasDeMedia($conEmbed));
}

// ─── Lo que entra ────────────────────────────────────────────────────────────

it('acepta una imagen dentro del límite', function (): void {
    $archivo = UploadedFile::fake()->image('flyer.jpg')->size(1024); // 1 MB

    expect(validarMedia(['media' => [$archivo]])->passes())->toBeTrue();
});

it('acepta hasta el tope de archivos', function (): void {
    $archivos = array_map(
        fn (int $i) => UploadedFile::fake()->image("f{$i}.jpg")->size(100),
        range(1, 10),
    );

    expect(validarMedia(['media' => $archivos])->passes())->toBeTrue();
});

it('no exige media: el campo es opcional', function (): void {
    expect(validarMedia([])->passes())->toBeTrue();
});

// ─── Lo que NO entra ─────────────────────────────────────────────────────────

it('rechaza pasarse del tope de archivos', function (): void {
    $archivos = array_map(
        fn (int $i) => UploadedFile::fake()->image("f{$i}.jpg")->size(100),
        range(1, 11),
    );

    $v = validarMedia(['media' => $archivos]);

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->has('media'))->toBeTrue();
});

it('rechaza una imagen que se pasa de 5 MB', function (): void {
    $archivo = UploadedFile::fake()->image('enorme.jpg')->size(6 * 1024);

    $v = validarMedia(['media' => [$archivo]]);

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('media.0'))->toContain('5 MB');
});

/**
 * El mime real manda por encima de la extensión del nombre.
 *
 * 🔴 ESTE TEST NO DISTINGUE `mimes` DE `mimetypes`, Y ANTES DECÍA QUE SÍ.
 *
 * La primera versión llevaba un comentario —heredado del trait que vivía en el
 * módulo de Comunicaciones— afirmando que con `mimes` un video renombrado a
 * `.jpg` pasaría. Al probarlo en rojo (cambiando la regla a `mimes`) el test
 * SIGUIÓ EN VERDE, o sea que no medía lo que decía medir.
 *
 * Se verificó con un archivo real de contenido mp4 y nombre `.jpg`:
 *
 *     mime detectado: video/mp4 | guessExtension: mp4
 *     mimes:jpeg,jpg,png       -> RECHAZA
 *     mimetypes:image/jpeg,... -> RECHAZA
 *
 * `mimes` NO mira el nombre: usa `guessExtension()`, que sale del contenido.
 * Las dos reglas protegen contra el archivo disfrazado. Se elige `mimetypes`
 * por precisión —la lista dice exactamente lo que el pipeline sabe guardar—,
 * no porque la otra deje pasar algo.
 *
 * El test se queda porque lo que sí prueba vale: un mime fuera de la lista se
 * rechaza. Lo que se corrigió es la afirmación de por qué.
 */
it('rechaza un archivo cuyo mime real no está en la lista', function (): void {
    $mentiroso = UploadedFile::fake()->create('foto.jpg', 100, 'application/x-msdownload');

    $v = validarMedia(['media' => [$mentiroso]]);

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->has('media.0'))->toBeTrue();
});

/**
 * 🔴 ACÁ ESTÁ LA PROTECCIÓN REAL contra "un video se cuela con el límite de una
 * imagen": la regla de TAMAÑO, que elige el tope mirando `getMimeType()` y no
 * la extensión. Un mp4 de 20 MB llamado `foto.jpg` tiene que entrar (es un
 * video válido, dentro del límite de video), no ser medido contra los 5 MB de
 * imagen ni rechazado por llamarse como se llama.
 */
it('mide por el mime real, no por la extensión del nombre', function (): void {
    $videoConNombreDeFoto = UploadedFile::fake()->create('foto.jpg', 20 * 1024, 'video/mp4');

    expect(validarMedia(['media' => [$videoConNombreDeFoto]])->passes())->toBeTrue();
});

it('rechaza algo que no es un archivo', function (): void {
    $v = validarMedia(['media' => ['no soy un archivo']]);

    expect($v->passes())->toBeFalse();
});

// ─── Video: OTRO límite, y ése es el punto ───────────────────────────────────

/**
 * 🔴 SI EL LÍMITE FUERA UNO SOLO, ESTE TEST Y EL DE LA IMAGEN NO PODRÍAN PASAR
 * LOS DOS. Un video de 20 MB tiene que entrar y una imagen de 6 MB tiene que
 * ser rechazada: con un `max:` único hay que elegir cuál de los dos romper.
 */
it('acepta un video de 20 MB, que como imagen sería rechazado', function (): void {
    $video = UploadedFile::fake()->create('clip.mp4', 20 * 1024, 'video/mp4');

    expect(validarMedia(['media' => [$video]])->passes())->toBeTrue();
});

it('rechaza un video que se pasa de 50 MB', function (): void {
    $video = UploadedFile::fake()->create('largo.mp4', 51 * 1024, 'video/mp4');

    $v = validarMedia(['media' => [$video]]);

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('media.0'))->toContain('50 MB');
});

// ─── El embed es OPT-IN ──────────────────────────────────────────────────────

/**
 * 🔴 QUE `embed_url` NO ESTÉ ES LA MITAD DEL DISEÑO. Si `mkMediaRules()` lo
 * arrastrara, un módulo que sólo quiere subir un flyer —Eventos— quedaría
 * aceptando URLs de terceros sin que nadie lo haya decidido.
 */
it('no incluye embed_url salvo que se lo pida explícitamente', function (): void {
    expect(reglasDeMedia())->not->toHaveKey('embed_url')
        ->and(reglasDeMedia(conEmbed: true))->toHaveKey('embed_url');
});

it('valida embed_url cuando se lo suma', function (): void {
    expect(validarMedia(['embed_url' => 'no-es-una-url'], conEmbed: true)->passes())->toBeFalse()
        ->and(validarMedia(['embed_url' => 'https://youtu.be/abc'], conEmbed: true)->passes())->toBeTrue();
});
