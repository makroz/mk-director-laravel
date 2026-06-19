<?php

declare(strict_types=1);

namespace Mk\Director\Tests;

/**
 * Backwards-compatible alias for {@see MkLaravelTestCase}.
 *
 * Existing Unit tests reference `Mk\Director\Tests\TestCase` (the minimal
 * PHPUnit base from v1.0.x). The 4R audit (R3-009, R3-010, R3-011) flagged
 * 6 Unit tests as failing because that base did not boot a Container, so
 * calls to `config()`, `app()`, `Schema::shouldReceive()`, etc. all threw
 * `BindingResolutionException`.
 *
 * We keep `TestCase` as a thin alias that extends `MkLaravelTestCase` so
 * every existing `uses(\Mk\Director\Tests\TestCase::class)` call now gets
 * the booted Container for free. New tests should use
 * `uses(\Mk\Director\Tests\MkLaravelTestCase::class)` directly to make the
 * dependency on a Laravel-aware boot explicit.
 *
 * @see audit-2026-06-17-R3-009, audit-2026-06-17-R3-010, audit-2026-06-17-R3-011
 */
abstract class TestCase extends MkLaravelTestCase
{
}