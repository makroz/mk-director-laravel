<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Concerns;

/**
 * Used by policies that need to call `canMk()` on an actor without
 * a hard dependency on the model. Centralises the lookup so callers
 * don't have to write the fallback.
 */
trait ChecksAbilities
{
    /**
     * @param mixed $actor
     */
    protected function actorCan(mixed $actor, string $ability): bool
    {
        if (method_exists($actor, 'canMk')) {
            return (bool) $actor->canMk($ability);
        }

        return \Illuminate\Support\Facades\Gate::forUser($actor)->allows($ability);
    }
}
