<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * `php artisan mk:skill:list` — lista las skills disponibles para deployar.
 *
 * Una "skill" es un archivo `SKILL.md` que documenta el uso de un paquete
 * del ecosistema MK-Director para que las IAs lo lean al trabajar en el
 * proyecto. Las skills viven en:
 *
 *   1. La agencia    → `~/.mavis/agents/main/skills/...` (no se deploya)
 *   2. El workspace  → `.makromania/agency/skills/{name}/SKILL.md`
 *   3. Convencional  → `.agents/skills/{name}/SKILL.md`
 *
 * Este command escanea las tres ubicaciones y muestra qué hay disponible,
 * cuál es la fuente canónica, y si ya está deployada en el proyecto actual
 * (working directory).
 *
 * @see MkSkillDeployCommand
 */
class MkSkillListCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mk:skill:list {--source= : Filtrar por origen (agency|package|local|all)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Lista las skills disponibles para deployar (paquete + agencia + locales).';

    public function handle(): int
    {
        $source = $this->option('source') ?? 'all';

        $this->info('📚 Skills disponibles para el ecosistema MK-Director');
        $this->newLine();

        $rows = [];

        // 1. Skills que el paquete trae consigo (si están en su árbol).
        //    Hoy el PHP package no embarca skills propias; este slot queda
        //    reservado para cuando decidamos versionarlas dentro del tarball.
        if ($source === 'all' || $source === 'package') {
            $packageSkills = $this->discoverPackageSkills();
            foreach ($packageSkills as $skill) {
                $rows[] = [
                    'name' => $skill['name'],
                    'source' => 'package',
                    'deployed' => $this->isDeployed($skill['name']) ? '✅' : '—',
                    'path' => $skill['path'],
                ];
            }
        }

        // 2. Skills de la agencia (la fuente canónica actual).
        if ($source === 'all' || $source === 'agency') {
            $agencySkills = $this->discoverAgencySkills();
            foreach ($agencySkills as $skill) {
                $rows[] = [
                    'name' => $skill['name'],
                    'source' => 'agency',
                    'deployed' => $this->isDeployed($skill['name']) ? '✅' : '—',
                    'path' => $skill['path'],
                ];
            }
        }

        // 3. Skills ya deployadas en el proyecto actual (puede tener otras
        //    que la agencia no conoce — útil para auditar).
        if ($source === 'all' || $source === 'local') {
            $localSkills = $this->discoverLocalSkills();
            foreach ($localSkills as $skill) {
                // Evita duplicar las que ya listamos arriba.
                $already = collect($rows)->firstWhere('name', $skill['name']);
                if ($already !== null) {
                    continue;
                }
                $rows[] = [
                    'name' => $skill['name'],
                    'source' => 'local',
                    'deployed' => '✅',
                    'path' => $skill['path'],
                ];
            }
        }

        if ($rows === []) {
            $this->warn('No se encontraron skills. La agencia debería tener skills en `~/.mavis/agents/main/skills/` o `.makromania/agency/skills/`.');
            $this->newLine();
            $this->line('Para deployar una skill, primero creala en la agencia y luego corré:');
            $this->line('  php artisan mk:skill:deploy {nombre}');

            return self::SUCCESS;
        }

        $this->table(['Name', 'Source', 'Deployed', 'Path'], $rows);

        $this->newLine();
        $this->line('Para deployar una skill al proyecto actual:');
        $this->line('  php artisan mk:skill:deploy {nombre}');
        $this->line('Para más detalles:');
        $this->line('  php artisan mk:skill:deploy --help');

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{name: string, path: string}>
     */
    protected function discoverPackageSkills(): array
    {
        // El PHP package todavía no embarca skills. Si en el futuro se
        // agregan a `src/Skills/` o `skills/`, este discovery las levanta.
        $candidates = [
            __DIR__.'/../../Skills',
        ];

        return $this->scanDirs($candidates, 'mk-');
    }

    /**
     * @return array<int, array{name: string, path: string}>
     */
    protected function discoverAgencySkills(): array
    {
        $candidates = [
            getenv('HOME').'/.mavis/agents/main/skills',
            base_path('.makromania/agency/skills'),
        ];

        return $this->scanDirs($candidates);
    }

    /**
     * @return array<int, array{name: string, path: string}>
     */
    protected function discoverLocalSkills(): array
    {
        $candidates = [
            base_path('.makromania/agency/skills'),
            base_path('.agents/skills'),
        ];

        return $this->scanDirs($candidates);
    }

    /**
     * @param  array<int, string>  $dirs
     * @return array<int, array{name: string, path: string}>
     */
    protected function scanDirs(array $dirs, string $prefix = ''): array
    {
        $found = [];

        foreach ($dirs as $dir) {
            if (! File::isDirectory($dir)) {
                continue;
            }
            foreach (File::directories($dir) as $subdir) {
                $skillFile = $subdir.'/SKILL.md';
                if (! File::exists($skillFile)) {
                    continue;
                }
                $name = $prefix.basename($subdir);
                $found[$name] = [
                    'name' => $name,
                    'path' => $skillFile,
                ];
            }
        }

        return array_values($found);
    }

    protected function isDeployed(string $name): bool
    {
        foreach ($this->deploymentRoots() as $root) {
            if (File::exists($root.'/'.$name.'/SKILL.md')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    protected function deploymentRoots(): array
    {
        return [
            base_path('.makromania/agency/skills'),
            base_path('.agents/skills'),
        ];
    }
}
