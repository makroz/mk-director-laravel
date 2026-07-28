<?php

declare(strict_types=1);

namespace Mk\Director\Http\Requests\Concerns;

use Closure;
use Illuminate\Http\UploadedFile;

/**
 * Reglas de validación de `media[]` para cualquier modelo con `HasMkMedia`.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 *  POR QUÉ ESTO VIVE EN EL PAQUETE Y NO EN CADA MÓDULO QUE SUBE ARCHIVOS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Estas NO son preferencias de formulario: son LÍMITES DE SEGURIDAD. Qué mimes
 * entran, cuánto puede pesar cada uno y cuántos archivos acepta una request son
 * las tres cosas que separan un uploader de un disco lleno o de un proceso
 * reventado midiendo una imagen de 40 MB.
 *
 * Nacieron en el módulo de Comunicaciones de RETO. Cuando el segundo módulo
 * (Eventos) necesitó lo mismo, no había forma de reusarlas sin cruzar la
 * frontera entre módulos (R-MK-001), y la salida fácil —copiar el trait— es la
 * que ya se pagó otras veces: dos copias divergen, y la que queda floja es la
 * que alguien va a encontrar. El paquete ya es dueño del resto del pipeline
 * (`HasMkMedia::attachUploadedFile`, `MkMediaKind`, `MkMediaResource`); las
 * reglas de entrada son la pieza que faltaba.
 *
 * Los NÚMEROS sí son política de cada app, y por eso salen de métodos
 * sobrescribibles en vez de constantes: un consumidor que necesite otro tope
 * redefine `mkMediaMaxFiles()` y nada más.
 *
 * ## Uso
 *
 *     class StoreEventRequest extends FormRequest
 *     {
 *         use ValidatesMkMedia;
 *
 *         public function rules(): array
 *         {
 *             return ['title' => ['required']] + $this->mkMediaRules();
 *         }
 *     }
 *
 * `mkEmbedUrlRules()` va APARTE y es opt-in: un flyer de evento no tiene por
 * qué aceptar links de YouTube, y una regla que se arrastra "porque venía
 * junta" es una superficie de entrada que nadie decidió abrir.
 */
trait ValidatesMkMedia
{
    /**
     * Los mimes que `mk_media` sabe guardar.
     *
     * Se valida con `mimetypes` y no con `mimes` porque la lista dice
     * EXACTAMENTE lo que el pipeline sabe procesar, en los mismos términos en
     * que el pipeline lo mira (`getMimeType()`). `mimes` compara contra la
     * extensión que Laravel ADIVINA, que es un paso de traducción de más entre
     * lo que se declara y lo que se acepta.
     *
     * 🔴 CORRECCIÓN DE UN COMENTARIO QUE ANDUVO DANDO VUELTAS. La versión de
     * este trait que vivía en el módulo de Comunicaciones de RETO decía que con
     * `mimes` "un video renombrado a .jpg pasa la validación". **Es falso, y se
     * midió**: `mimes` NO mira el nombre del archivo — usa
     * `UploadedFile::guessExtension()`, que sale del mime REAL detectado por
     * contenido. Un mp4 con extensión `.jpg` lo rechazan las dos reglas por
     * igual:
     *
     *     nombre: video_disfrazado.jpg | mime detectado: video/mp4
     *     mimes:jpeg,jpg,png        -> RECHAZA
     *     mimetypes:image/jpeg,...  -> RECHAZA
     *
     * Lo que de verdad impide que un video se cuele con el límite de una imagen
     * NO es esta regla: es {@see mkMediaSizeRule()}, que decide el tope mirando
     * `getMimeType()`. Vale la pena tenerlo claro, porque creer que la
     * protección está acá lleva a aflojar la de allá.
     *
     * @return list<string>
     */
    protected function mkAllowedMimeTypes(): array
    {
        return [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'video/mp4',
            'video/quicktime',
            'video/webm',
        ];
    }

    /**
     * Tope de archivos por request. Sin él, una sola request puede subir mil
     * archivos y ocupar el disco entero.
     */
    protected function mkMediaMaxFiles(): int
    {
        return 10;
    }

    /** Tamaño máximo de una imagen, en bytes. */
    protected function mkImageMaxBytes(): int
    {
        return 5 * 1024 * 1024;
    }

    /** Tamaño máximo de un video, en bytes. */
    protected function mkVideoMaxBytes(): int
    {
        return 50 * 1024 * 1024;
    }

    /**
     * Reglas de `media[]`.
     *
     * @return array<string, mixed>
     */
    protected function mkMediaRules(): array
    {
        return [
            'media' => ['sometimes', 'array', 'max:'.$this->mkMediaMaxFiles()],

            'media.*' => [
                'file',
                'mimetypes:'.implode(',', $this->mkAllowedMimeTypes()),
                $this->mkMediaSizeRule(),
            ],
        ];
    }

    /**
     * Reglas de `remove_media[]` — los ids que el cliente marcó para borrar.
     *
     * 🔴 LA PERTENENCIA NO SE VALIDA ACÁ, Y NO ES UN OLVIDO. Quien la garantiza
     * es `HasMkMedia::detachMedia()`, que scopea el borrado a la relación del
     * propio modelo: un id ajeno es un no-op silencioso, no un borrado de la
     * media de otro. Validarlo también acá pediría una query por id y daría un
     * mensaje de error que CONFIRMA que ese id existe en otro lado.
     *
     * @return array<string, mixed>
     */
    protected function mkRemoveMediaRules(): array
    {
        return [
            'remove_media' => ['sometimes', 'array'],
            'remove_media.*' => ['integer'],
        ];
    }

    /**
     * Regla de `embed_url`. OPT-IN: sumala sólo si el modelo acepta embeds.
     *
     * Una URL de un proveedor DESCONOCIDO no es un error — se guarda como link
     * plano —, así que acá sólo se valida que sea una URL.
     *
     * @return array<string, mixed>
     */
    protected function mkEmbedUrlRules(): array
    {
        return [
            'embed_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ];
    }

    /**
     * 🔴 EL LÍMITE DE TAMAÑO DEPENDE DEL TIPO, y por eso no se puede usar
     * `max:`. Un solo `max` obliga a elegir: con 5 MB no entra ningún video, y
     * con 50 MB una imagen de 40 MB pasa y hace reventar la memoria del proceso
     * que la mide. (El default del paquete para archivos sueltos es 2048 KB, que
     * tampoco alcanza para video.)
     */
    private function mkMediaSizeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile) {
                return;
            }

            // El mime REAL, no el declarado por el cliente: el header
            // `Content-Type` de un multipart lo elige quien sube el archivo.
            $esImagen = str_starts_with((string) $value->getMimeType(), 'image/');
            $limite = $esImagen ? $this->mkImageMaxBytes() : $this->mkVideoMaxBytes();

            if ((int) $value->getSize() > $limite) {
                $fail($esImagen
                    ? 'Cada imagen puede pesar hasta '.$this->enMegas($this->mkImageMaxBytes()).' MB.'
                    : 'Cada video puede pesar hasta '.$this->enMegas($this->mkVideoMaxBytes()).' MB.');
            }
        };
    }

    /** El límite en MB para el mensaje, sin decimales de más. */
    private function enMegas(int $bytes): string
    {
        return (string) (int) round($bytes / 1024 / 1024);
    }
}
