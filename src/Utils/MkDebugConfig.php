<?php

declare(strict_types=1);

namespace Mk\Director\Utils;

/**
 * Single read point for the `mk_director.debug` master switch.
 *
 * Why this exists instead of a plain `config('mk_director.debug', false)`:
 *
 *   1. The key holds an ARRAY (`['enabled' => ..., 'explain_enabled' => ...]`),
 *      and a non-empty array is always truthy in PHP. Reading it directly in a
 *      boolean context silently pins debug ON and makes `MK_DIRECTOR_DEBUG`
 *      inert. That was a live bug until v1.7.x: `config/mk_director.php`
 *      declared `'debug'` twice — a flat bool at the top (v1.0.1) and the
 *      nested block lower down (rc13) — and in PHP the later key wins, so the
 *      flat one was dead code and every call site saw a truthy array.
 *
 *   2. `MkServiceProvider::register()` merges the package config with
 *      `mergeConfigFrom()`, which is a SHALLOW `array_merge`. A consumer that
 *      ran `vendor:publish --tag=mk-config` before the block became nested
 *      still has a flat `'debug' => bool` in their own config, and that scalar
 *      replaces the whole nested block. Reading `mk_director.debug.enabled`
 *      against a scalar returns the default, which would silently switch debug
 *      off for those consumers. This helper reads the scalar instead.
 *
 * `explain_enabled` deliberately does NOT get the same fallback: it gates the
 * `EXPLAIN` path that was a SQL injection vector before rc13, so a stale
 * published config must never be able to turn it on. It stays a direct
 * `config('mk_director.debug.explain_enabled', false)` read at its call site,
 * where a missing key correctly means `false`.
 */
final class MkDebugConfig
{
    /**
     * Whether the debug payload / diagnostics are enabled.
     *
     * Driven by `MK_DIRECTOR_DEBUG`, defaults to `false`.
     */
    public static function enabled(): bool
    {
        $debug = config('mk_director.debug', false);

        // Pre-nested published config (see class docblock): honor the scalar.
        if (! is_array($debug)) {
            return (bool) $debug;
        }

        return (bool) ($debug['enabled'] ?? false);
    }
}
