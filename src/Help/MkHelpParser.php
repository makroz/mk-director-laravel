<?php

declare(strict_types=1);

namespace Mk\Director\Help;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Archivo Markdown con front matter → {@see MkHelpDocument}.
 *
 *   ---
 *   title: Aprobar una verificación
 *   audience: admin          # admin | member | business; IGUAL a la carpeta de arriba
 *   order: 20                # entre sus hermanos; default 0
 *   ability: admin.kyc.approve   # opcional e INFORMATIVO: nunca esconde el artículo
 *   screens: [admin.kyc.index]
 *   related: [admin/retiros/pagar]
 *   ---
 *
 * El slug es la ruta relativa sin `.md`; un `index.md` toma el de su carpeta
 * (`admin/kyc/index.md` → `admin/kyc`). El padre es la carpeta que lo contiene.
 */
final class MkHelpParser
{
    public const AUDIENCES = ['admin', 'member', 'business'];

    /** @throws MkHelpContentException */
    public function parse(string $root, string $file): MkHelpDocument
    {
        $root = rtrim(str_replace('\\', '/', $root), '/').'/';
        $path = str_replace('\\', '/', $file);

        if (! str_starts_with($path, $root) || ! str_ends_with($path, '.md')) {
            throw new MkHelpContentException("{$file}: no es un .md dentro de {$root}");
        }

        $relative = substr($path, strlen($root), -3);
        $slug = basename($relative) === 'index' ? dirname($relative) : $relative;
        $rel = $relative.'.md';

        if ($slug === '.' || ! str_contains($relative, '/')) {
            throw new MkHelpContentException("{$rel}: tiene que estar dentro de una carpeta de audiencia (".implode(', ', self::AUDIENCES).')');
        }

        $raw = file_get_contents($file);

        if ($raw === false) {
            throw new MkHelpContentException("{$rel}: no se pudo leer");
        }

        [$front, $body] = $this->split($raw, $rel);

        $folder = explode('/', $slug)[0];
        $audience = $front['audience'] ?? null;

        if (! in_array($audience, self::AUDIENCES, true)) {
            throw new MkHelpContentException("{$rel}: `audience` tiene que ser una de ".implode(', ', self::AUDIENCES));
        }

        if ($audience !== $folder) {
            throw new MkHelpContentException("{$rel}: `audience: {$audience}` no coincide con su carpeta `{$folder}/`");
        }

        $title = $front['title'] ?? null;

        if (! is_string($title) || trim($title) === '') {
            throw new MkHelpContentException("{$rel}: falta `title`");
        }

        $order = $front['order'] ?? 0;

        if (! is_int($order)) {
            throw new MkHelpContentException("{$rel}: `order` tiene que ser un número entero");
        }

        $ability = $front['ability'] ?? null;

        if ($ability !== null && (! is_string($ability) || $ability === '')) {
            throw new MkHelpContentException("{$rel}: `ability` tiene que ser texto");
        }

        return new MkHelpDocument(
            slug: $slug,
            audience: $audience,
            title: trim($title),
            order: $order,
            ability: $ability,
            screens: $this->stringList($front, 'screens', $rel),
            related: $this->stringList($front, 'related', $rel),
            body: $body,
            parentSlug: str_contains($slug, '/') ? dirname($slug) : null,
            hash: hash('sha256', $raw),
        );
    }

    /** @return array{0: array<string, mixed>, 1: string} el front matter y el cuerpo */
    private function split(string $raw, string $rel): array
    {
        $text = str_starts_with($raw, "\u{FEFF}") ? substr($raw, 3) : $raw;

        if (preg_match('/\A---\R(.*?)\R---[ \t]*(?:\R|\z)(.*)\z/s', $text, $m) !== 1) {
            throw new MkHelpContentException("{$rel}: falta el front matter (`---` arriba y abajo)");
        }

        try {
            $front = Yaml::parse($m[1]);
        } catch (ParseException $e) {
            throw new MkHelpContentException("{$rel}: front matter inválido: {$e->getMessage()}");
        }

        if (! is_array($front)) {
            throw new MkHelpContentException("{$rel}: el front matter tiene que ser una lista de claves");
        }

        return [$front, trim($m[2])."\n"];
    }

    /**
     * @param  array<string, mixed>  $front
     * @return list<string>
     */
    private function stringList(array $front, string $key, string $rel): array
    {
        $value = $front[$key] ?? [];

        if (! is_array($value) || ! array_is_list($value) || array_filter($value, fn ($v) => ! is_string($v) || $v === '') !== []) {
            throw new MkHelpContentException("{$rel}: `{$key}` tiene que ser una lista de textos");
        }

        return $value;
    }
}
