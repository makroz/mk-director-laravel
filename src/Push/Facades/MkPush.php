<?php

declare(strict_types=1);

namespace Mk\Director\Push\Facades;

use Illuminate\Support\Facades\Facade;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Push\PendingPush;
use Mk\Director\Push\PushService;

/**
 * `MkPush::to($member)->send(new PushMessage(...))`.
 *
 * @method static PendingPush to(AuthUser|iterable<AuthUser> $owners)
 *
 * @see PushService
 */
final class MkPush extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PushService::class;
    }
}
