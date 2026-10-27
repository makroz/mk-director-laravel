<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Mk\Director\Console\Commands\HelpStaleCommand;
use Mk\Director\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

/**
 * `mk:help:stale`: el manual contra los commits de los repos de sus `sources`.
 *
 * Repos git REALES en una carpeta temporal: `api` tiene los manuales y su
 * código; `admin` es otro repo. Las fechas de los commits se fijan a mano para
 * medir el respaldo por fecha sin depender del reloj.
 */
uses(TestCase::class);

beforeEach(function () {
    $tmp = sys_get_temp_dir().'/mk-help-stale-'.bin2hex(random_bytes(4));
    $this->api = $tmp.'/api';
    $this->admin = $tmp.'/admin';
    $this->help = $this->api.'/resources/help';

    foreach ([$this->api, $this->admin] as $repo) {
        mkdir($repo, 0777, true);
        staleGit($repo, 'init', '-q');
    }

    staleWrite($this->admin.'/modules/kyc/page.tsx', 'v1');
    staleCommit($this->admin, 'admin: kyc page', '2026-01-01T10:00:00Z');

    staleWrite($this->api.'/app/Kyc.php', 'v1');
    staleWrite($this->help.'/admin/kyc.md', "---\ntitle: KYC\naudience: admin\nsources: [api:app/Kyc.php, 'admin:modules/kyc/**']\n---\nx\n");
    staleWrite($this->help.'/admin/sin-fuentes.md', "---\ntitle: Otro\naudience: admin\n---\nx\n");
    staleCommit($this->api, 'api: kyc and its manual', '2026-02-01T10:00:00Z');

    config()->set('mk_director.help', [
        'content_path' => $this->help,
        'repos' => ['api' => $this->api, 'admin' => $this->admin],
    ]);
});

function staleGit(string $cwd, string ...$args): string
{
    $process = new Process(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', ...$args], $cwd);
    $process->mustRun();

    return trim($process->getOutput());
}

function staleWrite(string $file, string $content): void
{
    is_dir(dirname($file)) || mkdir(dirname($file), 0777, true);
    file_put_contents($file, $content);
}

function staleCommit(string $repo, string $message, string $date): void
{
    staleGit($repo, 'add', '-A');
    (new Process(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-qm', $message], $repo, [
        'GIT_AUTHOR_DATE' => $date, 'GIT_COMMITTER_DATE' => $date,
    ]))->mustRun();
}

/** @return array{0: int, 1: string} */
function runHelpStale(array $options = []): array
{
    $command = new HelpStaleCommand;
    $container = new class extends Container
    {
        public function runningUnitTests(): bool
        {
            return true;
        }
    };
    $command->setLaravel($container);
    $output = new BufferedOutput;

    return [$command->run(new ArrayInput($options), $output), $output->fetch()];
}

test('without a stamp nothing is stale right after the manual was committed', function () {
    [$code, $out] = runHelpStale(['--fail' => true]);

    expect($code)->toBe(0)
        ->and($out)->toContain('Desactualizados: 0 · Al día: 1 · Sin `sources`: 1');
});

test('without a stamp a later commit in the manuals repo makes it stale', function () {
    staleWrite($this->api.'/app/Kyc.php', 'v2');
    staleCommit($this->api, 'api: kyc v2', '2026-03-01T10:00:00Z');

    [$code, $out] = runHelpStale(['--fail' => true]);

    expect($code)->toBe(1)
        ->and($out)->toContain('admin/kyc')
        ->and($out)->toContain('api: 1 commit(s)')
        ->and($out)->toContain('api: kyc v2')
        ->and($out)->not->toContain('admin: 1 commit(s)');
});

test('without a stamp another repo counts the commits after the manual date', function () {
    // Fecha VIEJA pero commit NUEVO: la base sale por fecha y el rango por SHA.
    staleWrite($this->admin.'/modules/kyc/page.tsx', 'v2');
    staleCommit($this->admin, 'admin: kyc v2', '2026-03-01T10:00:00Z');
    staleWrite($this->admin.'/modules/otro/page.tsx', 'x');
    staleCommit($this->admin, 'admin: otro', '2026-03-02T10:00:00Z');

    [$code, $out] = runHelpStale();

    expect($code)->toBe(0)
        ->and($out)->toContain('admin: 1 commit(s)')
        ->and($out)->toContain('admin: kyc v2')
        ->and($out)->not->toContain('admin: otro');
});

test('--mark writes the HEAD of each repo and the article is no longer stale', function () {
    staleWrite($this->admin.'/modules/kyc/page.tsx', 'v2');
    staleCommit($this->admin, 'admin: kyc v2', '2026-03-01T10:00:00Z');

    expect(runHelpStale(['--fail' => true])[0])->toBe(1);

    [$code] = runHelpStale(['--mark' => ['admin/kyc']]);
    $stamps = json_decode((string) file_get_contents($this->help.'/.reviewed.json'), true);

    expect($code)->toBe(0)
        ->and($stamps)->toBe(['admin/kyc' => [
            'admin' => staleGit($this->admin, 'rev-parse', 'HEAD'),
            'api' => staleGit($this->api, 'rev-parse', 'HEAD'),
        ]])
        ->and(runHelpStale(['--fail' => true])[0])->toBe(0);
});

test('a commit touching a source after the stamp makes it stale again', function () {
    runHelpStale(['--mark-all' => true]);

    staleWrite($this->admin.'/modules/kyc/page.tsx', 'v3');
    // Fecha anterior a la marca: igual cuenta, porque se compara por SHA.
    staleCommit($this->admin, 'admin: kyc v3', '2025-06-01T10:00:00Z');

    [$code, $out] = runHelpStale(['--fail' => true]);
    [, $diff] = runHelpStale(['--diff' => 'admin/kyc']);

    expect($code)->toBe(1)
        ->and($out)->toContain('admin: kyc v3')
        ->and($diff)->toContain('modules/kyc/page.tsx');
});

test('a configured repo without a folder is warned and skipped', function () {
    config()->set('mk_director.help.repos', ['api' => $this->api, 'admin' => $this->admin.'-no-existe']);
    staleWrite($this->api.'/app/Kyc.php', 'v2');
    staleCommit($this->api, 'api: kyc v2', '2026-03-01T10:00:00Z');

    [$code, $out] = runHelpStale();

    expect($code)->toBe(0)
        ->and($out)->toContain('El repo `admin` no está disponible')
        ->and($out)->toContain('api: 1 commit(s)');
});

test('an article whose repo alias is not configured is skipped with a warning', function () {
    config()->set('mk_director.help.repos', ['api' => $this->api]);

    [$code, $out] = runHelpStale();

    expect($code)->toBe(0)
        ->and($out)->toContain('usa el repo `admin`, que no está en `mk_director.help.repos`')
        ->and($out)->toContain('Al día: 0');
});
