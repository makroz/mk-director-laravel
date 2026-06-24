<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * `php artisan mk:skill:deploy {name?}` — deploya una skill al proyecto actual.
 *
 * El command es asistente: la skill sigue viviendo en la agencia (single
 * source of truth). El dev corre este command en su proyecto y el paquete:
 *
 *   1. Detecta la skill a deployar (de argumento, prompt, o `--all`).
 *   2. La busca en orden de prioridad:
 *        a. `~/.mavis/agents/main/skills/{name}/SKILL.md` (Mavis agent)
 *        b. `.makromania/agency/skills/{name}/SKILL.md` (agencia del workspace)
 *        c. `src/Skills/{name}/SKILL.md` del propio paquete (futuro)
 *   3. Autodetecta la mejor ubicación destino en el proyecto:
 *        a. `.makromania/agency/skills/` si ya existe
 *        b. `.agents/skills/` si ya existe
 *        c. Prompt al usuario (default: `.makromania/agency/skills/`)
 *   4. Copia el `SKILL.md`.
 *   5. Crea o actualiza `AGENTS.md` con un snippet que lista las skills
 *      deployadas (idempotente — no duplica secciones).
 *
 * El command es no-invasivo por diseño: no toca código del proyecto, no
 * modifica el config, no agrega providers. Solo copia docs que las IAs
 * leen al trabajar en el proyecto.
 *
 * @see MkSkillListCommand
 */
class MkSkillDeployCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mk:skill:deploy
        {name? : Nombre de la skill a deployar (ej: mk-director-laravel)}
        {--to= : Forzar ubicación destino (.makromania/agency/skills o .agents/skills)}
        {--dry-run : Simular sin copiar archivos}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deploya una skill al proyecto actual (copia SKILL.md y registra en AGENTS.md).';

    public function handle(): int
    {
        $name = $this->argument('name');

        if ($name === null) {
            $name = $this->ask('¿Qué skill querés deployar? (ej: mk-director-laravel)');
        }

        if ($name === null || trim($name) === '') {
            $this->error('Nombre de skill requerido.');

            return self::FAILURE;
        }

        $name = trim($name);

        $source = $this->findSkillSource($name);
        if ($source === null) {
            $this->error("No encontré la skill '{$name}' en ninguna ubicación conocida.");
            $this->newLine();
            $this->line('Probá:');
            $this->line('  php artisan mk:skill:list    # ver qué hay disponible');

            return self::FAILURE;
        }

        $this->info("📦 Skill encontrada: {$name}");
        $this->line("   Origen: {$source['path']}");

        $target = $this->option('to') ?: $this->detectTargetRoot();
        $target = rtrim($target, '/');
        $this->line("   Destino: {$target}/{$name}/SKILL.md");

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->comment('[Simulación] No se copió nada. Corré sin --dry-run para aplicar.');

            return self::SUCCESS;
        }

        if (! $this->confirm("¿Copiar la skill a {$target}/{$name}/?", true)) {
            $this->comment('Deploy cancelado.');

            return self::SUCCESS;
        }

        File::ensureDirectoryExists("{$target}/{$name}");
        File::copy($source['path'], "{$target}/{$name}/SKILL.md");
        $this->info("✅ Skill copiada a {$target}/{$name}/SKILL.md");

        $this->updateAgentsMd($target, $name);
        $this->newLine();
        $this->info('🏁 Skill deployada. Las IAs que trabajen en este proyecto la van a leer automáticamente.');

        return self::SUCCESS;
    }

    /**
     * @return array{path: string, source: string}|null
     */
    protected function findSkillSource(string $name): ?array
    {
        $candidates = [
            getenv('HOME').'/.mavis/agents/main/skills/'.$name.'/SKILL.md',
            base_path('.makromania/agency/skills/'.$name.'/SKILL.md'),
            __DIR__.'/../../Skills/'.$name.'/SKILL.md',
        ];

        foreach ($candidates as $candidate) {
            if (File::exists($candidate)) {
                return [
                    'path' => $candidate,
                    'source' => $this->labelFor($candidate),
                ];
            }
        }

        return null;
    }

    protected function labelFor(string $path): string
    {
        $home = getenv('HOME') ?: '';
        if ($home !== '' && str_starts_with($path, $home)) {
            return 'mavis-agent';
        }
        if (str_contains($path, '.makromania/agency/skills')) {
            return 'agency';
        }

        return 'package';
    }

    /**
     * Resuelve la raíz destino con autodetección:
     *   1. `--to` explícito (override del usuario)
     *   2. `.makromania/agency/skills` si ya existe
     *   3. `.agents/skills` si ya existe
     *   4. Prompt al usuario con default `.makromania/agency/skills`
     */
    protected function detectTargetRoot(): string
    {
        $agency = base_path('.makromania/agency/skills');
        $agents = base_path('.agents/skills');

        if (File::isDirectory($agency)) {
            return $agency;
        }
        if (File::isDirectory($agents)) {
            return $agents;
        }

        $choice = $this->choice(
            '¿Dónde querés deployar la skill?',
            [
                'agency' => $agency.'  (recomendado si tu proyecto usa el flujo de la agencia Makromania)',
                'agents' => $agents.'  (convención Mavis/OpenCode estándar)',
            ],
            'agency',
        );

        return $choice === 'agents' ? $agents : $agency;
    }

    /**
     * Crea o actualiza `AGENTS.md` con una sección `## Skills` que lista
     * las skills deployadas. Idempotente: si la sección ya existe, agrega
     * la skill al final sin duplicar.
     */
    protected function updateAgentsMd(string $targetRoot, string $skillName): void
    {
        $agentsPath = base_path('AGENTS.md');
        $relPath = trim(str_replace(base_path().'/', '', $targetRoot.'/'.$skillName.'/SKILL.md'), '/');

        if (! File::exists($agentsPath)) {
            $this->newLine();
            $this->info('📝 Creando AGENTS.md en la raíz del proyecto con la skill registrada.');
            $template = <<<MD
                # AGENTS — Guía para IAs trabajando en este proyecto

                > Este archivo es leído por las IAs (Mavis, OpenCode, Cursor, etc.) al iniciar una sesión.
                > Mantenelo conciso: 1-2 pantallas de largo. Detalles extensos van en `docs/`.

                ## Stack

                - (Resumí el stack: Laravel, Next.js, Expo, base de datos, etc.)

                ## Convenciones

                - (Naming, branching, formato de commits, etc.)

                ## Skills deployadas

                - [{$skillName}]({$relPath})

                MD;
            File::put($agentsPath, $template);
            $this->info("   ✅ {$agentsPath} creado.");

            return;
        }

        $content = File::get($agentsPath);

        // Si ya tiene la sección, agregamos la skill al final de la lista
        // de la sección.
        if (preg_match('/^##\s+Skills\s+deployadas\s*$/m', $content)) {
            $bullet = "- [{$skillName}]({$relPath})";
            if (str_contains($content, $bullet)) {
                $this->line("   AGENTS.md ya referencia la skill {$skillName}, no se duplica.");

                return;
            }
            $updated = preg_replace_callback(
                '/(##\s+Skills\s+deployadas\s*\n)((?:\s*-\s+\[.+?\]\(.+?\)\n)*)/',
                static fn (array $m): string => $m[1].rtrim($m[2])."\n{$bullet}\n",
                $content,
                1,
            );
            File::put($agentsPath, $updated);
            $this->info("   ✅ AGENTS.md actualizado con {$skillName}.");

            return;
        }

        // Si no tiene la sección, la agregamos al final.
        $addition = "\n## Skills deployadas\n\n- [{$skillName}]({$relPath})\n";
        File::put($agentsPath, rtrim($content)."\n".$addition);
        $this->info("   ✅ AGENTS.md ahora referencia la skill {$skillName}.");
    }
}
