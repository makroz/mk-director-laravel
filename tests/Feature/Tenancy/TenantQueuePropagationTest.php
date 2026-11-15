<?php

declare(strict_types=1);

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Event;
use Mk\Director\Push\Jobs\SendPushJob;
use Mk\Director\Push\PushMessage;
use Mk\Director\Tenancy\TenantContext;
use Mk\Director\Tests\Concerns\BootsPushApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * S3 — the tenant travels with queued jobs (opt-in).
 */
uses(MkLaravelTestCase::class, BootsPushApp::class);

final class TenantProbeJob
{
    /** @var list<string|int|null> */
    public static array $seen = [];

    public function handle(): void
    {
        self::$seen[] = app(TenantContext::class)->current();
    }
}

/** A queue that exposes the payload it would store. */
function payloadOf(object $job): array
{
    $queue = new class extends SyncQueue
    {
        public function payloadOf(object $job): array
        {
            return json_decode($this->createPayload($job, 'default'), true);
        }
    };
    $queue->setContainer(app());
    $queue->setConnectionName('sync');

    return $queue->payloadOf($job);
}

function syncJobWith(array $payload): SyncJob
{
    return new SyncJob(app(), json_encode($payload), 'sync', 'default');
}

beforeEach(function () {
    $this->bootPushApp();
    TenantProbeJob::$seen = [];
});

test('with the flag on, a job queued under a tenant carries it in the payload', function () {
    config(['mk_director.tenant.queue_propagation' => true]);

    app(TenantContext::class)->set('t-1');

    expect(payloadOf(new TenantProbeJob))->toHaveKey('mk_tenant_id', 't-1');
    expect(payloadOf(new SendPushJob(new PushMessage('hi', 'body'), [], 'topic')))->toHaveKey('mk_tenant_id', 't-1');
});

test('with the flag on, a job queued without tenant has no tenant key', function () {
    config(['mk_director.tenant.queue_propagation' => true]);

    expect(payloadOf(new TenantProbeJob))->not->toHaveKey('mk_tenant_id');
});

test('with the flag off the payload has no tenant key even under a tenant', function () {
    app(TenantContext::class)->set('t-1');

    expect(payloadOf(new TenantProbeJob))->not->toHaveKey('mk_tenant_id');
});

test('with the flag on, the job runs with its tenant and the worker is left clean afterwards', function () {
    config(['mk_director.tenant.queue_propagation' => true]);
    $tenant = app(TenantContext::class);
    $job = syncJobWith(['uuid' => 'x', 'mk_tenant_id' => 'from-payload']);

    Event::dispatch(new JobProcessing('sync', $job));
    expect($tenant->current())->toBe('from-payload');

    Event::dispatch(new JobProcessed('sync', $job));
    expect($tenant->current())->toBeNull();
});

test('with the flag on, a job queued without tenant runs without tenant and the previous one comes back', function () {
    config(['mk_director.tenant.queue_propagation' => true]);
    $tenant = app(TenantContext::class);
    $tenant->set('leftover');
    $job = syncJobWith(['uuid' => 'x']);

    Event::dispatch(new JobProcessing('sync', $job));
    expect($tenant->current())->toBeNull();

    Event::dispatch(new JobFailed('sync', $job, new RuntimeException('boom')));
    expect($tenant->current())->toBe('leftover');
});

test('with the flag off, processing never touches the tenant', function () {
    $tenant = app(TenantContext::class);
    $tenant->set('mine');
    $job = syncJobWith(['uuid' => 'x', 'mk_tenant_id' => 'ignored']);

    Event::dispatch(new JobProcessing('sync', $job));
    expect($tenant->current())->toBe('mine');

    Event::dispatch(new JobProcessed('sync', $job));
    expect($tenant->current())->toBe('mine');
});

test('end to end on the sync queue the job sees the tenant and the request keeps it', function () {
    config(['mk_director.tenant.queue_propagation' => true]);
    $tenant = app(TenantContext::class);
    $tenant->set('t-7');

    app('queue')->connection('sync')->push(new TenantProbeJob);

    expect(TenantProbeJob::$seen)->toBe(['t-7']);
    expect($tenant->current())->toBe('t-7');
});
