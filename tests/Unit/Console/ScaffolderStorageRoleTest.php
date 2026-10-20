<?php

declare(strict_types=1);

/**
 * El scaffolder deja que el disk lo decida el ROL público (`MkStorage`).
 *
 * Antes pinaba `'disk' => 'public'` en la config del `FileStoragePlugin`, armaba
 * el link con `Storage::url()` (el disk DEFAULT de Laravel) y borraba con
 * `config('mk_director.storage.disk')`, una clave que nunca existió. Una app que
 * mapeaba el rol público a R2 seguía guardando los avatares en el disco local
 * del servidor.
 */

use Illuminate\Database\Eloquent\Model;
use Mk\Director\Console\Commands\MakeAuthUserCommand;
use Mk\Director\Tests\MkLaravelTestCase;

uses(MkLaravelTestCase::class);

function storageRoleInvoke(string $method, array $args): mixed
{
    $ref = new ReflectionMethod(new MakeAuthUserCommand, $method);
    $ref->setAccessible(true);

    return $ref->invoke(new MakeAuthUserCommand, ...$args);
}

test('the scaffolded plugin config and updateProfile() pin no disk: the plugin takes the public role', function () {
    $config = storageRoleInvoke('buildPluginsConfigLiteral', [['avatar'], 'admin']);
    $update = storageRoleInvoke('buildUpdateProfileMethod', [
        'Admin', 'admin', 'email', ['avatar' => ['type' => 'file', 'unique' => false]], [], ['avatar'], 'admins',
    ]);

    foreach ([$config, $update] as $out) {
        expect($out)->toContain("'path' => 'uploads/admin'");
        expect($out)->not->toContain("'disk' =>");
    }
});

test('the scaffolded repository deletes through MkStorage, never a config key that does not exist', function () {
    $out = storageRoleInvoke('buildFileFieldsDeleteCleanup', [['avatar'], 'admin']);

    expect($out)->toContain("app(\\Mk\\Director\\Storage\\MkStorage::class)->disk('public')");
    expect($out)->not->toContain('mk_director.storage.disk');
    expect($out)->not->toContain('Storage::disk(');
});

test('the scaffolded *_url accessor builds the link on the disk of the public role', function () {
    config()->set('filesystems.disks.role_public_test', [
        'driver' => 'local',
        'root' => sys_get_temp_dir().'/mk-role-public-test',
        'url' => 'https://cdn.role-public.test',
    ]);
    config()->set('mk_director.storage.public_disk', 'role_public_test');

    $accessors = storageRoleInvoke('buildFileFieldsAccessors', [['avatar']]);
    expect($accessors)->not->toContain('Storage::url(');

    // El accessor generado se ejecuta de verdad: lo que cuenta es el link.
    $class = 'ScaffoldedAvatarModel'.bin2hex(random_bytes(4));
    eval("class {$class} extends ".Model::class." { protected \$guarded = []; {$accessors} }");
    $model = new $class(['avatar' => 'uploads/admin/a.webp']);

    expect($model->avatar_url)->toBe('https://cdn.role-public.test/uploads/admin/a.webp');
    expect((new $class(['avatar' => null]))->avatar_url)->toBeNull();
    expect((new $class(['avatar' => 'https://elsewhere.test/a.webp']))->avatar_url)->toBe('https://elsewhere.test/a.webp');
});
