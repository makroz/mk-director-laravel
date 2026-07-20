<?php

declare(strict_types=1);

namespace Mk\Director\Embeds;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Http;
use Mk\Director\Enums\MkEmbedProvider;
use Throwable;

/**
 * MkEmbedService — reconoce URLs de YouTube / TikTok / Instagram.
 *
 * Spec: Comunicaciones Fase 1, PR 4.
 *
 * CERO API KEYS
 * -------------
 * Nada acá pide credenciales de terceros. YouTube ni siquiera necesita red.
 * TikTok e Instagram usan sus oEmbed PÚBLICOS, verificados contra el endpoint
 * real el 2026-07-20 (los tres responden 200 sin token).
 *
 * 🔴 Pero eso es una decisión de un tercero, no una garantía. Meta ya deprecó
 * el oEmbed sin token de Instagram el 23-oct-2020 y rompió medio internet;
 * recién en junio de 2026 dio marcha atrás. Puede volver a cambiar mañana. Ver
 * {@see MkEmbedProvider::oembedEndpoint()}.
 *
 * Por eso el diseño es de DOS ETAPAS SEPARADAS:
 *
 *   detect()  — sólo regex. Sin red, determinista, no puede fallar.
 *   resolve() — detect() + oEmbed para el thumbnail. Puede fallar, y CUANDO
 *               falla devuelve igual el embed de detect(), sin miniatura.
 *
 * O sea: el peor escenario posible (los tres oEmbed caídos a la vez) degrada
 * el muro a "embeds sin miniatura". No lo rompe. Un diseño donde el embed
 * dependiera de la respuesta del tercero convertiría una caída ajena en un
 * error nuestro.
 *
 * USO
 * ---
 *   $embed = app(MkEmbedService::class)->resolve($url);
 *
 *   if ($embed !== null) {
 *       $post->attachMedia($embed->toMediaAttributes());
 *   }
 *   // null = no es un proveedor conocido → guardalo como link plano.
 */
class MkEmbedService
{
    /**
     * Patrones de detección, en orden de evaluación.
     *
     * YouTube: `watch?v=`, `youtu.be/`, `/shorts/`, `/embed/`, `/live/`. El id
     * es de 11 caracteres exactos por definición de YouTube — acotarlo evita
     * que un query string se cuele adentro del id.
     *
     * TikTok: la URL canónica (`/@usuario/video/123...`) Y la corta
     * (`vm.tiktok.com/XXXX`). La corta está incluida porque es la que da el
     * botón de compartir, o sea la que la gente REALMENTE pega. Su capturado
     * es el shortcode, no el id del video; el oEmbed acepta igual la URL corta
     * y de ahí sale el thumbnail.
     *
     * Instagram: posts, reels y tv comparten formato de shortcode.
     *
     * @var array<int, array{0: MkEmbedProvider, 1: string}>
     */
    private const PATTERNS = [
        [MkEmbedProvider::YouTube, '#^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:[^\#]*&)?v=|shorts/|embed/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})#i'],
        [MkEmbedProvider::TikTok, '#^https?://(?:www\.)?tiktok\.com/@[\w.\-]+/video/(\d+)#i'],
        [MkEmbedProvider::TikTok, '#^https?://(?:vm|vt)\.tiktok\.com/([A-Za-z0-9]+)#i'],
        [MkEmbedProvider::Instagram, '#^https?://(?:www\.)?instagram\.com/(?:p|reel|reels|tv)/([A-Za-z0-9_\-]+)#i'],
    ];

    public function __construct(
        private readonly ?CacheRepository $cache = null,
        private readonly int $timeout = 3,
        private readonly int $cacheTtl = 86400,
    ) {}

    /**
     * Reconoce el proveedor y el id a partir de la URL. SIN RED.
     *
     * Devuelve null si no es ninguno de los proveedores conocidos — que NO es
     * un error: significa "guardalo como link plano".
     */
    public function detect(string $url): ?MkEmbed
    {
        $url = trim($url);

        foreach (self::PATTERNS as [$provider, $pattern]) {
            if (preg_match($pattern, $url, $matches) === 1) {
                return new MkEmbed(
                    provider: $provider,
                    providerId: $matches[1],
                    sourceUrl: $url,
                    thumbnailUrl: $this->conventionalThumbnail($provider, $matches[1]),
                );
            }
        }

        return null;
    }

    /**
     * `detect()` + thumbnail vía oEmbed cuando hace falta.
     *
     * NUNCA tira. Si el oEmbed falla, timeoutea o devuelve basura, se devuelve
     * el embed de `detect()` tal cual. Ver el docblock de la clase.
     */
    public function resolve(string $url): ?MkEmbed
    {
        $embed = $this->detect($url);

        if ($embed === null) {
            return null;
        }

        // YouTube ya tiene su miniatura por convención de URL: el caso más
        // común no toca la red en absoluto.
        if ($embed->provider->hasConventionalThumbnail()) {
            return $embed;
        }

        $payload = $this->fetchOembed($embed);

        return $payload === null ? $embed : $embed->withOembed($payload);
    }

    /**
     * Miniatura derivable de la URL, sin llamar a nadie.
     *
     * `hqdefault` y no `maxresdefault`: el segundo NO existe para todos los
     * videos y devuelve un 404 (que en la UI se ve como una imagen rota),
     * mientras que `hqdefault` está garantizado para cualquier id válido.
     */
    private function conventionalThumbnail(MkEmbedProvider $provider, string $providerId): ?string
    {
        return $provider === MkEmbedProvider::YouTube
            ? "https://i.ytimg.com/vi/{$providerId}/hqdefault.jpg"
            : null;
    }

    /**
     * Pega al oEmbed público del provider, con caché y sin propagar errores.
     *
     * El caché es por URL de origen y guarda TAMBIÉN el fallo (como array
     * vacío). Sin eso, una URL que el proveedor no reconoce vuelve a pegarle
     * en cada request que muestre el post — un post con un embed roto en un
     * feed muy visitado se convierte en un martilleo constante contra un
     * tercero.
     *
     * @return array<string, mixed>|null
     */
    private function fetchOembed(MkEmbed $embed): ?array
    {
        $endpoint = $embed->provider->oembedEndpoint();

        if ($endpoint === null) {
            return null;
        }

        $key = 'mk_director:embed:'.$embed->provider->value.':'.md5($embed->sourceUrl);

        $payload = $this->cache?->get($key);

        if (is_array($payload)) {
            return $payload === [] ? null : $payload;
        }

        $payload = $this->requestOembed($endpoint, $embed->sourceUrl);

        // Se cachea el vacío igual que el éxito: ver el comentario de arriba.
        $this->cache?->put($key, $payload ?? [], $this->cacheTtl);

        return $payload;
    }

    /**
     * La request cruda. Aislada para que el catch cubra SÓLO la llamada al
     * tercero y no se coma, de paso, un error de nuestro propio código.
     *
     * @return array<string, mixed>|null
     */
    private function requestOembed(string $endpoint, string $sourceUrl): ?array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->get($endpoint, ['url' => $sourceUrl]);

            if (! $response->successful()) {
                return null;
            }

            $json = $response->json();

            return is_array($json) && $json !== [] ? $json : null;
        } catch (Throwable) {
            // Timeout, DNS, TLS, el proveedor caído, un 500 suyo. Nada de eso
            // es un error nuestro ni debe romper el guardado del post.
            return null;
        }
    }
}
