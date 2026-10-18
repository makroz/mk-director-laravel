<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Mk\Director\Spec\Introspector;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mk:spec:introspect` — the running app as JSON on stdout, for `mk spec import` and
 * `mk spec check` (`@makroz/spec`). Read-only: models, real DB columns, enums, abilities,
 * routes with their `mk.ability:`/`mk.auth:` middleware, and every SmartController's `$mkConfig`.
 */
final class SpecIntrospectCommand extends Command
{
    protected $signature = 'mk:spec:introspect {--pretty : Indented JSON}';

    protected $description = 'Prints the running app (models, tables, enums, abilities, routes, CRUD configs) as JSON for @makroz/spec.';

    public function handle(): int
    {
        $introspector = new Introspector([config('mk_director.paths.modules', app_path('Modules'))]);
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
        $this->output->writeln(json_encode($introspector->collect(), $flags | ($this->option('pretty') ? JSON_PRETTY_PRINT : 0)), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
