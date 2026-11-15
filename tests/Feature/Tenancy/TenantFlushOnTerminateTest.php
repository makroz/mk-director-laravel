<?php

declare(strict_types=1);

use Mk\Director\Tenancy\Events\TenantFlushed;
use Mk\Director\Tenancy\TenantContext;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\Concerns\PushTestUser;
use Mk\Director\Tests\MkLaravelTestCase;

uses(MkLaravelTestCase::class, BootsHttpApp::class);

test('terminating the app flushes the tenant and announces it', function () {
    $app = $this->bootHttpApp(PushTestUser::class, ['tenant' => ['enabled' => false]]);
    $flushed = [];
    $app['events']->listen(TenantFlushed::class, function (TenantFlushed $e) use (&$flushed) {
        $flushed[] = $e->previous;
    });

    $app->make(TenantContext::class)->set('t-1');
    $app->terminate();

    expect($app->make(TenantContext::class)->current())->toBeNull();
    expect($flushed)->toBe(['t-1']);
});
