<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Exceptions;

use RuntimeException;

/**
 * Thrown when a request's auth_scope does not match the expected scope.
 *
 * Resolves to HTTP 401 — the auth itself is invalid for this endpoint,
 * not a permission issue (that would be 403).
 */
class ScopeMismatchException extends RuntimeException
{
    public function __construct(
        public readonly string $expectedScope,
        public readonly ?string $actualScope = null,
    ) {
        parent::__construct(sprintf(
            'Auth scope mismatch: expected "%s", got "%s".',
            $expectedScope,
            $actualScope ?? '<none>',
        ));
    }
}
