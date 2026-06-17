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

test('role_user migration defines a uuid user_id and configurable user_type default', function () {
    config(['mk_director.auth.default_user_type' => 'App\Models\CustomAdmin']);

    Schema::shouldReceive('create')
        ->once()
        ->with('role_user', Mockery::on(function ($closure) {
            $blueprint = Mockery::mock(Blueprint::class);

            // Verificamos que user_id sea de tipo uuid
            $blueprint->shouldReceive('uuid')
                ->once()
                ->with('user_id')
                ->andReturnSelf();

            // Verificamos que user_type tenga el valor por defecto configurable
            $columnMock = Mockery::mock();
            $columnMock->shouldReceive('default')
                ->once()
                ->with('App\Models\CustomAdmin')
                ->andReturnSelf();

            $blueprint->shouldReceive('string')
                ->once()
                ->with('user_type')
                ->andReturn($columnMock);

            $blueprint->shouldIgnoreMissing();

            $closure($blueprint);
            return true;
        }));

    $migration = require __DIR__ . '/../../src/Auth/Database/Migrations/2026_06_10_000004_create_role_user_table.php';
    $migration->up();
});
