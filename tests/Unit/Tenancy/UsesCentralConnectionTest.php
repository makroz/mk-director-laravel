<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Tenancy;

use Mk\Director\Auth\Models\Ability;
use Mk\Director\Help\Models\MkHelpArticle;
use Mk\Director\Help\Models\MkHelpShareLink;
use Mk\Director\Models\MkReport;
use Mk\Director\Tests\MkLaravelTestCase;

uses(MkLaravelTestCase::class);

dataset('central models', [
    'Ability' => [Ability::class],
    'MkHelpArticle' => [MkHelpArticle::class],
    'MkHelpShareLink' => [MkHelpShareLink::class],
]);

test('platform models use mk_director.tenant.central_connection when it is set', function (string $model) {
    config(['mk_director.tenant.central_connection' => 'central']);

    expect((new $model)->getConnectionName())->toBe('central');
})->with('central models');

test('with central_connection unset (default) platform models keep the default connection', function (string $model) {
    config(['mk_director.tenant.central_connection' => null]);

    expect((new $model)->getConnectionName())->toBeNull();
})->with('central models');

test('a tenant model is not affected by central_connection', function () {
    config(['mk_director.tenant.central_connection' => 'central']);

    expect((new MkReport)->getConnectionName())->toBeNull();
});
