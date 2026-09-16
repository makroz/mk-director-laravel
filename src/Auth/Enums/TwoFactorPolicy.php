<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Enums;

/**
 * Qué exige un scope como segundo factor: nada, si el usuario quiere, u
 * obligatorio.
 *
 * La política es PER-SCOPE y vive en el controller del scope
 * (`BaseAuthController::twoFactorPolicy()`), no en un mapa global de config:
 * es el mismo patrón que `loginField()` o `passwordResetTable()`. Un mapa
 * `config('mk_director.auth.two_factor.scopes.operator')` obliga a que el
 * paquete conozca los nombres de los scopes del consumer, y deja el
 * comportamiento del login en un archivo que ningún test del scope lee.
 *
 * Los valores son ENTEROS DESDE 1, como el resto de los enums del paquete
 * (ver {@see ScopeStatus}): el 0 es indistinguible de `null`/`false` en casts
 * flojos, `empty()` y query strings.
 *
 * La decisión del login son dos preguntas, y nunca son las dos «sí» a la vez:
 *
 * | política    | enrolado | desafío | enrolamiento |
 * |-------------|----------|---------|--------------|
 * | `off`       | no / sí  | no      | no           |
 * | `optional`  | no       | no      | no           |
 * | `optional`  | sí       | **sí**  | no           |
 * | `required`  | no       | no      | **sí**       |
 * | `required`  | sí       | **sí**  | no           |
 *
 * 🔴 `off` no mira si el usuario está enrolado: un scope que no pidió segundo
 * factor tiene que loguear EXACTAMENTE como antes, aunque su tabla tenga las
 * columnas cargadas (pasa cuando se apaga la política sin borrar los datos).
 */
enum TwoFactorPolicy: int
{
    case Off = 1;
    case Optional = 2;
    case Required = 3;

    /**
     * La política de un scope que no declaró ninguna: apagada.
     *
     * Es la garantía de compatibilidad — todo scope ya generado hereda esto.
     */
    public static function default(): self
    {
        return self::Off;
    }

    /**
     * Construye la política desde el nombre que escribe el scaffolder en el
     * controller generado (`--two-factor=required`).
     *
     * 🔴 Un valor desconocido LANZA. Caer en `off` por defecto apagaría el
     * segundo factor de un scope que lo declaró obligatorio, y no habría un
     * solo error: el login seguiría emitiendo tokens como si nada.
     *
     * @throws \ValueError
     */
    public static function fromName(string $name): self
    {
        return match (strtolower(trim($name))) {
            'off' => self::Off,
            'optional' => self::Optional,
            'required' => self::Required,
            default => throw new \ValueError("Política de dos factores desconocida: {$name} (válidas: off, optional, required)."),
        };
    }

    /**
     * ¿El login tiene que pedir el código en vez de emitir tokens?
     *
     * Sólo cuando el usuario YA confirmó su segundo factor: un secreto emitido
     * y no confirmado no puede trabar el login (el usuario perdería la cuenta
     * si abandonó el enrolamiento a mitad de camino).
     */
    public function requiresChallenge(bool $hasConfirmedTwoFactor): bool
    {
        return $this !== self::Off && $hasConfirmedTwoFactor;
    }

    /**
     * ¿El login tiene que mandar al usuario a enrolarse, sin darle sesión?
     */
    public function requiresEnrollment(bool $hasConfirmedTwoFactor): bool
    {
        return $this === self::Required && ! $hasConfirmedTwoFactor;
    }

    /**
     * ¿Se puede apagar el segundo factor desde la API?
     *
     * Con `required` no: el scope lo exige, así que apagarlo sería dejar la
     * cuenta fuera de la política sin pasar por un administrador.
     */
    public function allowsDisabling(): bool
    {
        return $this !== self::Required;
    }

    /**
     * ¿Hay que pedir la contraseña actual para prender el segundo factor?
     *
     * Con `optional` sí: el usuario ya tiene sesión y prender el 2FA cambia
     * cómo entra, así que un token robado no puede enrolar el dispositivo del
     * atacante. Con `required` el enrolamiento sale del login mismo (sin
     * sesión) y la prueba de identidad fue la contraseña que acaba de tipear.
     */
    public function requiresPasswordToEnable(): bool
    {
        return $this !== self::Required;
    }
}
