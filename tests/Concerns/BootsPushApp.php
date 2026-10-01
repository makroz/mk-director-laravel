<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Concerns;

use Illuminate\Bus\BusServiceProvider;
use Illuminate\Foundation\Providers\FoundationServiceProvider;
use Illuminate\Queue\QueueServiceProvider;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Services\TokenIssuer;

/**
 * La app de los tests de push: la de {@see BootsHttpApp} (kernel, router y
 * Sanctum reales) más la cola `sync` y las tablas de push corridas desde las
 * migraciones del paquete, no recreadas a mano.
 *
 * La cola es `sync` de verdad, no `Bus::fake()`: `SyncQueue` respeta
 * `afterCommit`, así que el test del rollback mide lo mismo que pasa en
 * producción con un worker.
 */
trait BootsPushApp
{
    use BootsHttpApp;

    /** @param array<string,mixed> $push overrides de `mk_director.push` */
    public function bootPushApp(array $push = []): void
    {
        $this->bootHttpApp(PushTestUser::class, [
            'tenant' => ['enabled' => false],
            'push' => array_replace([
                'driver' => 'null',
                'register_routes' => true,
                'route_prefix' => 'api/push',
                'route_middleware' => ['api', 'mk.auth:admin'],
                'queue' => null,
            ], $push),
        ]);

        config(['queue' => ['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]]]);
        // Trae `FormRequestServiceProvider`. Sin él, el `RegisterPushDeviceRequest`
        // sale del container VACÍO: sin validar y sin usuario.
        $this->httpApp->register(FoundationServiceProvider::class);
        $this->httpApp->register(BusServiceProvider::class);
        $this->httpApp->register(QueueServiceProvider::class);

        Schema::create('push_test_users', function ($t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('email');
            $t->string('password');
            $t->string('auth_scope')->default('admin');
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

        // `MkAuthenticate` carga roles y abilities después de autenticar: sin
        // estas tablas el request muere en 500 y el test no mide nada.
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
                $t->uuid('push_test_user_id')->nullable();
                $t->string('user_type')->nullable();
                $t->timestamps();
            });
        }

        Schema::create('ability_role', function ($t) {
            $t->uuid('ability_id');
            $t->uuid('role_id');
        });

        foreach (glob(__DIR__.'/../../src/Database/Migrations/2026_10_01_*_create_mk_push_*.php') ?: [] as $migration) {
            (require $migration)->up();
        }
    }

    /** @return array{0: PushTestUser, 1: string} el usuario y su access token */
    public function pushUser(string $name): array
    {
        $user = PushTestUser::create([
            'name' => $name,
            'email' => strtolower($name).'@test.local',
            'password' => 'irrelevante',
        ]);

        return [$user, app(TokenIssuer::class)->issueAccessToken($user)->plainTextToken];
    }

    /** @param array<string,string> $body */
    public function registerDevice(?string $token, array $body): int
    {
        $headers = $token === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token];

        return $this->httpPost('/api/push/devices', $body, $headers)->getStatusCode();
    }

    public function unregisterDevice(string $token, string $address): int
    {
        return $this->httpDelete('/api/push/devices/'.rawurlencode($address), ['HTTP_AUTHORIZATION' => 'Bearer '.$token])
            ->getStatusCode();
    }
}
