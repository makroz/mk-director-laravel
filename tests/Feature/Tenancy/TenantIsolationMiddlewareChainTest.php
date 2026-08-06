<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Auth\Services\TokenIssuer;
use Mk\Director\Tenancy\Concerns\HasTenantMembership;
use Mk\Director\Tenancy\TenantContext;
use Mk\Director\Tenancy\TenantMembershipGate;
use Mk\Director\Tenancy\TenantResolver;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * FUGA DE AISLAMIENTO ENTRE TENANTS — la cadena de middleware REAL.
 *
 * 🔴 EL BUG. `MkServiceProvider` empuja `TenantResolver` al grupo `api`:
 *
 *     $router->pushMiddlewareToGroup('api', TenantResolver::class);
 *
 * En Laravel el middleware de GRUPO corre ANTES que el de RUTA. La ruta lleva
 * `mk.auth:{scope}`, que es quien resuelve el token de Sanctum. O sea: cuando
 * `TenantResolver` preguntaba `$request->user()`, todavía no había corrido
 * NADA de auth. Ese `user()` sin argumento sale por el guard default (`web`,
 * sesión) y devuelve `null` — y TODA la validación de membresía vivía adentro
 * de `if ($user !== null) { ... }`.
 *
 * Resultado: el bloque se salteaba entero, sin dejar rastro, y el tenant se
 * tomaba del header SIN VERIFICAR. Un admin del tenant A mandaba
 * `X-Tenant-ID: <B>` y recibía 200 con los datos de B.
 *
 * 🔴 LA IRONÍA QUE MARCA EL CAMINO. El comentario de `TenantResolver`
 * celebraba haber cerrado "the loophole where a consumer forgot to add the
 * trait": endurecieron la rama interna de un chequeo que NUNCA se ejecutaba.
 * Mejorar la validación no sirve de nada si el camino no LLEGA hasta ella.
 *
 * 🔴 POR QUÉ ESTE TEST NO ES EL QUE YA EXISTÍA. Los tests de tenancy del
 * paquete son de dos clases, y ninguna de las dos podía ver esto:
 *   - source-parsing (`TenantResolverMembershipTest`): leen el .php como
 *     string y verifican que el `ERR_TENANT_MISMATCH` esté escrito. Prueban
 *     que alguien lo tipeó, no que corra.
 *   - unitarios (`TenantResolverTest`): instancian el middleware y le pasan un
 *     `$request` con el usuario YA resuelto. Le regalan al test justo la
 *     precondición que el bug rompe.
 * Un bug de ORDEN sólo se ve armando el orden. De ahí el harness
 * {@see BootsHttpApp}: app real, router real, kernel real, Sanctum real.
 *
 * @see TenantMembershipGate
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

/**
 * Admin de prueba. `client_id` es la columna de membresía por defecto de
 * {@see HasTenantMembership}.
 */
final class ChainTestAdmin extends AuthUser
{
    protected $table = 'chain_test_admins';

    protected $guarded = [];

    public function getAuthScope(): string
    {
        return 'admin';
    }
}

beforeEach(function () {
    $this->bootHttpApp(ChainTestAdmin::class);

    Schema::create('chain_test_admins', function ($t) {
        $t->uuid('id')->primary();
        $t->string('name');
        $t->string('email');
        $t->string('password');
        $t->string('auth_scope')->default('admin');
        $t->string('client_id')->nullable();
        $t->timestamps();
    });

    Schema::create('personal_access_tokens', function ($t) {
        $t->id();
        $t->morphs('tokenable');
        $t->string('name');
        $t->string('token', 64)->unique();
        $t->text('abilities')->nullable();
        $t->timestamp('last_used_at')->nullable();
        $t->timestamp('expires_at')->nullable();
        $t->timestamps();
    });

    // RBAC: `MkAuthenticate` hace `loadMissing(['roles.abilities', 'directAbilities'])`
    // después de autenticar. Sin estas tablas el request muere en 500 y el test
    // no llega a medir nada.
    foreach (['roles', 'abilities'] as $name) {
        Schema::create($name, function ($t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->timestamps();
        });
    }

    foreach (['role_user' => 'role_id', 'ability_user' => 'ability_id'] as $pivot => $fk) {
        Schema::create($pivot, function ($t) use ($fk) {
            $t->uuid($fk);
            $t->uuid('user_id')->nullable();
            $t->uuid('chain_test_admin_id')->nullable();
            $t->string('user_type')->nullable();
            $t->timestamps();
        });
    }

    Schema::create('ability_role', function ($t) {
        $t->uuid('ability_id');
        $t->uuid('role_id');
    });

    // 🔴 LA RUTA VA CABLEADA COMO LA CABLEA UN CONSUMER: grupo `api` + la
    // ability/auth por ruta. Ese cableado ES el bug — cambiarlo acá para que
    // el test pase sería medir otra aplicación.
    Route::middleware(['api', 'mk.auth:admin'])->group(function () {
        Route::get('api/admin/pedidos', fn () => response()->json([
            'success' => true,
            'tenant_en_contexto' => app(TenantContext::class)->current(),
        ]));
    });
});

afterEach(function () {
    $this->tearDownHttpApp();
});

/** Crea un admin y devuelve su access token en texto plano. */
function tokenDeAdminDe(string $tenantId): string
{
    $admin = ChainTestAdmin::create([
        'name' => 'Admin de '.$tenantId,
        'email' => strtolower($tenantId).'@test.local',
        'password' => 'irrelevante',
        'auth_scope' => 'admin',
        'client_id' => $tenantId,
    ]);

    return app(TokenIssuer::class)->issueAccessToken($admin)->plainTextToken;
}

/** @return array{0:int,1:array<string,mixed>} */
function pedirComo(object $test, string $token, string $tenantHeader): array
{
    $response = $test->httpGet('/api/admin/pedidos', [
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'HTTP_X_TENANT_ID' => $tenantHeader,
    ]);

    return [
        $response->getStatusCode(),
        (array) json_decode((string) $response->getContent(), true),
    ];
}

// ─────────────────────────────────────────────────────────────────────────────

test('🔴 LA FUGA: un admin del tenant A manda el X-Tenant-ID del tenant B y le cortan con 403', function () {
    $token = tokenDeAdminDe('tenant-A');

    [$status, $body] = pedirComo($this, $token, 'tenant-B');

    // Con el bug vivo esto da 200 y `tenant_en_contexto` = "tenant-B":
    // el admin de A operando sobre los datos de B.
    expect($status)->toBe(403);
    expect($body['__extraData']['code'] ?? null)->toBe('ERR_TENANT_MISMATCH');
});

test('el contexto NUNCA queda pisado con el tenant ajeno (el controller no llega a correr)', function () {
    $token = tokenDeAdminDe('tenant-A');

    [, $body] = pedirComo($this, $token, 'tenant-B');

    // La aserción que importa para las escrituras: si el 403 se devolviera
    // DESPUÉS de `$next($request)`, un POST ya habría escrito en el tenant B
    // antes del rechazo. La clave `tenant_en_contexto` sólo existe si el
    // controller corrió.
    expect($body)->not->toHaveKey('tenant_en_contexto');
});

test('EL CASO INVERSO: mismo tenant → 200 (para que el verde no sea "todo falla")', function () {
    $token = tokenDeAdminDe('tenant-A');

    [$status, $body] = pedirComo($this, $token, 'tenant-A');

    expect($status)->toBe(200);
    expect($body['success'] ?? null)->toBeTrue();
    expect($body['tenant_en_contexto'] ?? null)->toBe('tenant-A');
});

test('EL ORDEN REAL: el resolver está en el grupo `api` y por lo tanto corre ANTES de mk.auth', function () {
    // Esto no es decoración: es la prueba de que el test de arriba mide el
    // escenario roto y no uno acomodado. Si algún día el paquete moviera el
    // resolver a middleware de ruta, este test se pondría rojo y avisaría que
    // la premisa cambió.
    $grupo = $this->httpApp['router']->getMiddlewareGroups()['api'];

    expect($grupo)->toContain(TenantResolver::class);
});

test('tenant.enabled = false: el middleware es pass-through y el header ajeno no rompe nada (RETO)', function () {
    // RETO es single-tenant. Con la feature apagada, mandar cualquier
    // X-Tenant-ID tiene que seguir siendo indiferente — el fix no puede
    // romperle el 200 a quien nunca pidió tenancy.
    $this->tearDownHttpApp();
    $this->bootHttpApp(ChainTestAdmin::class, ['tenant' => ['enabled' => false]]);

    Schema::create('chain_test_admins', function ($t) {
        $t->uuid('id')->primary();
        $t->string('name');
        $t->string('email');
        $t->string('password');
        $t->string('auth_scope')->default('admin');
        $t->string('client_id')->nullable();
        $t->timestamps();
    });
    Schema::create('personal_access_tokens', function ($t) {
        $t->id();
        $t->morphs('tokenable');
        $t->string('name');
        $t->string('token', 64)->unique();
        $t->text('abilities')->nullable();
        $t->timestamp('last_used_at')->nullable();
        $t->timestamp('expires_at')->nullable();
        $t->timestamps();
    });
    foreach (['roles', 'abilities'] as $name) {
        Schema::create($name, function ($t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->timestamps();
        });
    }
    foreach (['role_user' => 'role_id', 'ability_user' => 'ability_id'] as $pivot => $fk) {
        Schema::create($pivot, function ($t) use ($fk) {
            $t->uuid($fk);
            $t->uuid('user_id')->nullable();
            $t->uuid('chain_test_admin_id')->nullable();
            $t->string('user_type')->nullable();
            $t->timestamps();
        });
    }
    Schema::create('ability_role', function ($t) {
        $t->uuid('ability_id');
        $t->uuid('role_id');
    });

    Route::middleware(['api', 'mk.auth:admin'])->group(function () {
        Route::get('api/admin/pedidos', fn () => response()->json(['success' => true]));
    });

    $token = tokenDeAdminDe('tenant-A');

    [$status, $body] = pedirComo($this, $token, 'tenant-B');

    expect($status)->toBe(200);
    expect($body['success'] ?? null)->toBeTrue();
});
