<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Services;

/**
 * TOTP (RFC 6238) — la aritmética del segundo factor, sin dependencias nuevas.
 *
 * 🔴 POR QUÉ ESTÁ ESCRITO ACÁ Y NO SE AGREGA UN PAQUETE.
 *
 * Todo lo que hace falta es `hash_hmac('sha1')` y `pack()`, que vienen con PHP.
 * Un HMAC de 20 bytes, un truncado dinámico y un módulo: 40 líneas. Una
 * dependencia más en un paquete que consumen cuatro repos es una versión más
 * que auditar, y la especificación no se mueve desde 2011.
 *
 * Parámetros, fijos a propósito (los que generan Google Authenticator, Authy,
 * 1Password y Aegis por defecto — un valor distinto acá no se ve roto: se ve
 * como «el código no es válido»):
 *   - HMAC-SHA1, 6 dígitos, paso de 30 segundos, T0 = 0.
 *   - Ventana de ±1 paso: cubre el desfase de reloj del teléfono y al usuario
 *     que tipea el código justo cuando cambia. Más ventana es más superficie
 *     de adivinanza por intento.
 *
 * ANTI-REPLAY: la clase no guarda estado. `verify()` recibe el último paso
 * aceptado del usuario (columna `two_factor_last_step`) y rechaza cualquier
 * paso menor o igual. Sin eso, un código interceptado sirve los 30 segundos
 * que le quedan de vida —y hasta 90 con la ventana—, que es exactamente el
 * tiempo que necesita un atacante que lo leyó de un log o de un hombro.
 *
 * El secreto viaja y se guarda en BASE32 porque es lo que leen los lectores de
 * QR y lo que tipea un usuario a mano; los bytes crudos no son transcribibles.
 */
final class TotpService
{
    /** Segundos de vida de cada código (RFC 6238 § 4: el valor recomendado). */
    public const STEP_SECONDS = 30;

    /** Dígitos del código. 6 es lo que emite toda app de autenticación por defecto. */
    public const DIGITS = 6;

    /**
     * Pasos de tolerancia hacia atrás y hacia adelante.
     *
     * Con 1 se aceptan 3 códigos (anterior, actual, siguiente) = 90 segundos de
     * ventana. Cada paso extra multiplica la chance de una adivinanza.
     */
    public const WINDOW_STEPS = 1;

    /** Bytes del secreto: 20 = el tamaño del bloque de SHA1 (RFC 4226 § 4 pide 16 como mínimo). */
    public const SECRET_BYTES = 20;

    /** Códigos de recuperación que se emiten de una sola vez. */
    public const RECOVERY_CODE_COUNT = 8;

    /** Bytes al azar de cada código de recuperación (5 → 10 caracteres hex, 40 bits). */
    public const RECOVERY_CODE_BYTES = 5;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Un secreto nuevo, en base32, listo para el QR y para la columna.
     */
    public function generateSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_BYTES));
    }

    /**
     * El paso de tiempo de un instante. `null` = ahora.
     *
     * Es la división entera del reloj unix por el paso: el mismo número a los
     * dos lados mientras los relojes estén cerca.
     */
    public function currentStep(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? $this->clock(), self::STEP_SECONDS);
    }

    /**
     * El reloj: `now()` de Laravel, no `time()`.
     *
     * 🔴 La diferencia es que `now()` se puede CONGELAR con
     * `Carbon::setTestNow()`, y sin eso un test del flujo completo es flaky por
     * diseño: entre sellar el paso aceptado y pedir el siguiente código puede
     * cruzarse el borde de los 30 segundos, y el código que el test calculó
     * queda fuera de la ventana. Medido: un `composer test` de cada tantos daba
     * un rojo que no volvía a aparecer al correr el archivo solo — el peor tipo
     * de rojo, porque invita a correr de nuevo en vez de mirar.
     *
     * `time()` queda como respaldo para usar la clase fuera de Laravel.
     */
    private function clock(): int
    {
        return function_exists('now') ? now()->getTimestamp() : time();
    }

    /**
     * El código de un paso concreto (RFC 4226 § 5.3 + RFC 6238 § 4).
     *
     * El contador va como entero de 64 bits big-endian; el truncado dinámico
     * toma los 4 bits bajos del último byte como desplazamiento y lee 4 bytes
     * desde ahí, apagando el bit de signo.
     */
    public function codeAt(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);
        if ($key === '') {
            return '';
        }

        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad(
            (string) ($binary % (10 ** self::DIGITS)),
            self::DIGITS,
            '0',
            STR_PAD_LEFT,
        );
    }

    /**
     * Valida un código y devuelve EL PASO que aceptó, o `null`.
     *
     * Devuelve el paso —y no un booleano— porque el caller tiene que guardarlo
     * en `two_factor_last_step` para que el mismo código no entre dos veces.
     *
     * @param  int|null  $lastAcceptedStep  el último paso que este usuario ya gastó
     * @param  int|null  $timestamp  reloj a usar (los tests lo fijan)
     */
    public function verify(string $secret, string $code, ?int $lastAcceptedStep = null, ?int $timestamp = null): ?int
    {
        if ($secret === '' || $code === '') {
            return null;
        }

        $current = $this->currentStep($timestamp);

        for ($offset = -self::WINDOW_STEPS; $offset <= self::WINDOW_STEPS; $offset++) {
            $step = $current + $offset;

            // `hash_equals` y no `===` ni `==`: la comparación de un secreto va
            // en tiempo constante, y `==` entre dos strings numéricos compara
            // NÚMEROS (`'287082' == ' 287082'` es true).
            if (! hash_equals($this->codeAt($secret, $step), $code)) {
                continue;
            }

            // El código es correcto pero su paso ya se usó (o es más viejo que
            // el último aceptado): es un replay, no un login.
            if ($lastAcceptedStep !== null && $step <= $lastAcceptedStep) {
                return null;
            }

            return $step;
        }

        return null;
    }

    /**
     * La URI `otpauth://` que el front convierte en QR.
     *
     * El emisor va DOS veces —en la etiqueta y en el parámetro `issuer`— porque
     * hay lectores que sólo miran uno de los dos, y sin él la cuenta aparece en
     * la app del usuario sin decir de qué sistema es.
     */
    public function otpauthUri(string $secret, string $accountLabel, ?string $issuer = null): string
    {
        $issuer = $issuer !== null && trim($issuer) !== '' ? trim($issuer) : 'MK Director';
        $label = rawurlencode($issuer).':'.rawurlencode($accountLabel);

        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::STEP_SECONDS,
        ], '', '&', PHP_QUERY_RFC3986);

        return "otpauth://totp/{$label}?{$query}";
    }

    /**
     * Los códigos de recuperación en claro. Se muestran UNA vez: en la base
     * sólo queda su hash.
     *
     * @return array<int, string>
     */
    public function generateRecoveryCodes(): array
    {
        $codes = [];

        // 🔴 La deduplicación va con `in_array` y NO con el código como clave de
        // un array, que es lo natural de escribir: PHP convierte a ENTERO toda
        // clave que sea un string numérico, así que un código que salga todo en
        // dígitos (`'1234567890'`, ~1% de las veces, o sea 7 de cada 100 tandas)
        // volvía como `int` y el response entregaba un número donde el contrato
        // dice string. Lo encontró un rojo intermitente del test —el peor tipo
        // de rojo: invita a correr de nuevo en vez de mirar—.
        while (count($codes) < self::RECOVERY_CODE_COUNT) {
            $code = bin2hex(random_bytes(self::RECOVERY_CODE_BYTES));

            if (! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * Hashea los códigos para guardarlos.
     *
     * 🔴 SHA-256 y no bcrypt, a propósito. Un código de recuperación son 40
     * bits al azar, no una contraseña elegida por una persona: no hay
     * diccionario contra el que un hash lento proteja. Y con bcrypt cada
     * intento costaría hasta OCHO comparaciones lentas (hay que probar contra
     * toda la lista) — casi un segundo de CPU por intento, en un endpoint
     * público. El tope de intentos es lo que corta la fuerza bruta acá.
     *
     * @param  array<int, string>  $plainCodes
     * @return array<int, string>
     */
    public function hashRecoveryCodes(array $plainCodes): array
    {
        return array_values(array_map(
            static fn (string $code): string => hash('sha256', $code),
            $plainCodes,
        ));
    }

    /**
     * Gasta un código de recuperación: devuelve la lista de hashes que QUEDA,
     * o `null` si el código no estaba.
     *
     * Devolver la lista nueva —y no un booleano— deja el consumo en un solo
     * lugar: el caller guarda lo que recibe y no puede olvidarse de sacar el
     * hash usado.
     *
     * @param  array<int, string>  $hashes
     * @return array<int, string>|null
     */
    public function consumeRecoveryCode(array $hashes, string $candidate): ?array
    {
        if ($candidate === '' || $hashes === []) {
            return null;
        }

        $candidateHash = hash('sha256', $candidate);
        $remaining = [];
        $found = false;

        foreach ($hashes as $hash) {
            // Un solo código se gasta por llamada aunque la lista tenga
            // duplicados (no debería, pero un consumer puede haber escrito la
            // columna a mano).
            if (! $found && is_string($hash) && hash_equals($hash, $candidateHash)) {
                $found = true;

                continue;
            }

            $remaining[] = $hash;
        }

        return $found ? $remaining : null;
    }

    /**
     * Base32 sin padding (RFC 4648), el alfabeto que leen los lectores de QR.
     */
    public static function base32Encode(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32_ALPHABET[(int) bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    /**
     * Decodifica base32 tolerando lo que pega un usuario: minúsculas, espacios
     * y el `=` de padding. Un caracter fuera del alfabeto se ignora en vez de
     * romper — el secreto que no decodifica termina en «código inválido», que
     * es la respuesta correcta.
     */
    public static function base32Decode(string $base32): string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $base32) ?? '');
        if ($clean === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($clean) as $char) {
            $index = strpos(self::BASE32_ALPHABET, $char);
            if ($index === false) {
                continue;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            // El último grupo puede quedar incompleto (base32 no alinea a 8
            // bits): son bits de relleno, no un byte.
            if (strlen($chunk) === 8) {
                $out .= chr((int) bindec($chunk));
            }
        }

        return $out;
    }
}
