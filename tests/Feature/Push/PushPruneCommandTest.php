<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Mk\Director\Console\Commands\PrunePushDevicesCommand;
use Mk\Director\Push\Models\MkPushDevice;
use Mk\Director\Tests\Concerns\BootsPushApp;
use Mk\Director\Tests\MkLaravelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `mk:push:prune`: borra los teléfonos que no se vieron en N días. Se afirma
 * qué filas QUEDAN en la base, no el texto de la salida.
 */
uses(MkLaravelTestCase::class, BootsPushApp::class);

beforeEach(fn () => $this->bootPushApp(['prune_after_days' => 60, 'chunk' => 2]));

afterEach(fn () => $this->tearDownHttpApp());

/** Un teléfono visto hace `$seenDaysAgo` días (null = nunca), creado hace `$createdDaysAgo`. */
function seenDevice(string $address, ?int $seenDaysAgo, int $createdDaysAgo = 400): void
{
    MkPushDevice::query()->insert([
        'id' => (string) Str::uuid(),
        'owner_type' => 'user',
        'owner_id' => '1',
        'provider' => 'fcm',
        'address' => $address,
        'platform' => 'android',
        'last_seen_at' => $seenDaysAgo === null ? null : now()->subDays($seenDaysAgo),
        'created_at' => now()->subDays($createdDaysAgo),
        'updated_at' => now(),
    ]);
}

/** @return array{0: int, 1: string} el código de salida y lo que imprimió */
function runPrune(array $options = []): array
{
    $command = new PrunePushDevicesCommand;
    $command->setLaravel(app());
    $output = new BufferedOutput;

    return [$command->run(new ArrayInput($options), $output), $output->fetch()];
}

/** @return list<string> */
function remainingAddresses(): array
{
    return MkPushDevice::query()->orderBy('address')->pluck('address')->all();
}

test('deletes only the devices not seen in N days, in chunks', function () {
    foreach (['stale-a', 'stale-b', 'stale-c'] as $address) {
        seenDevice($address, 61);
    }
    seenDevice('recent', 59);
    seenDevice('today', 0);

    [$code, $out] = runPrune();

    expect($code)->toBe(0)
        ->and(remainingAddresses())->toBe(['recent', 'today'])
        ->and($out)->toContain('Borrados 3 teléfonos');
});

test('a device without last_seen_at is judged by created_at', function () {
    seenDevice('never-seen-old', null, createdDaysAgo: 61);
    seenDevice('never-seen-new', null, createdDaysAgo: 5);

    runPrune();

    expect(remainingAddresses())->toBe(['never-seen-new']);
});

test('a recent last_seen_at keeps an old device', function () {
    seenDevice('old-but-active', 1, createdDaysAgo: 900);

    runPrune();

    expect(remainingAddresses())->toBe(['old-but-active']);
});

test('--days overrides the config', function () {
    seenDevice('ten-days', 10);
    seenDevice('three-days', 3);

    runPrune(['--days' => '7']);

    expect(remainingAddresses())->toBe(['three-days']);
});

test('0 or null in the config disables it', function (?int $days) {
    config(['mk_director.push.prune_after_days' => $days]);
    seenDevice('ancient', 9000, createdDaysAgo: 9000);

    [$code, $out] = runPrune();

    expect($code)->toBe(0)
        ->and(remainingAddresses())->toBe(['ancient'])
        ->and($out)->toContain('apagada');
})->with(['zero' => [0], 'null' => [null]]);

test('--days=0 disables it too, however it is written', function (string $days) {
    seenDevice('ancient', 9000);

    runPrune(['--days' => $days]);

    expect(remainingAddresses())->toBe(['ancient']);
})->with(['0' => ['0'], '00' => ['00']]);

test('--dry-run counts without deleting', function () {
    seenDevice('stale', 61);
    seenDevice('never-seen-old', null, createdDaysAgo: 61);
    seenDevice('recent', 1);

    [$code, $out] = runPrune(['--dry-run' => true]);

    expect($code)->toBe(0)
        ->and(remainingAddresses())->toBe(['never-seen-old', 'recent', 'stale'])
        ->and($out)->toContain('Se borrarían 2 teléfonos');
});

test('a non-numeric --days fails and deletes nothing', function (string $days) {
    seenDevice('stale', 61);

    [$code] = runPrune(['--days' => $days]);

    expect($code)->toBe(1)
        ->and(remainingAddresses())->toBe(['stale']);
})->with(['negative' => ['-5'], 'text' => ['abc']]);

test('registering again refreshes last_seen_at, so an active phone is never pruned', function () {
    [, $token] = $this->pushUser('Ana');
    seenDevice('ana-phone', 90);

    // Lo que hace la app en cada arranque con sesión.
    expect($this->registerDevice($token, ['provider' => 'fcm', 'address' => 'ana-phone', 'platform' => 'android']))->toBe(204);
    runPrune();

    expect(remainingAddresses())->toBe(['ana-phone']);
});
