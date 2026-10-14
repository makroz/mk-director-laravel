<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;

// El paquete mk-laravel no incluye una app Laravel completa, así que
// los TestCase por archivo se declaran explícitamente. No propagamos
// un TestCase global porque los tests que sí necesitan Laravel viven
// en `apps/sandbox-laravel/tests/Feature`.

// 🔴 Toda la suite corre con el guard de lazy loading PRENDIDO: un acceso a
// una relación no cargada sobre una colección de 2+ modelos tira
// `LazyLoadingViolationException` en vez de hacer una query por fila.
//
// Va ACÁ y no en un TestCase: este archivo lo carga Pest una vez para TODOS
// los tests, sea cual sea su base (MkLaravelTestCase, UsesDatabase con su
// Capsule propio, BootsHttpApp, o PHPUnit pelado). El flag es estático en
// `Model`, así que ponerlo en un `setUp()` dejaría a los tests sin esa base
// dependiendo del orden en que corren.
//
// ⚠️ Es una decisión de la SUITE, no del paquete: `src/` no lo prende nunca.
// Prenderlo en producción es decisión de cada app (RETO y Mozzo lo hacen
// fuera de producción).
//
// ⚠️ No ve un `$model->loadMissing()` dentro de un `foreach`: eso es carga
// EXPLÍCITA, no lazy, y hace igual una query por fila. Eso lo atrapan los
// tests de presupuesto de queries (`tests/Feature/ListadoSinNMasUnoTest.php`).
Model::preventLazyLoading();
