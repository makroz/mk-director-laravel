<?php

declare(strict_types=1);

namespace Mk\Director\Enums;

use Mk\Director\Auth\Enums\ScopeStatus;
use Mk\Director\Embeds\MkEmbedService;

/**
 * MkEmbedProvider — de qué plataforma es un embed.
 *
 * Spec: Comunicaciones Fase 1, PR 4.
 *
 * Int-backed desde 1, regla de la agencia. Ver el docblock de
 * {@see ScopeStatus} para la historia completa del revert.
 *
 * El set es CERRADO a propósito: sólo se guarda un provider que el paquete
 * sabe detectar por regex. Una URL de un proveedor desconocido no se guarda
 * como embed — se deja como link plano. Por eso una columna de texto libre
 * acá sería peor que el enum: podría contener cualquier cosa y nada la
 * validaría.
 */
enum MkEmbedProvider: int
{
    case YouTube = 1;
    case TikTok = 2;
    case Instagram = 3;

    /**
     * Lista de values int del enum (en orden de declaración).
     *
     * @return array<int, int>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): int => $case->value, self::cases());
    }

    /**
     * Mapa `value => label` listo para poblar un select.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * ¿El thumbnail se puede armar por convención de URL, sin llamar a nadie?
     *
     * Sólo YouTube: sus miniaturas viven en una URL predecible a partir del id
     * del video. Es la razón por la que el caso MÁS COMÚN de embed no toca la
     * red en absoluto — ni una request, ni un timeout, ni un modo de falla.
     */
    public function hasConventionalThumbnail(): bool
    {
        return $this === self::YouTube;
    }

    /**
     * Endpoint oEmbed público del provider, o null si no hace falta.
     *
     * 🔴 LOS TRES ANDAN SIN API KEY, PERO ESO YA SE ROMPIÓ UNA VEZ.
     * Meta deprecó el oEmbed sin token de Instagram y Facebook el 23-oct-2020
     * y rompió los embeds de medio internet (WordPress terminó sacando el
     * soporte de su core). En junio de 2026 dio marcha atrás y volvió a
     * funcionar sin token — verificado contra el endpoint real el 2026-07-20,
     * responde 200.
     *
     * Es decir: esto funciona hoy porque un tercero decidió que sí, y puede
     * dejar de funcionar sin aviso. Por eso el fallback a link plano de
     * {@see MkEmbedService::resolve()} NO es un caso de
     * borde: es la garantía de que el día que Meta cambie de opinión otra vez,
     * el muro sigue andando y sólo se ve un link sin miniatura.
     */
    public function oembedEndpoint(): ?string
    {
        return match ($this) {
            self::YouTube => null,
            self::TikTok => 'https://www.tiktok.com/oembed',
            self::Instagram => 'https://graph.facebook.com/v25.0/instagram_oembed',
        };
    }

    /**
     * Label human-readable para UI / responses.
     */
    public function label(): string
    {
        return match ($this) {
            self::YouTube => 'YouTube',
            self::TikTok => 'TikTok',
            self::Instagram => 'Instagram',
        };
    }
}
