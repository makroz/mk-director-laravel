<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Providers\FoundationServiceProvider;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Controllers\BaseAuthController;
use Mk\Director\Auth\Enums\ScopeStatus;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Auth\Support\MorphPivot;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/*
 * The session user is ONE shape whichever endpoint hands it out: what `customizeMePayload()` adds to `me` must also come
 * with the login, or a front that stores the user from the login response lacks it until it asks `me` again (found in a
 * consumer: the store name showed only after a reload).
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class MePayloadAdmin extends AuthUser
{
    protected $table = 'me_payload_admins';

    protected $guarded = [];

    protected $casts = ['status' => ScopeStatus::class];

    public function getAuthScope(): string
    {
        return 'admin';
    }
}

final class MePayloadAuthController extends BaseAuthController
{
    protected function authModelClass(): string
    {
        return MePayloadAdmin::class;
    }

    protected function authScope(): string
    {
        return 'admin';
    }

    protected function loginField(): string
    {
        return 'email';
    }

    protected function passwordResetTable(): string
    {
        return 'admin_password_reset_tokens';
    }

    /** What a consumer adds to the session user (in a multi-store app: the store the admin belongs to). */
    protected function customizeMePayload(Authenticatable $user): array
    {
        return ['store' => ['id' => 'store-1', 'name' => 'Norte']];
    }
}

beforeEach(function () {
    $this->bootHttpApp(MePayloadAdmin::class, ['tenant' => ['enabled' => false]]);
    config(['hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]]]);
    // `$request->validate()` es un macro de FoundationServiceProvider; el
    // harness no lo registra y el login lo usa.
    $this->httpApp->register(FoundationServiceProvider::class);

    Schema::create('me_payload_admins', function ($t) {
        $t->uuid('id')->primary();
        $t->string('name');
        $t->string('email');
        $t->string('password');
        $t->string('auth_scope')->default('admin');
        $t->unsignedTinyInteger('status')->default(ScopeStatus::Active->value);
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

    // `mk.auth` y el payload del login cargan roles/abilities.
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
            $t->uuid('me_payload_admin_id')->nullable();
            $t->string('user_type')->nullable();
            $t->timestamps();
        });
    }
    Schema::create('ability_role', function ($t) {
        $t->uuid('ability_id');
        $t->uuid('role_id');
    });

    // Cableado de un consumer: login/refresh públicos, me/logout detrás de mk.auth.
    Route::middleware(['api'])->group(function () {
        Route::post('api/admin/auth/login', [MePayloadAuthController::class, 'login']);
        Route::post('api/admin/auth/refresh', [MePayloadAuthController::class, 'refresh']);
    });
    Route::middleware(['api', 'mk.auth:admin'])->group(function () {
        Route::get('api/admin/auth/me', [MePayloadAuthController::class, 'me']);
        Route::post('api/admin/auth/logout', [MePayloadAuthController::class, 'logout']);
    });

    MePayloadAdmin::create([
        'name' => 'Admin',
        'email' => 'admin@test.local',
        'password' => Hash::make('secret'),
        'auth_scope' => 'admin',
    ]);
});

afterEach(function () {
    MorphPivot::flushSchemaCache();
    $this->tearDownHttpApp();
});

it('returns the customizeMePayload fields with the login, the same as me', function () {
    $login = $this->httpPost('/api/admin/auth/login', ['email' => 'admin@test.local', 'password' => 'secret']);
    expect($login->getStatusCode())->toBe(200, (string) $login->getContent());
    $data = json_decode((string) $login->getContent(), true)['data'];

    expect($data['admin']['store'] ?? null)->toBe(['id' => 'store-1', 'name' => 'Norte']);

    $me = $this->httpGet('/api/admin/auth/me', ['HTTP_AUTHORIZATION' => 'Bearer '.$data['access_token']]);
    expect($me->getStatusCode())->toBe(200)
        ->and(json_decode((string) $me->getContent(), true)['data']['store'])->toBe($data['admin']['store']);
});
