<?php

declare(strict_types=1);

namespace Mk\Director\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * TestCase minimal del paquete mk-laravel.
 *
 * No extiende de `Illuminate\Foundation\Testing\TestCase` porque
 * el paquete es un library que NO incluye la app Laravel completa
 * (solo `illuminate/support`, `illuminate/database`, `illuminate/http`).
 *
 * Los Feature tests con app Laravel real viven en
 * `apps/sandbox-laravel/tests/`. Acá los Unit tests son puros
 * o usan Mockery para aislar dependencias.
 */
abstract class TestCase extends BaseTestCase
{
}
