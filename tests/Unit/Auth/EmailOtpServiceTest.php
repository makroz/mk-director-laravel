<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Auth;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;
use Mk\Director\Auth\Services\EmailOtpService;
use Mk\Director\Auth\Services\OtpIssueResult;
use Mk\Director\Auth\Services\OtpVerifyResult;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * [SECURITY] Unit tests for `EmailOtpService`.
 *
 * Spec: 2026-07-15-profile-edit-password-otp
 *   - Domain: email-otp-verification (spec.md)
 *   - ADR-2 (design.md) — service API: issue/verify/isRequestThrottled/prune.
 *
 * Boots a real sqlite in-memory connection + a real Hash facade (bcrypt) so
 * hashing/expiry/attempt-cap/throttle assertions exercise real crypto and
 * real DB rows, not mocks — this is security-critical code.
 */
uses(MkLaravelTestCase::class);

function bootEmailOtpServiceContainer(array $otpConfig = []): Capsule
{
    $capsule = new Capsule();
    $capsule->addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $container = new Container();
    Container::setInstance($container);

    $container->instance('db', $capsule->getDatabaseManager());
    $container->bind('db.schema', fn () => $capsule->getConnection()->getSchemaBuilder());

    $container->instance('config', new ConfigRepository([
        'hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]],
        'mk_director' => [
            'auth' => [
                'otp' => array_replace([
                    'length' => 6,
                    'ttl_seconds' => 600,
                    'max_attempts' => 5,
                    'throttle' => [
                        'max' => 3,
                        'window_seconds' => 600,
                    ],
                ], $otpConfig),
            ],
        ],
    ]));

    $container->singleton('hash', fn ($app) => new HashManager($app));

    Facade::setFacadeApplication($container);

    // Create the table directly (avoid coupling this test to the migration file).
    $capsule->getConnection()->getSchemaBuilder()->create('verification_codes', function ($table) {
        $table->uuid('id')->primary();
        $table->string('auth_scope');
        $table->string('purpose');
        $table->string('identifier');
        $table->string('code_hash');
        $table->unsignedTinyInteger('attempts')->default(0);
        $table->unsignedTinyInteger('max_attempts');
        $table->timestamp('expires_at');
        $table->timestamp('consumed_at')->nullable();
        $table->timestamp('created_at')->nullable();
    });

    return $capsule;
}

// ── issue() ─────────────────────────────────────────────────────────────

it('issues a code, storing only its HASH — never the plaintext (SECURITY)', function () {
    bootEmailOtpServiceContainer();
    $service = new EmailOtpService();

    $result = $service->issue('member', 'password_change', 'jane@example.com');

    expect($result)->toBeInstanceOf(OtpIssueResult::class);
    expect($result->plainCode)->toMatch('/^\d{6}$/');

    $row = DB::table('verification_codes')
        ->where('auth_scope', 'member')
        ->where('purpose', 'password_change')
        ->where('identifier', 'jane@example.com')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->code_hash)->not->toBe($result->plainCode);
    expect(Hash::check($result->plainCode, $row->code_hash))->toBeTrue();
    expect($row->attempts)->toBe(0);
    expect($row->consumed_at)->toBeNull();
});

it('a re-request invalidates the prior code (concurrent requests invalidate prior codes)', function () {
    bootEmailOtpServiceContainer();
    $service = new EmailOtpService();

    $first = $service->issue('member', 'password_change', 'jane@example.com');
    // Force the throttle window to be already elapsed so a second issue() is allowed.
    DB::table('verification_codes')->update(['created_at' => now()->subMinutes(20)]);

    $service->issue('member', 'password_change', 'jane@example.com');

    $verdict = $service->verify('member', 'password_change', 'jane@example.com', $first->plainCode);

    expect($verdict)->toBe(OtpVerifyResult::Invalid);
});

// ── verify() — correctness within expiry + attempts ───────────────────────

it('verify() returns Confirmed for the correct PIN within expiry + attempts, and consumes the code', function () {
    bootEmailOtpServiceContainer();
    $service = new EmailOtpService();

    $result = $service->issue('member', 'password_change', 'jane@example.com');

    $verdict = $service->verify('member', 'password_change', 'jane@example.com', $result->plainCode);

    expect($verdict)->toBe(OtpVerifyResult::Confirmed);

    $row = DB::table('verification_codes')->where('identifier', 'jane@example.com')->first();
    expect($row->consumed_at)->not->toBeNull();
});

// ── verify() — expired code rejected even if correct ──────────────────────

it('verify() rejects an expired code even with the correct PIN', function () {
    bootEmailOtpServiceContainer();
    $service = new EmailOtpService();

    $result = $service->issue('member', 'password_change', 'jane@example.com');
    DB::table('verification_codes')
        ->where('identifier', 'jane@example.com')
        ->update(['expires_at' => now()->subMinute()]);

    $verdict = $service->verify('member', 'password_change', 'jane@example.com', $result->plainCode);

    expect($verdict)->toBe(OtpVerifyResult::Expired);
});

// ── verify() — already-consumed code cannot be reused ──────────────────────

it('verify() rejects a code that was already consumed (single-use)', function () {
    bootEmailOtpServiceContainer();
    $service = new EmailOtpService();

    $result = $service->issue('member', 'password_change', 'jane@example.com');
    expect($service->verify('member', 'password_change', 'jane@example.com', $result->plainCode))
        ->toBe(OtpVerifyResult::Confirmed);

    // Second attempt with the SAME (now-consumed) plaintext code must fail.
    $verdict = $service->verify('member', 'password_change', 'jane@example.com', $result->plainCode);

    expect($verdict)->toBe(OtpVerifyResult::NotFound);
});

// ── verify() — attempt cap locks the code ──────────────────────────────────

it('verify() locks the code once attempts reach max_attempts, blocking further tries even with the correct PIN', function () {
    bootEmailOtpServiceContainer(['max_attempts' => 3]);
    $service = new EmailOtpService();

    $result = $service->issue('member', 'password_change', 'jane@example.com');

    // 3 wrong attempts exhaust the cap.
    expect($service->verify('member', 'password_change', 'jane@example.com', '000000'))->toBe(OtpVerifyResult::Invalid);
    expect($service->verify('member', 'password_change', 'jane@example.com', '000000'))->toBe(OtpVerifyResult::Invalid);
    expect($service->verify('member', 'password_change', 'jane@example.com', '000000'))->toBe(OtpVerifyResult::Invalid);

    // Even the CORRECT pin is now rejected — the code is Locked.
    $verdict = $service->verify('member', 'password_change', 'jane@example.com', $result->plainCode);

    expect($verdict)->toBe(OtpVerifyResult::Locked);
});

it('verify() increments attempts on a wrong PIN without leaking any hint', function () {
    bootEmailOtpServiceContainer();
    $service = new EmailOtpService();

    $service->issue('member', 'password_change', 'jane@example.com');

    $verdict = $service->verify('member', 'password_change', 'jane@example.com', '000000');

    expect($verdict)->toBe(OtpVerifyResult::Invalid);

    $row = DB::table('verification_codes')->where('identifier', 'jane@example.com')->first();
    expect($row->attempts)->toBe(1);
});

// ── isRequestThrottled() ────────────────────────────────────────────────────

it('isRequestThrottled() blocks a rapid re-request within the throttle window', function () {
    bootEmailOtpServiceContainer(['throttle' => ['max' => 3, 'window_seconds' => 600]]);
    $service = new EmailOtpService();

    expect($service->isRequestThrottled('member', 'password_change', 'jane@example.com'))->toBeFalse();

    $service->issue('member', 'password_change', 'jane@example.com');

    expect($service->isRequestThrottled('member', 'password_change', 'jane@example.com'))->toBeTrue();
});

it('isRequestThrottled() allows a new request once the throttle window has elapsed', function () {
    bootEmailOtpServiceContainer(['throttle' => ['max' => 3, 'window_seconds' => 600]]);
    $service = new EmailOtpService();

    $service->issue('member', 'password_change', 'jane@example.com');
    DB::table('verification_codes')->update(['created_at' => now()->subMinutes(20)]);

    expect($service->isRequestThrottled('member', 'password_change', 'jane@example.com'))->toBeFalse();
});

// ── prune() ──────────────────────────────────────────────────────────────

it('prune() removes expired and consumed rows', function () {
    bootEmailOtpServiceContainer();
    $service = new EmailOtpService();

    $result = $service->issue('member', 'password_change', 'jane@example.com');
    $service->verify('member', 'password_change', 'jane@example.com', $result->plainCode); // consumes it

    $service->issue('member', 'password_change', 'other@example.com');
    DB::table('verification_codes')
        ->where('identifier', 'other@example.com')
        ->update(['expires_at' => now()->subDay()]);

    $service->issue('admin', 'password_change', 'still-live@example.com');

    $deleted = $service->prune();

    expect($deleted)->toBe(2);
    expect(DB::table('verification_codes')->count())->toBe(1);
});
