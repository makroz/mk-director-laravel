<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Tenancy;

use Mk\Director\Tenancy\TenantContext;

uses(\Mk\Director\Tests\TestCase::class);

test('TenantContext starts with null current tenant', function () {
    $context = new TenantContext();

    expect($context->current())->toBeNull();
});

test('TenantContext::set stores an int tenant id', function () {
    $context = new TenantContext();
    $context->set(7);

    expect($context->current())->toBe(7);
});

test('TenantContext::set stores a string tenant id (UUIDs, slugs)', function () {
    $context = new TenantContext();
    $context->set('uuid-9c0a');

    expect($context->current())->toBe('uuid-9c0a');
});

test('TenantContext::flush resets the current tenant', function () {
    $context = new TenantContext();
    $context->set(7);
    $context->flush();

    expect($context->current())->toBeNull();
});

test('TenantContext::set overwrites the previous tenant id', function () {
    $context = new TenantContext();
    $context->set(1);
    $context->set(2);

    expect($context->current())->toBe(2);
});
