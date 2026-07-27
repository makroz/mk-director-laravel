<?php

declare(strict_types=1);

namespace Mk\Director\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Mk\Director\Models\MkMedia;

/**
 * Serialización de una pieza de media ({@see MkMedia}).
 *
 * POR QUÉ VIVE EN EL PAQUETE
 * --------------------------
 * `MkMedia` es un modelo del paquete. Cómo se ve un modelo del paquete es
 * decisión del paquete, no de cada consumer: mientras esta clase vivió dentro
 * de un módulo de la app (`App\Modules\Communications\Http\Resources`), el
 * único camino para que OTRO módulo mostrara media era romper la frontera
 * MME (R-MK-001, `mk:lint:boundaries`) o hacer una copia. Se hicieron copias.
 * Ver la misma decisión aplicada a los hooks del muro.
 *
 * Al vivir acá, cualquier módulo la importa sin cruzar frontera: el linter
 * sólo mira FQCN `App\Modules\*`, y `Mk\Director\*` le es transparente.
 *
 * CONTRATO
 * --------
 * 🔴 EL SHAPE DE ESTE PAYLOAD ES CONTRATO PÚBLICO. Hay fronts consumiéndolo.
 * Agregar claves es aditivo y seguro; renombrar o sacar una clave es breaking
 * y se evalúa contra los consumers antes de tocar nada.
 *
 * Las tres variantes NO son intercambiables y la respuesta lo refleja: una
 * imagen o un video traen `url` (resuelta contra el disk DE SU FILA), y un
 * embed no tiene archivo propio nuestro — trae `provider`, `source_url` y una
 * miniatura remota que puede ser null si el oEmbed del tercero falló.
 *
 * El legacy sobrecargaba UNA columna `url` con tres significados según el
 * tipo, y por eso el cliente no podía saber qué estaba recibiendo.
 *
 * @mixin MkMedia
 */
class MkMediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind?->value,
            'kind_label' => $this->kind?->label(),
            'position' => $this->position,

            // Archivo propio (imagen/video). Null para embed.
            'url' => $this->url,
            'mime_type' => $this->mime_type,
            'width' => $this->width,
            'height' => $this->height,
            'duration' => $this->duration,

            // Embed. Null para archivo propio.
            'provider' => $this->provider?->value,
            'provider_label' => $this->provider?->label(),

            // 🔴 EL ID DEL VIDEO EN SU PLATAFORMA — SIN ESTO EL CLIENTE TIENE
            // QUE RE-PARSEAR LA URL.
            // La columna existe desde PR 4 y el paquete la llena con la MISMA
            // regex que usó para detectar el provider, ya testeada. No
            // exponerla obligaba a cada cliente (mobile y web) a reimplementar
            // ese parseo por su cuenta: dos regex distintas, ninguna testeada,
            // desincronizándose de la del paquete.
            //
            // Es lo que hace armable la URL del reproductor
            // (`youtube.com/embed/{id}`) sin tocar la red.
            'provider_id' => $this->provider_id,

            'source_url' => $this->source_url,
            // 🔴 Puede ser null aunque el embed sea válido: si el oEmbed del
            // proveedor falló, el embed se guarda igual sin miniatura. El
            // cliente TIENE que contemplar este caso, no es excepcional.
            'thumbnail_url' => $this->thumbnail_url,
        ];
    }
}
