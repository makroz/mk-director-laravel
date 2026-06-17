<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;

uses(\Mk\Director\Tests\TestCase::class);

afterEach(function () {
    Mockery::close();
});

test('auth_users migration defines a uuid primary key', function () {
    Schema::shouldReceive('create')
        ->once()
        ->with('auth_users', Mockery::on(function ($closure) {
            $blueprint = Mockery::mock(Blueprint::class);

            // Verificamos que se defina como UUID y clave primaria
            $blueprint->shouldReceive('uuid')
                ->once()
                ->with('id')
                ->andReturnSelf();

            $blueprint->shouldReceive('primary')
                ->once()
                ->andReturnSelf();

            $blueprint->shouldIgnoreMissing();

            $closure($blueprint);
            return true;
        }));

    $migration = require __DIR__ . '/../../src/Auth/Database/Migrations/2026_06_10_000001_create_auth_users_table.php';
    $migration->up();
});
