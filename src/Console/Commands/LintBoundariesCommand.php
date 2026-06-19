<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

/**
 * Lint cross-module imports in app/Modules/*.
 *
 * Enforces MME rule R-MK-001: cross-module communication only via
 * App\Modules\{X}\Api\* or Dto\* namespaces.
 *
 * Run via: php artisan mk:lint:boundaries
 */
final class LintBoundariesCommand extends Command
{
    protected $signature = 'mk:lint:boundaries
        {--path=app/Modules : Where to scan}
        {--format=table : Output format (table|json)}';

    protected $description = 'Lint cross-module imports in app/Modules (MME R-MK-001).';

    /**
     * Allowed external paths under another module.
     * Matches App\Modules\X\Api\, App\Modules\X\Api\Dto\,
     * App\Modules\X\Enums\, App\Modules\X\Exceptions\.
     */
    private const ALLOWED_PATTERNS = [
        '#App\\\\Modules\\\\[A-Za-z0-9_]+\\\\Api(\\\\Dto)?\\\\#',
        '#App\\\\Modules\\\\[A-Za-z0-9_]+\\\\Enums\\\\#',
        '#App\\\\Modules\\\\[A-Za-z0-9_]+\\\\Exceptions\\\\#',
    ];

    public function handle(): int
    {
        $basePath = (string) $this->option('path');
        $modulesPath = base_path($basePath);
        if (! is_dir($modulesPath)) {
            $this->warn("No modules directory at {$modulesPath} — nothing to lint.");
            return self::SUCCESS;
        }

        $modules = $this->discoverModules($modulesPath);
        if (empty($modules)) {
            $this->info('No modules found.');
            return self::SUCCESS;
        }

        $violations = [];
        foreach ($modules as $sourceModule) {
            $modulePath = $modulesPath . '/' . $sourceModule;
            foreach ($this->phpFiles($modulePath) as $file) {
                $contents = (string) file_get_contents($file);
                $imports = $this->extractUseStatements($contents);
                $relative = ltrim(str_replace($modulesPath . '/', '', $file), '/');

                foreach ($imports as $import) {
                    $targetModule = $this->extractTargetModule($import);
                    if ($targetModule === null || $targetModule === $sourceModule) {
                        continue;
                    }
                    if ($this->isAllowedExternal($import)) {
                        continue;
                    }
                    $violations[] = [
                        'file' => $relative,
                        'import' => $import,
                        'source' => $sourceModule,
                        'target' => $targetModule,
                    ];
                }
            }
        }

        if (empty($violations)) {
            $this->info('✅ All boundaries clean (' . count($modules) . ' modules checked).');
            return self::SUCCESS;
        }

        return $this->report($violations);
    }

    /** @return list<string> */
    private function discoverModules(string $modulesPath): array
    {
        $out = [];
        foreach (new \DirectoryIterator($modulesPath) as $entry) {
            if ($entry->isDot() || ! $entry->isDir()) {
                continue;
            }
            $out[] = $entry->getFilename();
        }
        sort($out);
        return $out;
    }

    /** @return iterable<string> */
    private function phpFiles(string $dir): iterable
    {
        $finder = new Finder();
        $finder->files()->in($dir)->name('*.php')->exclude('Tests');
        foreach ($finder as $file) {
            yield $file->getPathname();
        }
    }

    /**
     * Extract fully-qualified class names starting with App\Modules from code body and use statements.
     *
     * @return list<string>
     */
    private function extractUseStatements(string $src): array
    {
        $out = [];
        if (preg_match_all('/(?<![a-zA-Z0-9_\\\\])\\\\?(App\\\\Modules\\\\[A-Za-z0-9_]+(?:\\\\[A-Za-z0-9_]+)*)/', $src, $matches)) {
            foreach ($matches[1] as $m) {
                $out[] = $m;
            }
        }
        return array_unique($out);
    }

    private function extractTargetModule(string $fqcn): ?string
    {
        if (preg_match('#^App\\\\Modules\\\\([A-Za-z0-9_]+)\\\\#', $fqcn, $m)) {
            return $m[1];
        }
        return null;
    }

    private function isAllowedExternal(string $fqcn): bool
    {
        foreach (self::ALLOWED_PATTERNS as $pattern) {
            if (preg_match($pattern, $fqcn . '\\')) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array{file:string,import:string,source:string,target:string}> $violations */
    private function report(array $violations): int
    {
        $format = (string) $this->option('format');

        if ($format === 'json') {
            $this->line((string) json_encode($violations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $rows = [];
            foreach ($violations as $v) {
                $rows[] = [
                    $v['file'],
                    $v['import'],
                    $v['source'] . ' → ' . $v['target'],
                ];
            }
            $this->table(['File', 'Import', 'Source → Target'], $rows);
            $this->error('❌ ' . count($violations) . ' violation(s) found.');
        }

        return self::FAILURE;
    }
}
