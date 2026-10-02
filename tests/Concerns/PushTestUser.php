<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Concerns;

use Mk\Director\Auth\Models\AuthUser;

/** El dueño de los teléfonos en los tests de push. Ver {@see BootsPushApp}. */
final class PushTestUser extends AuthUser
{
    protected $table = 'push_test_users';

    protected $guarded = [];

    public function getAuthScope(): string
    {
        return 'admin';
    }
}
