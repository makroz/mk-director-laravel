<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mk\Director\Help\MkHelpShareLinks;
use Mk\Director\Tests\Concerns\BootsPushApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * El servicio de los enlaces públicos: qué guarda, cuándo un token deja de
 * abrir y cuánto puede durar.
 */
uses(MkLaravelTestCase::class, BootsPushApp::class);

beforeEach(function () {
    $this->bootPushApp([], ['help' => [
        'share_links_table' => 'mk_help_share_links',
        'share' => ['ability' => 'admin.help.share', 'max_hours' => 720, 'public_url' => 'https://panel.test/compartido/'],
    ]]);
    (require __DIR__.'/../../../src/Database/Migrations/2026_10_22_000004_create_mk_help_share_links_table.php')->up();
    $this->links = app(MkHelpShareLinks::class);
    [$this->user] = $this->pushUser('Ana');
});

afterEach(function () {
    Carbon::setTestNow();
    $this->tearDownHttpApp();
});

test('stores only the token hash', function () {
    ['link' => $link, 'url' => $url] = $this->links->create($this->user, 'manual:admin', 24);
    $token = substr($url, strlen('https://panel.test/compartido/'));
    $row = (array) DB::table('mk_help_share_links')->sole();

    expect($url)->toBe('https://panel.test/compartido/'.$token)
        ->and(strlen($token))->toBe(48)
        ->and($row['token_hash'])->toBe(hash('sha256', $token))
        ->and(implode('|', array_map('strval', $row)))->not->toContain($token)
        ->and($link->toArray())->not->toHaveKey('token_hash')
        ->and($row['created_by_type'])->toBe($this->user->getMorphClass())
        ->and($row['created_by_id'])->toBe((string) $this->user->getKey());
});

test('resolve returns null when expired', function () {
    $url = $this->links->create($this->user, 'manual:admin', 2)['url'];
    $token = substr($url, strrpos($url, '/') + 1);

    Carbon::setTestNow(Carbon::now()->addHours(2)->subMinute());
    expect($this->links->resolve($token))->not->toBeNull();

    Carbon::setTestNow(Carbon::now()->addMinutes(2));
    expect($this->links->resolve($token))->toBeNull()
        ->and($this->links->resolve('otro-token'))->toBeNull();
});

test('resolve returns null when revoked', function () {
    ['link' => $link, 'url' => $url] = $this->links->create($this->user, 'manual:admin', 24);
    $token = substr($url, strrpos($url, '/') + 1);
    [$other] = $this->pushUser('Beto');

    expect($this->links->revoke($other, $link->id))->toBeFalse()
        ->and($this->links->resolve($token))->not->toBeNull()
        ->and($this->links->revoke($this->user, $link->id))->toBeTrue()
        ->and($this->links->resolve($token))->toBeNull();
});

test('resolve counts uses', function () {
    ['link' => $link, 'url' => $url] = $this->links->create($this->user, 'manual:admin', 24);
    $token = substr($url, strrpos($url, '/') + 1);

    $this->links->resolve($token);
    $this->links->resolve($token);

    $fresh = $link->fresh();
    expect($fresh->uses)->toBe(2)
        ->and($fresh->last_used_at)->not->toBeNull()
        ->and($this->links->forCreator($this->user)->sole()->uses)->toBe(2);
});

test('rejects hours above max', function () {
    expect(fn () => $this->links->create($this->user, 'manual:admin', 721))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->links->create($this->user, 'manual:admin', 0))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('mk_help_share_links')->count())->toBe(0);

    $this->links->create($this->user, 'manual:admin', 720);

    expect(DB::table('mk_help_share_links')->count())->toBe(1);
});
