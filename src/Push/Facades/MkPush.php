<?php

declare(strict_types=1);

namespace Mk\Director\Push\Facades;

use Illuminate\Support\Facades\Facade;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Push\PendingPush;
use Mk\Director\Push\PushService;

/**
 * `MkPush::to($member)->send(new PushMessage(...))`, o a un tema:
 * `MkPush::subscribe($member, 'novedades')` y `MkPush::topic('novedades')->send(...)`.
 *
 * @method static PendingPush to(AuthUser|iterable<AuthUser> $owners)
 * @method static PendingPush topic(string $topic)
 * @method static void subscribe(AuthUser $owner, string $topic)
 * @method static void unsubscribe(AuthUser $owner, string $topic)
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
