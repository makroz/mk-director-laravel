<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Tenancy;

use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Mk\Director\Export\Jobs\GenerateCustomReportJob;
use Mk\Director\Export\Jobs\GenerateListExportJob;
use Mk\Director\Models\MkReport;
use Mk\Director\Tenancy\Events\TenantFlushed;
use Mk\Director\Tenancy\Events\TenantResolved;
use Mk\Director\Tenancy\TenantContext;
use Mk\Director\Tests\MkLaravelTestCase;
use RuntimeException;

uses(MkLaravelTestCase::class);

beforeEach(function () {
    // The package provider registers it as a singleton; the bare test container does not.
    app()->singleton(TenantContext::class);
});

// ── S2: run() ───────────────────────────────────────────────────────

test('run sets the tenant for the callable, returns its value and clears afterwards', function () {
    $context = new TenantContext;

    $seen = $context->run(5, fn () => $context->current());

    expect($seen)->toBe(5);
    expect($context->current())->toBeNull();
});

test('run restores the previous tenant', function () {
    $context = new TenantContext;
    $context->set(1);

    $context->run(2, fn () => expect($context->current())->toBe(2));

    expect($context->current())->toBe(1);
});

test('run restores the previous tenant even when the callable throws', function () {
    $context = new TenantContext;
    $context->set(1);

    expect(fn () => $context->run(2, fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class, 'boom');

    expect($context->current())->toBe(1);

    $empty = new TenantContext;
    try {
        $empty->run(3, fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }
    expect($empty->current())->toBeNull();
});

// ── S4: events ──────────────────────────────────────────────────────

test('set dispatches TenantResolved with the previous tenant', function () {
    $events = new Dispatcher;
    $seen = [];
    $events->listen(TenantResolved::class, function (TenantResolved $e) use (&$seen) {
        $seen[] = [$e->tenantId, $e->previous];
    });
    $context = new TenantContext($events);

    $context->set(1);
    $context->set(2);

    expect($seen)->toBe([[1, null], [2, 1]]);
});

test('set dispatches nothing when the value does not change', function () {
    $events = new Dispatcher;
    $count = 0;
    $events->listen(TenantResolved::class, function () use (&$count) {
        $count++;
    });
    $context = new TenantContext($events);

    $context->set(1);
    $context->set(1);

    expect($count)->toBe(1);
});

test('flush dispatches TenantFlushed only when there was a tenant', function () {
    $events = new Dispatcher;
    $seen = [];
    $events->listen(TenantFlushed::class, function (TenantFlushed $e) use (&$seen) {
        $seen[] = $e->previous;
    });
    $context = new TenantContext($events);

    $context->flush();
    expect($seen)->toBe([]);

    $context->set('t-9');
    $context->flush();
    $context->flush();

    expect($seen)->toBe(['t-9']);
    expect($context->current())->toBeNull();
});

test('TenantContext built without a dispatcher keeps working', function () {
    $context = new TenantContext;

    $context->set(3);
    expect($context->current())->toBe(3);

    $context->flush();
    expect($context->current())->toBeNull();
});

// ── S2: the export jobs keep restoring the tenant ───────────────────

dataset('export jobs', [
    'list export' => [fn () => new GenerateListExportJob(1, 'X')],
    'custom report' => [fn () => new GenerateCustomReportJob(1)],
]);

test('export jobs run the flow as the report tenant and restore the previous one', function (callable $make) {
    $context = app(TenantContext::class);
    $context->set('outer');

    $report = (new MkReport)->forceFill(['tenant_id' => 'inner']);
    $job = $make();
    $method = new \ReflectionMethod($job, 'comoElDuenoDelReporte');

    $args = $job instanceof GenerateListExportJob
        ? [$report, Request::create('/x'), function () use ($context, &$seen) {
            $seen = $context->current();
        }]
        : [$report, function () use ($context, &$seen) {
            $seen = $context->current();

            return 'ok';
        }];

    $method->invoke($job, ...$args);

    expect($seen)->toBe('inner');
    expect($context->current())->toBe('outer');
})->with('export jobs');

test('export jobs clear the tenant afterwards when none was active, even if the flow throws', function (callable $make) {
    $context = app(TenantContext::class);
    $context->flush();

    $report = (new MkReport)->forceFill(['tenant_id' => 'inner']);
    $job = $make();
    $method = new \ReflectionMethod($job, 'comoElDuenoDelReporte');
    $boom = function () {
        throw new RuntimeException('boom');
    };
    $args = $job instanceof GenerateListExportJob
        ? [$report, Request::create('/x'), $boom]
        : [$report, $boom];

    expect(fn () => $method->invoke($job, ...$args))->toThrow(RuntimeException::class);
    expect($context->current())->toBeNull();
})->with('export jobs');
