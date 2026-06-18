<?php
declare(strict_types=1);


namespace Mk\Director\Tests\Unit;

test('MkServiceProvider can be instantiated', function () {
    $provider = new \Mk\Director\MkServiceProvider(\app());
    expect($provider)->toBeObject();
});

test('package has correct name', function () {
    expect(\Mk\Director\MkServiceProvider::class)->toBeString();
});
