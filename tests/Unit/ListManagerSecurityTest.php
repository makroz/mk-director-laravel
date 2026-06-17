<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Mk\Director\Managers\ListManager;
use Mockery;

uses(\Mk\Director\Tests\TestCase::class);

afterEach(function () {
    Mockery::close();
});

test('applyFilters only applies whitelisted filters', function () {
    $query = Mockery::mock(Builder::class);

    $request = Request::create('/test', 'GET', [
        'filter' => [
            'status' => 'active',
            'admin_flag' => '1', // No en la whitelist
            'users.email` = ? OR 1=1; --' => 'exploit', // SQL Injection payload
        ],
    ]);

    $query->shouldReceive('where')
        ->once()
        ->with('status', '=', 'active')
        ->andReturnSelf();

    $query->shouldReceive('where')
        ->never()
        ->with('admin_flag', Mockery::any(), Mockery::any());

    $query->shouldReceive('where')
        ->never()
        ->with(Mockery::contains('1=1'), Mockery::any(), Mockery::any());

    ListManager::applyFilters($request, $query, ['status']);
});

test('applyJoins only applies whitelisted joins', function () {
    $query = Mockery::mock(Builder::class);
    $model = Mockery::mock(Model::class);

    $request = Request::create('/test', 'GET', [
        'joins' => 'profiles|roles|dangerous_table',
    ]);

    $model->shouldReceive('getTable')->andReturn('users');
    $query->shouldReceive('getModel')->andReturn($model);

    $query->shouldReceive('leftJoin')
        ->once()
        ->with('profiles', 'users.profile_id', '=', 'profiles.id')
        ->andReturnSelf();

    $query->shouldReceive('leftJoin')
        ->once()
        ->with('roles', 'users.role_id', '=', 'roles.id')
        ->andReturnSelf();

    $query->shouldReceive('leftJoin')
        ->never()
        ->with('dangerous_table', Mockery::any(), Mockery::any(), Mockery::any());

    $ref = new \ReflectionClass(ListManager::class);
    $method = $ref->getMethod('applyJoins');
    $method->setAccessible(true);
    $method->invoke(null, $request, $query, ['profiles', 'roles']);
});
