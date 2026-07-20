<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Illuminate\Container\Container;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Enums\ScopeStatus;
use Mk\Director\Console\Commands\MkMigrateStatusToIntCommand;
use Mk\Director\Tests\Concerns\UsesDatabase;
use Mk\Director\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(TestCase::class, UsesDatabase::class);

beforeEach(function () {
    $this->setUpDatabase();

    Schema::create('admins', function (Blueprint $table): void {
        $table->id();
        $table->string('status')->default('active');
    });

    $this->runCommand = function (array $params = []): array {
        $command = new MkMigrateStatusToIntCommand;

        // `Command` llama `$this->laravel->runningUnitTests()` al configurar
        // los prompts, y el Container pelado que bootea MkLaravelTestCase no
        // tiene ese método (es de Application, no de Container).
        //
        // Alcanza con un doble descartable: el comando NO resuelve nada más
        // desde `$this->laravel`. `DB::` y `Schema::` van por facade contra el
        // container GLOBAL, que sigue siendo el real con la conexión sqlite.
        $command->setLaravel(new class extends Container
        {
            public function runningUnitTests(): bool
            {
                return true;
            }
        });

        $output = new BufferedOutput;
        $exit = $command->run(new ArrayInput($params), $output);

        return [$exit, $output->fetch()];
    };
});

afterEach(function () {
    $this->tearDownDatabase();
});

/**
 * Revert de enums a int-backed (2026-07-19) — el comando que convierte la
 * data de los consumers que alcanzaron a migrar a string en R-PKG-047 D4.
 *
 * Corre contra sqlite real: una migración de data que se "verifica" grepeando
 * el source no verifica nada. Lo que importa acá es que la data QUEDE bien y,
 * sobre todo, que ante un value desconocido el comando NO destruya nada.
 */
test('convierte los 4 estados canónicos a sus values int', function () {
    DB::table('admins')->insert([
        ['id' => 1, 'status' => 'active'],
        ['id' => 2, 'status' => 'inactive'],
        ['id' => 3, 'status' => 'blocked'],
        ['id' => 4, 'status' => 'pending'],
    ]);

    [$exit] = ($this->runCommand)(['scope' => 'Admin']);

    expect($exit)->toBe(0);
    expect(DB::table('admins')->orderBy('id')->pluck('status')->all())
        ->toBe([
            ScopeStatus::Active->value,
            ScopeStatus::Inactive->value,
            ScopeStatus::Blocked->value,
            ScopeStatus::Pending->value,
        ]);
});

test('acepta el legacy suspended y lo mapea a Blocked', function () {
    // 'suspended' fue el nombre del tercer estado antes de que R-PKG-050
    // (F10-B12) lo renombrara a 'blocked'. Hay bases con ese valor escrito.
    DB::table('admins')->insert([['id' => 1, 'status' => 'suspended']]);

    [$exit] = ($this->runCommand)(['scope' => 'Admin']);

    expect($exit)->toBe(0);
    expect(DB::table('admins')->value('status'))->toBe(ScopeStatus::Blocked->value);
});

test('LA PROPIEDAD DE SEGURIDAD: aborta SIN TOCAR NADA si hay un value sin mapear', function () {
    // Éste es el test que justifica el orden de los pasos del comando. Si
    // convirtiera primero y validara después, la fila 'archived' caería en el
    // default y un usuario bloqueado pasaría a activo EN SILENCIO.
    DB::table('admins')->insert([
        ['id' => 1, 'status' => 'active'],
        ['id' => 2, 'status' => 'archived'],   // estado custom del consumer
        ['id' => 3, 'status' => 'blocked'],
    ]);

    [$exit, $output] = ($this->runCommand)(['scope' => 'Admin']);

    expect($exit)->not->toBe(0);
    expect($output)->toContain('archived');

    // Y lo más importante: la data quedó EXACTAMENTE como estaba.
    expect(DB::table('admins')->orderBy('id')->pluck('status')->all())
        ->toBe(['active', 'archived', 'blocked']);
});

test('un NULL también aborta en vez de caer al default', function () {
    Schema::drop('admins');
    Schema::create('admins', function (Blueprint $table): void {
        $table->id();
        $table->string('status')->nullable();
    });

    DB::table('admins')->insert([
        ['id' => 1, 'status' => 'active'],
        ['id' => 2, 'status' => null],
    ]);

    [$exit, $output] = ($this->runCommand)(['scope' => 'Admin']);

    expect($exit)->not->toBe(0);
    expect($output)->toContain('NULL');
    expect(DB::table('admins')->where('id', 2)->value('status'))->toBeNull();
});

test('el dry-run no modifica nada', function () {
    DB::table('admins')->insert([['id' => 1, 'status' => 'blocked']]);

    [$exit] = ($this->runCommand)(['scope' => 'Admin', '--dry-run' => true]);

    expect($exit)->toBe(0);
    expect(DB::table('admins')->value('status'))->toBe('blocked');
});

test('es idempotente: si la columna ya es int, no hace nada', function () {
    Schema::drop('admins');
    Schema::create('admins', function (Blueprint $table): void {
        $table->id();
        $table->unsignedTinyInteger('status')->default(1);
    });
    DB::table('admins')->insert([['id' => 1, 'status' => 3]]);

    [$exit, $output] = ($this->runCommand)(['scope' => 'Admin']);

    expect($exit)->toBe(0);
    expect($output)->toContain('idempotente');
    expect(DB::table('admins')->value('status'))->toBe(3);
});

test('fromLegacyString cubre los 4 canónicos + el alias suspended y rechaza el resto', function () {
    expect(ScopeStatus::fromLegacyString('active'))->toBe(ScopeStatus::Active);
    expect(ScopeStatus::fromLegacyString('INACTIVE'))->toBe(ScopeStatus::Inactive);
    expect(ScopeStatus::fromLegacyString('  blocked '))->toBe(ScopeStatus::Blocked);
    expect(ScopeStatus::fromLegacyString('suspended'))->toBe(ScopeStatus::Blocked);
    expect(ScopeStatus::fromLegacyString('pending'))->toBe(ScopeStatus::Pending);

    expect(fn () => ScopeStatus::fromLegacyString('archived'))->toThrow(\ValueError::class);
});
