<?php

declare(strict_types=1);

namespace Mk\Director\Tenancy;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;

/**
 * Opt-in (`mk_director.tenant.queue_propagation`): carries the active tenant
 * inside every queued payload and runs the job with it.
 *
 * One global hook instead of per-job code, so jobs of the consumer get it too.
 * The flag is read when the payload is built and when the job starts, so with
 * it off the payload and the execution are exactly the stock ones.
 *
 * A worker never leaks a tenant: whatever was active before the job is put
 * back when the job ends (processed, failed or errored).
 */
final class TenantQueuePropagation
{
    public const PAYLOAD_KEY = 'mk_tenant_id';

    private static bool $hooked = false;

    /** @var array<int, string|int|null> tenant active before each running job, by job object id */
    private array $before = [];

    public function __construct(private readonly TenantContext $context) {}

    public static function enabled(): bool
    {
        return filter_var(config('mk_director.tenant.queue_propagation', false), FILTER_VALIDATE_BOOLEAN);
    }

    /** Registers the payload hook once per process (it resolves the container lazily). */
    public static function hookPayload(): void
    {
        if (self::$hooked) {
            return;
        }

        self::$hooked = true;

        Queue::createPayloadUsing(function () {
            $tenant = self::enabled() ? app(TenantContext::class)->current() : null;

            return $tenant === null ? [] : [self::PAYLOAD_KEY => $tenant];
        });
    }

    public function starting(JobProcessing $event): void
    {
        if (! self::enabled()) {
            return;
        }

        $this->before[spl_object_id($event->job)] = $this->context->current();

        $tenant = $event->job->payload()[self::PAYLOAD_KEY] ?? null;
        $tenant === null ? $this->context->flush() : $this->context->set($tenant);
    }

    public function finished(JobProcessed|JobFailed|JobExceptionOccurred $event): void
    {
        $id = spl_object_id($event->job);

        if (! array_key_exists($id, $this->before)) {
            return;
        }

        $previous = $this->before[$id];
        unset($this->before[$id]);

        $previous === null ? $this->context->flush() : $this->context->set($previous);
    }
}
