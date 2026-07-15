<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Auth;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Unit tests for the package migration that creates `verification_codes`.
 *
 * Spec: 2026-07-15-profile-edit-password-otp, Phase 1.1 + ADR-1 (design.md),
 * ADR-6 R3 (Schema::hasTable guard so a double-load is a no-op).
 */
uses(MkLaravelTestCase::class);

function bootVerificationCodesCapsule(): Capsule
{
    $capsule = new Capsule();
    $capsule->addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    Container::setInstance(new Container());
    Container::getInstance()->instance('db', $capsule->getDatabaseManager());
    Container::getInstance()->bind('db.schema', fn () => $capsule->getConnection()->getSchemaBuilder());
    Facade::setFacadeApplication(Container::getInstance());

    return $capsule;
}

function verificationCodesMigrationPath(): string
{
    return dirname(__DIR__, 3).'/src/Auth/Database/Migrations/2026_07_15_000001_create_verification_codes_table.php';
}

it('creates the verification_codes table with the expected columns', function () {
    bootVerificationCodesCapsule();

    expect(file_exists(verificationCodesMigrationPath()))->toBeTrue();

    $migration = require verificationCodesMigrationPath();
    $migration->up();

    expect(Schema::hasTable('verification_codes'))->toBeTrue();

    foreach ([
        'id', 'auth_scope', 'purpose', 'identifier', 'code_hash',
        'attempts', 'max_attempts', 'expires_at', 'consumed_at', 'created_at',
    ] as $column) {
        expect(Schema::hasColumn('verification_codes', $column))->toBeTrue("Missing column: {$column}");
    }
});

it('is idempotent — running up() twice does not throw (Schema::hasTable guard, ADR-6 R3)', function () {
    bootVerificationCodesCapsule();

    $migration = require verificationCodesMigrationPath();
    $migration->up();
    $migration->up();

    expect(Schema::hasTable('verification_codes'))->toBeTrue();
});

it('down() drops the table', function () {
    bootVerificationCodesCapsule();

    $migration = require verificationCodesMigrationPath();
    $migration->up();
    $migration->down();

    expect(Schema::hasTable('verification_codes'))->toBeFalse();
});
