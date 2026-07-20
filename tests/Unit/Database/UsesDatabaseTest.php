<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Database;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Tests\Concerns\UsesDatabase;
use Mk\Director\Tests\TestCase;

uses(TestCase::class, UsesDatabase::class);

beforeEach(function () {
    $this->setUpDatabase();
});

afterEach(function () {
    $this->tearDownDatabase();
});

/**
 * Comunicaciones Fase 1 PR 0 — la infraestructura de DB real del paquete.
 *
 * Cada test de acá verifica una garantía que el estilo "source-grep test"
 * (leer el .php y assertear `toContain`) NO PUEDE verificar. Si alguno de
 * estos cuatro falla, los tests de mk_media / reacciones / comentarios que se
 * apoyan en esta base estarían dando falsos verdes.
 */
test('la conexión sqlite en memoria es real y persiste entre queries', function () {
    Schema::create('smoke', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    DB::table('smoke')->insert(['name' => 'post']);

    // Si `:memory:` se reconectara entre queries, la tabla no existiría acá.
    expect(Schema::hasTable('smoke'))->toBeTrue();
    expect(DB::table('smoke')->count())->toBe(1);
});

test('una constraint UNIQUE RECHAZA el duplicado de verdad', function () {
    // Éste es el test que justifica todo el PR 0. Es la forma exacta del fix
    // del peor bug del legacy: `clikes` sin UNIQUE(content_id, person_id).
    Schema::create('reactions', function (Blueprint $table): void {
        $table->id();
        $table->string('reactable_id');
        $table->string('author_id');
        $table->unique(['reactable_id', 'author_id'], 'reactions_unique');
    });

    DB::table('reactions')->insert(['reactable_id' => 'p1', 'author_id' => 'u1']);

    expect(fn () => DB::table('reactions')->insert(['reactable_id' => 'p1', 'author_id' => 'u1']))
        ->toThrow(QueryException::class);

    expect(DB::table('reactions')->count())->toBe(1);
});

test('las foreign keys están ENCENDIDAS y el cascade borra los hijos', function () {
    // sqlite arranca con `PRAGMA foreign_keys = OFF`. Sin el
    // `foreign_key_constraints => true` del trait, este test pasaría en verde
    // sin haber ejercitado ninguna FK — el peor tipo de falso positivo.
    Schema::create('posts', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('post_comments', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
    });

    DB::table('posts')->insert(['id' => 1]);
    DB::table('post_comments')->insert(['post_id' => 1]);

    // La FK rechaza un huérfano...
    expect(fn () => DB::table('post_comments')->insert(['post_id' => 999]))
        ->toThrow(QueryException::class);

    // ...y el cascade se lleva los hijos.
    DB::table('posts')->where('id', 1)->delete();
    expect(DB::table('post_comments')->count())->toBe(0);
});

test('una transacción hace ROLLBACK de verdad', function () {
    // El toggle de reacciones es transaccional (fila + contador materializado).
    // Si el rollback no funcionara, un fallo a mitad de camino dejaría el
    // contador desincronizado: precisamente el bug que estamos evitando.
    Schema::create('counters', function (Blueprint $table): void {
        $table->id();
        $table->integer('likes')->default(0);
    });

    DB::table('counters')->insert(['id' => 1, 'likes' => 0]);

    try {
        DB::transaction(function (): void {
            DB::table('counters')->where('id', 1)->increment('likes');
            throw new \RuntimeException('fallo a mitad del toggle');
        });
    } catch (\RuntimeException) {
        // esperado
    }

    expect(DB::table('counters')->where('id', 1)->value('likes'))->toBe(0);
});

test('cada test estrena una base vacía (aislamiento)', function () {
    // `smoke` la creó el primer test de este archivo. Si sobreviviera, los
    // tests serían order-dependent — el paquete ya tuvo ese problema antes
    // (ver el comentario de phpunit.xml sobre BugNew31MkBelongsToManyTest).
    expect(Schema::hasTable('smoke'))->toBeFalse();
});
