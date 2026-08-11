<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Utils;

use Mk\Director\Tests\MkLaravelTestCase;
use Mk\Director\Tests\Unit\ConfigNoDuplicateKeysTest;
use Mk\Director\Utils\MkDebugConfig;

/**
 * The `MK_DIRECTOR_DEBUG` kill switch must actually kill.
 *
 * Regression: `config/mk_director.php` declared `'debug'` twice — a flat bool
 * at the top and a nested block lower down. PHP kept the nested one, so every
 * `config('mk_director.debug', false)` read got a non-empty ARRAY, which is
 * always truthy. `MK_DIRECTOR_DEBUG=false` did nothing.
 *
 * @see ConfigNoDuplicateKeysTest for the structural guard.
 */
uses(MkLaravelTestCase::class);

test('THE BUG: a nested debug block with enabled=false is OFF, not truthy', function () {
    config(['mk_director.debug' => ['enabled' => false, 'explain_enabled' => false]]);

    // The old `config('mk_director.debug', false)` read returned this array,
    // and `(bool) ['enabled' => false, ...]` is `true` — the kill switch was
    // inert. Pin the array-is-truthy premise so the regression is unmistakable.
    expect((bool) config('mk_director.debug'))->toBeTrue();
    expect(MkDebugConfig::enabled())->toBeFalse();
});

test('a nested debug block with enabled=true is ON', function () {
    config(['mk_director.debug' => ['enabled' => true, 'explain_enabled' => false]]);

    expect(MkDebugConfig::enabled())->toBeTrue();
});

test('explain_enabled alone never turns the master switch on', function () {
    config(['mk_director.debug' => ['enabled' => false, 'explain_enabled' => true]]);

    expect(MkDebugConfig::enabled())->toBeFalse();
});

test('a missing enabled key is OFF (fail-safe default)', function () {
    config(['mk_director.debug' => ['explain_enabled' => false]]);

    expect(MkDebugConfig::enabled())->toBeFalse();
});

test('a missing debug block entirely is OFF', function () {
    config(['mk_director' => []]);

    expect(MkDebugConfig::enabled())->toBeFalse();
});

test('BC: a pre-nested published config with a flat false is OFF', function () {
    // `mergeConfigFrom()` is a shallow array_merge, so a consumer that
    // published `config/mk_director.php` before the block became nested still
    // has a flat bool here and it replaces the whole nested block.
    config(['mk_director.debug' => false]);

    expect(MkDebugConfig::enabled())->toBeFalse();
});

test('BC: a pre-nested published config with a flat true is ON, not silently dropped', function () {
    config(['mk_director.debug' => true]);

    expect(MkDebugConfig::enabled())->toBeTrue();
});

test('the shipped default is OFF when MK_DIRECTOR_DEBUG is unset', function () {
    // Guards the default the package actually ships, independent of whatever
    // the surrounding test run left in the config repository.
    $shipped = ['enabled' => env('MK_DIRECTOR_DEBUG', false), 'explain_enabled' => false];
    config(['mk_director.debug' => $shipped]);

    expect(MkDebugConfig::enabled())->toBeFalse();
});
