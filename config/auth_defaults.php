<?php

declare(strict_types=1);

/**
 * Defaults de configuración para el módulo Auth de mk-director.
 *
 * Merged into `mk_director.auth.*` cuando el consumer no publicó
 * config/mk_director.php.
 */

return [
    'auth' => [
        'guards' => [
            // Mapea scope → guard name de Laravel. El consumer los sobreescribe
            // desde su `config/auth.php` real.
            'admin' => 'web',
            'member' => 'web',
        ],
        'tables' => [
            'roles' => 'roles',
            'abilities' => 'abilities',
            'role_user' => 'role_user',
            'ability_role' => 'ability_role',
        ],
        'ttl' => [
            'access_seconds' => 15 * 60,        // 15 min
            'refresh_seconds' => 7 * 24 * 60 * 60, // 7 días
        ],
    ],
];
