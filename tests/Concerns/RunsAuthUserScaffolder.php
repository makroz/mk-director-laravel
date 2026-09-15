<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Concerns;

use Illuminate\Filesystem\Filesystem;
use Mk\Director\Console\Commands\MakeAuthUserCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Corre `mk:make:auth-user` ENTERO —`handle()`, no un método suelto— contra un
 * proyecto vacío en un tempdir. Requiere `BootsHttpApp` en el mismo test.
 *
 * 🔴 Por qué no alcanza con lo que ya había: los tests del scaffolder parsean
 * el SOURCE o invocan un método por Reflection pasándole los valores ya
 * calculados (`'Admin', 'admin', 'admins', ...`). Un bug que vive en CÓMO
 * `handle()` calcula esos valores —el plural, qué se emite en register, qué
 * prefijo lleva un throttle— pasa en verde por los dos caminos.
 *
 * Con la app real (`BootsHttpApp`) existen `app_path()`, `config_path()` y
 * `base_path()`, así que el comando escribe el módulo, cablea
 * `config/auth.php` y registra el provider en `bootstrap/providers.php` como en
 * un consumer. Lo único que se apaga es `runPostScaffoldSteps()`
 * (vendor:publish, composer require, migrate): no es generación de código y no
 * hay artisan que lo corra.
 */
trait RunsAuthUserScaffolder
{
    /** @var array<int, string> tempdirs a borrar en `cleanScaffolderTempDirs()`, aunque el test falle */
    protected array $scaffolderTempDirs = [];

    /**
     * @param  array<string, mixed>  $args
     * @return array{0: int, 1: string, 2: string} [exit code, output, base path]
     */
    public function runScaffolderInTempDir(array $args): array
    {
        $app = $this->bootHttpApp(\stdClass::class);

        $base = sys_get_temp_dir().'/mk-scaffold-'.uniqid();
        $this->scaffolderTempDirs[] = $base;
        $fs = new Filesystem;
        $fs->ensureDirectoryExists($base.'/config');
        $fs->ensureDirectoryExists($base.'/bootstrap');
        $fs->ensureDirectoryExists($base.'/app');
        file_put_contents($base.'/config/auth.php', "<?php\n\nreturn [\n    'guards' => [\n        'web' => ['driver' => 'session', 'provider' => 'users'],\n    ],\n\n    'providers' => [\n        'users' => ['driver' => 'eloquent', 'model' => 'App\\\\Models\\\\User'],\n    ],\n];\n");
        file_put_contents($base.'/bootstrap/providers.php', "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n");
        $app->setBasePath($base);

        $command = new class extends MakeAuthUserCommand
        {
            protected function runPostScaffoldSteps(string $scope, string $scopeLower, bool $withCrud, bool $setupSanctum, bool $migrate, bool $seed, bool $discover): void
            {
                // no-op: publish/migrate/composer no son generación de código.
            }
        };
        $command->setLaravel($app);

        $output = new BufferedOutput;
        $exit = $command->run(new ArrayInput($args), $output);

        return [$exit, $output->fetch(), $base];
    }

    public function cleanScaffolderTempDirs(): void
    {
        foreach ($this->scaffolderTempDirs as $dir) {
            (new Filesystem)->deleteDirectory($dir);
        }
        $this->scaffolderTempDirs = [];
    }

    /** Todo lo que el scaffolder dejó en disco, concatenado con el path de cada archivo. */
    public function allGeneratedContent(string $base): string
    {
        $out = '';
        foreach ((new Filesystem)->allFiles($base, true) as $file) {
            $out .= "\n=== ".$file->getRelativePathname()." ===\n".$file->getContents();
        }

        return $out;
    }
}
