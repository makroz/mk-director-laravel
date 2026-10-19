<?php

declare(strict_types=1);

namespace Mk\Director\Http\Requests\Concerns;

use Closure;
use Illuminate\Http\UploadedFile;
use Mk\Director\Storage\MkStorage;

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
 * Los NÚMEROS sí son política de cada app. Los tipos y los topes salen de los
 * perfiles `image`, `video` y `document` de `mk_director.storage.profiles`
 * —los mismos que revisa la subida directa—, así que el formulario y la subida
 * directa no pueden aceptar cosas distintas. Un request que necesite otro tope
 * todavía puede redefinir el método (`mkImageMaxBytes()`, `mkMediaMaxFiles()`).
 *
 * ⚠️ Una app con `config/mk_director.php` publicado necesita esos tres perfiles
 * en su bloque `storage`: sin ellos, `MkStorage::profile()` explota nombrando
 * el que falta (caer a un tope que nadie eligió sería peor).
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
 *
 * Los PDF siguen la misma lógica: `mkAllowsDocuments()` devuelve `false` y el
 * FormRequest que los quiera (planos, documentos legales) lo sobrescribe:
 *
 *     protected function mkAllowsDocuments(): bool
 *     {
 *         return true;
 *     }
 *
 * Así los consumidores que ya existen (muro, eventos) no empiezan a aceptar
 * PDFs por actualizar el paquete.
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
     * Default: los MIME de los perfiles `image` y `video`.
     *
     * @return list<string>
     */
    protected function mkAllowedMimeTypes(): array
    {
        return [...$this->mkProfileMimes('image'), ...$this->mkProfileMimes('video')];
    }

    /**
     * ¿Este request acepta documentos PDF en `media[]`? OPT-IN, `false` por
     * defecto. Sobrescribilo en el FormRequest que los necesite.
     */
    protected function mkAllowsDocuments(): bool
    {
        return false;
    }

    /**
     * Tope de archivos por request. Sin él, una sola request puede subir mil
     * archivos y ocupar el disco entero.
     */
    protected function mkMediaMaxFiles(): int
    {
        return 10;
    }

    /** Tamaño máximo de una imagen, en bytes: el `max_bytes` del perfil `image`. */
    protected function mkImageMaxBytes(): int
    {
        return app(MkStorage::class)->profile('image')->maxBytes;
    }

    /** Tamaño máximo de un video, en bytes: el `max_bytes` del perfil `video`. */
    protected function mkVideoMaxBytes(): int
    {
        return app(MkStorage::class)->profile('video')->maxBytes;
    }

    /**
     * Tamaño máximo de un documento, en bytes: el `max_bytes` del perfil
     * `document`. Sólo aplica si {@see mkAllowsDocuments()} es `true`.
     */
    protected function mkDocumentMaxBytes(): int
    {
        return app(MkStorage::class)->profile('document')->maxBytes;
    }

    /**
     * Reglas de `media[]`.
     *
     * @return array<string, mixed>
     */
    protected function mkMediaRules(): array
    {
        $mimes = $this->mkAllowedMimeTypes();

        if ($this->mkAllowsDocuments()) {
            $mimes = [...$mimes, ...$this->mkProfileMimes('document')];
        }

        return [
            'media' => ['sometimes', 'array', 'max:'.$this->mkMediaMaxFiles()],

            'media.*' => [
                'file',
                'mimetypes:'.implode(',', $mimes),
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
     * Regla de `upload_ids`: archivos que el front ya subió DIRECTO al storage
     * (`POST {scope}/uploads`). OPT-IN: sumala sólo si el servicio los adjunta
     * con `attachUpload()`; si no, el campo se validaría y se ignoraría.
     *
     * 🔴 LA PERTENENCIA NO SE VALIDA ACÁ, igual que en `remove_media`: la
     * garantiza `attachUpload()`, que sólo toma una subida COMPLETA del mismo
     * dueño (otra cosa es 404, sin confirmar que el id existe).
     *
     * @return array<string, mixed>
     */
    protected function mkUploadIdsRules(): array
    {
        return [
            'upload_ids' => ['sometimes', 'array', 'max:'.$this->mkMediaMaxFiles()],
            'upload_ids.*' => ['uuid', 'distinct'],
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
     *
     * Un documento (un MIME del perfil `document`) tiene SU propio tope: si
     * cayera en la rama "no es imagen", se mediría contra el de video y un PDF
     * de 45 MB entraría sin que nadie lo haya decidido.
     */
    private function mkMediaSizeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile) {
                return;
            }

            // El mime REAL, no el declarado por el cliente: el header
            // `Content-Type` de un multipart lo elige quien sube el archivo.
            $mime = (string) $value->getMimeType();

            [$limite, $tipo] = match (true) {
                str_starts_with($mime, 'image/') => [$this->mkImageMaxBytes(), 'imagen'],
                $this->mkAllowsDocuments() && in_array($mime, $this->mkProfileMimes('document'), true) => [$this->mkDocumentMaxBytes(), 'documento'],
                default => [$this->mkVideoMaxBytes(), 'video'],
            };

            if ((int) $value->getSize() > $limite) {
                $fail("Cada {$tipo} puede pesar hasta ".$this->enMegas($limite).' MB.');
            }
        };
    }

    /** @return list<string> los MIME que acepta el perfil `$profile` */
    private function mkProfileMimes(string $profile): array
    {
        return array_keys(app(MkStorage::class)->profile($profile)->mimes);
    }

    /** El límite en MB para el mensaje, sin decimales de más. */
    private function enMegas(int $bytes): string
    {
        return (string) (int) round($bytes / 1024 / 1024);
    }
}
